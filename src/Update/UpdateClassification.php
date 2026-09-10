<?php

namespace HScript\Update;

use InvalidArgumentException;

final class UpdateClassification
{
	public const CODE_ONLY = 'code-only';
	public const BACKUP_REQUIRED = 'backup-required';
	public const IRREVERSIBLE = 'irreversible';

	public static function all(): array
	{
		return array(
			self::CODE_ONLY,
			self::BACKUP_REQUIRED,
			self::IRREVERSIBLE,
		);
	}

	public static function requireValid(mixed $classification): string
	{
		$classification = is_string($classification) ? trim($classification) : '';
		if (!in_array($classification, self::all(), true))
			throw new InvalidArgumentException('Invalid update classification');
		return $classification;
	}

	public static function requiresBackup(string $classification): bool
	{
		return self::requireValid($classification) !== self::CODE_ONLY;
	}
}
