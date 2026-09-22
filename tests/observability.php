<?php

use HScript\Cache\RedisCache;
use HScript\Database\Connection;
use HScript\Http\ApiResponse;
use HScript\Observability\AlertManager;
use HScript\Observability\CorrelationContext;
use HScript\Observability\HealthService;
use HScript\Observability\MetricRegistry;
use HScript\Observability\OperationalStateRepository;
use HScript\Observability\StructuredLogger;
use HScript\Payment\PaymentGatewayInterface;
use HScript\Payment\PaymentManager;
use HScript\Security\SensitiveDataRedactor;
use HScript\Telemetry\TelemetryClient;

require dirname(__DIR__) . '/vendor/autoload.php';

function observabilityAssert(bool $condition, string $message): void
{
	if (!$condition) throw new RuntimeException($message);
}

function observabilityRemoveTree(string $path): void
{
	if (!is_dir($path) || is_link($path)) return;
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ($iterator as $entry)
	{
		if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname());
		else unlink($entry->getPathname());
	}
	rmdir($path);
}

final class ObservabilityConnection extends Connection
{
	public function query($query, $values = null)
	{
		return (string)$query;
	}

	public function select($table, $fields = '*', $filter = '', $values = null, $order = '', $limit = '', $group = '')
	{
		return 'configuration';
	}

	public function fetch1($query)
	{
		if ($query === 'SELECT 1') return 1;
		if ($query === 'configuration') return '0';
		return 0;
	}

	public function fetchRows($query, $singleField = false)
	{
		return array();
	}
}

final class ObservabilityGateway implements PaymentGatewayInterface
{
	public function getName(): string { return 'Test'; }
	public function getCurrencyId(): string { return 'TEST'; }
	public function getFormFields(array $operation): string { return ''; }
	public function processDeposit(array $params): array { return array('redirect' => 'https://gateway.invalid/'); }
	public function processWithdrawal(array $params): array { return array('result' => 'OK'); }
	public function handleCallback(array $request): array { return array('correct' => true); }
	public function validateConfig(array $config): bool { return true; }
}

$root = sys_get_temp_dir() . '/hscript-observability-' . bin2hex(random_bytes(6));
mkdir($root . '/logs', 0700, true);
mkdir($root . '/.cfg', 0700, true);
mkdir($root . '/resources', 0700, true);
mkdir($root . '/vendor', 0700, true);
file_put_contents($root . '/vendor/autoload.php', "<?php\n");
file_put_contents($root . '/VERSION', "1.0.4\n");
copy(dirname(__DIR__) . '/resources/observability-alerts.json', $root . '/resources/observability-alerts.json');

$oldEnvironment = getenv('APP_ENV');
$oldLogPath = getenv('OBSERVABILITY_LOG_PATH');
$oldMetricsPath = getenv('OBSERVABILITY_METRICS_PATH');
$oldAlertCommand = getenv('OBSERVABILITY_ALERT_COMMAND');
$oldRedisEnabled = getenv('REDIS_ENABLED');

