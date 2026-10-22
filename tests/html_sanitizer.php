<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use HScript\Content\HtmlSanitizer;
use HScript\Security\SafeUrl;
use HScript\Util\StringHelper;

require dirname(__DIR__) . '/vendor/autoload.php';

$html = '<p onclick="alert(1)">Проверка русского текста <strong>и English</strong><script>alert(2)</script>'
	. '<a href="javascript:alert(3)" target="_blank">bad</a>'
	. '<a href="https://example.test" target="_blank">safe</a></p>';
$sanitized = HtmlSanitizer::sanitize($html);
foreach (array('onclick', '<script', 'alert(2)', 'javascript:') as $forbidden)
	if (stripos($sanitized, $forbidden) !== false)
		throw new RuntimeException('Unsafe rich-text fragment remains: ' . $forbidden);
if (!str_contains($sanitized, 'Проверка русского текста')
	|| !str_contains($sanitized, '<strong>и English</strong>')
	|| !str_contains($sanitized, 'noopener noreferrer'))
	throw new RuntimeException('Allowed rich text was not preserved safely');
if (preg_match('/(?:Ã.|Â.|Ð.|Ñ.)/u', $sanitized))
	throw new RuntimeException('UTF-8 rich text was corrupted by HTML parsing');
if (HtmlSanitizer::sanitize($sanitized) !== $sanitized)
	throw new RuntimeException('Repeated rich-text sanitization must be idempotent');

$localized = '{!ru!}<p>Русская версия</p>{!en!}<p>English version</p>{!!}<p>Общий блок</p>';
$localized = HtmlSanitizer::sanitize($localized);
$russian = StringHelper::textLangFilter($localized, 'ru');
$english = StringHelper::textLangFilter($localized, 'en');
if (!str_contains($russian, 'Русская версия')
	|| str_contains($russian, 'English version')
	|| !str_contains($russian, 'Общий блок'))
	throw new RuntimeException('Russian rich-text language markers were not preserved');
if (!str_contains($english, 'English version')
	|| str_contains($english, 'Русская версия')
	|| !str_contains($english, 'Общий блок'))
	throw new RuntimeException('English rich-text language markers were not preserved');

$editorLocalized = '<p>{!ru!}</p><p>Русская версия</p><p>{!en!}</p>'
	. '<p>English version</p><p>{!!}</p>';
$editorLocalized = HtmlSanitizer::sanitize($editorLocalized);
if ($editorLocalized !== '{!ru!}<p>Русская версия</p>{!en!}<p>English version</p>{!!}')
	throw new RuntimeException('CKEditor paragraph wrappers were not removed from language markers');
if (HtmlSanitizer::sanitize($editorLocalized) !== $editorLocalized)
	throw new RuntimeException('Normalized multilingual rich text must remain idempotent');

foreach (array(
	'//evil.example/path', '\\\\evil.example', 'javascript:alert(1)', 'java%73cript:alert(1)',
	'data:text/html,boom', 'https%3A%2F%2Fevil.example', "https://example.test/\r\nX-Test: yes"
) as $unsafeUrl)
	if (SafeUrl::href($unsafeUrl) || SafeUrl::image($unsafeUrl) || SafeUrl::paymentAction($unsafeUrl))
		throw new RuntimeException('Unsafe URL was accepted: ' . $unsafeUrl);
if (!SafeUrl::href('/news/show?id=1') || !SafeUrl::href('mailto:team@example.test')
	|| !SafeUrl::image('https://example.test/photo%20one.png')
	|| !SafeUrl::paymentAction('https://gateway.example.test/pay?invoice=1')
	|| SafeUrl::paymentAction('http://gateway.example.test/pay'))
	throw new RuntimeException('URL scheme policy rejected an expected URL or accepted HTTP payment');

$malicious = '<svg onload="alert(1)"><foreignObject><script>alert(2)</script></foreignObject></svg>'
	. '<math><mtext><img src="data:image/svg+xml;base64,PHN2Zz4=" onerror="alert(3)"></mtext></math>'
	. '<a href="//evil.example">link</a><a href="java&#x73;cript:alert(4)">bad</a>'
	. '<a href="https://example.test/ok">good</a>';
$clean = HtmlSanitizer::sanitize($malicious);
foreach (array('<svg', '<math', '<script', '<img', 'onload', 'onerror', 'javascript:', 'href="//evil.example"') as $forbidden)
	if (stripos($clean, $forbidden) !== false)
		throw new RuntimeException('Sanitizer kept malicious markup: ' . $forbidden);
if (!str_contains($clean, 'href="https://example.test/ok"'))
	throw new RuntimeException('Sanitizer lost an allowed HTTPS link');

$invalidUtf8 = "Text \xC3\x28 <script>alert(1)</script>";
if (str_contains(HtmlSanitizer::sanitize($invalidUtf8), '<script'))
	throw new RuntimeException('Malformed UTF-8 bypassed the sanitizer');
$deep = str_repeat('<div>', 40) . 'deep' . str_repeat('</div>', 40);
if (str_contains(HtmlSanitizer::sanitize($deep), '<div>'))
	throw new RuntimeException('Excessive nesting was not reduced to escaped text');
$oversized = str_repeat('x', 262145) . '<script>alert(1)</script>';
if (strlen(HtmlSanitizer::sanitize($oversized)) > 262144)
	throw new RuntimeException('Oversized rich text was not bounded');

echo "HTML sanitizer tests passed.\n";
