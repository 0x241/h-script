<?php

namespace HScript\Observability;

use HScript\Security\SensitiveDataRedactor;
use Throwable;

/** Writes a fixed, bounded NDJSON event schema and never interrupts callers. */
final class StructuredLogger
{
	private const SEVERITIES = array('debug', 'info', 'notice', 'warning', 'error', 'critical');
	private const OUTCOMES = array('started', 'success', 'failure', 'degraded', 'blocked', 'noop');
	private const CONTEXT_KEYS = array(
		'attempt', 'cache_state', 'check', 'delivery', 'error_class', 'gateway', 'job_type',
		'gateway_operation', 'http_status', 'method_class', 'owner', 'queue_state',
		'reason', 'runbook', 'state', 'surface', 'suppression_seconds', 'transport_status',
	);
	private static ?self $default = null;

	public function __construct(private string $path, private int $maximumBytes = 20971520) {}

	public static function event(
		string $severity,
		string $component,
		string $eventCode,
		string $outcome,
		int $durationMs = 0,
		string $resourceId = '',
		array $context = array()
	): bool {
		return self::default()->write($severity, $component, $eventCode, $outcome, $durationMs, $resourceId, $context);
	}

	public function write(
		string $severity,
		string $component,
		string $eventCode,
		string $outcome,
		int $durationMs = 0,
		string $resourceId = '',
		array $context = array()
	): bool {
		try
		{
			$severity = in_array($severity, self::SEVERITIES, true) ? $severity : 'error';
			$outcome = in_array($outcome, self::OUTCOMES, true) ? $outcome : 'failure';
			$record = array(
				'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
				'severity' => $severity,
				'environment' => self::environment(),
				'component' => self::token($component, 'application'),
				'event_code' => self::token($eventCode, 'invalid_event'),
				'correlation_id' => CorrelationContext::current(),
				'actor_class' => CorrelationContext::actorClass(),
				'resource_id' => preg_match('/^[a-z][a-z0-9_.:-]{0,95}$/', $resourceId) ? $resourceId : '',
				'duration_ms' => max(0, min($durationMs, 86400000)),
				'outcome' => $outcome,
				'context' => self::safeContext($context),
			);
			$line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n";
			return $this->append($line);
		}
		catch (Throwable)
		{
			return false;
		}
	}

	public static function resourceId(string $type, string|int $identity): string
	{
		$type = self::token($type, 'resource');
		return $type . ':' . substr(hash('sha256', (string)$identity), 0, 24);
	}

	public static function reset(): void
	{
		self::$default = null;
	}

	private static function default(): self
	{
		if (self::$default instanceof self) return self::$default;
		$path = trim((string)(getenv('OBSERVABILITY_LOG_PATH') ?: ''));
		if (!str_starts_with($path, '/'))
			$path = dirname(__DIR__, 2) . '/logs/observability.ndjson';
		$maximum = filter_var(getenv('OBSERVABILITY_LOG_MAX_BYTES'), FILTER_VALIDATE_INT);
		$maximum = $maximum === false ? 20971520 : max(1048576, min((int)$maximum, 104857600));
		return self::$default = new self($path, $maximum);
	}

	private function append(string $line): bool
	{
		return NdjsonWriter::append($this->path, $line, $this->maximumBytes);
	}

	private static function safeContext(array $context): array
	{
		$context = SensitiveDataRedactor::redact($context);
		$result = array();
		foreach (array_slice($context, 0, 24, true) as $key => $value)
		{
			$key = strtolower((string)$key);
			if (!in_array($key, self::CONTEXT_KEYS, true)) continue;
			if (is_bool($value) || is_int($value)) $result[$key] = $value;
			elseif (is_float($value) && is_finite($value)) $result[$key] = $value;
			elseif (is_scalar($value))
				$result[$key] = substr(preg_replace('/[^A-Za-z0-9_.:#\/-]/', '_', (string)$value) ?? '', 0, 160);
		}
		return $result;
	}

	private static function environment(): string
	{
		$value = strtolower(trim((string)(getenv('APP_ENV') ?: 'production')));
		return preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $value) ? $value : 'production';
	}

	private static function token(string $value, string $fallback): string
	{
		$value = strtolower(trim($value));
		return preg_match('/^[a-z][a-z0-9_.-]{0,63}$/', $value) ? $value : $fallback;
	}
}
