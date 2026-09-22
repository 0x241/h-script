<?php

use HScript\Observability\MetricRegistry;
use HScript\Observability\OperationalStateRepository;
use HScript\Observability\StructuredLogger;

require dirname(__DIR__) . '/vendor/autoload.php';

function fpmStorageProbe(string $root): array
{
	putenv('OBSERVABILITY_METRICS_PATH=' . $root . '/logs/metrics.ndjson');
	putenv('OBSERVABILITY_METRICS_MAX_BYTES=1048576');
	$state = new OperationalStateRepository($root);
	MetricRegistry::gauge('dns_backlog', 1);
	return array(
		'sapi' => PHP_SAPI,
		'log' => (new StructuredLogger($root . '/logs/events.ndjson', 500))->write('info', 'test', 'fpm_storage', 'success'),
		'metrics' => MetricRegistry::flush(),
		'cron' => $state->recordCron('success'),
	);
}

// This test is never included in release artifacts; the isolated pool executes it too.
if (PHP_SAPI === 'fpm-fcgi')
{
	header('Content-Type: application/json');
	echo json_encode(fpmStorageProbe($_SERVER['HS_TEST_ROOT']), JSON_THROW_ON_ERROR);
	exit;
}

function fpmAssert(bool $condition, string $message): void
{
	if (!$condition) throw new RuntimeException($message);
}

function fpmRecord(int $type, string $body): string
{
	return pack('CCnnCC', 1, $type, 1, strlen($body), 0, 0) . $body;
}

function fpmRequest(string $socket, string $root): array
{
	$connection = stream_socket_client('unix://' . $socket, $errno, $error, 2);
	fpmAssert(is_resource($connection), 'Could not connect to isolated FPM pool');
	stream_set_timeout($connection, 5);
	try
	{
		$parameters = '';
		foreach (array('SCRIPT_FILENAME' => __FILE__, 'REQUEST_METHOD' => 'GET', 'HS_TEST_ROOT' => $root) as $name => $value)
		{
			fpmAssert(strlen($name) < 128 && strlen($value) < 128, 'Fixture FastCGI parameter too long');
			$parameters .= chr(strlen($name)) . chr(strlen($value)) . $name . $value;
		}
		$request = fpmRecord(1, pack('nCxxxxx', 1, 0)) . fpmRecord(4, $parameters) . fpmRecord(4, '') . fpmRecord(5, '');
		fpmAssert(fwrite($connection, $request) === strlen($request), 'FastCGI request failed');
		$read = static function (int $length) use ($connection): string {
			$result = '';
			while (strlen($result) < $length)
			{
				$chunk = fread($connection, $length - strlen($result));
				fpmAssert(is_string($chunk) && $chunk !== '', 'FastCGI response ended or timed out');
				$result .= $chunk;
			}
			return $result;
		};
		$output = '';
		while (true)
		{
			$header = unpack('Cversion/Ctype/nid/nlength/Cpadding/Creserved', $read(8));
			$body = $header['length'] ? $read($header['length']) : '';
			if ($header['padding']) $read($header['padding']);
			if ($header['type'] === 6) $output .= $body;
			if ($header['type'] === 3) break;
		}
		return json_decode(explode("\r\n\r\n", $output, 2)[1] ?? '', true, 16, JSON_THROW_ON_ERROR);
	}
	finally { fclose($connection); }
}

$fpm = '/usr/local/sbin/php-fpm';
if (!is_executable($fpm) || trim((string)shell_exec('id -u')) !== '0')
{
	echo "SKIP FPM integration: requires a root PHP-FPM Linux container.\n";
	exit;
}
$root = sys_get_temp_dir() . '/hs-fpm-' . bin2hex(random_bytes(6));
mkdir($root, 0755);
$process = null;
try
{
	foreach (array('logs', '.cfg') as $directory)
	{
		mkdir($root . '/' . $directory, 0700);
		fpmAssert(chown($root . '/' . $directory, 'www-data'), 'Could not provision application ownership');
	}
	$socket = $root . '/fpm.sock';
	$config = $root . '/fpm.conf';
	file_put_contents($config, "[global]\nerror_log = $root/fpm.log\ndaemonize = no\n[probe]\nuser = www-data\ngroup = www-data\nlisten = $socket\npm = static\npm.max_children = 1\n");
	$process = proc_open(array($fpm, '-F', '-y', $config), array(
		0 => array('file', '/dev/null', 'r'), 1 => array('file', $root . '/fpm.log', 'a'), 2 => array('file', $root . '/fpm.log', 'a'),
	), $pipes);
	fpmAssert(is_resource($process), 'Could not start isolated FPM pool');
	for ($i = 0; $i < 100 && !file_exists($socket); $i++) usleep(20000);
	fpmAssert(file_exists($socket), 'Isolated FPM pool did not start');
	for ($i = 0; $i < 4; $i++)
	{
		$result = $i % 2 === 0 ? fpmStorageProbe($root) : fpmRequest($socket, $root);
		fpmAssert($result['sapi'] === ($i % 2 === 0 ? 'cli' : 'fpm-fcgi'), 'Wrong writer SAPI');
		foreach (array('log', 'metrics', 'cron') as $field) fpmAssert($result[$field] === true, 'CLI/FPM ' . $field . ' failed');
		if ($i < 3) file_put_contents($root . '/logs/metrics.ndjson', str_repeat("{}\n", 349525));
	}
	fpmAssert(is_file($root . '/logs/events.ndjson.1') && is_file($root . '/logs/metrics.ndjson.1'), 'CLI/FPM rotation was not exercised');
	echo "Actual root CLI/www-data PHP-FPM append, rotation and cron state passed.\n";
}
finally
{
	if (is_resource($process)) { proc_terminate($process); proc_close($process); }
	MetricRegistry::reset();
	$entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
	foreach ($entries as $entry)
	{
		if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname());
		else unlink($entry->getPathname());
	}
	rmdir($root);
}
