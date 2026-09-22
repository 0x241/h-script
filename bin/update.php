<?php

declare(strict_types=1);

use HScript\Application;
use HScript\Backup\DatabaseCredentials;
use HScript\Database\Connection;
use HScript\Cache\CatalogCache;
use HScript\Cache\RedisCache;
use HScript\Update\MigrationLoader;
use HScript\Update\MigrationRunner;
use HScript\Update\SchemaStateRepository;
use HScript\Update\SchemaStorageInstaller;
use HScript\Update\UpdateStatusService;
use HScript\Update\UpdateService;
use HScript\Update\UpdateHealthChecker;
use HScript\Update\UpdateCliContext;

$root = dirname(__DIR__);
$engineRoot = $root;
$domain = (string)(getenv('APP_DOMAIN') ?: 'localhost');
$_SERVER += array(
	'SERVER_NAME' => $domain,
	'HTTP_HOST' => $domain,
	'SCRIPT_NAME' => '/bin/update.php',
	'SERVER_PORT' => 80,
	'REQUEST_URI' => '/',
	'SERVER_ADDR' => '127.0.0.1',
	'REMOTE_ADDR' => '127.0.0.1',
);

chdir($root);
require $root . '/vendor/autoload.php';

$usage = static function (): void {
	fwrite(STDERR, "Usage:\n");
	fwrite(STDERR, "  Shared-hosting external verified engine: append --project-root=/absolute/installed-site\n");
	fwrite(STDERR, "  php bin/update.php status\n");
	fwrite(STDERR, "  php bin/update.php reconcile\n");
	fwrite(STDERR, "  php bin/update.php bootstrap --acknowledge-application=<version> --acknowledge-schema=<version>\n");
	fwrite(STDERR, "  php bin/update.php plan [target-schema-version]\n");
	fwrite(STDERR, "  php bin/update.php prepare --package=/absolute/h-script-<version>-shared-hosting.tar.gz\n");
	fwrite(STDERR, "  php bin/update.php prepare --github\n");
	fwrite(STDERR, "  php bin/update.php migrate-bundled\n");
	fwrite(STDERR, "  php bin/update.php apply <prepared-id> [--file=path:local|release ...]\n");
	fwrite(STDERR, "  php bin/update.php resume <run-id>\n");
	fwrite(STDERR, "  php bin/update.php cancel <run-id>\n");
	fwrite(STDERR, "  php bin/update.php rollback-code <run-id>\n");
};

$jsonOutput = static function (array $data): void {
	echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
};

$fileChoices = static function (array $arguments): array {
	$choices = array();
	foreach ($arguments as $argument)
	{
		if (!str_starts_with($argument, '--file='))
			throw new InvalidArgumentException('Unknown update option: ' . $argument);
		$value = substr($argument, strlen('--file='));
		$separator = strrpos($value, ':');
		if ($separator === false)
			throw new InvalidArgumentException('File choice must use --file=path:local|release');
		$path = substr($value, 0, $separator);
		$choice = substr($value, $separator + 1);
		if ($path === '' || isset($choices[$path]) || !in_array($choice, array('local', 'release'), true))
			throw new InvalidArgumentException('File conflict choice is invalid or duplicated');
		$choices[$path] = $choice;
	}
	return $choices;
};

