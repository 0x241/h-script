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

	public static function turnstileEnabled(array $configuration): bool
	{
		return ($configuration['Captcha_Service'] ?? 'turnstile') === 'turnstile'
			&& !empty($configuration['Turnstile_SiteKey']) && !empty($configuration['Turnstile_SecretKey']);
	}

	public static function apply(string $surface, array $configuration): void
	{
		if (headers_sent()) return;
		$enabled = self::turnstileEnabled($configuration);
		header('Content-Security-Policy: ' . self::enforced($surface, $enabled));
		header('Content-Security-Policy-Report-Only: ' . self::reportOnly($surface, $enabled));
	}

	public static function enforced(string $surface = 'error', bool $turnstile = false): string
	{
		$external = $turnstile && !in_array($surface, ['error','technical','configurator'], true) ? ' https://challenges.cloudflare.com' : '';
		return "default-src 'self'; script-src 'self' 'unsafe-inline'$external; style-src 'self' 'unsafe-inline'; font-src 'self'; img-src 'self' data:$external; media-src 'self' blob:; connect-src 'self'$external; frame-src " . ($external !== '' ? trim($external) : "'none'") . "; form-action 'self'" . ($surface === 'payment' ? ' https:' : '') . "; object-src 'none'; base-uri 'self'; frame-ancestors 'none'";
	}

	public static function reportOnly(string $surface, bool $configuredTurnstile = false): string
	{
		$nonce = self::nonce();
		if (!preg_match('~^[A-Za-z0-9+/]{24}$~D', $nonce))
			throw new \InvalidArgumentException('Invalid CSP nonce');
		$minimal = in_array($surface, array('technical', 'error'), true);
		$turnstile = $configuredTurnstile && in_array($surface, array('public', 'authentication', 'account', 'administration', 'payment'), true);
		$script = $minimal ? "script-src 'none'"
			: "script-src 'self' 'nonce-$nonce'" . ($turnstile ? ' https://challenges.cloudflare.com' : '');
		$styleSources = "'self'";
		$directives = array(
			"default-src 'self'",
			$script,
			"script-src-attr 'none'",
			"style-src $styleSources",
			"style-src-elem $styleSources",
			"style-src-attr " . ($minimal ? "'none'" : "'unsafe-inline'"),
			"font-src 'self'",
			"img-src 'self' data:" . ($turnstile ? ' https://challenges.cloudflare.com' : ''),
			"media-src 'self' blob:",
			"connect-src 'self'" . ($turnstile ? ' https://challenges.cloudflare.com' : ''),
			"frame-src " . ($turnstile ? 'https://challenges.cloudflare.com' : "'none'"),
			"form-action 'self'" . ($surface === 'payment' ? ' https:' : ''),
			"object-src 'none'",
			"base-uri 'self'",
			"frame-ancestors 'none'",
			'report-uri /csp-report',
		);
		return implode('; ', $directives);
	}
}
