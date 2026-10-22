<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use HScript\Http\ContentSecurityPolicy;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/module/captcha/turnstile/onstart.php';

$_GS = array('csp_nonce' => ContentSecurityPolicy::beginRequest());
$_cfg = array('Turnstile_SiteKey' => 'site" onerror="alert(1)');

$html = captchaGetHTML('login_frm');
if (!str_contains($html, 'src="https://challenges.cloudflare.com/turnstile/v0/api.js"')
	|| !str_contains($html, 'nonce="' . $_GS['csp_nonce'] . '"')
	|| !str_contains($html, 'data-sitekey="site&quot; onerror=&quot;alert(1)"')
	|| substr_count($html, '<script') !== 1)
	throw new RuntimeException('Turnstile markup lacks a per-response nonce or escaped site key');

$policy = ContentSecurityPolicy::reportOnly('authentication', true);
if (!str_contains($policy, "'nonce-{$_GS['csp_nonce']}' https://challenges.cloudflare.com"))
	throw new RuntimeException('Turnstile script and header nonce do not match');

echo "Turnstile CSP markup tests passed.\n";
