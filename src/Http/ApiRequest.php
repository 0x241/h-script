<?php

namespace HScript\Http;

use InvalidArgumentException;

/**
 * Normalizes REST request methods, headers, JSON bodies, and query values.
 */
final class ApiRequest
{
	private const MAX_BODY_BYTES = 1048576;

	public static function method(): string
	{
		return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
	}

	public static function bearerToken(): ?string
	{
		$authorization = self::header('Authorization');
		if ($authorization === null || !preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $authorization, $matches))
			return null;
		return $matches[1];
	}

	public static function json(): array
	{
		$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
		if ($contentLength > self::MAX_BODY_BYTES)
			throw new InvalidArgumentException('request_too_large');

		$raw = file_get_contents('php://input');
		if ($raw === false || trim($raw) === '')
			return array();
		if (strlen($raw) > self::MAX_BODY_BYTES)
			throw new InvalidArgumentException('request_too_large');

		$contentType = strtolower(trim((string)self::header('Content-Type')));
		$contentType = trim(explode(';', $contentType, 2)[0]);
		if ($contentType !== 'application/json' && !str_ends_with($contentType, '+json'))
			throw new InvalidArgumentException('content_type_invalid');

		try
		{
			$data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
		}
		catch (\JsonException $e)
		{
			throw new InvalidArgumentException('json_invalid', 0, $e);
		}
		if (!is_array($data) || array_is_list($data))
			throw new InvalidArgumentException('json_object_required');
		return $data;
	}

	public static function queryInt(string $name, int $default, int $min, int $max): int
	{
		$value = $_GET[$name] ?? $default;
		if (is_array($value) || filter_var($value, FILTER_VALIDATE_INT) === false)
			throw new InvalidArgumentException('query_invalid:' . $name);
		$value = (int)$value;
		if ($value < $min || $value > $max)
			throw new InvalidArgumentException('query_invalid:' . $name);
		return $value;
	}

	public static function clientIp(): string
	{
		return ClientIp::resolve($_SERVER);
	}

	public static function header(string $name): ?string
	{
		$key = strtoupper(str_replace('-', '_', $name));
		$candidates = array(
			'HTTP_' . $key,
			$key,
		);
		if ($key === 'AUTHORIZATION')
			$candidates[] = 'REDIRECT_HTTP_AUTHORIZATION';
		foreach ($candidates as $candidate)
			if (isset($_SERVER[$candidate]) && !is_array($_SERVER[$candidate]))
				return trim((string)$_SERVER[$candidate]);
		return null;
	}
}