try
{
	putenv('APP_ENV=test');
	putenv('OBSERVABILITY_LOG_PATH=' . $root . '/logs/observability.ndjson');
	putenv('OBSERVABILITY_METRICS_PATH=' . $root . '/logs/metrics.ndjson');
	StructuredLogger::reset();
	MetricRegistry::reset();

	$accepted = CorrelationContext::initializeHttp(array(
		'REMOTE_ADDR' => '10.1.2.3',
		'HTTP_X_REQUEST_ID' => str_repeat('1', 32),
	), '10.0.0.0/8');
	observabilityAssert($accepted === str_repeat('1', 32), 'Trusted request ID was not accepted');
	$generated = CorrelationContext::initializeHttp(array(
		'REMOTE_ADDR' => '203.0.113.10',
		'HTTP_X_REQUEST_ID' => str_repeat('a', 32),
	), '10.0.0.0/8');
	observabilityAssert($generated !== str_repeat('a', 32) && preg_match('/^[a-f0-9]{32}$/', $generated) === 1, 'Untrusted request ID was accepted');
	$malformed = CorrelationContext::initializeHttp(array(
		'REMOTE_ADDR' => '10.1.2.3',
		'HTTP_X_REQUEST_ID' => "bad request\nvalue",
	), '10.0.0.0/8');
	observabilityAssert(preg_match('/^[a-f0-9]{32}$/', $malformed) === 1, 'Malformed trusted request ID was accepted');

	$correlation = CorrelationContext::adopt(str_repeat('2', 32), 'authenticated');
	$apiMeta = (new ReflectionMethod(ApiResponse::class, 'meta'))->invoke(null);
	observabilityAssert(($apiMeta['correlation_id'] ?? '') === $correlation, 'API response metadata lost correlation ID');
	$secret = 'super-secret-bearer-value';
	observabilityAssert(StructuredLogger::event(
		'info', 'test', 'schema_checked', 'success', 12,
		StructuredLogger::resourceId('user', 987654),
		array('gateway' => 'AC', 'reason' => 'Bearer ' . $secret, 'user_id' => 987654)
	), 'Structured event could not be written');
	$event = json_decode(trim((string)file_get_contents($root . '/logs/observability.ndjson')), true, 16, JSON_THROW_ON_ERROR);
	observabilityAssert(array_keys($event) === array(
		'timestamp', 'severity', 'environment', 'component', 'event_code', 'correlation_id',
		'actor_class', 'resource_id', 'duration_ms', 'outcome', 'context',
	), 'Structured event schema changed');
	observabilityAssert($event['correlation_id'] === $correlation && $event['actor_class'] === 'authenticated', 'Event correlation context was lost');
	observabilityAssert(str_contains($event['resource_id'], 'user:') && !str_contains(json_encode($event), '987654'), 'Raw resource identifier was logged');
	observabilityAssert(($event['context']['gateway'] ?? '') === 'AC' && !isset($event['context']['user_id']), 'Context allowlist is incorrect');
	observabilityAssert(!str_contains(json_encode($event), $secret), 'Bearer credential was logged');

	$sensitive = array(
		'cookie' => 'cookie-value',
		'authorization' => 'Bearer auth-value-123456',
		'service_token' => 'hst_' . str_repeat('a', 64),
		'installation_token' => 'hsi_' . str_repeat('b', 64),
		'pin' => '1842',
		'secret_answer' => 'rosebud',
		'gateway_key' => 'gateway-secret',
		'payout_destination' => 'wallet-secret',
		'callback_payload' => 'signed-body',
		'dsn' => 'mysql://user:password@database/private',
	);
	$redacted = SensitiveDataRedactor::redact($sensitive);
	foreach ($redacted as $key => $value)
		observabilityAssert($value === '[REDACTED]', 'Sensitive field was not redacted: ' . $key);
	$text = 'Bearer auth-value-123456 pin=1842 gateway_key=gateway-secret dsn=mysql://user:password@database/private';
	$cleanText = SensitiveDataRedactor::redactText($text);
	foreach (array('auth-value-123456', '1842', 'gateway-secret', 'user:password') as $value)
		observabilityAssert(!str_contains($cleanText, $value), 'Sensitive text value was not redacted');

	observabilityAssert(MetricRegistry::increment('http_requests_total', array('outcome' => 'success', 'surface' => 'api')), 'Valid counter was rejected');
	observabilityAssert(MetricRegistry::observe('http_request_duration_ms', 14.5, array('surface' => 'api', 'outcome' => 'success')), 'Valid histogram was rejected');
	observabilityAssert(!MetricRegistry::increment('http_requests_total', array('surface' => 'api', 'outcome' => 'success', 'user_id' => '7')), 'High-cardinality label was accepted');
	observabilityAssert(!MetricRegistry::increment('http_requests_total', array('surface' => 'tenant.example', 'outcome' => 'success')), 'Unbounded label value was accepted');
	observabilityAssert(MetricRegistry::flush(), 'Metrics could not be flushed');
	foreach (file($root . '/logs/metrics.ndjson', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $line)
	{
		$metric = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
		foreach (array_keys($metric['labels'] ?? array()) as $label)
			observabilityAssert(!in_array($label, array('domain', 'uuid', 'user_id', 'token'), true), 'Forbidden metric label was emitted');
	}

	$failedLogger = new StructuredLogger('/dev/full');
	observabilityAssert(!$failedLogger->write('info', 'test', 'disk_failed', 'failure'), 'Log disk failure was not fail-open');
	MetricRegistry::reset();
	putenv('OBSERVABILITY_METRICS_PATH=/dev/full');
	observabilityAssert(MetricRegistry::gauge('dns_backlog', 1.0), 'Metric buffering failed before backend outage');
	observabilityAssert(!MetricRegistry::flush(), 'Metrics backend outage did not fail open');
	putenv('OBSERVABILITY_LOG_PATH=/dev/full');
	StructuredLogger::reset();
	$failOpenManager = new PaymentManager(new Connection());
	$failOpenManager->register('TEST', new ObservabilityGateway());
	observabilityAssert(
		$failOpenManager->processDeposit('TEST', array('gateway_key' => 'never-log-this')) !== array(),
		'Observability outage interrupted a financial operation'
	);
	observabilityAssert(!MetricRegistry::flush(), 'Gateway metrics outage was not contained');
	putenv('OBSERVABILITY_LOG_PATH=' . $root . '/logs/observability.ndjson');
	putenv('OBSERVABILITY_METRICS_PATH=' . $root . '/logs/metrics.ndjson');
	StructuredLogger::reset();
	MetricRegistry::reset();

	putenv('OBSERVABILITY_ALERT_COMMAND=/bin/false');
	$alerts = new AlertManager($root);
	observabilityAssert($alerts->emit('database_unavailable') === 'emitted', 'Alert was not emitted');
	observabilityAssert($alerts->emit('database_unavailable') === 'suppressed', 'Alert suppression failed');
	$alertFiles = glob($root . '/.cfg/observability/alerts/*.json') ?: array();
	observabilityAssert(count($alertFiles) === 1, 'Alert evidence is missing or duplicated');
	$alert = json_decode((string)file_get_contents($alertFiles[0]), true, 16, JSON_THROW_ON_ERROR);
	observabilityAssert(($alert['runbook'] ?? '') === 'docs/observability.md#database-unavailable', 'Alert does not link to its runbook');
	$eventLog = (string)file_get_contents($root . '/logs/observability.ndjson');
	observabilityAssert(str_contains($eventLog, 'notifier_unavailable'), 'Notifier failure evidence was not recorded');

	$health = new HealthService($root);
	observabilityAssert(($health->process()['status'] ?? '') === 'healthy', 'Process health failed');
	putenv('REDIS_ENABLED=0');
	$readiness = $health->readiness(new ObservabilityConnection(), new RedisCache(enabled: false));
	observabilityAssert(($readiness['status'] ?? '') === 'ready', 'Healthy dependencies were not ready');
	observabilityAssert(!str_contains(json_encode($readiness), 'mysql://'), 'Readiness disclosed connection details');
	$state = new OperationalStateRepository($root);
	observabilityAssert($state->recordCron('success') && ($state->cron()['status'] ?? '') === 'success', 'Cron heartbeat was not persisted');

	$manager = new PaymentManager(new Connection());
	$manager->register('TEST', new ObservabilityGateway());
	observabilityAssert($manager->processDeposit('TEST', array('gateway_key' => 'never-log-this')) !== array(), 'Gateway operation changed during observation');

	$server = stream_socket_server('tcp://127.0.0.1:0', $socketError, $socketMessage);
	observabilityAssert(is_resource($server), 'Could not reserve telemetry test port');
	$address = stream_socket_get_name($server, false);
	fclose($server);
	$port = (int)substr(strrchr((string)$address, ':'), 1);
	$router = $root . '/telemetry-router.php';
	$headerPath = $root . '/telemetry-correlation.txt';
	file_put_contents($router, '<?php file_put_contents(' . var_export($headerPath, true) . ', (string)($_SERVER["HTTP_X_REQUEST_ID"] ?? "")); header("Content-Type: application/json"); echo "{\\"success\\":true,\\"data\\":{}}";');
	$descriptors = array(
		0 => array('file', '/dev/null', 'r'),
		1 => array('file', '/dev/null', 'a'),
		2 => array('file', '/dev/null', 'a'),
	);
	$process = proc_open(array(PHP_BINARY, '-S', '127.0.0.1:' . $port, $router), $descriptors, $pipes, $root);
	observabilityAssert(is_resource($process), 'Could not start telemetry test endpoint');
	try
	{
		$available = false;
		for ($attempt = 0; $attempt < 100; $attempt++)
		{
			$probe = fsockopen('127.0.0.1', $port, $probeError, $probeMessage, 0.05);
			if (is_resource($probe)) { fclose($probe); $available = true; break; }
			usleep(10000);
		}
		observabilityAssert($available, 'Telemetry test endpoint did not start');
		$result = (new TelemetryClient('http://127.0.0.1:' . $port))->request('POST', 'reports', array('ok' => true), 'hsi_' . str_repeat('c', 64));
		observabilityAssert(!empty($result['ok']), 'Telemetry request failed');
		observabilityAssert(trim((string)file_get_contents($headerPath)) === $correlation, 'Telemetry correlation header was not propagated');
	}
	finally
	{
		proc_terminate($process);
		proc_close($process);
	}

	$correlatedEvents = 0;
	foreach (file($root . '/logs/observability.ndjson', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $line)
	{
		$record = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
		if (($record['correlation_id'] ?? '') === $correlation) $correlatedEvents++;
		observabilityAssert(!str_contains($line, 'never-log-this') && !str_contains($line, str_repeat('c', 64)), 'Financial or telemetry secret was logged');
	}
	observabilityAssert($correlatedEvents >= 4, 'Correlation ID did not link gateway, telemetry and operational events');
}
finally
{
	foreach (array(
		'APP_ENV' => $oldEnvironment,
		'OBSERVABILITY_LOG_PATH' => $oldLogPath,
		'OBSERVABILITY_METRICS_PATH' => $oldMetricsPath,
		'OBSERVABILITY_ALERT_COMMAND' => $oldAlertCommand,
		'REDIS_ENABLED' => $oldRedisEnabled,
	) as $name => $value)
		putenv($value === false ? $name : $name . '=' . $value);
	StructuredLogger::reset();
	MetricRegistry::reset();
	CorrelationContext::reset();
	observabilityRemoveTree($root);
}

echo "Observability and correlation tests passed.\n";
