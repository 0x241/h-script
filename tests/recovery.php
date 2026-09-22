<?php

declare(strict_types=1);

use HScript\Backup\BackupManifest;
use HScript\Recovery\RecoveryBundleManifest;
use HScript\Recovery\RecoveryBundleRepository;
use HScript\Recovery\RecoveryException;
use HScript\Recovery\RecoveryPolicyMonitor;
use HScript\Recovery\RecoveryReportRepository;
use HScript\Recovery\RecoverySettings;
use HScript\Recovery\RuntimeArchiveService;

require dirname(__DIR__) . '/vendor/autoload.php';

function recoveryAssert(bool $condition, string $message): void
{
	if (!$condition) throw new RuntimeException($message);
}

function recoveryRejects(callable $callback, string $expectedCode, string $message): void
{
	try
	{
		$callback();
	}
	catch (RecoveryException $exception)
	{
		recoveryAssert($exception->errorCode() === $expectedCode, $message . ': ' . $exception->errorCode());
		return;
	}
	catch (Throwable)
	{
		return;
	}
	throw new RuntimeException($message);
}

function recoverySettings(
	string $root,
	string $backup,
	string $state,
	array $paths,
	bool $protected = false,
	string $alertCommand = ''
): RecoverySettings {
	$tar = is_executable('/bin/tar') ? '/bin/tar' : '/usr/bin/tar';
	return new RecoverySettings(
		$root, $backup, $state, $root, $paths, $protected, 100, 1048576,
		$tar, 2, 2, '', $alertCommand
	);
}

$base = sys_get_temp_dir() . '/hscript-recovery-test-' . bin2hex(random_bytes(8));
mkdir($base, 0700);

