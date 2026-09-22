<?php

namespace HScript\Observability;

/** Adds the response correlation header and records one bounded request result. */
final class HttpRequestObserver
{
	private static bool $registered = false;
	private static float $startedAt = 0.0;

	public static function begin(?array $server = null): string
	{
		$identifier = CorrelationContext::initializeHttp($server);
		if (!headers_sent()) header('X-Request-ID: ' . $identifier);
		if (!self::$registered)
		{
			self::$registered = true;
			self::$startedAt = microtime(true);
			register_shutdown_function([self::class, 'finish']);
		}
		return $identifier;
	}

	public static function finish(): void
	{
		$status = http_response_code();
		if ($status < 100) $status = 200;
		$fatal = error_get_last();
		if (is_array($fatal) && in_array((int)($fatal['type'] ?? 0), array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true))
			$status = max(500, $status);
		$outcome = $status >= 500 ? 'server_error' : ($status >= 400 ? 'client_error' : 'success');
		$surface = self::surface();
		$duration = max(0, (int)round((microtime(true) - self::$startedAt) * 1000));
		MetricRegistry::increment('http_requests_total', array('surface' => $surface, 'outcome' => $outcome));
		MetricRegistry::observe('http_request_duration_ms', (float)$duration, array('surface' => $surface, 'outcome' => $outcome));
		StructuredLogger::event(
			$outcome === 'server_error' ? 'error' : ($outcome === 'client_error' ? 'notice' : 'info'),
			'http', 'http_request_completed', $outcome === 'success' ? 'success' : 'failure',
			$duration, '', array(
				'surface' => $surface,
				'http_status' => $status,
				'method_class' => self::methodClass(),
			)
		);
		MetricRegistry::flush();
	}

	private static function surface(): string
	{
		global $_GS;
		if (!empty($_GS['is_api'])) return 'api';
		if (strtolower((string)($_SERVER['HTTP_HX_REQUEST'] ?? '')) === 'true') return 'htmx';
		$module = (string)($_GS['module'] ?? '');
		if ($module === 'cron') return 'cron';
		if ($module === '_config') return 'configurator';
		return 'web';
	}

	private static function methodClass(): string
	{
		$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
		return in_array($method, array('GET', 'HEAD', 'OPTIONS'), true) ? 'read' : 'write';
	}
}
