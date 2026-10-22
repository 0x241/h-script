<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use HScript\Http\CspReportSummary;
use HScript\Observability\AlertManager;
use HScript\Observability\HealthService;
use HScript\Observability\NdjsonHistory;
use HScript\Observability\NdjsonWriter;

require dirname(__DIR__) . '/vendor/autoload.php';

$checks = 0;
function retentionAssert(bool $condition, string $message): void
{
	global $checks;
	$checks++;
	if (!$condition) throw new RuntimeException($message);
}
function retentionRecord(int $id, ?int $timestamp = null): string
{
	return json_encode(array('timestamp' => gmdate('Y-m-d\TH:i:s\Z', $timestamp ?? time()), 'id' => $id), JSON_THROW_ON_ERROR) . "\n";
}
function retentionIds(string $path): array
{
	$ids = array();
	NdjsonHistory::read($path, static function (array $record) use (&$ids): void { $ids[] = $record['id']; });
	return $ids;
}

if (($argv[1] ?? '') === '--worker')
{
	$worker = (int)$argv[3];
	for ($index = 0; $index < 60; $index++)
	{
		$line = retentionRecord($worker * 60 + $index);
		$deadline = microtime(true) + 20;
		while (!NdjsonWriter::append($argv[2], $line, 500, 40))
		{
			if (microtime(true) > $deadline) exit(1);
			usleep(1000);
		}
	}
	exit(0);
}

