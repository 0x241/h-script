<?php

namespace HScript\Security;

/** URL policy for links, rich-text images, and payment form actions. */
final class SafeUrl
{
	private const MAX_BYTES = 2048;

	public static function href(string $url): bool
	{
		return self::allowed($url, array('http', 'https', 'mailto'), true);
	}

	public static function image(string $url): bool
	{
		return self::allowed($url, array('http', 'https'), true);
	}

	public static function paymentAction(string $url): bool
	{
		return self::allowed($url, array('https'), true);
	}

	public static function redirect(string $url): bool
	{
		return self::allowed($url, array('http', 'https'), true);
	}

	private static function allowed(string $url, array $schemes, bool $relative): bool
	{
		if ($url === '' || strlen($url) > self::MAX_BYTES || $url !== trim($url)
			|| preg_match('/[\x00-\x20\x7f\\\\]/', $url))
			return false;

		// DOM attributes are entity-decoded; check the original as well for callers
		// that pass a string before HTML parsing.
		$canonical = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$decoded = $canonical;
		for ($i = 0; $i < 3; $i++)
		{
			if ($decoded === '' || strlen($decoded) > self::MAX_BYTES
				|| preg_match('/[\x00-\x1f\x7f\\\\]/', $decoded)
				|| str_starts_with($decoded, '//'))
				return false;
			$next = rawurldecode($decoded);
			if ($next === $decoded)
				break;
			$decoded = $next;
		}
		$originalParts = parse_url($canonical);
		$parts = parse_url($decoded);
		if (!is_array($originalParts) || !is_array($parts)
			|| isset($parts['user']) || isset($parts['pass'])
			|| strtolower((string)($originalParts['scheme'] ?? '')) !== strtolower((string)($parts['scheme'] ?? ''))
			|| strtolower((string)($originalParts['host'] ?? '')) !== strtolower((string)($parts['host'] ?? '')))
			return false;
		$scheme = strtolower((string)($parts['scheme'] ?? ''));
		if ($scheme !== '')
		{
			if (!in_array($scheme, $schemes, true))
				return false;
			if ($scheme === 'mailto')
				return !str_contains($decoded, '//') && !empty($parts['path']);
			return !empty($parts['host']);
		}
		return $relative && !str_starts_with($decoded, '//') && !str_starts_with($decoded, '\\');
	}
}
