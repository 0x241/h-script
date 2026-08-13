<?php

namespace HScript\Telemetry;

/**
 * Restricts central collector features to an explicitly configured domain.
 */
final class CollectorMode
{
	/**
	 * Checks both the collector flag and normalized request domain.
	 */
	public static function enabled(array $config, string $requestDomain): bool
	{
		$flag = strtolower(trim((string)($config['telemetry_collector_enabled'] ?? '0')));
		if (!in_array($flag, array('1', 'true', 'yes', 'on'), true))
			return false;

		$expectedDomain = self::normalizeDomain(
			(string)($config['telemetry_collector_domain'] ?? '')
		);
		$actualDomain = self::normalizeDomain($requestDomain);
		return $expectedDomain !== '' && $actualDomain === $expectedDomain;
	}

	public static function expectedDomain(array $config): string
	{
		return self::normalizeDomain(
			(string)($config['telemetry_collector_domain'] ?? '')
		);
	}

	private static function normalizeDomain(string $domain): string
	{
		$domain = strtolower(trim($domain));
		if (str_contains($domain, '://'))
			$domain = (string)(parse_url($domain, PHP_URL_HOST) ?: '');
		$domain = preg_replace('/:\d+$/', '', $domain) ?? '';
		$domain = preg_replace('/^www\./', '', $domain) ?? '';
		$domain = rtrim($domain, '.');
		if ($domain === '' || strlen($domain) > 253 || !preg_match('/^[a-z0-9.-]+$/', $domain))
			return '';
		return $domain;
	}
}
