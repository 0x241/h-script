<?php

declare(strict_types=1);

use HScript\Backup\BackupService;
use HScript\Backup\DatabaseCredentials;
use HScript\Backup\DatabaseRestoreService;

if (getenv('H_SCRIPT_BACKUP_DB_TEST') !== '1')
{
	echo "Backup database integration test skipped.\n";
	return;
}

$root = dirname(__DIR__);
$domain = (string)(getenv('APP_DOMAIN') ?: 'localhost');
$_SERVER += array(
	'SERVER_NAME' => $domain,
	'HTTP_HOST' => $domain,
	'SCRIPT_NAME' => '/tests/backup_database_integration.php',
	'SERVER_PORT' => 80,
	'REQUEST_URI' => '/',
	'SERVER_ADDR' => '127.0.0.1',
	'REMOTE_ADDR' => '127.0.0.1',
);

chdir($root);
require $root . '/vendor/autoload.php';
global $_cfg;
$_cfg = array();
if (is_file($root . '/_config.php')) require $root . '/_config.php';
if (is_file($root . '/_config.local.php')) require $root . '/_config.local.php';
require $root . '/module/dbinit.php';

$target = new DatabaseCredentials(
	(string)(getenv('H_SCRIPT_RESTORE_DB_HOST') ?: ''),
	(string)(getenv('H_SCRIPT_RESTORE_DB_NAME') ?: ''),
	(string)(getenv('H_SCRIPT_RESTORE_DB_USER') ?: ''),
	DatabaseCredentials::environmentValue('RESTORE_DB_PASSWORD')
);
$source = DatabaseCredentials::fromConfig($_cfg, $domain);
$service = BackupService::fromConfig($db, $_cfg, $domain, $root);
$backupId = '';

try
{
	$expectedRows = array('Cfg' => $db->count('Cfg'), 'Users' => $db->count('Users'));
	$manifest = $service->create('plain');
	$backupId = (string)$manifest['id'];
	$verified = $service->verify($backupId);
	if (($manifest['status'] ?? '') !== 'verified' || ($verified['id'] ?? '') !== $backupId)
		throw new RuntimeException('Created database backup was not verified');

	$restored = (new DatabaseRestoreService($service, $source))->restore(
		$backupId,
		$target,
		$target->database()
	);
	if (($restored['schema_version'] ?? '') !== ($manifest['schema_version'] ?? ''))
		throw new RuntimeException('Restored schema version differs from the verified backup');
	if (($restored['rows'] ?? array()) !== $expectedRows)
		throw new RuntimeException('Restored core table row counts differ from the source database');
	if (($restored['target_database_sha256'] ?? '') !== $target->databaseHash())
		throw new RuntimeException('Restore result identifies the wrong target database');

	echo "Backup database integration test passed.\n";
}
finally
{
	if ($backupId !== '')
	{
		try { $service->delete($backupId); }
		catch (Throwable) {}
	}
}
