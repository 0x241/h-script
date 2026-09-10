<?php

declare(strict_types=1);

use HScript\Security\IntegrityScanner;
use HScript\Security\IntegrityStateRepository;

require dirname(__DIR__) . '/vendor/autoload.php';

function integrityAssert(bool $condition, string $message): void
{
	if (!$condition) throw new RuntimeException($message);
}

function integrityRejects(callable $callback, string $message): void
{
	try { $callback(); }
	catch (Throwable) { return; }
	throw new RuntimeException($message);
}

function integrityRemove(string $path): void
{
	if (is_link($path) || is_file($path)) { unlink($path); return; }
	if (!is_dir($path)) return;
	foreach ((array)scandir($path) as $item)
		if ($item !== '.' && $item !== '..') integrityRemove($path . '/' . $item);
	rmdir($path);
}

function integrityEntry(string $contents, string $class = 'core_strict'): array
{
	return array('sha256' => hash('sha256', $contents), 'size' => strlen($contents), 'class' => $class);
}

function integrityComplete(IntegrityScanner $scanner, ?array $state = null): array
{
	$state = $state ?? $scanner->start();
	$guard = 0;
	while (($state['status'] ?? '') === 'running')
	{
		if (++$guard > 100) throw new RuntimeException('Integrity scan did not resume to completion');
		$state = $scanner->advance();
	}
	return $state;
}

function integrityFinding(array $state, string $path, string $status): bool
{
	foreach ((array)($state['findings'] ?? array()) as $finding)
		if (($finding['path'] ?? '') === $path && ($finding['status'] ?? '') === $status) return true;
	return false;
}

$root = sys_get_temp_dir() . '/hscript-integrity-' . bin2hex(random_bytes(8));
if (!mkdir($root . '/tpl/themes/default', 0700, true) || !mkdir($root . '/upload', 0700, true)
	|| !mkdir($root . '/cache', 0700, true) || !mkdir($root . '/logs', 0700, true)
	|| !mkdir($root . '/backup', 0700, true) || !mkdir($root . '/tpl_c', 0700, true)
	|| !mkdir($root . '/.cfg/update/baselines', 0700, true) || !mkdir($root . '/resources', 0700, true))
	throw new RuntimeException('Integrity test directory could not be created');

