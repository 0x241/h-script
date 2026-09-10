<?php

declare(strict_types=1);

use HScript\Update\JournaledReleaseActivator;
use HScript\Update\ReleaseManifest;
use HScript\Update\UpdateSettings;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

function activationAssert(bool $condition, string $message): void
{
	if (!$condition) throw new RuntimeException($message);
}

function activationRemove(string $path): void
{
	if (is_link($path) || is_file($path)) { unlink($path); return; }
	if (!is_dir($path)) return;
	foreach ((array)scandir($path) as $item)
		if ($item !== '.' && $item !== '..') activationRemove($path . '/' . $item);
	rmdir($path);
}

function activationFixture(string $staging): array
{
	$files = array(
		'VERSION' => "1.0.1\n",
		'SCHEMA_VERSION' => "1.0.0\n",
		'src/Probe.php' => "<?php\n// release\n",
		'src/Custom.php' => "<?php\n// release custom\n",
		'tpl/page.twig' => "release template\n",
	);
	foreach ($files as $path => $contents)
	{
		$directory = dirname($staging . '/' . $path);
		if (!is_dir($directory)) mkdir($directory, 0700, true);
		file_put_contents($staging . '/' . $path, $contents);
	}
	$plan = array(
		array('path' => 'SCHEMA_VERSION', 'action' => 'preserve', 'reason' => 'already_target', 'class' => 'core_strict', 'source_sha256' => hash('sha256', "1.0.0\n"), 'local_sha256' => hash('sha256', "1.0.0\n"), 'target_sha256' => hash('sha256', $files['SCHEMA_VERSION']), 'target_size' => strlen($files['SCHEMA_VERSION'])),
		array('path' => 'VERSION', 'action' => 'install', 'reason' => 'official_update', 'class' => 'core_strict', 'source_sha256' => hash('sha256', "1.0.0\n"), 'local_sha256' => hash('sha256', "1.0.0\n"), 'target_sha256' => hash('sha256', $files['VERSION']), 'target_size' => strlen($files['VERSION'])),
		array('path' => 'src/Custom.php', 'action' => 'conflict', 'reason' => 'local_and_release_changed', 'class' => 'core_strict', 'source_sha256' => hash('sha256', "<?php\n// base custom\n"), 'local_sha256' => hash('sha256', "<?php\n// local custom\n"), 'target_sha256' => hash('sha256', $files['src/Custom.php']), 'target_size' => strlen($files['src/Custom.php'])),
		array('path' => 'src/Probe.php', 'action' => 'install', 'reason' => 'official_update', 'class' => 'core_strict', 'source_sha256' => hash('sha256', "<?php\n// local\n"), 'local_sha256' => hash('sha256', "<?php\n// local\n"), 'target_sha256' => hash('sha256', $files['src/Probe.php']), 'target_size' => strlen($files['src/Probe.php'])),
		array('path' => 'src/Removed.php', 'action' => 'delete', 'reason' => 'removed_from_release', 'class' => 'core_strict', 'source_sha256' => hash('sha256', "<?php\n// removed\n"), 'local_sha256' => hash('sha256', "<?php\n// removed\n"), 'target_sha256' => null, 'target_size' => 0),
		array('path' => 'src/RemovedCustom.php', 'action' => 'conflict', 'reason' => 'removed_but_locally_changed', 'class' => 'core_strict', 'source_sha256' => hash('sha256', "<?php\n// base removed\n"), 'local_sha256' => hash('sha256', "<?php\n// custom removed\n"), 'target_sha256' => null, 'target_size' => 0),
		array('path' => 'tpl/page.twig', 'action' => 'conflict', 'reason' => 'local_and_release_changed', 'class' => 'customizable', 'source_sha256' => hash('sha256', "base template\n"), 'local_sha256' => hash('sha256', "custom template\n"), 'target_sha256' => hash('sha256', $files['tpl/page.twig']), 'target_size' => strlen($files['tpl/page.twig'])),
	);
	$data = array(
		'format' => 1,
		'source' => 'manual',
		'activation_plan_sha256' => hash('sha256', json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
		'compatibility' => array('format' => 1, 'application' => array('minimum' => '1.0.0', 'maximum' => '1.0.0'), 'schema' => array('minimum' => '1.0.0', 'maximum' => '1.0.0')),
		'release' => array('application_version' => '1.0.1', 'schema_version' => '1.0.0', 'released_at' => '2026-09-08T00:00:00Z', 'summary' => 'Тест активации.', 'changes' => array('Переключение релиза.')),
		'classification' => 'code-only',
		'artifact' => array('name' => 'h-script-1.0.1-shared-hosting.tar.gz', 'sha256' => str_repeat('a', 64), 'sigstore_url' => ''),
		'files' => array(
			'managed' => array(
				array('path' => 'VERSION', 'sha256' => hash('sha256', $files['VERSION']), 'source_sha256' => hash('sha256', "1.0.0\n"), 'size' => strlen($files['VERSION']), 'class' => 'core_strict'),
				array('path' => 'SCHEMA_VERSION', 'sha256' => hash('sha256', $files['SCHEMA_VERSION']), 'source_sha256' => hash('sha256', $files['SCHEMA_VERSION']), 'size' => strlen($files['SCHEMA_VERSION']), 'class' => 'core_strict'),
				array('path' => 'src/Custom.php', 'sha256' => hash('sha256', $files['src/Custom.php']), 'source_sha256' => hash('sha256', "<?php\n// base custom\n"), 'size' => strlen($files['src/Custom.php']), 'class' => 'core_strict'),
				array('path' => 'src/Probe.php', 'sha256' => hash('sha256', $files['src/Probe.php']), 'source_sha256' => hash('sha256', "<?php\n// local\n"), 'size' => strlen($files['src/Probe.php']), 'class' => 'core_strict'),
				array('path' => 'tpl/page.twig', 'sha256' => hash('sha256', $files['tpl/page.twig']), 'source_sha256' => hash('sha256', "base template\n"), 'size' => strlen($files['tpl/page.twig']), 'class' => 'customizable'),
			),
		),
	);
	return array(ReleaseManifest::fromJson(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), $plan);
}

$temporary = sys_get_temp_dir() . '/hscript-activation-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700, true);
try
{
	$journalRoot = $temporary . '/journal-live';
	mkdir($journalRoot . '/src', 0700, true);
	mkdir($journalRoot . '/tpl', 0700, true);
	mkdir($journalRoot . '/upload', 0700, true);
	file_put_contents($journalRoot . '/VERSION', "1.0.0\n");
	file_put_contents($journalRoot . '/SCHEMA_VERSION', "1.0.0\n");
	file_put_contents($journalRoot . '/src/Probe.php', "<?php\n// local\n");
	file_put_contents($journalRoot . '/src/Custom.php', "<?php\n// local custom\n");
	file_put_contents($journalRoot . '/src/Removed.php', "<?php\n// removed\n");
	file_put_contents($journalRoot . '/src/RemovedCustom.php', "<?php\n// custom removed\n");
	file_put_contents($journalRoot . '/tpl/page.twig', "custom template\n");
	file_put_contents($journalRoot . '/upload/user.txt', "keep\n");
	$journalStaging = $temporary . '/journal-stage';
	mkdir($journalStaging, 0700, true);
	[$journalManifest, $activationPlan] = activationFixture($journalStaging);
	$journalSettings = new UpdateSettings($journalRoot, $temporary . '/journal-work', 10485760, 10485760, 100, 10485760, 2);
	$journal = new JournaledReleaseActivator($journalSettings);
	$journalRun = str_repeat('1', 32);
	activationAssert($journal->preflight($journalManifest, $activationPlan)['mode'] === 'journaled-three-way-copy', 'Journaled activation preflight mode is invalid');
	$fileChoices = array('src/Custom.php' => 'local', 'src/RemovedCustom.php' => 'release', 'tpl/page.twig' => 'local');
	$journal->activate($journalRun, str_repeat('2', 32), $journalManifest, $journalStaging, $fileChoices, $activationPlan);
	activationAssert((string)file_get_contents($journalRoot . '/VERSION') === "1.0.1\n", 'Journaled activation did not replace core code');
	activationAssert((string)file_get_contents($journalRoot . '/tpl/page.twig') === "custom template\n", 'Journaled activation overwrote chosen local Twig');
	activationAssert((string)file_get_contents($journalRoot . '/src/Custom.php') === "<?php\n// local custom\n", 'Journaled activation overwrote chosen local core customization');
	activationAssert(!is_file($journalRoot . '/src/Removed.php') && !is_file($journalRoot . '/src/RemovedCustom.php'), 'Journaled activation did not apply release removals');
	activationAssert((string)file_get_contents($journalRoot . '/upload/user.txt') === "keep\n", 'Journaled activation changed uploads');
	$journal->activate($journalRun, str_repeat('2', 32), $journalManifest, $journalStaging, $fileChoices, $activationPlan);
	$journalPath = $journalSettings->runsDirectory() . '/' . $journalRun . '.journal.json';
	$interruptedJournal = json_decode((string)file_get_contents($journalPath), true, 64, JSON_THROW_ON_ERROR);
	foreach ($interruptedJournal['entries'] as &$entry)
		if ($entry['path'] === 'VERSION') $entry['state'] = 'pending';
	unset($entry);
	file_put_contents($journalPath, json_encode($interruptedJournal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
	$journal->rollback($journalRun);
	activationAssert((string)file_get_contents($journalRoot . '/VERSION') === "1.0.0\n", 'Journaled rollback did not restore previous code');
	activationAssert((string)file_get_contents($journalRoot . '/src/Probe.php') === "<?php\n// local\n", 'Journaled rollback did not restore prior file');
	activationAssert(is_file($journalRoot . '/src/Removed.php') && is_file($journalRoot . '/src/RemovedCustom.php'), 'Journaled rollback did not restore removed files');
	activationAssert($journal->prunePrevious(1) === array(), 'Journaled activator unexpectedly pruned code outside update retention');

	echo "Update activation tests passed.\n";
}
finally
{
	activationRemove($temporary);
}
