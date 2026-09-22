<?php

declare(strict_types=1);

use HScript\Database\Connection;
use HScript\Update\MigrationLoader;
use HScript\Update\MigrationRunner;
use HScript\Update\SchemaStorageInstaller;
use HScript\Update\SchemaStateRepository;
use HScript\Update\UpdateLock;

if (getenv('H_SCRIPT_UPDATE_DB_TEST') !== '1')
{
	echo "Update migration database integration test skipped.\n";
	return;
}

$root = dirname(__DIR__);
$domain = (string)(getenv('APP_DOMAIN') ?: 'localhost');
$_SERVER += array(
	'SERVER_NAME' => $domain,
	'HTTP_HOST' => $domain,
	'SCRIPT_NAME' => '/tests/update_migration_integration.php',
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
require $root . '/module/dbinit.php';

$migrationId = '202609071200_phase1_probe';
$baseline = '1.0.0';
$target = '1.0.1';
$state = new SchemaStateRepository($db);
$current = $state->currentVersion();
$sourceApplication = $state->installedApplicationVersion();
if (!in_array($current, array($baseline, $target, HScript\Application::schemaVersion()), true))
	throw new RuntimeException('Integration test requires schema baseline 1.0.0');

$cleanup = static function () use ($db, $state, $migrationId, $baseline, $sourceApplication): void {
	$db->query('DROP TABLE IF EXISTS Phase1MigrationProbe');
	$db->delete('SchemaMigrations', 'smID=?', array($migrationId));
	$state->setCurrentVersion($baseline);
	if ($sourceApplication === null) $db->delete('Cfg', 'Module=? and Prop=?', array('Const', 'AppVersion'));
	else $state->setInstalledApplicationVersion($sourceApplication);
};

$cleanup();
try
{
	$db->delete('Cfg', 'Module=? and Prop=?', array('Const', 'AppVersion'));
	require $root . '/_dbstru.php';
	(new SchemaStorageInstaller($db))->install('1.0.1', $baseline, $_dbstru);
	if ($state->installedApplicationVersion() !== '1.0.1' || $state->currentVersion() !== $baseline)
		throw new RuntimeException('Explicit source CMS/schema bootstrap was not persisted');

	$secondDatabase = new Connection();
	if (!$secondDatabase->open($_cfg['db_host'], $_cfg['db_name'], $dbLogin, $dbPassword))
		throw new RuntimeException('Second test database connection could not be opened');
	$firstLock = new UpdateLock($db);
	$secondLock = new UpdateLock($secondDatabase);
	$firstLock->acquire();
	$parallelRejected = false;
	try { $secondLock->acquire(); }
	catch (RuntimeException) { $parallelRejected = true; }
	$firstLock->release();
	if (!$parallelRejected)
		throw new RuntimeException('Parallel update lock was accepted');
	$secondLock->acquire();
	$secondLock->release();
	$secondDatabase->close();

	$runner = new MigrationRunner(
		$db,
		$state,
		new MigrationLoader($root . '/tests/fixtures/update_migrations')
	);
	$backupRejected = false;
	try
	{
		$runner->run($target, false);
	}
	catch (RuntimeException $exception)
	{
		$backupRejected = str_contains($exception->getMessage(), 'verified backup');
	}
	if (!$backupRejected)
		throw new RuntimeException('Backup-required migration ran without backup approval');

	$applied = $runner->run($target, true);
	if ($applied !== array($migrationId) || $state->currentVersion() !== $target)
		throw new RuntimeException('Migration did not advance explicit schema state');
	if ($runner->run($target, true) !== array())
		throw new RuntimeException('Retry of completed migration was not idempotent');

	$row = $state->migration($migrationId);
	if (!$row || $row['smStatus'] !== 'applied' || !$row['smStartedAt'] || !$row['smFinishedAt'])
		throw new RuntimeException('Migration audit record is incomplete');
	$db->update('SchemaMigrations', array('smStatus' => 'running'), '', 'smID=?', array($migrationId));
	$incompleteStateRejected = false;
	try { $runner->preflight($target); }
	catch (RuntimeException $exception) { $incompleteStateRejected = str_contains($exception->getMessage(), 'incomplete after schema advanced'); }
	$db->update('SchemaMigrations', array('smStatus' => 'applied'), '', 'smID=?', array($migrationId));
	if (!$incompleteStateRejected)
		throw new RuntimeException('Inconsistent migration and schema state was accepted');
	$realChecksum = (string)$row['smChecksum'];
	$db->update('SchemaMigrations', array('smChecksum' => str_repeat('0', 64)), '', 'smID=?', array($migrationId));
	$checksumRejected = false;
	try
	{
		$runner->preflight($target);
	}
	catch (RuntimeException $exception)
	{
		$checksumRejected = str_contains($exception->getMessage(), 'Checksum changed');
	}
	$db->update('SchemaMigrations', array('smChecksum' => $realChecksum), '', 'smID=?', array($migrationId));
	if (!$checksumRejected)
		throw new RuntimeException('Changed checksum of known migration was accepted');

	echo "Update migration database integration test passed.\n";
}
finally
{
	$cleanup();
	$state->setCurrentVersion($current);
}