try
{
	$core = "<?php\nreturn true;\n";
	$twig = "<h1>Official</h1>\n";
	$theme = "body { color: black; }\n";
	$large = str_repeat('0123456789abcdef', 131072);
	file_put_contents($root . '/core.php', $core);
	file_put_contents($root . '/tpl/page.twig', $twig);
	file_put_contents($root . '/tpl/themes/default/theme.css', $theme);
	file_put_contents($root . '/large.bin', $large);
	file_put_contents($root . '/upload/user.txt', "allowed runtime data\n");
	file_put_contents($root . '/cache/item.bin', "allowed cache data\n");
	file_put_contents($root . '/logs/runtime.log', "allowed log data\n");
	file_put_contents($root . '/backup/database.sql.gz', "allowed backup data\n");
	file_put_contents($root . '/tpl_c/generated.php', "<?php\n// generated template cache\n");
	file_put_contents($root . '/_config.php', "<?php\n// runtime config\n");
	$baseline = array(
		'core.php' => integrityEntry($core),
		'large.bin' => integrityEntry($large),
		'tpl/page.twig' => integrityEntry($twig, 'customizable'),
		'tpl/themes/default/theme.css' => integrityEntry($theme, 'customizable'),
	);
	$states = new IntegrityStateRepository($root);
	file_put_contents($root . '/.cfg/update/baselines/installed.json', json_encode(array(
		'format' => 2,
		'version' => '1.0.1',
		'files' => $baseline,
	), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
	file_put_contents($root . '/resources/release-baseline.json', json_encode(array(
		'format' => 2,
		'version' => HScript\Application::version(),
		'files' => $baseline,
	), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
	$fallback = integrityComplete(new IntegrityScanner($root, null, $states, 10, 10));
	integrityAssert($fallback['counts']['critical'] === 0 && $fallback['baseline_source'] === 'bundled-release', 'Bundled image baseline did not replace a stale installed baseline');

	$scanner = new IntegrityScanner($root, $baseline, $states, 1, 10);
	$initial = $scanner->start();
	integrityAssert($initial['status'] === 'running' && $initial['checked'] === 1, 'Bounded scan did not persist a resumable cursor');
	$initial = integrityComplete($scanner, $initial);
	integrityAssert($initial['status'] === 'completed' && $initial['counts']['unchanged'] === 4, 'Unchanged release was not accepted');
	integrityAssert($initial['counts']['critical'] === 0 && !$initial['findings'], 'Allowed runtime data caused an alert');

	$mtime = filemtime($root . '/core.php');
	touch($root . '/core.php', $mtime + 3600);
	$mtimeOnly = integrityComplete($scanner);
	integrityAssert($mtimeOnly['counts']['critical'] === 0 && $mtimeOnly['counts']['unchanged'] === 4, 'mtime-only change caused an integrity alert');

	file_put_contents($root . '/core.php', "<?php\nreturn false;\n");
	file_put_contents($root . '/tpl/page.twig', "<h1>Custom</h1>\n");
	file_put_contents($root . '/tpl/themes/default/theme.css', "body { color: blue; }\n");
	file_put_contents($root . '/tpl/themes/local.css', "body { color: green; }\n");
	file_put_contents($root . '/tpl/shell.php', "<?php echo 'bad';\n");
	file_put_contents($root . '/backup/shell.phtml', "<?php echo 'bad';\n");
	file_put_contents($root . '/upload/run.sh', "#!/bin/sh\nexit 0\n");
	chmod($root . '/upload/run.sh', 0700);
	$changed = integrityComplete($scanner);
	integrityAssert(integrityFinding($changed, 'core.php', 'modified'), 'Modified core file was not reported');
	integrityAssert(integrityFinding($changed, 'tpl/page.twig', 'customized'), 'Customized Twig template was not reported');
	integrityAssert(integrityFinding($changed, 'tpl/themes/default/theme.css', 'customized'), 'Customized official theme file was not reported');
	integrityAssert(integrityFinding($changed, 'tpl/themes/local.css', 'customized'), 'Additional user theme file was not reported');
	integrityAssert(integrityFinding($changed, 'tpl/shell.php', 'unexpected_executable'), 'Unexpected executable in tpl was not reported');
	integrityAssert(integrityFinding($changed, 'backup/shell.phtml', 'unexpected_executable'), 'Unexpected executable in backup storage was not reported');
	integrityAssert(integrityFinding($changed, 'upload/run.sh', 'unexpected_executable'), 'Executable file in writable storage was not reported');
	integrityAssert($changed['counts']['critical'] === 4 && $changed['counts']['customized'] === 3, 'Template customization changed critical alert semantics');
	integrityAssert($changed['new_alert'] === true, 'New integrity alert was not marked once');
	$repeated = integrityComplete($scanner);
	integrityAssert($repeated['alert_signature'] === $changed['alert_signature'] && $repeated['new_alert'] === false, 'Unchanged critical set created a duplicate new alert');

	$acknowledged = $scanner->acknowledge();
	integrityAssert($acknowledged['alert_signature'] === $acknowledged['acknowledged_signature'], 'Integrity acknowledgement was not persisted');
	integrityAssert($acknowledged['counts']['critical'] === 4, 'Acknowledgement incorrectly cleared the alert');
	$states->markNotified((string)$acknowledged['alert_signature']);
	integrityAssert(($states->get()['notified_signature'] ?? '') === $acknowledged['alert_signature'], 'Notification signature was not persisted');

	unlink($root . '/core.php');
	symlink('../upload/user.txt', $root . '/upload/nested-link');
	$missing = integrityComplete($scanner);
	integrityAssert(integrityFinding($missing, 'core.php', 'missing'), 'Missing core file was not reported');
	integrityAssert(integrityFinding($missing, 'upload/nested-link', 'unexpected_link'), 'Writable-directory symlink was not reported');

	unlink($root . '/upload/nested-link');
	unlink($root . '/upload/run.sh');
	unlink($root . '/tpl/shell.php');
	unlink($root . '/backup/shell.phtml');
	unlink($root . '/tpl/themes/local.css');
	file_put_contents($root . '/tpl/themes/default/theme.css', $theme);
	file_put_contents($root . '/core.php', $core);
	$restored = integrityComplete($scanner);
	integrityAssert($restored['counts']['critical'] === 0 && $restored['alert_signature'] === '', 'Restored integrity did not clear the alert');
	integrityAssert($restored['counts']['customized'] === 1, 'Allowed Twig customization disappeared from the report');

	$customTwig = (string)file_get_contents($root . '/tpl/page.twig');
	$updatedBaseline = $baseline;
	$updatedBaseline['tpl/page.twig'] = integrityEntry($customTwig, 'customizable');
	$updatedScanner = new IntegrityScanner($root, $updatedBaseline, $states, 10, 10);
	$updated = integrityComplete($updatedScanner);
	integrityAssert($updated['counts']['critical'] === 0 && $updated['counts']['customized'] === 0, 'Verified baseline update was not applied');

	integrityRejects(
		fn() => new IntegrityScanner($root, array('../unsafe.php' => integrityEntry('bad')), $states),
		'Unsafe baseline path was accepted'
	);

	file_put_contents($root . '/core.php', $core);
	chmod($root . '/core.php', 0000);
	if (!is_readable($root . '/core.php'))
	{
		$unreadable = integrityComplete(new IntegrityScanner($root, $baseline, $states, 10, 10));
		integrityAssert(integrityFinding($unreadable, 'core.php', 'unreadable'), 'Unreadable core file was not reported');
	}
	chmod($root . '/core.php', 0600);

	echo "Integrity tests passed.\n";
}
finally
{
	integrityRemove($root);
}
