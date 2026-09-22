<?php

namespace HScript\Observability;

use Throwable;

/** Aggregates a fixed low-cardinality metric catalog and flushes NDJSON fail-open. */
final class MetricRegistry
{
	private const SPECS = array(
		'http_requests_total' => array('counter', array('surface' => array('web', 'api', 'htmx', 'cron', 'configurator'), 'outcome' => array('success', 'client_error', 'server_error'))),
		'http_request_duration_ms' => array('histogram', array('surface' => array('web', 'api', 'htmx', 'cron', 'configurator'), 'outcome' => array('success', 'client_error', 'server_error'))),
		'session_failures_total' => array('counter', array('stage' => array('start', 'regenerate'))),
		'db_queries_total' => array('counter', array('outcome' => array('success', 'failure'))),
		'db_query_duration_ms' => array('histogram', array('outcome' => array('success', 'failure'))),
		'queue_depth' => array('gauge', array('state' => array('pending', 'processing', 'failed'))),
		'queue_oldest_age_seconds' => array('gauge', array('state' => array('pending'))),
		'queue_retries_total' => array('counter', array('reason' => array('handler_failure', 'stale'))),
		'cron_lag_seconds' => array('gauge', array('state' => array('ok', 'stale', 'missing'))),
		'telemetry_requests_total' => array('counter', array('operation' => array('register', 'report'), 'outcome' => array('success', 'failure'))),
		'dns_backlog' => array('gauge', array()),
		'gateway_attempts_total' => array('counter', array('operation' => array('deposit', 'withdrawal', 'callback', 'balance'), 'outcome' => array('success', 'failure'))),
		'backup_age_seconds' => array('gauge', array('target' => array('cms', 'collector', 'service_tokens'))),
		'restore_drill_age_seconds' => array('gauge', array('target' => array('cms', 'collector', 'service_tokens'))),
		'migration_required' => array('gauge', array('state' => array('ready', 'required'))),
	);
	private static array $buffer = array();
	private static bool $registered = false;

	public static function increment(string $metric, array $labels = array(), float $amount = 1.0): bool
	{
		return self::record($metric, 'counter', $labels, max(0.0, $amount));
	}

	public static function gauge(string $metric, float $value, array $labels = array()): bool
	{
		return self::record($metric, 'gauge', $labels, max(0.0, $value));
	}

	public static function observe(string $metric, float $value, array $labels = array()): bool
	{
		return self::record($metric, 'histogram', $labels, max(0.0, $value));
	}

	public static function flush(): bool
	{
		if (!self::$buffer) return true;
		$records = self::$buffer;
		self::$buffer = array();
		try
		{
			$path = trim((string)(getenv('OBSERVABILITY_METRICS_PATH') ?: ''));
			if (!str_starts_with($path, '/'))
				$path = dirname(__DIR__, 2) . '/logs/metrics.ndjson';
			if (!is_dir(dirname($path)) || !is_writable(dirname($path)) || is_link($path)) return false;
			$maximum = filter_var(getenv('OBSERVABILITY_METRICS_MAX_BYTES'), FILTER_VALIDATE_INT);
			$maximum = $maximum === false ? 20971520 : max(1048576, min((int)$maximum, 104857600));
			$lines = '';
			foreach ($records as $record)
			{
				$record = array('timestamp' => gmdate('Y-m-d\TH:i:s\Z')) + $record;
				$lines .= json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
			}
			return NdjsonWriter::append($path, $lines, $maximum);
		}
		catch (Throwable)
		{
			return false;
		}
	}

	public static function reset(): void
	{
		self::$buffer = array();
		self::$registered = false;
	}

	private static function record(string $metric, string $type, array $labels, float $value): bool
	{
		$spec = self::SPECS[$metric] ?? null;
		if (!is_array($spec) || $spec[0] !== $type || !self::validLabels($labels, $spec[1]) || !is_finite($value))
			return false;
		ksort($labels);
		$key = $metric . "\0" . json_encode($labels, JSON_UNESCAPED_SLASHES);
		if (!isset(self::$buffer[$key]))
		{
			self::$buffer[$key] = array('metric' => $metric, 'type' => $type, 'labels' => $labels);
			if ($type === 'histogram') self::$buffer[$key] += array('count' => 0, 'sum' => 0.0, 'max' => 0.0);
			else self::$buffer[$key]['value'] = 0.0;
		}
		if ($type === 'counter') self::$buffer[$key]['value'] += $value;
		elseif ($type === 'gauge') self::$buffer[$key]['value'] = $value;
		else
		{
			self::$buffer[$key]['count']++;
			self::$buffer[$key]['sum'] += $value;
			self::$buffer[$key]['max'] = max(self::$buffer[$key]['max'], $value);
		}
		if (!self::$registered)
		{
			self::$registered = true;
			register_shutdown_function([self::class, 'flush']);
		}
		return true;
	}

	private static function validLabels(array $labels, array $allowed): bool
	{
		$providedKeys = array_keys($labels);
		$allowedKeys = array_keys($allowed);
		sort($providedKeys);
		sort($allowedKeys);
		if ($providedKeys !== $allowedKeys) return false;
		foreach ($allowed as $name => $values)
			if (!is_string($labels[$name] ?? null) || !in_array($labels[$name], $values, true)) return false;
		return true;
	}
}
