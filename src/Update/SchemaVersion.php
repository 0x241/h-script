<?php

namespace HScript\Update;

use InvalidArgumentException;

final class SchemaVersion
{
	public const UNKNOWN = '0.0.0';

	public static function isValid(string $version): bool
	{
		return (bool)preg_match(
			'/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?$/',
			$version
		);
	}

	public static function requireValid(mixed $version, string $field = 'schema version'): string
	{
		$version = is_string($version) ? trim($version) : '';
		if (!self::isValid($version))
			throw new InvalidArgumentException('Invalid ' . $field);
		return $version;
	}

	public static function compare(string $left, string $right): int
	{
		return version_compare(
			self::requireValid($left),
			self::requireValid($right)
		);
	}
}
