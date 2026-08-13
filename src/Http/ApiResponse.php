<?php

namespace HScript\Http;

/**
 * Emits the versioned JSON response envelope used by REST API v1.
 *
 * Every terminal response includes success, data, error, and meta keys and
 * exits immediately after writing the body.
 */
final class ApiResponse
{
	public const VERSION = '1.0';

	/**
	 * Sends a successful JSON response and terminates the request.
	 *
	 * @param mixed $data JSON-serializable response data.
	 * @param array<string, mixed> $meta Additional metadata merged into defaults.
	 * @return never
	 */
	public static function success($data = null, int $status = 200, array $meta = array()): void
	{
		self::send(array(
			'success' => true,
			'data' => $data,
			'error' => null,
			'meta' => array_merge(self::meta(), $meta),
		), $status);
	}

	/**
	 * Sends an error envelope and terminates the request.
	 *
	 * @param array<string, mixed> $details Optional machine-readable error context.
	 * @return never
	 */
	public static function error(string $code, string $message, int $status = 400, array $details = array()): void
	{
		$error = array(
			'code' => $code,
			'message' => $message,
		);
		if ($details)
			$error['details'] = $details;

		self::send(array(
			'success' => false,
			'data' => null,
			'error' => $error,
			'meta' => self::meta(),
		), $status);
	}

	/**
	 * Adds standard rate-limit headers before the response body is sent.
	 *
	 * @param int $reset Unix timestamp at which the current window resets.
	 */
	public static function setRateLimitHeaders(int $limit, int $remaining, int $reset): void
	{
		if (headers_sent())
			return;
		header('X-RateLimit-Limit: ' . max(0, $limit));
		header('X-RateLimit-Remaining: ' . max(0, $remaining));
		header('X-RateLimit-Reset: ' . max(0, $reset));
	}

	private static function meta(): array
	{
		return array(
			'timestamp' => time(),
			'version' => self::VERSION,
		);
	}

	private static function send(array $payload, int $status): void
	{
		http_response_code($status);
		if (!headers_sent())
		{
			header('Content-Type: application/json; charset=utf-8');
			header('Cache-Control: no-store');
		}

		$json = json_encode(
			$payload,
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
		);
		if ($json === false)
		{
			http_response_code(500);
			$json = '{"success":false,"data":null,"error":{"code":"encoding_error","message":"Response encoding failed"},"meta":{"timestamp":'
				. time() . ',"version":"' . self::VERSION . '"}}';
		}
		echo $json;
		exit;
	}
}
