<?php

declare(strict_types=1);

use HScript\Update\OfficialReleaseProvider;
use HScript\Update\ReleaseBaselineRepository;
use HScript\Update\SchemaStateRepository;
use HScript\Update\UpdateRunRepository;
use HScript\Update\UpdateService;
use HScript\Update\UpdateSettings;

if (getenv('H_SCRIPT_UPDATE_SERVICE_TEST') !== '1')
{
	echo "Update service integration test skipped.\n";
	return;
}

$root = dirname(__DIR__);
$domain = (string)(getenv('APP_DOMAIN') ?: 'localhost');
$_SERVER += array(
	'SERVER_NAME' => $domain, 'HTTP_HOST' => $domain, 'SCRIPT_NAME' => '/tests/update_service_integration.php',
	'SERVER_PORT' => 80, 'REQUEST_URI' => '/', 'SERVER_ADDR' => '127.0.0.1', 'REMOTE_ADDR' => '127.0.0.1',
);
chdir($root);
require $root . '/vendor/autoload.php';
global $_cfg;
$_cfg = array();
if (is_file($root . '/_config.php')) require $root . '/_config.php';
if (is_file($root . '/_config.local.php')) require $root . '/_config.local.php';
require $root . '/module/dbinit.php';

function updateServiceAssert(bool $condition, string $message): void
{
	if (!$condition) throw new RuntimeException($message);
}

function updateServiceRemove(string $path): void
{
	if (is_link($path) || is_file($path)) { unlink($path); return; }
	if (!is_dir($path)) return;
	foreach ((array)scandir($path) as $item)
		if ($item !== '.' && $item !== '..') updateServiceRemove($path . '/' . $item);
	rmdir($path);
}

final class ServiceReleaseProvider implements OfficialReleaseProvider
{
	private array $archives = array();
	public function add(string $version, string $path): void { $this->archives[$version] = $path; }
	public function latest(): array
	{
		$version = (string)array_key_last($this->archives);
		return $this->metadata($version);
	}
	public function byVersion(string $version): array { return $this->metadata($version); }
	public function downloadArchive(array $release, string $target, int $maximumBytes): void
	{
		if (!copy($this->archives[$release['version']], $target)) throw new RuntimeException('Test archive download failed');
	}
	private function metadata(string $version): array
	{
		$path = $this->archives[$version] ?? '';
		if (!is_file($path)) throw new RuntimeException('Unknown test release');
		return array(
			'version' => $version, 'tag' => 'v' . $version, 'released_at' => '2026-09-08T00:00:00Z',
			'summary' => 'Сквозной тест официального релиза.', 'changes' => array('Проверка файлов, бэкапа, миграций и health-check.'),
			'archive_name' => 'h-script-' . $version . '-shared-hosting.tar.gz',
			'archive_url' => 'https://github.com/0x241/h-script/releases/download/v' . $version . '/h-script-' . $version . '-shared-hosting.tar.gz',
			'archive_sha256' => (string)hash_file('sha256', $path), 'sigstore_url' => '',
		);
	}
}

