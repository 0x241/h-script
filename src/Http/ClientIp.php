<?php

namespace HScript\Http;

/**
 * Resolves client IP and HTTPS state while honoring only trusted proxies.
 */
final class ClientIp
{
	private const DEFAULT_TRUSTED_PROXY_CIDRS = '127.0.0.1/32,::1/128,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16';

	public static function resolve(?array $server = null, ?string $trustedProxyCidrs = null): string
	{
		$server = $server ?? $_SERVER;
		$remoteAddress = self::validIp($server['REMOTE_ADDR'] ?? null) ?? '0.0.0.0';
		if (!self::isTrustedProxy($remoteAddress, $trustedProxyCidrs))
			return $remoteAddress;

		$cloudflareAddress = self::validIp($server['HTTP_CF_CONNECTING_IP'] ?? null);
		if ($cloudflareAddress !== null)
			return $cloudflareAddress;

		$forwardedFor = isset($server['HTTP_X_FORWARDED_FOR']) && !is_array($server['HTTP_X_FORWARDED_FOR'])
			? (string)$server['HTTP_X_FORWARDED_FOR']
			: '';
		$chain = array();
		foreach (explode(',', $forwardedFor) as $candidate)
		{
			$ip = self::validIp($candidate);
			if ($ip !== null)
				$chain[] = $ip;
		}
		if ($chain)
		{
			$chain[] = $remoteAddress;
			for ($index = count($chain) - 1; $index >= 0; $index--)
				if (!self::isTrustedProxy($chain[$index], $trustedProxyCidrs))
					return $chain[$index];
			return $chain[0];
		}

		$realAddress = self::validIp($server['HTTP_X_REAL_IP'] ?? null);
		if ($realAddress !== null)
			return $realAddress;
		return $remoteAddress;
	}

	public static function isForwardedHttps(?array $server = null, ?string $trustedProxyCidrs = null): bool
	{
		$server = $server ?? $_SERVER;
		$remoteAddress = self::validIp($server['REMOTE_ADDR'] ?? null);
		if ($remoteAddress === null || !self::isTrustedProxy($remoteAddress, $trustedProxyCidrs))
			return false;

		$forwardedProto = isset($server['HTTP_X_FORWARDED_PROTO']) && !is_array($server['HTTP_X_FORWARDED_PROTO'])
			? strtolower(trim(explode(',', (string)$server['HTTP_X_FORWARDED_PROTO'], 2)[0]))
			: '';
		if ($forwardedProto !== '')
			return $forwardedProto === 'https';

		$forwardedSsl = isset($server['HTTP_X_FORWARDED_SSL']) && !is_array($server['HTTP_X_FORWARDED_SSL'])
			? strtolower(trim((string)$server['HTTP_X_FORWARDED_SSL']))
			: '';
		return in_array($forwardedSsl, array('1', 'true', 'yes', 'on'), true);
	}

	public static function isTrustedProxy(string $ip, ?string $trustedProxyCidrs = null): bool
	{
		$ip = self::validIp($ip);
		if ($ip === null)
			return false;

		$trustedProxyCidrs = trim((string)($trustedProxyCidrs ?? getenv('TRUSTED_PROXY_CIDRS')));
		if ($trustedProxyCidrs === '')
			$trustedProxyCidrs = self::DEFAULT_TRUSTED_PROXY_CIDRS;
		foreach (preg_split('/[\s,]+/', $trustedProxyCidrs, -1, PREG_SPLIT_NO_EMPTY) as $cidr)
			if (self::matchesCidr($ip, $cidr))
				return true;
		return false;
	}

	private static function validIp($value): ?string
	{
		if (!is_scalar($value))
			return null;
		$value = trim((string)$value);
		return filter_var($value, FILTER_VALIDATE_IP) !== false ? $value : null;
	}

	private static function matchesCidr(string $ip, string $cidr): bool
	{
		$parts = explode('/', trim($cidr), 2);
		$network = self::validIp($parts[0] ?? null);
		if ($network === null)
			return false;

		$ipBytes = inet_pton($ip);
		$networkBytes = inet_pton($network);
		if ($ipBytes === false || $networkBytes === false || strlen($ipBytes) !== strlen($networkBytes))
			return false;

		$maxBits = strlen($ipBytes) * 8;
		$prefixBits = isset($parts[1]) && $parts[1] !== '' ? filter_var($parts[1], FILTER_VALIDATE_INT) : $maxBits;
		if ($prefixBits === false || $prefixBits < 0 || $prefixBits > $maxBits)
			return false;

		$fullBytes = intdiv($prefixBits, 8);
		$remainingBits = $prefixBits % 8;
		if ($fullBytes > 0 && substr($ipBytes, 0, $fullBytes) !== substr($networkBytes, 0, $fullBytes))
			return false;
		if ($remainingBits === 0)
			return true;

		$mask = (0xff << (8 - $remainingBits)) & 0xff;
		return (ord($ipBytes[$fullBytes]) & $mask) === (ord($networkBytes[$fullBytes]) & $mask);
	}
}
