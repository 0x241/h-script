<?php

use HScript\Cache\RedisCache;
use HScript\Database\Connection;
use HScript\Observability\AlertManager;
use HScript\Observability\HealthService;
use HScript\Observability\MetricRegistry;
use HScript\Observability\NdjsonWriter;
use HScript\Observability\OperationalStateRepository;
use HScript\Observability\StructuredLogger;

require dirname(__DIR__) . '/vendor/autoload.php';

function regressionAssert(bool $condition, string $message): void
{
	if (!$condition) throw new RuntimeException($message);
}

final class ObservabilityFailureConnection extends Connection
{
	public string $failure = '';
	public string $mode = 'false';
	public string $cronEnabled = '0';
	public array $rows = array();

	private function result(string $stage, mixed $value): mixed
	{
		if ($this->failure !== $stage) return $value;
		if ($this->mode === 'throw') throw new RuntimeException('Fixture dependency failure');
		return false;
	}

	public function query($query, $values = null)
	{
		$stage = match (true) {
			$query === 'SELECT 1' => 'database',
			str_contains($query, 'GROUP BY') => 'queue_counts',
			str_contains($query, 'MIN(jCTS)') => 'queue_age',
			str_contains($query, 'information_schema') => 'dns_table',
			default => 'dns_count',
		};
		return $this->result($stage . '_query', $stage);
	}

	public function select($table, $fields = '*', $filter = '', $values = null, $order = '', $limit = '', $group = '')
	{
		return $this->result('config_query', 'config');
	}

	public function fetch1($query)
	{
		regressionAssert($query !== false, 'Readiness attempted to fetch a failed query');
		return $this->result($query . '_fetch', match ($query) {
			'database', 'dns_table' => '1',
			'config' => $this->cronEnabled,
			default => '0',
		});
	}

	public function fetchRows($query, $singleField = false)
	{
		regressionAssert($query !== false, 'Readiness attempted to fetch failed queue SQL');
		return $this->result('queue_counts_fetch', $this->rows);
	}
}

$root = sys_get_temp_dir() . '/hscript-observability-regressions-' . bin2hex(random_bytes(8));
mkdir($root, 0755);
mkdir($root . '/logs', 0700);
mkdir($root . '/.cfg', 0700);
$environment = array();
foreach (array('OBSERVABILITY_LOG_PATH', 'OBSERVABILITY_METRICS_PATH', 'OBSERVABILITY_METRICS_MAX_BYTES',
	'OBSERVABILITY_ALERT_COMMAND', 'OBSERVABILITY_CRON_MAX_AGE_SECONDS', 'REDIS_ENABLED') as $name)
	$environment[$name] = getenv($name);