$root = sys_get_temp_dir() . '/hscript-retention-' . bin2hex(random_bytes(8));
mkdir($root, 0755);
mkdir($root . '/logs', 0700);
mkdir($root . '/.cfg', 0700);
$environment = array();
foreach (array('NDJSON_ROTATED_FILES', 'NDJSON_RETENTION_DAYS', 'OBSERVABILITY_LOG_PATH', 'OBSERVABILITY_METRICS_PATH', 'OBSERVABILITY_ALERT_COMMAND') as $name) $environment[$name] = getenv($name);
try
{
	putenv('NDJSON_ROTATED_FILES=7');
	putenv('NDJSON_RETENTION_DAYS=7');
	putenv('OBSERVABILITY_ALERT_COMMAND=');
	$path = $root . '/logs/sequence.ndjson';
	for ($id = 1; $id <= 12; $id++) retentionAssert(NdjsonWriter::append($path, retentionRecord($id), 60, 3), 'Rotation append failed');
	retentionAssert(retentionIds($path) === array(9, 10, 11, 12), 'Oldest eviction or chronological order failed');
	retentionAssert(!file_exists($path . '.4'), 'Rotations exceeded count');
	foreach (array($path, $path . '.1', $path . '.2', $path . '.3', $path . '.lock') as $file)
		retentionAssert((fileperms($file) & 0777) === 0600, 'Log files are not private');
	retentionAssert(NdjsonWriter::append($path, retentionRecord(13), 60, 1), 'Lower-count configuration failed');
	retentionAssert(retentionIds($path) === array(12, 13) && !file_exists($path . '.2'), 'Count reduction retained obsolete files');
	retentionAssert(!NdjsonWriter::append($path, '{"id":14}', 60, 1), 'Unterminated record accepted');
	retentionAssert(!NdjsonWriter::append($path, "malformed\n", 60, 1), 'Invalid JSON accepted');
	retentionAssert(!NdjsonWriter::append($path, retentionRecord(14), 60, 0), 'Unbounded/zero count accepted');
	retentionAssert(!NdjsonWriter::append($path, retentionRecord(14), 60, 101), 'Excessive count accepted');

	$alias = $root . '/runtime-logs';
	symlink($root . '/logs', $alias);
	retentionAssert(NdjsonWriter::append($alias . '/runtime-alias.ndjson', retentionRecord(1), 1000, 3), 'Docker runtime logs directory alias was rejected');
	retentionAssert(retentionIds($alias . '/runtime-alias.ndjson') === array(1), 'Runtime alias history could not be read');

	$crash = $root . '/logs/crash.ndjson';
	retentionAssert(NdjsonWriter::append($crash, retentionRecord(1), 1000, 3), 'Crash fixture failed');
	file_put_contents($crash, '{"timestamp":"secret-crash-token', FILE_APPEND);
	retentionAssert(NdjsonWriter::append($crash, retentionRecord(2), 1000, 3), 'Incomplete tail did not recover');
	retentionAssert(retentionIds($crash) === array(1, 2), 'Tail repair damaged complete record');
	retentionAssert(!str_contains(file_get_contents($crash), 'secret-crash-token'), 'Incomplete tail survived');
	// Kill points both before and after each rename/metadata update, plus oldest eviction.
	foreach (array(0, 1, 2, 3) as $next)
		foreach (array(false, true) as $renamed)
		{
			$recovery = $root . '/logs/recovery-' . $next . '-' . (int)$renamed . '.ndjson';
			for ($index = 0; $index <= 3; $index++) file_put_contents($index === 0 ? $recovery : $recovery . '.' . $index, retentionRecord(4 - $index));
			file_put_contents($recovery . '.lock', '');
			// Apply already-journaled operations above this step.
			for ($index = 3; $index > $next; $index--)
				if ($index === 3) unlink($recovery . '.3');
				else rename($recovery . '.' . $index, $recovery . '.' . ($index + 1));
			if ($renamed)
			{
				if ($next === 3) unlink($recovery . '.3');
				else rename($next === 0 ? $recovery : $recovery . '.' . $next, $recovery . '.' . ($next + 1));
			}
			file_put_contents($recovery . '.rotation', json_encode(array('format' => 1, 'count' => 3, 'next' => $next)));
			retentionAssert(NdjsonWriter::append($recovery, retentionRecord(5), 60, 3), 'Interrupted rotation did not recover');
			retentionAssert(retentionIds($recovery) === array(2, 3, 4, 5), 'Recovered rotation lost/duplicated records');
			retentionAssert(!file_exists($recovery . '.rotation'), 'Completed journal was not cleared');
		}

	foreach (array('', '.lock', '.1', '.3', '.99', '.rotation', '.rotation.next') as $suffix)
	{
		$linked = $root . '/logs/symlink-' . bin2hex(random_bytes(3)) . '.ndjson';
		symlink($crash, $linked . $suffix);
		retentionAssert(!NdjsonWriter::append($linked, retentionRecord(1), 1000, 3), 'Symlink accepted');
		try { NdjsonHistory::read($linked, static fn() => null); retentionAssert(false, 'History followed symlink'); }
		catch (RuntimeException $error) { retentionAssert(!str_contains($error->getMessage(), $root), 'Diagnostic exposed storage path'); }
	}
	$lock = fopen($crash . '.lock', 'c+b');
	flock($lock, LOCK_EX);
	$start = microtime(true);
	retentionAssert(!NdjsonWriter::append($crash, retentionRecord(3), 1000, 3) && microtime(true) - $start < 1, 'Writer blocked on lock');
	fclose($lock);

	// Snapshot handles must survive rotation/eviction triggered while consumer runs.
	$snapshot = $root . '/logs/snapshot.ndjson';
	foreach (array(1, 2, 3) as $id) NdjsonWriter::append($snapshot, retentionRecord($id), 60, 2);
	$captured = array();
	NdjsonHistory::read($snapshot, static function (array $record) use (&$captured, $snapshot): void {
		$captured[] = $record['id'];
		retentionAssert(NdjsonWriter::append($snapshot, retentionRecord(10 + $record['id']), 60, 2), 'Reader held lock while processing');
	});
	retentionAssert($captured === array(1, 2, 3), 'Concurrent rotation changed captured snapshot');

	$tailSnapshot = $root . '/logs/tail-snapshot.ndjson';
	for ($id = 1; $id <= 300; $id++) retentionAssert(NdjsonWriter::append($tailSnapshot, retentionRecord($id), 1000000, 3), 'Tail snapshot bootstrap failed');
	file_put_contents($tailSnapshot, str_repeat('x', 200), FILE_APPEND);
	$oldRecords = array();
	$tailStats = NdjsonHistory::read($tailSnapshot, static function (array $record) use (&$oldRecords, $tailSnapshot): void {
		$oldRecords[] = $record['id'];
		if (count($oldRecords) === 1) retentionAssert(NdjsonWriter::append($tailSnapshot, retentionRecord(999), 1000000, 3), 'Concurrent tail repair failed');
	});
	retentionAssert($oldRecords === range(1, 300), 'Snapshot included a later append or hit EOF after concurrent tail repair');
	retentionAssert($tailStats['incomplete_tail_bytes'] === 200, 'Incomplete captured tail was not classified');
	retentionAssert(retentionIds($tailSnapshot) === array_merge(range(1, 300), array(999)), 'New complete record was lost after tail repair');

	// Parallel workers retry only in the fixture, so every accepted attempt is represented once.
	$concurrent = $root . '/logs/concurrent.ndjson';
	$workers = array();
	for ($worker = 0; $worker < 4; $worker++)
	{
		$process = proc_open(array(PHP_BINARY, __FILE__, '--worker', $concurrent, (string)$worker), array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
		retentionAssert(is_resource($process), 'Could not spawn worker');
		$workers[] = array($process, $pipes);
	}
	foreach ($workers as [$process, $pipes])
	{
		foreach ($pipes as $pipe) { stream_get_contents($pipe); fclose($pipe); }
		retentionAssert(proc_close($process) === 0, 'Concurrent worker failed');
	}
	$ids = retentionIds($concurrent);
	sort($ids);
	retentionAssert($ids === range(0, 239), 'Concurrent append lost or duplicated accepted records');

	$summary = $root . '/logs/csp-violations.ndjson';
	$now = time();
	foreach (array(8, 4, 0) as $day)
	{
		$record = array('timestamp' => gmdate('Y-m-d\TH:i:s\Z', $now - $day * 86400), 'document_surface' => 'authentication', 'directive' => 'script-src', 'blocked_origin' => 'https://safe.example', 'source_kind' => 'web', 'source_asset' => '/static/js/private-path.js', 'disposition' => 'report', 'callback_payload' => 'private-callback-password');
		retentionAssert(NdjsonWriter::append($summary, json_encode($record) . "\n", 600, 7), 'Summary fixture append failed');
	}
	$malicious = array('timestamp' => gmdate('Y-m-d\TH:i:s\Z', $now), 'document_surface' => '/private/user/path', 'directive' => 'secret-password', 'blocked_origin' => 'https://user:pass@192.0.2.1/path?secret=password', 'source_kind' => 'private-password', 'source_asset' => '/private/token', 'disposition' => 'secret');
	retentionAssert(NdjsonWriter::append($summary, json_encode($malicious) . "\n", 600, 7), 'Malformed aggregate fixture append failed');
	$result = CspReportSummary::summarize($summary, $now);
	$json = json_encode($result);
	retentionAssert($result['history']['records'] === 4 && $result['history']['status'] === 'ok', 'Summary skipped rotations or misreported span');
	foreach (array('private-path', '/private/', 'password', 'callback', '192.0.2.1', 'user:pass') as $secret)
		retentionAssert(!str_contains($json, $secret), 'Summary leaked private fields');
	retentionAssert($result['capacity']['total_byte_ceiling'] === 167772160, 'Configured byte ceiling changed');
	retentionAssert(NdjsonHistory::inspect($summary, $now)['status'] === 'ok', 'Cheap monitor differs from full summary');
	$archiveOnly = $root . '/logs/archive-only.ndjson';
	file_put_contents($archiveOnly . '.2', retentionRecord(1, $now - 8 * 86400));
	file_put_contents($archiveOnly . '.lock', '');
	putenv('OBSERVABILITY_LOG_PATH=' . $archiveOnly);
	$retention = (new HealthService($root))->logRetention();
	retentionAssert($retention['streams']['events']['status'] === 'ok', 'Higher retained generation was classified as unused');
	retentionAssert($retention['streams']['events']['files'] === 1, 'Archive-only history was not inspected');
	$unsafeUnused = $root . '/logs/unsafe-unused.ndjson';
	symlink($crash . '.lock', $unsafeUnused . '.lock');
	putenv('OBSERVABILITY_LOG_PATH=' . $unsafeUnused);
	retentionAssert((new HealthService($root))->logRetention()['streams']['events']['status'] === 'unknown', 'Unsafe unused sink was classified as not started');
	putenv('OBSERVABILITY_LOG_PATH=' . $crash);
	putenv('OBSERVABILITY_METRICS_PATH=' . $root . '/logs/not-started.ndjson');
	$retention = (new HealthService($root))->logRetention();
	retentionAssert($retention['status'] === 'shortfall', 'Short observation window was not detected');
	$alerts = (new AlertManager($root, dirname(__DIR__) . '/resources/observability-alerts.json'))->evaluateReadiness(array('checks' => array('log_retention' => 'shortfall')));
	retentionAssert(($alerts['log_retention_shortfall'] ?? '') === 'emitted', 'Shortfall alert was not emitted');

	if (function_exists('posix_geteuid') && posix_geteuid() === 0 && ($web = posix_getpwnam('www-data')) !== false)
	{
		$private = $root . '/private';
		mkdir($private, 0700);
		try
		{
			retentionAssert(posix_seteuid($web['uid']), 'Could not select restricted writer');
			retentionAssert(!NdjsonWriter::append($private . '/events.ndjson', retentionRecord(1), 1000, 3), 'Permission-denied sink accepted');
		}
		finally { posix_seteuid(0); }
	}
	echo "NDJSON retention: $checks checks passed (concurrency, eviction, crash journal/tail, privacy, locks, permissions, retention alert).\n";
}
finally
{
	foreach ($environment as $name => $value) putenv($value === false ? $name : $name . '=' . $value);
	$entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
	foreach ($entries as $entry)
	{
		if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname());
		else unlink($entry->getPathname());
	}
	rmdir($root);
}
