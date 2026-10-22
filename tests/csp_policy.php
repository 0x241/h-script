<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use HScript\Http\ContentSecurityPolicy;

require dirname(__DIR__) . '/vendor/autoload.php';

$first = ContentSecurityPolicy::beginRequest();
if (!preg_match('~^[A-Za-z0-9+/]{24}$~D', $first) || ContentSecurityPolicy::nonce() !== $first)
	throw new RuntimeException('CSP nonce is invalid or changed within a request');
$second = ContentSecurityPolicy::beginRequest();
if ($second === $first)
	throw new RuntimeException('CSP nonce was reused across requests');
$_GET['csp_nonce'] = 'AAAAAAAAAAAAAAAAAAAAAAAA';
if ($second === $_GET['csp_nonce'] || str_contains(ContentSecurityPolicy::reportOnly('public'), "nonce-{$_GET['csp_nonce']}"))
	throw new RuntimeException('Request-supplied CSP nonce was accepted');
unset($_GET['csp_nonce']);

$public = ContentSecurityPolicy::reportOnly('public', true);
if (!str_contains($public, "script-src 'self' 'nonce-$second' https://challenges.cloudflare.com")
	|| !str_contains($public, "script-src-attr 'none'")
	|| !str_contains($public, "object-src 'none'")
	|| !str_contains($public, "base-uri 'self'")
	|| !str_contains($public, "frame-ancestors 'none'")
	|| !str_contains($public, 'report-uri /csp-report')
	|| str_contains(explode(';', $public)[1], "'unsafe-inline'"))
	throw new RuntimeException('Public CSP candidate has an unsafe script policy');
if (!str_contains($public, "style-src-attr 'unsafe-inline'"))
	throw new RuntimeException('Legacy style-attribute exception was not scoped');

foreach (['public','authentication','account','administration','payment','technical','configurator','error'] as $surface) {
    foreach ([ContentSecurityPolicy::reportOnly($surface), ContentSecurityPolicy::enforced($surface)] as $policy)
        if (preg_match('~https?://~', $policy)) throw new RuntimeException('Inactive third-party origin is allowed');
}
if (ContentSecurityPolicy::turnstileEnabled([]) || ContentSecurityPolicy::turnstileEnabled(['Turnstile_SiteKey'=>'site']))
    throw new RuntimeException('Incomplete captcha configuration activates external origins');
if (!ContentSecurityPolicy::turnstileEnabled(['Turnstile_SiteKey'=>'site','Turnstile_SecretKey'=>'secret'])
    || ContentSecurityPolicy::turnstileEnabled(['Captcha_Service'=>'local','Turnstile_SiteKey'=>'site','Turnstile_SecretKey'=>'secret']))
    throw new RuntimeException('Configured captcha origin activation is incorrect');
$configurator = ContentSecurityPolicy::reportOnly('configurator');
if (str_contains($configurator, 'challenges.cloudflare.com') || !str_contains($configurator, "frame-src 'none'"))
	throw new RuntimeException('Configurator CSP includes unrelated external origins');
$error = ContentSecurityPolicy::reportOnly('error');
if (!str_contains($error, "style-src-attr 'none'") || str_contains($error, 'fonts.googleapis.com'))
	throw new RuntimeException('System error page CSP is too broad');
$payment = ContentSecurityPolicy::reportOnly('payment');
if (!str_contains($payment, "form-action 'self' https:"))
	throw new RuntimeException('Payment CSP would block HTTPS gateway forms');

if (ContentSecurityPolicy::surface('_config') !== 'configurator'
	|| ContentSecurityPolicy::surface('account/login') !== 'authentication'
	|| ContentSecurityPolicy::surface('balance/oper') !== 'payment'
	|| ContentSecurityPolicy::surface('news/admin/news', array('admin' => true)) !== 'administration'
	|| ContentSecurityPolicy::surface('captcha', array('audience' => 'technical callback')) !== 'technical'
	|| ContentSecurityPolicy::surface('api/v1/deposit') !== 'technical')
	throw new RuntimeException('CSP surface selection is incorrect');

$nonceProperty = new ReflectionProperty(ContentSecurityPolicy::class, 'nonce');
$nonceProperty->setValue(null, 'attacker-supplied');
try { ContentSecurityPolicy::reportOnly('public'); }
catch (InvalidArgumentException) {
	ContentSecurityPolicy::beginRequest();
	echo "CSP policy tests passed.\n";
	exit;
}
throw new RuntimeException('Invalid nonce was accepted');
