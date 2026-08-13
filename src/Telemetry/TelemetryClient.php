<?php

namespace HScript\Telemetry;

use HScript\Application;
use JsonException;
use Throwable;

/**
 * Sends authenticated installation reports to the configured collector.
 *
 * Production endpoints must use HTTPS; local HTTP endpoints are accepted only
 * in development and test environments.
 */
final class TelemetryClient
{
	private string $endpoint;

	public function __construct(string $endpoint)
	{
		$this->endpoint = rtrim(trim($endpoint), '/');
	}

	public function request(string $method, string $path, array $payload, string $token): array
	{
		if (!function_exists('curl_init'))
			return $this->failure('curl_unavailable');
		if (!$this->endpointAllowed())
			return $this->failure('endpoint_invalid');

		$handle = curl_init($this->endpoint . '/' . ltrim($path, '/'));
		if ($handle === false)
			return $this->failure('curl_init_failed');

		try
		{
			$body = json_encode(
				$payload,
				JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			);
			curl_setopt_array($handle, array(
				CURLOPT_CUSTOMREQUEST => strtoupper($method),
				CURLOPT_POSTFIELDS => $body,
				CURLOPT_HTTPHEADER => array(
					'Accept: application/json',
					'Authorization: Bearer ' . $token,
					'Content-Type: application/json',
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
				return $this->failure('network_error', $status);

			$response = json_decode((string)$responseBody, true, 32, JSON_THROW_ON_ERROR);
			$ok = $status >= 200 && $status < 300 && is_array($response) && !empty($response['success']);
			return array(
				'ok' => $ok,
				'status' => $status,
				'error' => $ok ? '' : (string)($response['error']['code'] ?? 'remote_error'),
				'data' => is_array($response['data'] ?? null) ? $response['data'] : array(),
			);
		}
		catch (JsonException)
		{
			return $this->failure('response_invalid');
		}
		catch (Throwable)
		{
			return $this->failure('request_failed');
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
}