try
{
	$root = $base . '/root';
	$backup = $base . '/backups';
	mkdir($root . '/upload/nested', 0700, true);
	mkdir($root . '/tpl/themes/custom', 0700, true);
	mkdir($root . '/logs', 0700, true);
	mkdir($backup, 0700);
	file_put_contents($root . '/upload/avatar.txt', 'avatar');
	file_put_contents($root . '/upload/nested/document.txt', 'document');
	file_put_contents($root . '/tpl/themes/custom/theme.twig', '<main>custom</main>');
	file_put_contents($root . '/logs/error.log', 'must-not-enter-backup');

	$settings = recoverySettings($root, $backup, $backup . '/recovery', array('upload', 'tpl/themes'));
	$runtime = new RuntimeArchiveService($settings);
	$id = str_repeat('a', 32);
	$result = $runtime->create($id);
	recoveryAssert($result['manifest']['file_count'] === 3, 'Runtime file inventory is incomplete');
	recoveryAssert(!str_contains(json_encode($result['manifest']), 'error.log'), 'Logs entered the runtime archive');
	recoveryAssert($runtime->verify($result['archive_path'], $result['manifest_path'])['file_checksums'], 'Runtime checksums were not verified');

	$corrupt = $base . '/corrupt-runtime.tar.gz';
	copy($result['archive_path'], $corrupt);
	file_put_contents($corrupt, 'corrupt', FILE_APPEND);
	recoveryRejects(
		static fn() => $runtime->verify($corrupt, $result['manifest_path']),
		'runtime_archive_corrupt',
		'Corrupted runtime archive was accepted'
	);

	$symlinkRoot = $base . '/symlink-root';
	mkdir($symlinkRoot . '/upload', 0700, true);
	mkdir($symlinkRoot . '/tpl/themes', 0700, true);
	symlink($root . '/logs/error.log', $symlinkRoot . '/upload/leak');
	$symlinkSettings = recoverySettings($symlinkRoot, $backup, $backup . '/symlink-state', array('upload', 'tpl/themes'));
	recoveryRejects(
		static fn() => (new RuntimeArchiveService($symlinkSettings))->create(str_repeat('b', 32)),
		'runtime_symlink_rejected',
		'Runtime symlink was accepted'
	);

	$missingRoot = $base . '/missing-root';
	mkdir($missingRoot . '/upload', 0700, true);
	$missingSettings = recoverySettings($missingRoot, $backup, $backup . '/missing-state', array('upload', 'tpl/themes'));
	recoveryRejects(
		static fn() => (new RuntimeArchiveService($missingSettings))->create(str_repeat('c', 32)),
		'runtime_path_missing',
		'Incomplete runtime allowlist was accepted'
	);

	$protectedRejected = false;
	try { recoverySettings($root, $backup, $backup . '/protected-state', array('_config.php')); }
	catch (Throwable) { $protectedRejected = true; }
	recoveryAssert($protectedRejected, 'Protected configuration did not require explicit opt-in');
	$forbiddenRejected = false;
	try { recoverySettings($root, $backup, $backup . '/forbidden-state', array('logs')); }
	catch (Throwable) { $forbiddenRejected = true; }
	recoveryAssert($forbiddenRejected, 'Forbidden runtime path was accepted');

	$databaseArchive = $backup . '/h-script-20260911-000000-' . $id . '.sql';
	$databaseManifestPath = $backup . '/' . $id . '.manifest.json';
	file_put_contents($databaseArchive, 'verified database archive');
	file_put_contents($databaseManifestPath, '{}');
	$databaseManifest = BackupManifest::fromArray(array(
		'format' => 1,
		'id' => $id,
		'created_at' => '2026-09-11T00:00:00Z',
		'verified_at' => '2026-09-11T00:00:01Z',
		'database_sha256' => str_repeat('1', 64),
		'application_version' => '1.0.4',
		'schema_version' => '1.0.1',
		'archive' => basename($databaseArchive),
		'compression' => 'plain',
		'adapter' => 'php-stream',
		'location' => 'external',
		'uncompressed_size' => filesize($databaseArchive),
		'stored_size' => filesize($databaseArchive),
		'sha256' => hash_file('sha256', $databaseArchive),
		'table_count' => 2,
		'tables' => array('Cfg', 'Users'),
		'status' => 'verified',
		'verification' => array(
			'archive_readable' => true,
			'checksum_match' => true,
			'core_tables_present' => true,
			'footer_present' => true,
			'table_count_match' => true,
		),
	));
	$bundle = RecoveryBundleManifest::create(
		$databaseManifest,
		$databaseArchive,
		$databaseManifestPath,
		$result,
		$backup
	);
	$repository = new RecoveryBundleRepository($settings);
	$repository->publish($bundle);
	$bundle->verifyFiles($backup);
	$partial = $base . '/partial';
	mkdir($partial, 0700);
	foreach ($bundle->toArray()['files'] as $name => $file)
	{
		if ($name === 'runtime_archive') continue;
		$target = $partial . '/' . $file['path'];
		if (!is_dir(dirname($target))) mkdir(dirname($target), 0700, true);
		copy($backup . '/' . $file['path'], $target);
	}
	recoveryRejects(
		static fn() => $bundle->verifyFiles($partial),
		'bundle_incomplete',
		'Partial local recovery bundle was accepted'
	);

	$falseBinary = is_executable('/bin/false') ? '/bin/false' : '/usr/bin/false';
	recoveryAssert($repository->latestId() === $id, 'Latest local bundle not found');
	$staged = $base . '/staged';
	mkdir($staged, 0700);
	$repository->stage($id, $staged);
	$repository->find($id, $staged)->verifyFiles($staged);
	recoveryAssert(file_get_contents($databaseManifestPath) === '{}', 'Staging changed source manifest');
	$expired = str_repeat('d', 32);
	mkdir($backup . '/recovery/bundles/' . $expired, 0700);
	file_put_contents($backup . '/recovery/bundles/' . $expired . '/orphan', 'orphan');
	recoveryAssert($repository->pruneLocal(array($id), $id) === array($expired), 'Orphan local bundle not pruned');
	recoveryAssert(is_dir($backup . '/recovery/bundles/' . $id), 'Current local bundle was pruned');
	$newer = str_repeat('e', 32);
	$newerPath = $backup . '/recovery/bundles/' . $newer . '/' . $newer . '.bundle.json';
	mkdir(dirname($newerPath), 0700);
	// A renamed manifest must not redirect "latest" to a different, older bundle.
	file_put_contents($newerPath, json_encode($bundle->toArray(), JSON_THROW_ON_ERROR));
	touch($newerPath, time() + 2);
	recoveryRejects(static fn() => $repository->latestId(), 'bundle_manifest_invalid', 'Mismatched latest bundle ID was accepted');
	file_put_contents($newerPath, '{');
	recoveryRejects(static fn() => $repository->latestId(), 'bundle_manifest_invalid', 'Corrupt latest bundle silently fell back');
	unlink($newerPath);
	rmdir(dirname($newerPath));

	foreach (array('CMS', 'COLLECTOR', 'SERVICE_TOKENS') as $target)
	{
		putenv('RECOVERY_' . $target . '_RPO_SECONDS=60');
		putenv('RECOVERY_' . $target . '_RTO_SECONDS=60');
	}
	putenv('RECOVERY_DRILL_MAX_AGE_SECONDS=60');
	$reports = new RecoveryReportRepository($settings);
	$reports->record('backup', $id, time(), array(
		'database_backup', 'runtime_archive', 'bundle_manifest', 'local_retention',
	), 'success');
	$reports->record('drill', $id, time(), array(
		'local_bundle', 'database_restore', 'runtime_checksums', 'financial_invariants',
	), 'success');
	$health = (new RecoveryPolicyMonitor($settings, $reports))->evaluate();
	recoveryAssert($health['status'] === 'ok', 'Fresh recovery evidence breached policy');

	$alertSettings = recoverySettings($root, $backup, $backup . '/alert-state', array('upload'));
	$alertReports = new RecoveryReportRepository($alertSettings);
	$alertHealth = (new RecoveryPolicyMonitor($alertSettings, $alertReports))->evaluate();
	recoveryAssert($alertHealth['status'] === 'alert', 'Missing recovery evidence did not create an alert');
	$recoveryAlertFiles = glob($alertSettings->stateDirectory() . '/reports/alerts/*.json') ?: array();
	recoveryAssert(count($recoveryAlertFiles) === 1, 'Recovery alert evidence was not stored');
	$recoveryAlert = json_decode((string)file_get_contents($recoveryAlertFiles[0]), true, 16, JSON_THROW_ON_ERROR);
	recoveryAssert(($recoveryAlert['runbook'] ?? '') === 'docs/observability.md#recovery-policy-breached', 'Recovery alert has no runbook');
	recoveryAssert(preg_match('/^[a-f0-9]{32}$/', (string)($recoveryAlert['correlation_id'] ?? '')) === 1, 'Recovery alert has no correlation ID');

	$notifierSettings = recoverySettings(
		$root, $backup, $backup . '/notifier-state', array('upload'), false,
		$falseBinary
	);
	$notifierReports = new RecoveryReportRepository($notifierSettings);
	$notifierHealth = (new RecoveryPolicyMonitor($notifierSettings, $notifierReports))->evaluate();
	recoveryAssert($notifierHealth['status'] === 'alert', 'Notifier failure suppressed recovery policy state');
	recoveryAssert(count(glob($notifierSettings->stateDirectory() . '/reports/alerts/*.json') ?: array()) === 1, 'Notifier failure removed local alert evidence');

	$hangingNotifier = $base . '/hanging-notifier';
	file_put_contents($hangingNotifier, "#!/bin/sh\nexec sleep 30\n");
	chmod($hangingNotifier, 0700);
	$hangingSettings = recoverySettings(
		$root, $backup, $backup . '/hanging-notifier-state', array('upload'), false,
		$hangingNotifier
	);
	$started = microtime(true);
	$hangingHealth = (new RecoveryPolicyMonitor($hangingSettings, new RecoveryReportRepository($hangingSettings)))->evaluate();
	$elapsed = microtime(true) - $started;
	recoveryAssert($elapsed >= 4.5 && $elapsed < 10, 'Hanging notifier was not bounded');
	recoveryAssert($hangingHealth['status'] === 'alert', 'Notifier timeout suppressed recovery policy state');
	recoveryAssert(count(glob($hangingSettings->stateDirectory() . '/reports/alerts/*.json') ?: array()) === 1, 'Notifier timeout lost alert evidence');

	$serializedReports = json_encode(array($reports->latest('backup'), $reports->latest('drill'), $health));
	recoveryAssert(!str_contains($serializedReports, $backup), 'Recovery reports exposed the local backup path');

	echo "Recovery component tests passed.\n";
}
finally
{
	foreach (array('CMS', 'COLLECTOR', 'SERVICE_TOKENS') as $target)
	{
		putenv('RECOVERY_' . $target . '_RPO_SECONDS');
		putenv('RECOVERY_' . $target . '_RTO_SECONDS');
	}
	putenv('RECOVERY_DRILL_MAX_AGE_SECONDS');
	RuntimeArchiveService::removeTree($base);
}
