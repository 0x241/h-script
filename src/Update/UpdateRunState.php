<?php

namespace HScript\Update;

use InvalidArgumentException;

final class UpdateRunState
{
	public const PREFLIGHT = 'preflight';
	public const BACKUP = 'backup';
	public const PACKAGE = 'package';
	public const MIGRATION = 'migration';
	public const HEALTH = 'health';
	public const COMPLETED = 'completed';
	public const FAILED = 'failed';

	private const TRANSITIONS = array(
		self::PREFLIGHT => array(self::BACKUP, self::PACKAGE, self::FAILED),
		self::BACKUP => array(self::PACKAGE, self::FAILED),
		self::PACKAGE => array(self::MIGRATION, self::HEALTH, self::FAILED),
		self::MIGRATION => array(self::HEALTH, self::FAILED),
		self::HEALTH => array(self::COMPLETED, self::FAILED),
		self::COMPLETED => array(),
		self::FAILED => array(),
	);

	public static function all(): array
	{
		return array_keys(self::TRANSITIONS);
	}

	public static function requireValid(mixed $state): string
	{
		$state = is_string($state) ? trim($state) : '';
		if (!isset(self::TRANSITIONS[$state]))
			throw new InvalidArgumentException('Invalid update run state');
		return $state;
	}

	public static function assertTransition(string $from, string $to): void
	{
		$from = self::requireValid($from);
		$to = self::requireValid($to);
		if (!in_array($to, self::TRANSITIONS[$from], true))
			throw new InvalidArgumentException('Invalid update run transition: ' . $from . ' -> ' . $to);
	}

	public static function terminal(string $state): bool
	{
		return in_array(self::requireValid($state), array(self::COMPLETED, self::FAILED), true);
	}
}
