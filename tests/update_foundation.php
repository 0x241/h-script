<?php

declare(strict_types=1);

use HScript\Application;
use HScript\Update\ConfiguratorRouteRegistry;
use HScript\Update\ReleaseManifest;
use HScript\Update\SchemaUpdateGate;
use HScript\Update\SchemaVersion;
use HScript\Update\UpdateRunState;

require dirname(__DIR__) . '/vendor/autoload.php';

function updateFoundationAssert(bool $condition, string $message): void
{
	if (!$condition)
		throw new RuntimeException($message);
}

function updateFoundationRejects(callable $callback, string $message): void
{
	try
	{
		$callback();
	}
	catch (Throwable)
	{
		return;
	}
	throw new RuntimeException($message);
}

$root = dirname(__DIR__);
updateFoundationAssert(Application::version() === trim((string)file_get_contents($root . '/VERSION')), 'Application version is not explicit');
updateFoundationAssert(Application::schemaVersion() === trim((string)file_get_contents($root . '/SCHEMA_VERSION')), 'Schema version is not explicit');
updateFoundationAssert(SchemaVersion::compare('1.0.1', '1.0.0') > 0, 'Semantic schema ordering failed');

$gateRoot = sys_get_temp_dir() . '/hscript-update-gate-' . bin2hex(random_bytes(8));
mkdir($gateRoot . '/.cfg', 0700, true);
try
{
	updateFoundationAssert(!SchemaUpdateGate::requiresTrafficGate($gateRoot), 'Missing marker enabled the traffic gate');
	foreach (array('schema_metadata_missing', 'application_metadata_missing') as $reason)
	{
		file_put_contents($gateRoot . '/.cfg/schema-update-required.json', json_encode(array('reason' => $reason), JSON_THROW_ON_ERROR));
		updateFoundationAssert(!SchemaUpdateGate::requiresTrafficGate($gateRoot), 'Lifecycle onboarding blocked public traffic: ' . $reason);
	}
	file_put_contents($gateRoot . '/.cfg/schema-update-required.json', json_encode(array('reason' => 'schema_update_required'), JSON_THROW_ON_ERROR));
	updateFoundationAssert(SchemaUpdateGate::requiresTrafficGate($gateRoot), 'Schema mismatch did not enable the traffic gate');
	file_put_contents($gateRoot . '/.cfg/schema-update-required.json', '{');
	updateFoundationAssert(SchemaUpdateGate::requiresTrafficGate($gateRoot), 'Invalid marker disabled the traffic gate');

	$gateReflection = new ReflectionClass(SchemaUpdateGate::class);
	$gateWriter = $gateReflection->newInstanceWithoutConstructor();
	$markerProperty = $gateReflection->getProperty('marker');
	$markerProperty->setValue($gateWriter, $gateRoot . '/.cfg/schema-update-required.json');
	$publishMethod = $gateReflection->getMethod('publish');
	$publishMethod->invoke($gateWriter, 'schema_metadata_missing', null, null);
	clearstatcache(true, $gateRoot . '/.cfg/schema-update-required.json');
	$markerMode = fileperms($gateRoot . '/.cfg/schema-update-required.json');
	updateFoundationAssert($markerMode !== false && (($markerMode & 0004) !== 0), 'Lifecycle marker is not readable by the web process');
}
finally
{
	if (is_file($gateRoot . '/.cfg/schema-update-required.json')) unlink($gateRoot . '/.cfg/schema-update-required.json');
	rmdir($gateRoot . '/.cfg');
	rmdir($gateRoot);
}

$manifest = ReleaseManifest::fromArray(array(
	'format' => 1,
	'source' => 'github',
	'activation_plan_sha256' => hash('sha256', '[]'),
	'compatibility' => array(
		'format' => 1,
		'application' => array('minimum' => '1.0.0', 'maximum' => '1.0.2'),
		'schema' => array('minimum' => '1.0.0', 'maximum' => '1.0.0'),
	),
	'release' => array(
		'application_version' => '1.0.3',
		'schema_version' => '1.0.0',
		'released_at' => '2026-09-08T00:00:00Z',
		'summary' => 'Официальный тестовый релиз.',
		'changes' => array('Проверка внутреннего описания релиза.'),
	),
	'classification' => 'code-only',
	'artifact' => array(
		'name' => 'h-script-1.0.3-shared-hosting.tar.gz',
		'sha256' => str_repeat('a', 64),
		'sigstore_url' => 'https://github.com/0x241/h-script/releases/download/v1.0.3/h-script-1.0.3-shared-hosting.tar.gz.sigstore.json',
	),
	'files' => array('managed' => array(
		array('path' => 'VERSION', 'sha256' => str_repeat('b', 64), 'source_sha256' => null, 'size' => 6, 'class' => 'core_strict'),
		array('path' => 'SCHEMA_VERSION', 'sha256' => str_repeat('c', 64), 'source_sha256' => null, 'size' => 6, 'class' => 'core_strict'),
	)),
));
updateFoundationAssert($manifest->applicationVersion() === '1.0.3', 'Release application version is invalid');
updateFoundationAssert($manifest->schemaVersion() === '1.0.0', 'Manifest schema version is invalid');
updateFoundationAssert($manifest->source() === 'github', 'Release source is invalid');
updateFoundationAssert($manifest->classification() === 'code-only', 'Release classification is invalid');

