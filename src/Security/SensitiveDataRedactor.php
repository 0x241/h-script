<?php

namespace HScript\Security;

/**
 * Removes credentials and authentication material before diagnostic output.
 */
final class SensitiveDataRedactor
{
	private const REDACTED = '[REDACTED]';
	private const SENSITIVE_KEY = '/(?:authorization|bearer|callback|cookie|credential|destination|dsn|gateway.?key|merchant.?key|hmac|pass(?:word)?|payout|pin|private.?key|raw.?body|secret(?:.?answer)?|session|signature|sign2?|token|api.?key|wallet|address|payload|(?:^|_)key$|hash)/i';

	public static function redact(mixed $value, string $key = ''): mixed
	{
		if ($key !== '' && preg_match(self::SENSITIVE_KEY, $key))
			return self::REDACTED;
		if (is_string($value))
			return self::redactText($value);
		if (!is_array($value)) return $value;

		$redacted = array();
		foreach ($value as $childKey => $childValue)
			$redacted[$childKey] = self::redact($childValue, (string)$childKey);
		return $redacted;
	}

	public static function redactText(string $value): string
	{
		$patterns = array(
			'/\bBearer\s+[A-Za-z0-9._~+\/=:-]{8,}/i',
			'/\b(?:hsi|hst|hs3|hs)_[a-f0-9]{32,128}\b/i',
			'#\b([a-z][a-z0-9+.-]*://)[^\s/@:]+:[^\s/@]+@#i',
			'/\b(password|passwd|pwd|secret(?:[_-]?answer)?|token|api[_-]?key|gateway[_-]?key|merchant[_-]?key|pin|payout[_-]?destination|callback[_-]?payload|dsn|session|phpsessid)=([^\s;&]+)/i',
		);
		$replacements = array(
			self::REDACTED,
			self::REDACTED,
			'$1[REDACTED]@',
			'$1=' . self::REDACTED,
		);
		return preg_replace($patterns, $replacements, $value) ?? self::REDACTED;
	}
}
