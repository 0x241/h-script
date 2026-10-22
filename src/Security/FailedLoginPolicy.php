<?php

namespace HScript\Security;

use InvalidArgumentException;

/** Account configuration is distinct from the consecutive-attempt counter. */
final class FailedLoginPolicy
{
	public const MINIMUM = 1;
	public const MAXIMUM = 100;

	/** Zero or a blank form value inherits the global policy; SQL stores NULL. */
	public static function parse(mixed $value): ?int
	{
		if ($value === null || $value === '') return null;
		if (!is_int($value) && !is_string($value)) throw new InvalidArgumentException('login_threshold_wrong');
		$value = trim((string)$value);
		if ($value === '') return null;
		if ($value === '0') return null;
		if (!preg_match('/^[1-9][0-9]{0,2}$/D', $value)) throw new InvalidArgumentException('login_threshold_wrong');
		$threshold = (int)$value;
		if ($threshold < self::MINIMUM || $threshold > self::MAXIMUM) throw new InvalidArgumentException('login_threshold_wrong');
		return $threshold;
	}

	/** A personal setting can strengthen a global setting, or opt in when it is disabled. */
	public static function effective(mixed $global, mixed $personal): int
	{
		$global = max(0, (int)$global);
		$personal = self::parse($personal);
		return $personal === null ? $global : ($global > 0 ? min($global, $personal) : $personal);
	}

	public static function needsConfirmation(mixed $global, mixed $personal, int $failures): bool
	{
		$threshold = self::effective($global, $personal);
		return $threshold > 0 && $failures >= $threshold;
	}

	/** The legacy adapter converts numeric NULL to zero; use a validated SQL assignment. */
	public static function assignment(?int $threshold): string
	{
		if ($threshold === null) return 'NULL';
		$parsed = self::parse($threshold);
		if ($parsed === null) throw new InvalidArgumentException('login_threshold_wrong');
		return (string)$parsed;
	}

	public static function describe(mixed $global, mixed $personal): array
	{
		return array('global' => max(0, (int)$global), 'effective' => self::effective($global, $personal), 'minimum' => self::MINIMUM, 'maximum' => self::MAXIMUM);
	}
}
