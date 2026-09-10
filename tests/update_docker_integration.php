<?php

declare(strict_types=1);

use HScript\Database\Connection;
use HScript\Application;
use HScript\Update\SchemaUpdateGate;
use HScript\Update\SchemaStateRepository;
use HScript\Update\UpdateService;
use HScript\Update\UpdateSettings;
use HScript\Update\UpdateStatusService;

if (getenv('H_SCRIPT_UPDATE_SERVICE_TEST') !== '1')
{
	echo "Docker update integration test skipped.\n";
	return;
}

$root = dirname(__DIR__);
$domain = (string)(getenv('APP_DOMAIN') ?: 'localhost');
$_SERVER += array(
	'SERVER_NAME' => $domain, 'HTTP_HOST' => $domain, 'SCRIPT_NAME' => '/tests/update_docker_integration.php',
	'SERVER_PORT' => 80, 'REQUEST_URI' => '/', 'SERVER_ADDR' => '127.0.0.1', 'REMOTE_ADDR' => '127.0.0.1',
);
chdir($root);
require $root . '/vendor/autoload.php';
global $_cfg;
$_cfg = array();
if (is_file($root . '/_config.php')) require $root . '/_config.php';
if (is_file($root . '/_config.local.php')) require $root . '/_config.local.php';
require $root . '/module/dbinit.php';

function dockerUpdateRemove(string $path): void
{
	if (is_link($path) || is_file($path)) { unlink($path); return; }
	if (!is_dir($path)) return;
	foreach ((array)scandir($path) as $item)
		if ($item !== '.' && $item !== '..') dockerUpdateRemove($path . '/' . $item);
	rmdir($path);
}

$temporary = sys_get_temp_dir() . '/hscript-docker-update-' . bin2hex(random_bytes(8));
$backup = $temporary . '/backup';
mkdir($backup, 0700, true);
$schemaPath = $root . '/SCHEMA_VERSION';
$schemaBefore = (string)file_get_contents($schemaPath);
$migrationId = '202609081100_phase3_docker_probe';
$migrationPath = $root . '/migrations/versioned/' . $migrationId . '.php';
$customPath = $root . '/static/docker-custom-probe.txt';
$oldBackup = getenv('BACKUP_STORAGE_PATH');
$oldRelease = getenv('APP_RELEASE_VERSION');
$runId = '';

try
{
	$state = new SchemaStateRepository($db);
	$sourceSchema = $state->currentVersion();
	$sourceApplication = $state->installedApplicationVersion();
	if ($sourceSchema !== '1.0.0') throw new RuntimeException('Docker integration test requires schema baseline 1.0.0');
	$state->setInstalledApplicationVersion(Application::version());
	$migration = <<<'PHP'
<?php

use HScript\Database\Connection;

return array(
	'id' => '202609081100_phase3_docker_probe',
	'from' => '1.0.0',
	'to' => '1.0.1',
	'classification' => 'backup-required',
	'up' => static function (Connection $database): void {
		$database->query('CREATE TABLE IF NOT EXISTS Phase3DockerProbe (probeID int not null, PRIMARY KEY (probeID)) ENGINE=InnoDB');
	},
);
PHP;
	file_put_contents($migrationPath, $migration);
	file_put_contents($schemaPath, "1.0.1\n");
	file_put_contents($customPath, "custom code remains\n");
	putenv('BACKUP_STORAGE_PATH=' . $backup);
	putenv('APP_RELEASE_VERSION=1.0.3');
	$state->setInstalledApplicationVersion('0.9.0');
	$gate = (new SchemaUpdateGate($db, $root))->refresh();
	if (($gate['reason'] ?? '') !== 'unsupported_source_version' || !is_file($root . '/.cfg/schema-update-required.json')) throw new RuntimeException('Unsupported Docker source did not enable the traffic gate');
	$status = (new UpdateStatusService($db))->snapshot();
	if (($status['schema_gate']['reason'] ?? '') !== 'unsupported_source_version') throw new RuntimeException('Configurator status did not expose the Docker compatibility gate');
	$state->setInstalledApplicationVersion(Application::version());
	$gate = (new SchemaUpdateGate($db, $root))->refresh();
	if (($gate['reason'] ?? '') !== 'schema_update_required') throw new RuntimeException('Docker schema mismatch did not enable the migration gate');

	$service = new UpdateService(
		$db,
		$_cfg,
		$domain,
		$root,
		new UpdateSettings($root, $temporary . '/work', 10485760, 20971520, 300, 10485760, 2)
	);
	if ($service->deploymentMode() !== 'docker') throw new RuntimeException('Docker deployment mode was not detected');
	$packageRejected = false;
	try { $service->prepareManual($schemaPath); }
	catch (RuntimeException $exception) { $packageRejected = str_contains($exception->getMessage(), 'image tag'); }
	if (!$packageRejected) throw new RuntimeException('Docker Configurator accepted a code archive');

	$result = $service->applyBundled();
	$runId = (string)$result['run']['urID'];
	if ($result['run']['urState'] !== 'completed') throw new RuntimeException('Bundled Docker migration did not complete');
	if ($result['prepared']['source'] !== 'bundled' || $result['prepared']['backup_id'] === '') throw new RuntimeException('Bundled Docker update did not attach its verified backup');
	if ($state->currentVersion() !== '1.0.1') throw new RuntimeException('Bundled Docker migration did not advance schema');
	if ((string)file_get_contents($customPath) !== "custom code remains\n") throw new RuntimeException('Bundled Docker update changed application code');
	if (is_file($root . '/.cfg/schema-update-required.json')) throw new RuntimeException('Docker schema gate remained active after migration');
	$sqlBackups = glob($backup . '/*.sql');
	if (!is_array($sqlBackups) || count($sqlBackups) !== 1) throw new RuntimeException('Bundled Docker SQL backup is missing');

	echo "Docker update integration tests passed.\n";
}
finally
{
	if ($runId !== '') $db->delete('UpdateRuns', 'urID=?', array($runId));
	$db->delete('SchemaMigrations', 'smID=?', array($migrationId));
	$db->query('DROP TABLE IF EXISTS Phase3DockerProbe');
	if (isset($state, $sourceSchema)) $state->setCurrentVersion($sourceSchema);
	if (isset($state))
	{
		if ($sourceApplication === null) $db->delete('Cfg', 'Module=? and Prop=?', array('Const', 'AppVersion'));
		else $state->setInstalledApplicationVersion($sourceApplication);
	}
	file_put_contents($schemaPath, $schemaBefore);
	if (is_file($migrationPath)) unlink($migrationPath);
	if (is_file($customPath)) unlink($customPath);
	if (is_file($root . '/.cfg/maintenance.json')) unlink($root . '/.cfg/maintenance.json');
	if (is_file($root . '/.cfg/schema-update-required.json')) unlink($root . '/.cfg/schema-update-required.json');
	if ($oldBackup === false) putenv('BACKUP_STORAGE_PATH'); else putenv('BACKUP_STORAGE_PATH=' . $oldBackup);
	if ($oldRelease === false) putenv('APP_RELEASE_VERSION'); else putenv('APP_RELEASE_VERSION=' . $oldRelease);
	dockerUpdateRemove($temporary);
}
