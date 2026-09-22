<?php
declare(strict_types=1);

use HScript\Backup\BackupService;
use HScript\Backup\BackupSettings;
use HScript\Backup\DatabaseCredentials;
use HScript\Database\Connection;
use HScript\Recovery\RecoveryService;
use HScript\Recovery\RecoverySettings;
use HScript\Recovery\RuntimeArchiveService;
use HScript\Security\ProductionPreflight;
use HScript\Update\ReleaseManifest;
use HScript\Update\RuntimeEnvironment;
use HScript\Update\UpdateCompatibility;
use HScript\Update\SchemaStateRepository;

if (getenv('H_SCRIPT_UPDATE_SERVICE_TEST') !== '1') throw new RuntimeException('Isolated test database opt-in required');
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$db = new Connection();
if (!$db->open(getenv('DB_HOST'), getenv('DB_NAME'), getenv('DB_USER'), getenv('DB_PASSWORD'))) throw new RuntimeException('Test DB unavailable');
$schemaState = new SchemaStateRepository($db);
$sourceApplication = $schemaState->installedApplicationVersion();
$temporary = sys_get_temp_dir() . '/hscript-production-preflight-' . bin2hex(random_bytes(8));
mkdir($temporary . '/runtime/upload', 0700, true);
mkdir($temporary . '/backups', 0700);
file_put_contents($temporary . '/runtime/upload/fixture.txt', 'synthetic-runtime');
file_put_contents($temporary . '/bounds.json', json_encode(array('Users' => array('min' => 1, 'max' => 100))));
$settings = array(
	'APP_ENV' => 'production', 'APP_AUTO_INSTALL' => '0', 'REQUIRE_TURNSTILE' => '0', 'RECOVERY_ENABLED' => '1',
	'REDIS_ENABLED' => '1', 'REDIS_HOST' => 'redis', 'REDIS_PORT' => '6379',
	'BACKUP_STORAGE_PATH' => $temporary . '/backups', 'RECOVERY_STATE_PATH' => $temporary . '/backups/recovery',
	'RECOVERY_RUNTIME_ROOT' => $temporary . '/runtime', 'RECOVERY_RUNTIME_PATHS' => 'upload',
	'RECOVERY_CMS_RPO_SECONDS' => '3600', 'RECOVERY_CMS_RTO_SECONDS' => '3600',
	'RECOVERY_DRILL_MAX_AGE_SECONDS' => '3600', 'RECOVERY_ROW_BOUNDS_FILE' => $temporary . '/bounds.json',
	'RECOVERY_DRILL_DB_HOST' => getenv('DB_HOST'), 'RECOVERY_DRILL_DB_ADMIN_USER' => 'phase4_drill_operator',
	'RECOVERY_DRILL_DB_ADMIN_PASSWORD' => 'synthetic-drill-password', 'RECOVERY_DRILL_DB_PREFIX' => 'phase4_drill',
);
$previous = array();
foreach ($settings as $name => $value) { $previous[$name] = getenv($name); putenv($name . '=' . $value); }
function productionIntegrationReject(callable $operation, string $message): void
{
	try { $operation(); } catch (RuntimeException) { return; }
	throw new RuntimeException($message);
}
$administrator = new Connection();
if (!$administrator->open(getenv('DB_HOST'), '', 'root', 'synthetic-isolated-root')) throw new RuntimeException('Isolated test administrator unavailable');
$drillAccount = "'phase4_drill_operator'@'%'";
try
{
	if ($administrator->query("CREATE USER " . $drillAccount . " IDENTIFIED BY 'synthetic-drill-password'") === false
		|| $administrator->query('GRANT ALL PRIVILEGES ON `phase4\\_drill\\_%`.* TO ' . $drillAccount) === false)
		throw new RuntimeException('Isolated limited drill account setup failed');
	$limited = new Connection();
	if ($limited->open(getenv('DB_HOST'), 'mysql', 'phase4_drill_operator', 'synthetic-drill-password'))
		throw new RuntimeException('Drill account unexpectedly has system database access');
	$runtime = RuntimeEnvironment::inspect($db);
	UpdateCompatibility::fromRoot($root)->assertRuntime($runtime);
	printf("Real Redis runtime contract passed: %s.\n", $runtime['redis_version']);
	$config = array('db_credentials_env' => 1, 'db_host' => getenv('DB_HOST'), 'db_name' => getenv('DB_NAME'));
	$manifest = ReleaseManifest::fromArray(array('format' => 1, 'source' => 'bundled', 'classification' => 'backup-required',
		'activation_plan_sha256' => str_repeat('a', 64), 'compatibility' => UpdateCompatibility::fromRoot($root)->toArray(),
		'release' => array('application_version' => '1.0.5', 'schema_version' => '1.0.2', 'released_at' => '2026-09-14T00:00:00Z', 'summary' => 'Synthetic fixture', 'changes' => array('Fixture')),
		'files' => array('managed' => array()), 'artifact' => array('name' => 'fixture', 'sha256' => str_repeat('a', 64), 'sigstore_url' => '')));
	$preflight = new ProductionPreflight($root, true, true);
	$assertReady = fn() => $preflight->assertRelease($manifest, $db, $config, 'fixture.invalid');
	productionIntegrationReject($assertReady, 'Missing DR reports accepted');
	$backupSettings = BackupSettings::fromEnvironment($root);
	$backups = BackupService::fromConfig($db, $config, 'fixture.invalid', $root);
	$recoverySettings = RecoverySettings::fromEnvironment($root, $backupSettings->directory());
	$recovery = new RecoveryService($db, DatabaseCredentials::fromConfig($config, 'fixture.invalid'), $backups, $recoverySettings);
	// A new image/updater may already be running while the DB still describes the source release.
	$schemaState->setInstalledApplicationVersion('1.0.3');
	$backup = $backups->create('plain');
	if ($backups->verify($backup['id'])['application_version'] !== '1.0.3') throw new RuntimeException('Backup recorded updater version instead of source application');
	file_put_contents($temporary . '/backup-result.json', json_encode($backup, JSON_THROW_ON_ERROR));
	$recovery->bundleFromBackupResult($temporary . '/backup-result.json');
	productionIntegrationReject($assertReady, 'Backup without a drill accepted');
	$manifestPath = $temporary . '/backups/' . $backup['id'] . '.manifest.json';
	$sourceManifest = file_get_contents($manifestPath);
	$recovery->drill();
	if (file_get_contents($manifestPath) !== $sourceManifest) throw new RuntimeException('Local drill changed source backup manifest');
	$assertReady();
	$path = $temporary . '/backups/recovery/reports/latest-drill.json';
	$reportBytes = file_get_contents($path);
	$report = json_decode($reportBytes, true, 32, JSON_THROW_ON_ERROR);
	foreach (array('status' => 'failed', 'completed_at' => '2000-01-01T00:00:00Z', 'duration_ms' => 3600001) as $key => $value)
	{
		file_put_contents($path, json_encode(array_replace($report, array($key => $value)), JSON_THROW_ON_ERROR));
		productionIntegrationReject($assertReady, 'Invalid drill report accepted: ' . $key);
	}
	file_put_contents($path, $reportBytes);
	$bundle = (new HScript\Recovery\RecoveryBundleRepository($recoverySettings))->find($backup['id']);
	$runtimePath = $temporary . '/backups/' . $bundle->file('runtime_archive')['path'];
	rename($runtimePath, $runtimePath . '.saved');
	productionIntegrationReject($assertReady, 'Missing local runtime archive accepted');
	productionIntegrationReject(fn() => $recovery->drill(), 'Partial local bundle accepted for restore');
	rename($runtimePath . '.saved', $runtimePath);
	$recovery->drill();
	putenv('REDIS_PORT=1');
	productionIntegrationReject($assertReady, 'Unavailable enabled Redis accepted');
	putenv('REDIS_PORT=6379');
	$assertReady();
	if ((int)$db->fetch1($db->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE 'phase4_drill_%'")) !== 0)
		throw new RuntimeException('Drill database was not cleaned up');
	echo "Production schema preflight with real local SQL/runtime restore drill and Redis passed.\n";
}
finally
{
	$administrator->query('DROP USER IF EXISTS ' . $drillAccount);
	$administrator->close();
	$schemaState->setInstalledApplicationVersion($sourceApplication);
	foreach ($previous as $name => $value) putenv($value === false ? $name : $name . '=' . $value);
	RuntimeArchiveService::removeTree($temporary);
	$db->close();
}
