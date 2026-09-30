<?php

namespace HScript\Http;

/** Builds a per-response CSP candidate while the current policy remains enforced. */
final class ContentSecurityPolicy
{
	private static ?string $nonce = null;

	public static function beginRequest(): string
	{
		return self::$nonce = base64_encode(random_bytes(18));
	}

	public static function nonce(): string
	{
		return self::$nonce ?? self::beginRequest();
	}

	public static function surface(string $module, array $route = array()): string
	{
		if ($module === '_config') return 'configurator';
		if (str_starts_with($module, 'api/v1/') || str_contains((string)($route['audience'] ?? ''), 'technical')
			|| in_array($module, array('ajax', 'cron', 'robots', 'sitemap', 'balance/status'), true))
			return 'technical';
		if (in_array($module, array('account/login', 'account/register', 'account/reset_pass'), true))
			return 'authentication';
		if ($module === 'balance/oper') return 'payment';
		if ($module === 'admin' || isset($route['admin']) || str_contains($module, '/admin/')) return 'administration';
		if (($route['audience'] ?? '') === 'authenticated') return 'account';
		return 'public';
	}

	public static function reportOnly(string $surface): string
	{
		$nonce = self::nonce();
		if (!preg_match('~^[A-Za-z0-9+/]{24}$~D', $nonce))
			throw new \InvalidArgumentException('Invalid CSP nonce');
		$minimal = in_array($surface, array('technical', 'error'), true);
		$turnstile = in_array($surface, array('public', 'authentication', 'account', 'administration', 'payment'), true);
		$media = in_array($surface, array('public', 'administration'), true);
		$script = $minimal ? "script-src 'none'"
			: "script-src 'self' 'nonce-$nonce'" . ($turnstile ? ' https://challenges.cloudflare.com' : '');
		$styleSources = "'self'" . ($minimal ? '' : ' https://fonts.googleapis.com https://cdnjs.cloudflare.com');
		$directives = array(
			"default-src 'self'",
			$script,
			"script-src-attr 'none'",
			"style-src $styleSources",
			"style-src-elem $styleSources",
			"style-src-attr " . ($minimal ? "'none'" : "'unsafe-inline'"),
			"font-src 'self'" . ($minimal ? '' : ' https://fonts.gstatic.com https://cdnjs.cloudflare.com'),
			"img-src 'self' data:" . ($turnstile ? ' https://challenges.cloudflare.com' : ''),
			"media-src 'self' blob:",
			"connect-src 'self'" . ($turnstile ? ' https://challenges.cloudflare.com' : ''),
			"frame-src " . ($turnstile ? 'https://challenges.cloudflare.com' : "'none'")
				. ($media ? ' https://www.youtube.com https://www.youtube-nocookie.com https://player.vimeo.com' : ''),
			"form-action 'self'" . ($surface === 'payment' ? ' https:' : ''),
			"object-src 'none'",
			"base-uri 'self'",
			"frame-ancestors 'none'",
			'report-uri /csp-report',
		);
		return implode('; ', $directives);
	}
}