set_error_handler(static function (int $severity, string $message): never {
	throw new ErrorException($message, 0, $severity);
});
try
{
	putenv('OBSERVABILITY_LOG_PATH=' . $root . '/logs/events.ndjson');
	putenv('OBSERVABILITY_METRICS_PATH=' . $root . '/logs/metrics.ndjson');
	putenv('OBSERVABILITY_ALERT_COMMAND=');
	putenv('OBSERVABILITY_CRON_MAX_AGE_SECONDS=180');
	putenv('REDIS_ENABLED=0');
	StructuredLogger::reset();
	$health = new HealthService($root);
	$cache = new RedisCache(enabled: false);
	$db = new ObservabilityFailureConnection();
	regressionAssert($health->readiness($db, $cache)['status'] === 'ready', 'Empty queues must remain ready');

	foreach (array('false', 'throw') as $mode)
		foreach (array('queue_counts', 'queue_age', 'dns_table', 'dns_count', 'config') as $stage)
			foreach (array('query', 'fetch') as $operation)
			{
				$db->failure = $stage . '_' . $operation;
				$db->mode = $mode;
				MetricRegistry::reset();
				$metricPath = $root . '/logs/' . $db->failure . '-' . $mode . '.ndjson';
				putenv('OBSERVABILITY_METRICS_PATH=' . $metricPath);
				$result = $health->readiness($db, $cache);
				$check = str_starts_with($stage, 'queue') ? 'queue' : (str_starts_with($stage, 'dns') ? 'dns' : 'cron');
				regressionAssert($result['status'] === 'degraded' && $result['checks'][$check] === 'unknown', 'Failed ' . $db->failure . ' was treated as healthy');
				if ($check === 'queue') regressionAssert($result['queue']['pending'] === null && $result['queue']['oldest_age_seconds'] === null, 'Unknown queue was represented as zero');
				if ($check === 'dns') regressionAssert($result['dns_backlog'] === null, 'Unknown DNS was represented as zero');
				if ($check === 'cron') regressionAssert($result['cron_age_seconds'] === null, 'Unknown cron was represented as fresh');
				regressionAssert(MetricRegistry::flush(), 'Metric flush failed');
				$metrics = file_get_contents($metricPath);
				$forbidden = $check === 'queue' ? 'queue_depth' : ($check === 'dns' ? 'dns_backlog' : 'cron_lag_seconds');
				regressionAssert(!str_contains($metrics, '"metric":"' . $forbidden . '"'), 'An unknown measurement emitted a healthy gauge');
				$alerts = new AlertManager($root, dirname(__DIR__) . '/resources/observability-alerts.json');
				$event = $check === 'cron' ? 'cron_stale' : $check . '_backlog';
				regressionAssert(isset($alerts->evaluateReadiness($result)[$event]), 'Unknown dependency did not trigger alert policy');
			}
	$db->failure = '';
	$db->rows = array(array('jState' => '0', 'total' => false));
	regressionAssert($health->readiness($db, $cache)['checks']['queue'] === 'unknown', 'Invalid count was accepted');
	$db->rows = array(array('jState' => '3', 'total' => '10000001'));
	regressionAssert($health->readiness($db, $cache)['checks']['queue'] === 'backlog', 'Queue backlog detection regressed');
	$db->rows = array();
	$db->cronEnabled = '1';
	$state = new OperationalStateRepository($root);
	regressionAssert($state->recordCron('started'), 'Could not persist cron start');
	regressionAssert($health->readiness($db, $cache)['checks']['cron'] === 'missing', 'A start was mistaken for successful cron');
	regressionAssert($state->recordCron('success'), 'Could not persist cron success');
	$success = file_get_contents($root . '/.cfg/observability/cron-success.json');
	foreach (array('started', 'failed', 'disabled') as $status)
	{
		regressionAssert($state->recordCron($status), 'Could not persist heartbeat');
		regressionAssert(file_get_contents($root . '/.cfg/observability/cron-success.json') === $success, 'Non-success overwrote successful completion');
	}
	regressionAssert($health->readiness($db, $cache)['checks']['cron'] === 'ok', 'Recent success was lost to another run');
	$old = json_decode($success, true, 16, JSON_THROW_ON_ERROR);
	$old['updated_at'] = gmdate('Y-m-d\TH:i:s\Z', time() - 3600);
	file_put_contents($root . '/.cfg/observability/cron-success.json', json_encode($old));
	for ($i = 0; $i < 3; $i++) $state->recordCron('started');
	regressionAssert($health->readiness($db, $cache)['checks']['cron'] === 'stale', 'Repeated crashed runs concealed stale cron');
	$db->cronEnabled = '0';
	regressionAssert($health->readiness($db, $cache)['checks']['cron'] === 'disabled', 'Explicit cron disable was ignored');

	$lockedPath = $root . '/logs/locked.ndjson';
	$lock = fopen($lockedPath . '.lock', 'c+b');
	flock($lock, LOCK_EX);
	$start = microtime(true);
	regressionAssert(!NdjsonWriter::append($lockedPath, "{}\n", 100), 'Contended sink should fail open');
	regressionAssert(microtime(true) - $start < 1, 'Logging blocked on a busy sink');
	fclose($lock);
	regressionAssert(NdjsonWriter::append($lockedPath, "{}\n", 100), 'Sink did not recover after lock release');
	symlink($lockedPath, $root . '/logs/symlink.ndjson');
	regressionAssert(!NdjsonWriter::append($root . '/logs/symlink.ndjson', "{}\n", 100), 'Symlink sink accepted');
	regressionAssert(!NdjsonWriter::append($lockedPath, str_repeat('x', 101), 100), 'Oversized record exceeded bound');

	$notifier = $root . '/notifier';
	file_put_contents($notifier, "#!/bin/sh\nexec sleep 30\n");
	chmod($notifier, 0700);
	putenv('OBSERVABILITY_ALERT_COMMAND=' . $notifier);
	$start = microtime(true);
	regressionAssert($alerts->emit('redis_degraded') === 'emitted', 'Hung notifier lost local evidence');
	regressionAssert(microtime(true) - $start >= 4.5, 'Hung notifier fixture did not exercise the deadline');
	regressionAssert(microtime(true) - $start < 7, 'Hung notifier blocked readiness beyond deadline');
	regressionAssert(str_contains(file_get_contents($root . '/logs/events.ndjson'), 'notifier_unavailable'), 'Notifier timeout was not logged');
	putenv('OBSERVABILITY_ALERT_COMMAND=');

	// Linux container root is required to exercise the actual root/www-data identities.
	if (function_exists('posix_geteuid') && posix_geteuid() === 0 && ($web = posix_getpwnam('www-data')) !== false)
	{
		$shared = $root . '/shared';
		mkdir($shared, 0755);
		foreach (array('logs', '.cfg') as $directory)
		{
			mkdir($shared . '/' . $directory, 0700);
			chown($shared . '/' . $directory, $web['uid']);
		}
		$eventPath = $shared . '/logs/events.ndjson';
		$metricPath = $shared . '/logs/metrics.ndjson';
		putenv('OBSERVABILITY_METRICS_PATH=' . $metricPath);
		putenv('OBSERVABILITY_METRICS_MAX_BYTES=1048576');
		$logger = new StructuredLogger($eventPath, 700);
		$sharedState = new OperationalStateRepository($shared);
		$sharedAlerts = new AlertManager($shared, dirname(__DIR__) . '/resources/observability-alerts.json');
		foreach (array(0, $web['uid'], 0, $web['uid'], 0, $web['uid']) as $index => $uid)
		{
			try
			{
				regressionAssert(posix_seteuid($uid), 'Could not select writer identity');
				regressionAssert($logger->write('info', 'test', 'mixed_uid', 'success'), 'Mixed-UID log append/rotation failed');
				MetricRegistry::reset();
				MetricRegistry::gauge('dns_backlog', 1);
				regressionAssert(MetricRegistry::flush(), 'Mixed-UID metric append/rotation failed');
				regressionAssert($sharedState->recordCron('success'), 'Mixed-UID cron write failed');
				regressionAssert($sharedState->cronSuccess()['status'] === 'success', 'Mixed-UID cron read failed');
				regressionAssert($sharedState->recordReadiness(array('status' => 'ready')), 'Mixed-UID readiness write failed');
				regressionAssert($sharedAlerts->emit('database_unavailable') === ($index === 0 ? 'emitted' : 'suppressed'), 'Mixed-UID alert evidence/suppression failed');
			}
			finally { regressionAssert(posix_seteuid(0), 'Could not restore test identity'); }
			foreach (array($eventPath, $eventPath . '.lock', $metricPath, $metricPath . '.lock', $shared . '/.cfg/observability/cron-success.json') as $file)
			{
				clearstatcache(true, $file);
				regressionAssert(fileowner($file) === $web['uid'] && (fileperms($file) & 0777) === 0600, 'Private owner/mode contract failed');
			}
			// Force the next metric flush to rotate; both identities must recreate it safely.
			if ($index < 5) file_put_contents($metricPath, str_repeat("{}\n", 349525));
		}
		regressionAssert(is_file($eventPath . '.1') && is_file($metricPath . '.1'), 'Both streams must exercise rotation');
		foreach (array($eventPath, $eventPath . '.1', $metricPath, $metricPath . '.1') as $file)
			foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) json_decode($line, true, 16, JSON_THROW_ON_ERROR);
		echo "Mixed root/www-data ownership and rotation passed.\n";
	}
	else echo "SKIP mixed-UID regression: run as root in the PHP Linux container.\n";
}
finally
{
	restore_error_handler();
	foreach ($environment as $name => $value) putenv($value === false ? $name : $name . '=' . $value);
	MetricRegistry::reset();
	StructuredLogger::reset();
	$entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
	foreach ($entries as $entry)
	{
		if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname());
		else unlink($entry->getPathname());
	}
	rmdir($root);
}

echo "Observability readiness, cron and storage regressions passed.\n";