function updateServiceArchive(string $directory, string $name, string $sourceVersion, string $version, string $sourceSchema, string $schema, array $files): string
{
	$tar = $directory . '/' . $name . '.tar';
	$phar = new PharData($tar);
	$releaseFiles = array(
		'VERSION' => $version . "\n",
		'SCHEMA_VERSION' => $schema . "\n",
		'resources/update-compatibility.json' => json_encode(array(
		'format' => 1,
		'application' => array('minimum' => $sourceVersion, 'maximum' => $version),
		'schema' => array('minimum' => $sourceSchema, 'maximum' => $sourceSchema),
	), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
	);
	if (!array_key_exists('module/_config.php', $files))
		$files['module/_config.php'] = (string)file_get_contents(dirname(__DIR__) . '/module/_config.php');
	$releaseFiles += $files;
	$baseline = array();
	foreach ($releaseFiles as $path => $contents)
	{
		$customizable = str_starts_with($path, 'tpl/themes/') || (str_starts_with($path, 'tpl/') && str_ends_with($path, '.twig'));
		$baseline[$path] = array('sha256' => hash('sha256', $contents), 'size' => strlen($contents), 'class' => $customizable ? 'customizable' : 'core_strict');
	}
	ksort($baseline, SORT_STRING);
	$releaseFiles['resources/release-baseline.json'] = json_encode(array('format' => 2, 'version' => $version, 'files' => $baseline), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
	foreach ($releaseFiles as $path => $contents) $phar->addFromString('h-script/' . $path, $contents);
	$phar->compress(Phar::GZ);
	unset($phar);
	unlink($tar);
	return $tar . '.gz';
}

$temporaryRoot = sys_get_temp_dir() . '/hscript-update-service-' . bin2hex(random_bytes(8));
$backupRoot = $temporaryRoot . '/backup';
if (!mkdir($backupRoot, 0700, true)) throw new RuntimeException('Integration test directory could not be created');
$oldBackupPath = getenv('BACKUP_STORAGE_PATH');
$oldReleaseVersion = getenv('APP_RELEASE_VERSION');
putenv('BACKUP_STORAGE_PATH=' . $backupRoot);
putenv('APP_RELEASE_VERSION');
$schemaFilePath = $root . '/SCHEMA_VERSION';
$schemaFileBefore = (string)file_get_contents($schemaFilePath);
$versionFilePath = $root . '/VERSION';
$versionFileBefore = (string)file_get_contents($versionFilePath);
$compatibilityPath = $root . '/resources/update-compatibility.json';
$compatibilityBefore = (string)file_get_contents($compatibilityPath);
$releaseBaselinePath = $root . '/resources/release-baseline.json';
$releaseBaselineBefore = is_file($releaseBaselinePath) ? (string)file_get_contents($releaseBaselinePath) : null;
$probePath = $root . '/static/update-service-probe.txt';
$routesPath = $root . '/module/_config.php';
$routesBefore = (string)file_get_contents($routesPath);
$migrationId = '202609081000_phase3_service_probe';
$migrationPath = $root . '/migrations/versioned/' . $migrationId . '.php';
$runIds = array();
$preparedIds = array();

try
{
	if (is_file($root . '/.cfg/maintenance.json')) throw new RuntimeException('Integration test requires maintenance mode to be inactive');
	$state = new SchemaStateRepository($db);
	$sourceSchema = $state->currentVersion();
	$sourceApplication = $state->installedApplicationVersion();
	if ($sourceSchema !== '1.0.0') throw new RuntimeException('Integration test requires schema baseline 1.0.0');
	$state->setInstalledApplicationVersion(trim((string)file_get_contents($versionFilePath)));
	$provider = new ServiceReleaseProvider();
	$settings = new UpdateSettings($root, $temporaryRoot . '/work', 10485760, 20971520, 300, 10485760, 5);
	$service = new UpdateService($db, $_cfg, $domain, $root, $settings, $provider);
	$initialVersion = trim((string)file_get_contents($versionFilePath));
	(new ReleaseBaselineRepository($settings))->publish($initialVersion, array(
		array('path' => 'VERSION', 'sha256' => hash_file('sha256', $versionFilePath), 'size' => filesize($versionFilePath), 'class' => 'core_strict'),
		array('path' => 'SCHEMA_VERSION', 'sha256' => hash_file('sha256', $schemaFilePath), 'size' => filesize($schemaFilePath), 'class' => 'core_strict'),
		array('path' => 'resources/update-compatibility.json', 'sha256' => hash_file('sha256', $root . '/resources/update-compatibility.json'), 'size' => filesize($root . '/resources/update-compatibility.json'), 'class' => 'core_strict'),
		array('path' => 'module/_config.php', 'sha256' => hash_file('sha256', $routesPath), 'size' => filesize($routesPath), 'class' => 'core_strict'),
	));

	$codeArchive = updateServiceArchive($temporaryRoot, 'code-only', $initialVersion, '1.0.3', $sourceSchema, $sourceSchema, array(
		'static/update-service-probe.txt' => "phase3-code-only\n",
	));
	$provider->add('1.0.3', $codeArchive);
	$codeOnly = $service->prepareManual($codeArchive);
	$preparedIds[] = $codeOnly['id'];
	$codeResult = $service->apply($codeOnly['id'], array());
	$runIds[] = $codeResult['run']['urID'];
	updateServiceAssert($codeResult['run']['urState'] === 'completed', 'Code-only update did not complete');
	updateServiceAssert($codeResult['prepared']['backup_id'] === '', 'Code-only update unexpectedly created a database backup');
	updateServiceAssert((string)file_get_contents($probePath) === "phase3-code-only\n", 'Code-only release file was not activated');
	updateServiceAssert(!is_file($root . '/.cfg/maintenance.json'), 'Maintenance mode remained active after code-only update');

	$brokenRoutes = "<?php\n\$_rwlinks = array();\n\$_oncron = array();\n";
	$healthArchive = updateServiceArchive($temporaryRoot, 'health-failure', '1.0.3', '1.0.4', $sourceSchema, $sourceSchema, array(
		'module/_config.php' => $brokenRoutes,
		'static/update-service-probe.txt' => "phase3-code-only\n",
	));
	$provider->add('1.0.4', $healthArchive);
	$healthFailure = $service->prepareManual($healthArchive);
	$preparedIds[] = $healthFailure['id'];
	$healthRoutePlan = array_values(array_filter($healthFailure['activation_plan'], static fn(array $entry): bool => $entry['path'] === 'module/_config.php'));
	updateServiceAssert(count($healthRoutePlan) === 1 && $healthRoutePlan[0]['action'] === 'install', 'Broken route file was not scheduled for activation');
	$healthRejected = false;
	$healthError = '';
	try { $service->apply($healthFailure['id'], array()); }
	catch (RuntimeException $exception) { $healthError = $exception->getMessage(); $healthRejected = str_contains($healthError, 'health check failed'); }
	updateServiceAssert((string)file_get_contents($routesPath) === $brokenRoutes, 'Broken release route contents were not activated before health check: ' . $healthError);
	updateServiceAssert($healthRejected, 'Broken release routes passed the post-update health check');
	$healthRecord = $service->prepared($healthFailure['id']);
	$healthRunId = (string)$healthRecord['run_id'];
	$runIds[] = $healthRunId;
	$healthRun = (new UpdateRunRepository($db))->get($healthRunId);
	updateServiceAssert($healthRun && $healthRun['urState'] === 'health' && $healthRun['urMessageCode'] === 'retry_health', 'Health failure was not recorded as resumable');
	updateServiceAssert(is_file($root . '/.cfg/maintenance.json'), 'Maintenance mode was disabled after health failure');
	$resumeReachedHealth = false;
	try { $service->resume($healthRunId, array()); }
	catch (RuntimeException $exception) { $resumeReachedHealth = str_contains($exception->getMessage(), 'health check failed'); }
	updateServiceAssert($resumeReachedHealth, 'Resumed update was rejected after target files were already activated');
	$rollback = $service->rollbackCode($healthRunId);
	updateServiceAssert($rollback['run']['urState'] === 'failed', 'Code rollback did not terminate the failed update');
	updateServiceAssert((string)file_get_contents($routesPath) === $routesBefore, 'Code rollback did not restore application routes');
	updateServiceAssert($state->installedApplicationVersion() === '1.0.3', 'Code rollback did not restore the recorded source CMS version');
	updateServiceAssert(!is_file($root . '/.cfg/maintenance.json'), 'Maintenance mode remained active after code rollback');

	$migrationContents = <<<'PHP'
<?php

use HScript\Database\Connection;

return array(
	'id' => '202609081000_phase3_service_probe',
	'from' => '1.0.0',
	'to' => '1.0.1',
	'classification' => 'backup-required',
	'up' => static function (Connection $database): void {
		$database->query('CREATE TABLE IF NOT EXISTS Phase3UpdateProbe (probeID int not null, PRIMARY KEY (probeID)) ENGINE=InnoDB');
	},
);
PHP;
	$cancelArchive = updateServiceArchive($temporaryRoot, 'cancel', '1.0.3', '1.0.4-cancel', $sourceSchema, '1.0.1', array(
		'migrations/versioned/' . $migrationId . '.php' => $migrationContents,
		'static/update-service-probe.txt' => "phase3-code-only\n",
	));
	$provider->add('1.0.4-cancel', $cancelArchive);
	$cancelPrepared = $service->prepareManual($cancelArchive);
	$preparedIds[] = $cancelPrepared['id'];
	$db->query('CREATE OR REPLACE VIEW Phase3BackupUnsupportedView AS SELECT 1 AS probeID');
	$backupRejected = false;
	try { $service->apply($cancelPrepared['id'], array()); }
	catch (RuntimeException $exception) { $backupRejected = str_contains($exception->getMessage(), 'unsupported database objects'); }
	updateServiceAssert($backupRejected, 'Backup incorrectly ignored a database view');
	$cancelRecord = $service->prepared($cancelPrepared['id']);
	$cancelRunId = (string)$cancelRecord['run_id'];
	$runIds[] = $cancelRunId;
	$cancelled = $service->cancel($cancelRunId);
	updateServiceAssert($cancelled['urState'] === 'failed' && $cancelled['urMessageCode'] === 'update_cancelled', 'Safe pre-activation cancellation failed');
	$db->query('DROP VIEW IF EXISTS Phase3BackupUnsupportedView');

	$databaseArchive = updateServiceArchive($temporaryRoot, 'database', '1.0.3', '1.0.5', $sourceSchema, '1.0.1', array(
		'migrations/versioned/' . $migrationId . '.php' => $migrationContents,
		'static/update-service-probe.txt' => "phase3-code-only\n",
	));
	$provider->add('1.0.5', $databaseArchive);
	$databasePrepared = $service->prepareManual($databaseArchive);
	$preparedIds[] = $databasePrepared['id'];
	updateServiceAssert($databasePrepared['manifest']['classification'] === 'backup-required', 'Migration classification was not derived from the bundled migration');
	$databaseResult = $service->apply($databasePrepared['id'], array());
	$runIds[] = $databaseResult['run']['urID'];
	updateServiceAssert($databaseResult['run']['urState'] === 'completed', 'Database update did not complete');
	updateServiceAssert($databaseResult['prepared']['backup_id'] !== '', 'Database update has no attached verified SQL backup');
	$sqlBackups = glob($backupRoot . '/*.sql');
	updateServiceAssert(is_array($sqlBackups) && count($sqlBackups) === 1, 'Update backup is not a plain SQL file');
	updateServiceAssert($state->currentVersion() === '1.0.1', 'Database migration did not advance schema version');
	updateServiceAssert((bool)$db->fetch1($db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Phase3UpdateProbe'")), 'Database migration probe table is missing');
	updateServiceAssert(!is_file($root . '/.cfg/maintenance.json'), 'Maintenance mode remained active after database update');

	echo "Update service integration tests passed.\n";
}
finally
{
	if (isset($service)) foreach ($preparedIds as $preparedId)
	{
		try { $record = $service->prepared($preparedId); if ($record['run_id'] !== '') $runIds[] = $record['run_id']; }
		catch (Throwable) {}
	}
	foreach (array_values(array_unique($runIds)) as $runId) $db->delete('UpdateRuns', 'urID=?', array($runId));
	$db->delete('SchemaMigrations', 'smID=?', array($migrationId));
	$db->query('DROP TABLE IF EXISTS Phase3UpdateProbe');
	$db->query('DROP VIEW IF EXISTS Phase3BackupUnsupportedView');
	if (isset($state) && isset($sourceSchema)) $state->setCurrentVersion($sourceSchema);
	if (isset($state))
	{
		if ($sourceApplication === null) $db->delete('Cfg', 'Module=? and Prop=?', array('Const', 'AppVersion'));
		else $state->setInstalledApplicationVersion($sourceApplication);
	}
	if (is_file($probePath)) unlink($probePath);
	if (is_file($migrationPath)) unlink($migrationPath);
	if ((string)file_get_contents($routesPath) !== $routesBefore) file_put_contents($routesPath, $routesBefore);
	file_put_contents($schemaFilePath, $schemaFileBefore);
	file_put_contents($versionFilePath, $versionFileBefore);
	file_put_contents($compatibilityPath, $compatibilityBefore);
	if ($releaseBaselineBefore === null)
	{
		if (is_file($releaseBaselinePath)) unlink($releaseBaselinePath);
	}
	else file_put_contents($releaseBaselinePath, $releaseBaselineBefore);
	if (is_file($root . '/.cfg/maintenance.json')) unlink($root . '/.cfg/maintenance.json');
	if ($oldBackupPath === false) putenv('BACKUP_STORAGE_PATH'); else putenv('BACKUP_STORAGE_PATH=' . $oldBackupPath);
	if ($oldReleaseVersion === false) putenv('APP_RELEASE_VERSION'); else putenv('APP_RELEASE_VERSION=' . $oldReleaseVersion);
	updateServiceRemove($temporaryRoot);
}
