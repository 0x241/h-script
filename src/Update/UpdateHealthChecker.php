<?php

namespace HScript\Update;

use HScript\Database\Connection;
use HScript\Telemetry\CollectorMode;
use RuntimeException;

final class UpdateHealthChecker
{
	private Connection $database;
	private string $projectRoot;
	private array $config;
	private string $domain;

	public function __construct(Connection $database, string $projectRoot, array $config, string $domain)
	{
		$this->database = $database;
		$this->projectRoot = rtrim($projectRoot, '/');
		$this->config = $config;
		$this->domain = $domain;
	}

	public function check(ReleaseManifest $manifest): array
	{
		$checks = array();
		$checks['bootstrap'] = is_file($this->projectRoot . '/vendor/autoload.php')
			&& is_readable($this->projectRoot . '/VERSION');
		$checks['database'] = (int)$this->database->fetch1($this->database->query('SELECT 1')) === 1;
		$checks['application_version'] = trim((string)file_get_contents($this->projectRoot . '/VERSION')) === $manifest->applicationVersion();
		$checks['schema_version'] = (new SchemaStateRepository($this->database))->currentVersion() === $manifest->schemaVersion();
		$routes = $this->runtimeRoutes();
		$checks['admin_route'] = isset($routes['rwlinks']['admin'])
			&& ($routes['rwlinks']['admin'][0] ?? null) === 'admin'
			&& is_file($this->projectRoot . '/module/admin/onstart.php');
		$checks['cron'] = is_file($this->projectRoot . '/module/cron/index.php')
			&& isset($routes['oncron']['telemetry']);
		$demo = is_file($this->projectRoot . '/tpl_c/demo')
			|| in_array(strtolower(trim((string)($this->config['demo_mode'] ?? '0'))), array('1', 'true', 'yes', 'on'), true);
		$checks['telemetry_isolation'] = !$demo || !CollectorMode::enabled($this->config, $this->domain);
		$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
		if ($failed)
			throw new RuntimeException('Post-update health check failed: ' . implode(', ', $failed));
		return $checks;
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
