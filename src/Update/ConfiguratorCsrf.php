<?php

namespace HScript\Update;

use RuntimeException;

final class ConfiguratorCsrf
{
	private const SESSION_KEY = '_cfg_csrf';

	public static function token(): string
	{
		if (session_status() !== PHP_SESSION_ACTIVE)
			throw new RuntimeException('Configurator session is not active');
		$token = (string)($_SESSION[self::SESSION_KEY] ?? '');
		if (!preg_match('/^[a-f0-9]{64}$/', $token))
		{
			$token = bin2hex(random_bytes(32));
			$_SESSION[self::SESSION_KEY] = $token;
		}
		return $token;
	}

	public static function consume(mixed $submitted): void
	{
		self::validate($submitted);
		unset($_SESSION[self::SESSION_KEY]);
	}

	public static function validate(mixed $submitted): void
	{
		$known = self::token();
		$submitted = is_string($submitted) ? $submitted : '';
		if (!hash_equals($known, $submitted))
			throw new RuntimeException('Configurator CSRF token is invalid');
	}
}
