<?php

declare(strict_types=1);

use HScript\Database\Connection;
use HScript\Security\ProductionPreflight;
use HScript\Update\RecoveryPreflight;
use HScript\Update\ReleaseManifest;
use HScript\Update\RuntimeEnvironment;
use HScript\Update\UpdateCompatibility;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/fixtures/update_contract.php';

function runtimeAssert(bool $condition, string $message): void
{
	if (!$condition) throw new RuntimeException($message);
}
function runtimeReject(callable $operation, string $message): void
{
	try { $operation(); } catch (RuntimeException) { return; }
	throw new RuntimeException($message);
}
function runtimeRemove(string $path): void
{
	if (is_file($path) || is_link($path)) { unlink($path); return; }
	foreach (scandir($path) as $entry)
		if ($entry !== '.' && $entry !== '..') runtimeRemove($path . '/' . $entry);
	rmdir($path);
}

$contract = UpdateCompatibility::fromRoot(dirname(__DIR__));
$measured = array('php' => '8.4.25', 'extensions' => $contract->environment()['extensions'],
	'database_engine' => 'mysql', 'database_version' => '8.4.6', 'redis_enabled' => false, 'redis_version' => null);
$contract->assertRuntime($measured);
foreach (array('10.11.12-MariaDB', '5.5.5-11.4.8-MariaDB-ubu2404') as $version)
{
	$parsed = RuntimeEnvironment::databaseVersion($version);
	$contract->assertRuntime(array_replace($measured, array('database_engine' => $parsed['engine'], 'database_version' => $parsed['version'])));
}
foreach (array(
	array('php' => '8.3.99'), array('php' => '8.5.0'), array('database_version' => '8.0.45'),
	array('database_engine' => 'mariadb', 'database_version' => '11.5.0'), array('extensions' => array()),
	array('redis_enabled' => true), array('redis_enabled' => true, 'redis_version' => '7.4.0'),
) as $change)
	runtimeReject(fn() => $contract->assertRuntime(array_replace($measured, $change)), 'Unsupported runtime accepted');
$contract->assertRuntime(array_replace($measured, array('redis_enabled' => true, 'redis_version' => '8.0.4')));
runtimeReject(fn() => RuntimeEnvironment::databaseVersion(false), 'Unavailable DB version accepted');
foreach (array('old_format', 'reversed_range', 'downgrade') as $case)
{
	$data = $contract->toArray();
	if ($case === 'old_format') $data['format'] = 1;
	if ($case === 'reversed_range') $data['environment']['php']['minimum'] = '8.6.0';
	if ($case === 'downgrade') $data['rollback']['automatic_downgrade'] = true;
	runtimeReject(fn() => UpdateCompatibility::fromArray($data), 'Invalid compatibility accepted');
}
$now = 1800000000;
$report = array('format' => 1, 'type' => 'backup', 'status' => 'success', 'bundle_id' => str_repeat('a', 32),
	'completed_at' => gmdate('Y-m-d\TH:i:s\Z', $now - 10), 'components' => array('database_backup', 'bundle_manifest', 'local_retention'));
RecoveryPreflight::assertReport($report, 'backup', 60, $report['components'], $now);
foreach (array(
	array(gmdate('Y-m-d\TH:i:s\Z', $now - 61), 'expired'),
	array(gmdate('Y-m-d\TH:i:s\Z', $now + 1), 'future'),
	array('2026-02-30T00:00:00Z', 'invalid'),
	array(null, 'invalid'),
) as [$timestamp, $reason]) {
	try { RecoveryPreflight::assertAge($timestamp, 60, $now, 'drill'); throw new LogicException('Invalid evidence accepted'); }
	catch (RuntimeException $error) {
		if (!str_starts_with($error->getMessage(), 'Recovery evidence ' . $reason . ': drill')) throw $error;
	}
}
foreach (array(array('status' => 'failed'), array('components' => array()), array('bundle_id' => '../bad'),
	array('components' => array(array('bundle_manifest'))), array('completed_at' => 'now'),
	array('completed_at' => gmdate('Y-m-d\TH:i:s\Z', $now - 61)), array('completed_at' => gmdate('Y-m-d\TH:i:s\Z', $now + 1))) as $change)
	runtimeReject(fn() => RecoveryPreflight::assertReport(array_replace($report, $change), 'backup', 60, $report['components'], $now), 'Invalid recovery report accepted');

