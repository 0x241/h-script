<?php

namespace HScript\Security;

/** Stateless shared-secret gate for the scheduler HTTP endpoint. */
final class CronAuthentication
{
	public static function authorized(array $server, mixed $configuredToken): bool
	{
		if (($server['REQUEST_METHOD'] ?? '') !== 'POST'
			|| !is_string($configuredToken)
			|| strlen($configuredToken) < 32)
			return false;
		$provided = $server['HTTP_X_HSCRIPT_CRON_TOKEN'] ?? null;
		return is_string($provided) && hash_equals($configuredToken, $provided);
	}
}
