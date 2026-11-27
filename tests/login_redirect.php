<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require $root . '/module/account/login/lib.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
	$checks++;
	if (!$condition) throw new RuntimeException($message);
};

// 1. Verify loginReturnUrl exists
$assert(function_exists('loginReturnUrl'), 'loginReturnUrl function must be defined');

// 2. Test rejection of external schemes, hosts, protocol-relative prefixes, backslashes, whitespace tricks
$rejected = array(
	'https://evil.example/phish',
	'http://evil.example/phish',
	'http://evil.example',
	'https://evil.example',
	'//evil.example',
	'//evil.example/path',
	'///evil.example',
	'////evil.example',
	'/\\evil.example',
	'\\evil.example',
	'\\\\evil.example',
	'evil.example\\phish',
	'javascript:alert(1)',
	'data:text/html,evil',
	'mailto:victim@example.com',
	'https:evil.example',
	'http:evil.example',
	' https://evil.example',
	'https://evil.example ',
	' //evil.example',
	'//evil.example ',
	"\t//evil.example",
	"\r//evil.example",
	"\n//evil.example",
	"/cabinet\r\nLocation: evil.com",
	"/cabinet\0",
	'//',
	'',
);

foreach ($rejected as $url) {
	$assert(loginReturnUrl($url) === '', 'Expected rejected return URL for: ' . var_export($url, true));
	$effective = HScript\Util\StringHelper::exValue('/cabinet', loginReturnUrl($url));
	$assert($effective === '/cabinet', 'Fallback to /cabinet failed for rejected URL: ' . var_export($url, true));
}

// 3. Test acceptance of valid same-site relative paths
$accepted = array(
	'/cabinet' => '/cabinet',
	'cabinet' => 'cabinet',
	'/account/security' => '/account/security',
	'/account/security?tab=1' => '/account/security?tab=1',
	'?tab=1' => '?tab=1',
	'#section' => '#section',
	'/news/show/1' => '/news/show/1',
	'sub/path?a=1&b=2#frag' => 'sub/path?a=1&b=2#frag',
	'/' => '/',
);

foreach ($accepted as $url => $expected) {
	$assert(loginReturnUrl($url) === $expected, 'Expected accepted return URL for: ' . var_export($url, true));
	$effective = HScript\Util\StringHelper::exValue('/cabinet', loginReturnUrl($url));
	$assert($effective === $expected, 'Effective URL mismatch for accepted URL: ' . var_export($url, true));
}

echo "Login redirect tests passed: $checks checks.\n";