final class RuntimePreflightDatabase extends Connection
{
	public string $mode = '';
	function query($query, $params = array()) { return $this->mode === 'sql-error' ? false : $query; }
	function fetch1($query = null) { return $query === 'SELECT VERSION()' ? '8.4.6' : 0; }
	function fetchRows($query = null, $key = null) { return $this->mode === 'processing' ? array(array('jState' => 1, 'total' => 1)) : array(); }
}

$root = sys_get_temp_dir() . '/hscript-runtime-' . bin2hex(random_bytes(8));
mkdir($root . '/module/_config', 0700, true);
foreach (array('.cfg', 'upload', 'backup') as $directory) mkdir($root . '/' . $directory, 0700);
file_put_contents($root . '/_config.php', '<?php');
chmod($root . '/_config.php', 0600);
file_put_contents($root . '/module/_config/pass', 'synthetic-fixture');
$previous = array();
foreach (array('APP_ENV', 'REDIS_ENABLED', 'APP_AUTO_INSTALL', 'BACKUP_STORAGE_PATH', 'UPDATE_WORK_PATH',
	'APP_DATA_KEY', 'APP_DATA_KEY_FILE', 'DB_USER', 'DB_PASSWORD', 'DB_PASSWORD_FILE', 'REQUIRE_TURNSTILE') as $name)
	$previous[$name] = getenv($name);
try
{
	foreach (array('APP_ENV=production', 'REDIS_ENABLED=0', 'APP_AUTO_INSTALL=0', 'REQUIRE_TURNSTILE=0',
		'APP_DATA_KEY=synthetic-runtime-test-key', 'DB_USER=fixture', 'DB_PASSWORD=synthetic-fixture',
		'APP_DATA_KEY_FILE', 'DB_PASSWORD_FILE', 'BACKUP_STORAGE_PATH=' . $root . '/backup', 'UPDATE_WORK_PATH=' . $root . '/.cfg/update') as $value) putenv($value);
	$manifest = ReleaseManifest::fromArray(array(
		'format' => 1, 'source' => 'bundled', 'classification' => 'code-only', 'activation_plan_sha256' => str_repeat('a', 64),
		'release' => array('application_version' => '1.0.4', 'schema_version' => '1.0.1', 'released_at' => '2026-09-14T00:00:00Z', 'summary' => 'Fixture', 'changes' => array('Fixture')),
		'compatibility' => $contract->toArray(), 'files' => array('managed' => array()),
		'artifact' => array('name' => 'fixture', 'sha256' => str_repeat('a', 64), 'sigstore_url' => ''),
	));
	$config = array('db_credentials_env' => 1, 'db_host' => 'unused.invalid', 'db_name' => 'fixture');
	$database = new RuntimePreflightDatabase();
	$preflight = new ProductionPreflight($root, true, true);
	$preflight->assertRelease($manifest, $database, $config, 'fixture.invalid');
	runtimeAssert(scandir($root . '/backup') === array('.', '..') && scandir($root . '/.cfg') === array('.', '..'), 'Code-only preflight created backup/recovery state');
	foreach (array('processing', 'sql-error') as $mode)
	{
		$database->mode = $mode;
		runtimeReject(fn() => $preflight->assertRelease($manifest, $database, $config, 'fixture.invalid'), 'Unsafe queue/database accepted');
	}
	$database->mode = '';
	putenv('APP_DATA_KEY');
	runtimeReject(fn() => $preflight->assertRelease($manifest, $database, $config, 'fixture.invalid'), 'Missing production key accepted');
}
finally
{
	foreach ($previous as $name => $value) putenv($value === false ? $name : $name . '=' . $value);
	runtimeRemove($root);
}
echo "Update runtime/preflight tests passed.\n";
