<?php

namespace HScript;

/**
 * Exposes canonical product metadata shared by HTTP integrations.
 */
final class Application
{
	public const NAME = 'H-Script';
	public const VERSION = '1.0.0';
	public const LICENSE = 'MIT';

	public static function userAgent(): string
	{
		return self::NAME . '/' . self::VERSION . ' (+curl)';
	}
}