try
{
	[$root, $argv, $externalEngine] = UpdateCliContext::resolve($argv, $engineRoot);
	chdir($root);
	$command = strtolower((string)($argv[1] ?? ''));
	global $_cfg;
	$_cfg = array();
	if (is_file($root . '/_config.php')) require $root . '/_config.php';
	if (is_file($root . '/_config.local.php')) require $root . '/_config.local.php';
	if (!hsHasDatabaseConfiguration($_cfg))
		throw new RuntimeException('Application database configuration was not found.');
	if ($command === 'reconcile' || $externalEngine)
	{
		// Web dbinit can render an error and exit(0); a release gate must instead
		// fail with a bounded JSON result and must not install mutation callbacks.
		$credentials = DatabaseCredentials::fromConfig($_cfg, $domain);
		$db = new Connection();
		if (!$db->open($credentials->connectionHost(), $credentials->database(), $credentials->username(), $credentials->password()))
			throw new RuntimeException('Reconciliation database is unavailable');
	}
	if ($command === 'reconcile')
	{
		if (count($argv) !== 2) throw new InvalidArgumentException('Reconcile does not accept options');
		if ($db->query('START TRANSACTION READ ONLY') === false) throw new RuntimeException('Read-only reconciliation could not start');
		try { $result = (new UpdateHealthChecker($db, $root))->reconcile(); }
		finally { $db->query('ROLLBACK'); }
		$jsonOutput($result);
		exit(0);
	}
	// Load the verified engine, not a possibly interrupted/old target bootstrap.
	if (!$externalEngine) require $engineRoot . '/module/dbinit.php';
	else
	{
		$catalogCache = new CatalogCache(RedisCache::fromEnvironment());
		$db->onMutation(static fn(string $table) => $catalogCache->invalidateTable($table));
	}
	if ($command === 'status')
	{
		echo json_encode(
			(new UpdateStatusService($db, $root))->snapshot(),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
		) . PHP_EOL;
		exit(0);
	}
	if ($command === 'bootstrap')
	{
		$acknowledged = array();
		foreach (array_slice($argv, 2) as $argument)
		{
			if (str_starts_with($argument, '--acknowledge-application=')) $name = 'application';
			elseif (str_starts_with($argument, '--acknowledge-schema=')) $name = 'schema';
			else throw new InvalidArgumentException('Unknown bootstrap option: ' . $argument);
			if (isset($acknowledged[$name])) throw new InvalidArgumentException('Bootstrap acknowledgement is duplicated: ' . $name);
			$acknowledged[$name] = substr($argument, strlen('--acknowledge-' . $name . '='));
		}
		if (!isset($acknowledged['application'], $acknowledged['schema']))
			throw new InvalidArgumentException('Explicit source application and schema versions are required');
		require $root . '/_dbstru.php';
		(new SchemaStorageInstaller($db))->install($acknowledged['application'], $acknowledged['schema'], $_dbstru);
		echo "Explicit lifecycle metadata initialized at CMS " . $acknowledged['application'] . " / schema " . $acknowledged['schema'] . ".\n";
		exit(0);
	}
	if ($command === 'plan')
	{
		$target = (string)($argv[2] ?? Application::schemaVersion());
		$runner = new MigrationRunner(
			$db,
			new SchemaStateRepository($db),
			new MigrationLoader($root . '/migrations/versioned')
		);
		$plan = array_map(
			static fn($migration): array => array(
				'id' => $migration->id(),
				'from' => $migration->fromVersion(),
				'to' => $migration->toVersion(),
				'classification' => $migration->classification(),
				'checksum' => $migration->checksum(),
			),
			$runner->preflight($target)
		);
		echo json_encode(array('target_schema_version' => $target, 'migrations' => $plan), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
		exit(0);
	}
	if (in_array($command, array('prepare', 'migrate-bundled', 'apply', 'resume', 'cancel', 'rollback-code'), true))
	{
		$service = new UpdateService($db, $_cfg, $domain, $root);
		if ($command === 'migrate-bundled')
		{
			$jsonOutput($service->applyBundled());
			exit(0);
		}
		if ($command === 'prepare')
		{
			if (count($argv) !== 3) throw new InvalidArgumentException('Prepare requires exactly one package source');
			$source = (string)($argv[2] ?? '');
			if ($source === '--github')
				$result = $service->prepareLatest();
			elseif (str_starts_with($source, '--package='))
				$result = $service->prepareManual(substr($source, strlen('--package=')));
			else
				throw new InvalidArgumentException('Use exactly --github or --package=/absolute/h-script-<version>-shared-hosting.tar.gz');
			if ($externalEngine && $result['manifest']['release']['application_version'] !== Application::version())
				throw new RuntimeException('External engine must come from the exact target release');
			$jsonOutput($result);
			exit(0);
		}
		$id = (string)($argv[2] ?? '');
		if ($id === '')
			throw new InvalidArgumentException('Prepared update or run ID is required');
		if ($externalEngine && in_array($command, array('apply', 'resume'), true))
		{
			$record = $command === 'apply' ? $service->prepared($id) : $service->preparedForRun($id);
			if ($record['manifest']['release']['application_version'] !== Application::version())
				throw new RuntimeException('External engine must come from the exact target release');
		}
		if ($command === 'apply')
			$result = $service->apply($id, $fileChoices(array_slice($argv, 3)));
		elseif ($command === 'resume')
		{
			if (count($argv) !== 3)
				throw new InvalidArgumentException('Resume does not accept additional options; saved file choices are reused');
			$result = $service->resume($id, array());
		}
		elseif ($command === 'cancel')
		{
			if (count($argv) !== 3)
				throw new InvalidArgumentException('Cancel does not accept additional options');
			$result = $service->cancel($id);
		}
		else
		{
			if (count($argv) !== 3)
				throw new InvalidArgumentException('Rollback-code does not accept additional options');
			$result = $service->rollbackCode($id);
		}
		$jsonOutput($result);
		exit(0);
	}
	$usage();
	exit(2);
}
catch (Throwable $exception)
{
	if (($command ?? '') === 'reconcile')
	{
		$result = array('format' => 1, 'checked_at' => gmdate('Y-m-d\TH:i:s\Z'), 'status' => 'not_ready', 'error_code' => 'reconciliation_failed');
		if ($exception instanceof \HScript\Update\UpdateHealthCheckFailed)
			$result['failed_checks'] = $exception->failedChecks();
		$jsonOutput($result);
		exit(1);
	}
	fwrite(STDERR, $exception->getMessage() . PHP_EOL);
	exit(1);
}
