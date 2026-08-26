<?php

namespace HScript;

/**
 * Exposes canonical product metadata shared by HTTP integrations.
 */
final class Application
{
	public const NAME = 'H-Script';
	public const LICENSE = 'MIT';
	private const UNKNOWN_VERSION = '0.0.0';

	private static ?string $version = null;

	public static function version(): string
	{
		if (self::$version !== null)
			return self::$version;

		$versionFile = dirname(__DIR__) . '/VERSION';
		if (!is_readable($versionFile))
			return self::$version = self::UNKNOWN_VERSION;

		$version = trim((string)file_get_contents($versionFile));
		if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?$/', $version))
			return self::$version = self::UNKNOWN_VERSION;

		return self::$version = $version;
	}

	public static function userAgent(): string
	{
		return self::NAME . '/' . self::version() . ' (+curl)';
	}
}
