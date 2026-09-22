<?php

declare(strict_types=1);

use HScript\Backup\BackupService;
use HScript\Backup\BackupSettings;
use HScript\Backup\DatabaseCredentials;
use HScript\Recovery\RecoveryException;
use HScript\Recovery\RecoveryPolicyMonitor;
use HScript\Recovery\RecoveryReportRepository;
use HScript\Recovery\RecoveryService;
use HScript\Recovery\RecoverySettings;

$root = dirname(__DIR__);
$domain = (string)(getenv('APP_DOMAIN') ?: 'localhost');
$_SERVER += array(
	'REQUEST_TIME_FLOAT' => microtime(true),
	'SERVER_NAME' => $domain,
	'HTTP_HOST' => $domain,
	'SCRIPT_NAME' => '/bin/recovery.php',
	'SERVER_PORT' => 80,
	'REQUEST_URI' => '/',
	'SERVER_ADDR' => '127.0.0.1',
	'REMOTE_ADDR' => '127.0.0.1',
);

$usage = static function (): void {
	fwrite(STDERR, "Usage:\n");
	fwrite(STDERR, "  php bin/recovery.php bundle --backup-result=/absolute/path/to/result.json\n");
	fwrite(STDERR, "  php bin/recovery.php drill [latest|bundle-id]\n");
	fwrite(STDERR, "  php bin/recovery.php drill-if-due\n");
	fwrite(STDERR, "  php bin/recovery.php policy\n");
	fwrite(STDERR, "  php bin/recovery.php status\n");
	fwrite(STDERR, "  php bin/recovery.php record-local-backup-failure\n");
};
$json = static function (array $data): void {
	echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
};
$options = static function (array $arguments): array {
	$result = array();
	foreach ($arguments as $argument)
	{
		if (!str_starts_with($argument, '--')) continue;
		$parts = explode('=', substr($argument, 2), 2);
		$result[$parts[0]] = $parts[1] ?? true;
	}
	return $result;
};

chdir($root);
require $root . '/vendor/autoload.php';
global $_cfg;
$_cfg = array();
if (is_file($root . '/_config.php')) require $root . '/_config.php';
if (is_file($root . '/_config.local.php')) require $root . '/_config.local.php';

try
{
	$command = strtolower((string)($argv[1] ?? ''));
	$options = $options(array_slice($argv, 2));
	$backupSettings = BackupSettings::fromEnvironment($root);
	$settings = RecoverySettings::fromEnvironment($root, $backupSettings->directory());
	$lockPath = $settings->stateDirectory() . '/recovery.lock';
	$lock = fopen($lockPath, 'c+b');
	if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB))
		throw new RecoveryException('recovery_already_running', 'Another recovery operation is already running');
	chmod($lockPath, 0600);
	try
	{
		$reports = new RecoveryReportRepository($settings);
		if ($command === 'record-local-backup-failure')
		{
			$json($reports->record('backup', '', time(), array('database_backup'), 'failed', 'local_backup_failed'));
			return;
		}
		if ($command === 'policy')
		{
			$result = (new RecoveryPolicyMonitor($settings, $reports))->evaluate();
			$json($result);
			if (($result['status'] ?? '') !== 'ok') exit(3);
			return;
		}
		if ($command === 'status')
		{
			$healthPath = $settings->stateDirectory() . '/reports/recovery-health.json';
			$json(array(
				'backup' => $reports->latest('backup'),
				'drill' => $reports->latest('drill'),
				'health' => is_file($healthPath) ? json_decode((string)file_get_contents($healthPath), true) : null,
			));
			return;
		}
		if (!hsHasDatabaseConfiguration($_cfg))
			throw new RecoveryException('database_not_configured', 'Application database configuration was not found');
		require $root . '/module/dbinit.php';
		$source = DatabaseCredentials::fromConfig($_cfg, $domain);
		$backups = BackupService::fromConfig($db, $_cfg, $domain, $root);
		$service = new RecoveryService($db, $source, $backups, $settings);
		switch ($command)
		{
		case 'bundle':
			$resultPath = (string)($options['backup-result'] ?? '');
			if (!str_starts_with($resultPath, '/') || !is_file($resultPath) || is_link($resultPath))
				throw new RecoveryException('database_backup_result_invalid', '--backup-result must reference a regular absolute file');
			$json($service->bundleFromBackupResult($resultPath));
			break;

		case 'drill':
			$json($service->drill((string)($argv[2] ?? 'latest')));
			break;

		case 'drill-if-due':
			$json($service->drillIfDue());
			break;

		default:
			$usage();
			exit(2);
		}
	}
	finally
	{
		flock($lock, LOCK_UN);
		fclose($lock);
	}
}
catch (Throwable $exception)
{
	$code = $exception instanceof RecoveryException ? $exception->errorCode() : 'recovery_failed';
	fwrite(STDERR, $code . ': ' . $exception->getMessage() . PHP_EOL);
	exit(1);
}
