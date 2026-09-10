<?php

declare(strict_types=1);

use HScript\Backup\BackupService;
use HScript\Backup\DatabaseCredentials;
use HScript\Backup\DatabaseRestoreService;

$root = dirname(__DIR__);
$domain = (string)(getenv('APP_DOMAIN') ?: 'localhost');
$_SERVER += array(
	'SERVER_NAME' => $domain,
	'HTTP_HOST' => $domain,
	'SCRIPT_NAME' => '/bin/backup.php',
	'SERVER_PORT' => 80,
	'REQUEST_URI' => '/',
	'SERVER_ADDR' => '127.0.0.1',
	'REMOTE_ADDR' => '127.0.0.1',
);

chdir($root);
require $root . '/vendor/autoload.php';
global $_cfg;
$_cfg = array();
if (is_file($root . '/_config.php'))
	require $root . '/_config.php';
if (is_file($root . '/_config.local.php'))
	require $root . '/_config.local.php';
if (!hsHasDatabaseConfiguration($_cfg))
{
	fwrite(STDERR, "Application database configuration was not found.\n");
	exit(1);
}
require $root . '/module/dbinit.php';

$usage = static function (): void {
	fwrite(STDERR, "Usage:\n");
	fwrite(STDERR, "  php bin/backup.php create\n");
	fwrite(STDERR, "  php bin/backup.php list\n");
	fwrite(STDERR, "  php bin/backup.php verify <backup-id>\n");
	fwrite(STDERR, "  php bin/backup.php delete <backup-id>\n");
	fwrite(STDERR, "  RESTORE_DB_PASSWORD=... php bin/backup.php restore <backup-id> \\\n");
	fwrite(STDERR, "    --target-host=<host[:port]> --target-database=<name> --target-user=<user> \\\n");
	fwrite(STDERR, "    --confirm-target=<name> [--allow-non-empty-target] [--allow-current-database]\n");
};

$json = static function (array $data): void {
	echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
};
$options = static function (array $arguments): array {
	$result = array();
	foreach ($arguments as $argument)
	{
		if (!str_starts_with($argument, '--'))
			continue;
		$parts = explode('=', substr($argument, 2), 2);
		$result[$parts[0]] = $parts[1] ?? true;
	}
	return $result;
};

try
{
	$service = BackupService::fromConfig($db, $_cfg, $domain, $root);
	$command = strtolower((string)($argv[1] ?? ''));
	switch ($command)
	{
	case 'create':
		$json($service->create('plain'));
		break;

	case 'list':
		$json(array('backups' => $service->list()));
		break;

	case 'verify':
		$json($service->verify((string)($argv[2] ?? '')));
		break;

	case 'delete':
		$id = (string)($argv[2] ?? '');
		$service->delete($id);
		$json(array('deleted' => true, 'id' => $id));
		break;

	case 'restore':
		$id = (string)($argv[2] ?? '');
		$values = $options(array_slice($argv, 3));
		foreach (array('target-host', 'target-database', 'target-user', 'confirm-target') as $required)
			if (!isset($values[$required]) || !is_string($values[$required]) || trim($values[$required]) === '')
				throw new InvalidArgumentException('--' . $required . ' is required');
		$passwordFile = getenv('RESTORE_DB_PASSWORD_FILE');
		$passwordValue = getenv('RESTORE_DB_PASSWORD');
		if ($passwordValue === false && ($passwordFile === false || $passwordFile === ''))
			throw new InvalidArgumentException('RESTORE_DB_PASSWORD or RESTORE_DB_PASSWORD_FILE is required');
		$target = new DatabaseCredentials(
			$values['target-host'],
			$values['target-database'],
			$values['target-user'],
			DatabaseCredentials::environmentValue('RESTORE_DB_PASSWORD')
		);
		$source = DatabaseCredentials::fromConfig($_cfg, $domain);
		$json((new DatabaseRestoreService($service, $source))->restore(
			$id,
			$target,
			$values['confirm-target'],
			isset($values['allow-non-empty-target']),
			isset($values['allow-current-database'])
		));
		break;

	default:
		$usage();
		exit(2);
	}
}
catch (Throwable $exception)
{
	fwrite(STDERR, $exception->getMessage() . PHP_EOL);
	exit(1);
}