$unknownFieldManifest = $manifest->toArray();
$unknownFieldManifest['unexpected'] = true;
updateFoundationRejects(
	static fn() => ReleaseManifest::fromJson(json_encode($unknownFieldManifest, JSON_THROW_ON_ERROR)),
	'Unknown manifest field was accepted'
);

$twigManifest = $manifest->toArray();
$twigManifest['files']['managed'][] = array(
	'path' => 'tpl/example.twig',
	'sha256' => str_repeat('0', 64),
	'source_sha256' => str_repeat('1', 64),
	'size' => 0,
	'class' => 'core_strict',
);
updateFoundationRejects(
	static fn() => ReleaseManifest::fromJson(json_encode($twigManifest, JSON_THROW_ON_ERROR)),
	'Twig template was accepted as a strict core file'
);

$nonCanonicalManifest = $manifest->toArray();
$nonCanonicalManifest['files']['managed'][] = array(
	'path' => 'src//Probe.php',
	'sha256' => str_repeat('0', 64),
	'source_sha256' => null,
	'size' => 0,
	'class' => 'core_strict',
);
updateFoundationRejects(
	static fn() => ReleaseManifest::fromJson(json_encode($nonCanonicalManifest, JSON_THROW_ON_ERROR)),
	'Non-canonical manifest path was accepted'
);

$runtimeManifest = $manifest->toArray();
$runtimeManifest['files']['managed'][] = array(
	'path' => 'module/_config/pass',
	'sha256' => str_repeat('0', 64),
	'source_sha256' => null,
	'size' => 0,
	'class' => 'core_strict',
);
updateFoundationRejects(
	static fn() => ReleaseManifest::fromJson(json_encode($runtimeManifest, JSON_THROW_ON_ERROR)),
	'Configurator runtime secret was accepted as a managed release file'
);

$badSigstore = $manifest->toArray();
$badSigstore['artifact']['sigstore_url'] = 'https://example.com/fake.sigstore.json';
updateFoundationRejects(
	static fn() => ReleaseManifest::fromArray($badSigstore),
	'Foreign Sigstore URL was accepted'
);

$badCompatibility = $manifest->toArray();
$badCompatibility['compatibility']['application'] = array('minimum' => '1.0.2', 'maximum' => '1.0.0');
updateFoundationRejects(
	static fn() => ReleaseManifest::fromArray($badCompatibility),
	'Reversed source compatibility range was accepted'
);

$routes = ConfiguratorRouteRegistry::all();
updateFoundationAssert($routes['install']['access'] === ConfiguratorRouteRegistry::INITIAL_INSTALL, 'Install route classification is invalid');
updateFoundationAssert($routes['modules']['methods'] === array('GET'), 'Diagnostics route is not read-only');
updateFoundationAssert(
	$routes['update']['methods'] === array('GET', 'POST') && $routes['update']['mutates_server_state'] === true,
	'Phase 3 update route must allow protected mutations'
);
updateFoundationAssert(
	$routes['backup']['access'] === ConfiguratorRouteRegistry::AUTHENTICATED_MAINTENANCE
		&& $routes['backup']['methods'] === array('GET', 'POST')
		&& $routes['backup']['mutates_server_state'] === true,
	'Backup route must be authenticated maintenance'
);
updateFoundationAssert(
	$routes['security']['access'] === ConfiguratorRouteRegistry::AUTHENTICATED_MAINTENANCE
		&& $routes['security']['methods'] === array('GET', 'POST')
		&& $routes['security']['mutates_server_state'] === true,
	'Security route must be authenticated maintenance'
);

UpdateRunState::assertTransition(UpdateRunState::PREFLIGHT, UpdateRunState::BACKUP);
UpdateRunState::assertTransition(UpdateRunState::PACKAGE, UpdateRunState::HEALTH);
updateFoundationRejects(
	static fn() => UpdateRunState::assertTransition(UpdateRunState::COMPLETED, UpdateRunState::PREFLIGHT),
	'Terminal update run was reopened'
);

$legacyUpdater = (string)file_get_contents($root . '/module/_config/update.php');
updateFoundationAssert(!str_contains($legacyUpdater, 'RENAME TABLE'), 'Legacy table replacement is still active');
updateFoundationAssert(!str_contains($legacyUpdater, '_hs_new_'), 'Legacy replacement table is still active');
foreach (array('cfg_update_error_message', 'Обновления не найдены:', 'Установлена актуальная или более новая версия CMS.', 'cfg_update_run_message') as $localizedUpdateUi)
	updateFoundationAssert(str_contains($legacyUpdater, $localizedUpdateUi), 'Russian update status localization is missing: ' . $localizedUpdateUi);
