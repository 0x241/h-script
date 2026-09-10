<?php

declare(strict_types=1);

use HScript\Application;
use HScript\Update\MaintenanceMode;
use HScript\Update\ReleaseManifest;
use HScript\Update\SchemaStateRepository;
use HScript\Update\SchemaUpdateGate;
use HScript\Update\UpdatePackageService;
use HScript\Update\UpdateRunRepository;
use HScript\Update\UpdateRunState;
use HScript\Update\UpdateService;
use HScript\Update\UpdateSettings;
use HScript\Update\UpdateStatusService;

if (getenv('H_SCRIPT_UPDATE_SERVICE_TEST') !== '1')
{
	echo "Docker code-only update integration test skipped.\n";
	return;
}

$root = dirname(__DIR__);
$domain = (string)(getenv('APP_DOMAIN') ?: 'localhost');
$_SERVER += array(
	'SERVER_NAME' => $domain, 'HTTP_HOST' => $domain, 'SCRIPT_NAME' => '/tests/update_docker_code_only_integration.php',
	'SERVER_PORT' => 80, 'REQUEST_URI' => '/', 'SERVER_ADDR' => '127.0.0.1', 'REMOTE_ADDR' => '127.0.0.1',
);
chdir($root);
require $root . '/vendor/autoload.php';
global $_cfg;
$_cfg = array();
if (is_file($root . '/_config.php')) require $root . '/_config.php';
if (is_file($root . '/_config.local.php')) require $root . '/_config.local.php';
require $root . '/module/dbinit.php';

function dockerCodeOnlyRemove(string $path): void
{
	if (is_link($path) || is_file($path)) { unlink($path); return; }
	if (!is_dir($path)) return;
	foreach ((array)scandir($path) as $item)
		if ($item !== '.' && $item !== '..') dockerCodeOnlyRemove($path . '/' . $item);
	rmdir($path);
}

$temporary = sys_get_temp_dir() . '/hscript-docker-code-only-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700, true);
$oldRelease = getenv('APP_RELEASE_VERSION');
$runIds = array();
$sourceApplicationKnown = false;

try
{
	$state = new SchemaStateRepository($db);
	$sourceApplication = $state->installedApplicationVersion();
	$sourceApplicationKnown = true;
	if ($state->currentVersion() !== Application::schemaVersion())
		throw new RuntimeException('Docker code-only integration test requires the current schema');
	$state->setInstalledApplicationVersion('1.0.1');
	putenv('APP_RELEASE_VERSION=' . Application::version());
	$gate = (new SchemaUpdateGate($db, $root))->refresh();
	if (($gate['reason'] ?? '') !== 'application_update_required')
		throw new RuntimeException('Code-only Docker update did not enable the traffic gate');
	$gateMode = fileperms($root . '/.cfg/schema-update-required.json');
	if ($gateMode === false || (($gateMode & 0004) === 0))
		throw new RuntimeException('Docker lifecycle marker is not readable by the web process');
	$status = (new UpdateStatusService($db))->snapshot();
	if (($status['schema_gate']['reason'] ?? '') !== 'application_update_required')
		throw new RuntimeException('Configurator status did not expose the code-only Docker gate');

	$service = new UpdateService(
		$db,
		$_cfg,
		$domain,
		$root,
		new UpdateSettings($root, $temporary . '/work', 10485760, 20971520, 300, 10485760, 2)
	);
	$result = $service->applyBundled();
	$runIds[] = (string)$result['run']['urID'];
	if ($result['run']['urState'] !== 'completed' || $result['prepared']['manifest']['classification'] !== 'code-only')
		throw new RuntimeException('Code-only Docker update did not complete through the common update run');
	if ($result['prepared']['backup_id'] !== '')
		throw new RuntimeException('Code-only Docker update unexpectedly created a database backup');
	if ($state->installedApplicationVersion() !== Application::version())
		throw new RuntimeException('Code-only Docker update did not record the healthy image version');
	if (is_file($root . '/.cfg/schema-update-required.json'))
		throw new RuntimeException('Code-only Docker traffic gate remained active after health check');

	$serviceSettings = new UpdateSettings($root, $temporary . '/rollback-work', 10485760, 20971520, 300, 10485760, 2);
	$packages = new UpdatePackageService($db, $serviceSettings);
	$prepared = $packages->prepareBundled();
	$manifest = ReleaseManifest::fromArray($prepared['manifest']);
	$runs = new UpdateRunRepository($db);
	$rolledBackRun = $runs->create($manifest, Application::version(), Application::schemaVersion());
	$runIds[] = (string)$rolledBackRun['urID'];
	$packages->attachRun($prepared['id'], (string)$rolledBackRun['urID']);
	$runs->transition((string)$rolledBackRun['urID'], UpdateRunState::PACKAGE, 'code_only', 'Code-only image activated');
	$runs->transition((string)$rolledBackRun['urID'], UpdateRunState::HEALTH, 'retry_health', 'Health check failed before image rollback');
	(new MaintenanceMode($root))->enable((string)$rolledBackRun['urID'], $manifest->applicationVersion());
	$rollbackService = new UpdateService($db, $_cfg, $domain, $root, $serviceSettings);
	$cancelled = $rollbackService->cancel((string)$rolledBackRun['urID']);
	if ($cancelled['urMessageCode'] !== 'docker_image_rolled_back' || is_file($root . '/.cfg/maintenance.json'))
		throw new RuntimeException('Safe external Docker image rollback was not recorded');

	echo "Docker code-only update integration tests passed.\n";
}
finally
{
	foreach ($runIds as $runId) $db->delete('UpdateRuns', 'urID=?', array($runId));
	if (isset($state) && $sourceApplicationKnown)
	{
		if ($sourceApplication === null) $db->delete('Cfg', 'Module=? and Prop=?', array('Const', 'AppVersion'));
		else $state->setInstalledApplicationVersion($sourceApplication);
	}
	if (is_file($root . '/.cfg/maintenance.json')) unlink($root . '/.cfg/maintenance.json');
	if (is_file($root . '/.cfg/schema-update-required.json')) unlink($root . '/.cfg/schema-update-required.json');
	if ($oldRelease === false) putenv('APP_RELEASE_VERSION'); else putenv('APP_RELEASE_VERSION=' . $oldRelease);
	dockerCodeOnlyRemove($temporary);
}
