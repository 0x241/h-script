<?php

namespace HScript\Update;

use HScript\Database\Connection;
use HScript\Observability\HealthService;
use HScript\Recovery\RecoveryDrillVerifier;
use RuntimeException;
use Throwable;

final class UpdateHealthChecker
{
	private Connection $database;
	private string $projectRoot;

	public function __construct(Connection $database, string $projectRoot)
	{
		$this->database = $database;
		$this->projectRoot = rtrim($projectRoot, '/');
	}

	public function check(ReleaseManifest $manifest): array
	{
		$checks = $this->inspect($manifest->applicationVersion(), $manifest->schemaVersion());
		$this->assertChecks($checks);
		return $checks;
	}

	/** Operator-only post-upgrade gate. Never repairs state or sends telemetry. */
	public function reconcile(): array
	{
		$application = trim((string)file_get_contents($this->projectRoot . '/VERSION'));
		$schema = trim((string)file_get_contents($this->projectRoot . '/SCHEMA_VERSION'));
		$checks = $this->inspect($application, $schema);
		$checks['installed_application'] = (new SchemaStateRepository($this->database))->installedApplicationVersion() === $application;
		$checks['lifecycle'] = !file_exists($this->projectRoot . '/.cfg/maintenance.json')
			&& !is_link($this->projectRoot . '/.cfg/maintenance.json')
			&& !file_exists($this->projectRoot . '/.cfg/schema-update-required.json')
			&& !is_link($this->projectRoot . '/.cfg/schema-update-required.json')
			&& $this->count("SELECT COUNT(*) FROM UpdateRuns WHERE urState NOT IN ('completed','failed')") === 0;
		$checks['cron_freshness'] = $this->configuration('Cron', 'Enabled') === '1'
			&& (new HealthService($this->projectRoot))->cronCheck(true)['status'] === 'ok';
		$lastSuccess = $this->configuration('Telemetry', 'LastSuccessAt');
		$maximumAge = getenv('UPDATE_TELEMETRY_MAX_AGE_SECONDS') ?: '172800';
		$checks['telemetry_freshness'] = ctype_digit($maximumAge) && (int)$maximumAge >= 60 && (int)$maximumAge <= 604800
			&& ctype_digit($lastSuccess) && (int)$lastSuccess > 0 && (int)$lastSuccess <= time()
			&& time() - (int)$lastSuccess <= (int)$maximumAge;
		$this->assertChecks($checks);
		return array('format' => 1, 'checked_at' => gmdate('Y-m-d\TH:i:s\Z'), 'status' => 'ready',
			'application_version' => $application, 'schema_version' => $schema, 'checks' => $checks);
	}

	private function inspect(string $application, string $schema): array
	{
		SchemaVersion::requireValid($application);
		SchemaVersion::requireValid($schema);
		$checks = array();
		$checks['bootstrap'] = is_file($this->projectRoot . '/vendor/autoload.php')
			&& is_readable($this->projectRoot . '/VERSION');
		$checks['database'] = $this->count('SELECT 1') === 1;
		$checks['application_version'] = trim((string)file_get_contents($this->projectRoot . '/VERSION')) === $application;
		$checks['schema_version'] = (new SchemaStateRepository($this->database))->currentVersion() === $schema
			&& trim((string)file_get_contents($this->projectRoot . '/SCHEMA_VERSION')) === $schema;
		$checks['runtime'] = $this->passes(function (): void {
			UpdateCompatibility::fromRoot($this->projectRoot)->assertRuntime(RuntimeEnvironment::inspect($this->database));
		});
		$checks['migrations'] = $this->passes(fn() => $this->verifyMigrations($schema));
		$checks['application_invariants'] = $this->passes(function () use ($schema): void {
			(new RecoveryDrillVerifier(''))->verifyApplicationState($this->database, SchemaVersion::compare($schema, '1.0.1') >= 0);
		});
		$checks['queue_state'] = (new HealthService($this->projectRoot))->queueCheck($this->database)['status'] === 'ok';
		$routes = $this->runtimeRoutes();
		$checks['admin_route'] = isset($routes['rwlinks']['admin'])
			&& ($routes['rwlinks']['admin'][0] ?? null) === 'admin'
			&& is_file($this->projectRoot . '/module/admin/onstart.php');
		$checks['cron'] = is_file($this->projectRoot . '/module/cron/index.php')
			&& isset($routes['oncron']['telemetry']);
		$checks['http_routes'] = true;
		foreach (array('account/login' => 'login', 'api/v1/balance' => 'api/v1/balance', 'api/v1/user' => 'api/v1/user',
			'api/v1/operations' => 'api/v1/operations', 'api/v1/installations/register' => 'api/v1/installations/register') as $route => $module)
			$checks['http_routes'] = $checks['http_routes'] && ($routes['rwlinks'][$route][0] ?? null) === $module
				&& is_readable($this->projectRoot . '/module/' . $route . '/index.php');
		return $checks;
	}

	private function assertChecks(array $checks): void
	{
		$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
		if ($failed)
			throw new UpdateHealthCheckFailed($failed);
	}

	private function verifyMigrations(string $schema): void
	{
		$known = array();
		foreach ((new MigrationLoader($this->projectRoot . '/migrations/versioned'))->all() as $migration) $known[$migration->id()] = $migration;
		$query = $this->database->query('SELECT smID,smChecksum,smFromVersion,smToVersion,smClassification,smStatus FROM SchemaMigrations');
		$rows = $query === false ? false : $this->database->fetchRows($query);
		if (!is_array($rows)) throw new RuntimeException('Migration state is unavailable');
		foreach ($rows as $row)
		{
			$migration = $known[$row['smID']] ?? null;
			if ($migration === null || $row['smStatus'] !== 'applied' || !hash_equals($migration->checksum(), (string)$row['smChecksum'])
				|| $row['smFromVersion'] !== $migration->fromVersion() || $row['smToVersion'] !== $migration->toVersion()
				|| $row['smClassification'] !== $migration->classification() || SchemaVersion::compare($row['smToVersion'], $schema) > 0)
				throw new RuntimeException('Migration metadata does not match the release');
		}
		// A fresh install/baseline may have no historical rows; never invent them.
		if ((new MigrationRunner($this->database, new SchemaStateRepository($this->database), new MigrationLoader($this->projectRoot . '/migrations/versioned')))->preflight($schema))
			throw new RuntimeException('Pending migrations remain');
	}

	private function count(string $sql): ?int
	{
		$query = $this->database->query($sql);
		$value = $query === false ? false : $this->database->fetch1($query);
		return (is_int($value) || (is_string($value) && ctype_digit($value))) && (int)$value >= 0 ? (int)$value : null;
	}

	private function configuration(string $module, string $property): string
	{
		$query = $this->database->select('Cfg', 'Val', 'Module=? AND Prop=?', array($module, $property), '', 1);
		$value = $query === false ? false : $this->database->fetch1($query);
		return is_string($value) || is_int($value) ? (string)$value : '';
	}

	private function passes(callable $check): bool
	{
		try { $check(); return true; } catch (Throwable) { return false; }
	}

	private function runtimeRoutes(): array
	{
		$loader = static function (string $path): array {
			$_rwlinks = array();
			$_oncron = array();
			require $path;
			return array('rwlinks' => $_rwlinks, 'oncron' => $_oncron);
		};
		return $loader($this->projectRoot . '/module/_config.php');
	}
}
