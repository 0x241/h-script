<?php

namespace HScript\Security;

use RuntimeException;

/** Session-bound token for repeatable form and HTMX actions. */
final class SessionCsrf
{
	private const KEY = '_session_csrf';

	public static function token(): string
	{
		if (session_status() !== PHP_SESSION_ACTIVE)
			throw new RuntimeException('A session is required for CSRF protection');
		$token = $_SESSION[self::KEY] ?? null;
		if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token))
			$_SESSION[self::KEY] = $token = bin2hex(random_bytes(32));
		return $token;
	}

	public static function valid(mixed $submitted): bool
	{
		return is_string($submitted)
			&& (bool)preg_match('/^[a-f0-9]{64}$/D', $submitted)
			&& hash_equals(self::token(), $submitted);
	}
}
