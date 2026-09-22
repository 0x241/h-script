<?php

namespace HScript\Telemetry;

use HScript\Application;
use HScript\Observability\CorrelationContext;
use HScript\Observability\MetricRegistry;
use HScript\Observability\StructuredLogger;
use JsonException;
use Throwable;

/**
 * Sends authenticated installation reports to the configured collector.
 *
 * Production endpoints must use HTTPS; local HTTP endpoints are accepted only
 * in development and test environments.
 */
final class TelemetryClient implements TelemetryClientInterface
{
	private string $endpoint;

	public function __construct(string $endpoint)
	{
		$this->endpoint = rtrim(trim($endpoint), '/');
	}

	public function request(string $method, string $path, array $payload, string $token): array
	{
		$startedAt = microtime(true);
		$operation = str_contains(strtolower($path), 'register') ? 'register' : 'report';
		if (!function_exists('curl_init'))
			return $this->observedFailure($operation, 'curl_unavailable', $startedAt);
		if (!$this->endpointAllowed())
			return $this->observedFailure($operation, 'endpoint_invalid', $startedAt);

		$handle = curl_init($this->endpoint . '/' . ltrim($path, '/'));
		if ($handle === false)
			return $this->observedFailure($operation, 'curl_init_failed', $startedAt);

		try
		{
			$body = json_encode(
				$payload,
				JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
			);
			curl_setopt_array($handle, array(
				CURLOPT_CUSTOMREQUEST => strtoupper($method),
				CURLOPT_POSTFIELDS => $body,
				CURLOPT_HTTPHEADER => array(
					'Accept: application/json',
					'Authorization: Bearer ' . $token,
					'Content-Type: application/json',
					'X-Request-ID: ' . CorrelationContext::current(),
				),
				CURLOPT_CONNECTTIMEOUT => 5,
				CURLOPT_TIMEOUT => 15,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_USERAGENT => Application::userAgent(),
			));
			if (defined('CURLOPT_PROTOCOLS'))
				curl_setopt($handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);

			$responseBody = curl_exec($handle);
			$status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
			if ($responseBody === false)
				return $this->observedFailure($operation, 'network_error', $startedAt, $status);

			$response = json_decode((string)$responseBody, true, 32, JSON_THROW_ON_ERROR);
			$ok = $status >= 200 && $status < 300 && is_array($response) && !empty($response['success']);
			$result = array(
				'ok' => $ok,
				'status' => $status,
				'error' => $ok ? '' : (string)($response['error']['code'] ?? 'remote_error'),
				'data' => is_array($response['data'] ?? null) ? $response['data'] : array(),
			);
			$this->observe($operation, $result, $startedAt);
			return $result;
		}
		catch (JsonException)
		{
			return $this->observedFailure($operation, 'response_invalid', $startedAt);
		}
		catch (Throwable)
		{
			return $this->observedFailure($operation, 'request_failed', $startedAt);
		}
		finally
		{
			curl_close($handle);
		}
	}

	private function endpointAllowed(): bool
	{
		$parts = parse_url($this->endpoint);
		if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host']))
			return false;
		$scheme = strtolower((string)$parts['scheme']);
		if ($scheme === 'https')
			return true;
		if ($scheme !== 'http')
			return false;

		$environment = strtolower((string)(getenv('APP_ENV') ?: 'production'));
		$host = strtolower((string)$parts['host']);
		return in_array($environment, array('dev', 'development', 'local', 'test'), true)
			&& ($host === 'localhost' || $host === '127.0.0.1' || str_ends_with($host, '.local'));
	}

	private function failure(string $error, int $status = 0): array
	{
		return array(
			'ok' => false,
			'status' => $status,
			'error' => $error,
			'data' => array(),
		);
	}

	private function observedFailure(string $operation, string $error, float $startedAt, int $status = 0): array
	{
		$result = $this->failure($error, $status);
		$this->observe($operation, $result, $startedAt);
		return $result;
	}

	private function observe(string $operation, array $result, float $startedAt): void
	{
		$outcome = !empty($result['ok']) ? 'success' : 'failure';
		$duration = max(0, (int)round((microtime(true) - $startedAt) * 1000));
		MetricRegistry::increment('telemetry_requests_total', array('operation' => $operation, 'outcome' => $outcome));
		StructuredLogger::event(
			$outcome === 'success' ? 'info' : 'warning', 'telemetry', 'telemetry_' . $operation . '_request',
			$outcome, $duration, '', array('transport_status' => (int)($result['status'] ?? 0))
		);
	}
}