$backupConfigurator = (string)file_get_contents($root . '/module/_config/backup.php');
foreach (array('location ^~ /backup/', 'Обычный сервер без Docker', '--target-host=database:3306') as $inlineOperatorGuide)
	updateFoundationAssert(str_contains($backupConfigurator, $inlineOperatorGuide), 'Inline backup operator guide is missing: ' . $inlineOperatorGuide);
updateFoundationAssert(!is_file($root . '/resources/nginx/hscript-backups.conf'), 'Obsolete standalone Nginx backup snippet is still shipped');
$securityConfigurator = (string)file_get_contents($root . '/module/_config/security.php');
foreach (array('securityOfficialBuild', 'Локальная сборка запущена без эталона релиза', 'Не создавайте новый эталон из текущих рабочих файлов') as $baselineDiagnostic)
	updateFoundationAssert(str_contains($securityConfigurator, $baselineDiagnostic), 'Integrity baseline diagnostic is missing: ' . $baselineDiagnostic);
$webInstaller = (string)file_get_contents($root . '/module/_config/install.php');
foreach (array('SHOW FULL TABLES', 'initial installation requires an empty database', 'ConfiguratorCsrf::consume') as $requiredSafety)
	updateFoundationAssert(str_contains($webInstaller, $requiredSafety), 'Web installer safety contract is missing: ' . $requiredSafety);
foreach (array('APP_INSTALL_FORCE', 'confirmDatabase', 'DROP TABLE', 'DROP VIEW') as $removedDestructivePath)
	updateFoundationAssert(!str_contains($webInstaller, $removedDestructivePath), 'Destructive web reinstall path is still active: ' . $removedDestructivePath);
$dockerInstaller = (string)file_get_contents($root . '/docker/runtime/install-db.php');
foreach (array('APP_INSTALL_FORCE', 'DROP TABLE', 'DROP VIEW') as $removedDestructivePath)
	updateFoundationAssert(!str_contains($dockerInstaller, $removedDestructivePath), 'Destructive Docker reinstall path is still active: ' . $removedDestructivePath);
$configuratorHeader = (string)file_get_contents($root . '/module/_config/_header.php');
updateFoundationAssert(!str_contains($configuratorHeader, "cfg_t('Установка', 'Install')"), 'Persistent installation section is still visible');
updateFoundationAssert(!str_contains($configuratorHeader, '?login&out'), 'Logout is still a state-changing GET action');
$configuratorIndex = (string)file_get_contents($root . '/module/_config/index.php');
foreach (array('assertAllowed', 'consumeRateLimit', 'mutates_server_state') as $securityGuard)
	updateFoundationAssert(str_contains($configuratorIndex, $securityGuard), 'Configurator security guard is missing: ' . $securityGuard);
foreach (array('login.php', 'pass.php', 'setup.php', 'install.php', 'backup.php', 'update.php', 'security.php') as $protectedController)
{
	$controller = (string)file_get_contents($root . '/module/_config/' . $protectedController);
	updateFoundationAssert(str_contains($controller, 'ConfiguratorCsrf::'), 'Configurator controller is missing CSRF protection: ' . $protectedController);
}
$connectionSetup = (string)file_get_contents($root . '/module/_config/setup.php');
foreach (array('SHOW FULL TABLES', "?install", "?modules") as $initialRouteSafety)
	updateFoundationAssert(str_contains($connectionSetup, $initialRouteSafety), 'Initial empty-database routing is missing: ' . $initialRouteSafety);
foreach (array('/docker-compose.yml', '/docker/env.example') as $environmentFile)
	updateFoundationAssert(!str_contains((string)file_get_contents($root . $environmentFile), 'APP_INSTALL_FORCE'), 'Removed force-install variable is still exposed: ' . $environmentFile);
$updateCli = (string)file_get_contents($root . '/bin/update.php');
foreach (array('--acknowledge-application=', '--acknowledge-schema=') as $explicitVersionOption)
	updateFoundationAssert(str_contains($updateCli, $explicitVersionOption), 'Explicit lifecycle bootstrap option is missing: ' . $explicitVersionOption);
updateFoundationAssert(!str_contains($updateCli, '--acknowledge-baseline='), 'Ambiguous schema-only lifecycle bootstrap is still accepted');

require $root . '/_dbstru.php';
foreach (array('SchemaState', 'SchemaMigrations', 'UpdateRuns') as $table)
	updateFoundationAssert(isset($_dbstru[$table]), 'Missing update table definition: ' . $table);

echo "Update foundation tests passed.\n";
