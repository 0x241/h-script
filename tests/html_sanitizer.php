<?php

use HScript\Content\HtmlSanitizer;

require dirname(__DIR__) . '/vendor/autoload.php';

$html = '<p onclick="alert(1)">Hello <strong>world</strong><script>alert(2)</script>'
	. '<a href="javascript:alert(3)" target="_blank">bad</a>'
	. '<a href="https://example.test" target="_blank">safe</a></p>';
$sanitized = HtmlSanitizer::sanitize($html);
foreach (array('onclick', '<script', 'alert(2)', 'javascript:') as $forbidden)
	if (stripos($sanitized, $forbidden) !== false)
		throw new RuntimeException('Unsafe rich-text fragment remains: ' . $forbidden);
if (!str_contains($sanitized, '<strong>world</strong>') || !str_contains($sanitized, 'noopener noreferrer'))
	throw new RuntimeException('Allowed rich text was not preserved safely');

echo "HTML sanitizer tests passed.\n";
