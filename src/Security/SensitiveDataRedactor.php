<?php

namespace HScript\Security;

/**
 * Removes credentials and authentication material before diagnostic output.
 */
final class SensitiveDataRedactor
{
	private const REDACTED = '[REDACTED]';
	private const SENSITIVE_KEY = '/(?:authorization|cookie|credential|hmac|pass(?:word)?|private.?key|secret|signature|sign2?|token|api.?key|hash|raw.?body|(?:^|_)key$)/i';

	public static function redact(mixed $value, string $key = ''): mixed
	{
		if ($key !== '' && preg_match(self::SENSITIVE_KEY, $key))
			return self::REDACTED;
		if (!is_array($value))
			return $value;

		$redacted = array();
		foreach ($value as $childKey => $childValue)
			$redacted[$childKey] = self::redact($childValue, (string)$childKey);
		return $redacted;
	}
}
