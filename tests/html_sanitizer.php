<?php

use HScript\Content\HtmlSanitizer;
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

echo "HTML sanitizer tests passed.\n";
