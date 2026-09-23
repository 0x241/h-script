<?php

declare(strict_types=1);

// Extends the existing read-only HTTP harness. No fixture database changes.
require __DIR__ . '/locale_routing_http.php';

require __DIR__ . '/helpers/seo_document.php';
$extra = [
    'seo:page' => ['/en/news/?page=2&utm_source=test&ref=alice'],
    'seo:missing' => ['/en/show/?id=2147483647'],
    'seo:login-ru' => ['/login', 'lang=ru'],
    'seo:login-en' => ['/login', 'lang=en'],
];
$run($extra);
$check($results['seo:missing']['status'] === 404, 'Missing news returns 404');
$allCases = array_merge($cases, $follow, $extra);
$parsed = [];
$targets = [];
foreach ($results as $key => $result) {
    $path = $allCases[$key][0] ?? '';
    if ($result['status'] !== 200 || !preg_match('~^/(ru|en|de|fr|es)/~', $path, $match)) {
        continue;
    }
    $seo = $parse($result['body']);
    $parsed[$key] = $seo;
    $check($seo['canonical_count'] === 1 && str_starts_with($seo['canonical'], 'https://' . $host . '/'), 'One absolute HTTPS canonical: ' . $key);
    $check(str_contains($seo['canonical'], '/' . $match[1] . '/'), 'Same-language canonical: ' . $key);
    $check($seo['language'] === $match[1] && $seo['og_locale'] === $match[1], 'HTML/OG locale agreement: ' . $key);
    $check($seo['og_url'] === $seo['canonical'] && $seo['title'] === $seo['og_title'] && $seo['description'] === $seo['og_description'], 'Open Graph agrees with document metadata: ' . $key);
    $check(!str_contains($seo['canonical'], 'utm_') && !str_contains($seo['canonical'], 'ref='), 'Canonical excludes tracking: ' . $key);
    if (str_contains($seo['robots'], 'noindex')) {
        $check($seo['alternates'] === [], 'Noindex page cannot publish alternates: ' . $key);
    } else {
        $check(($seo['alternates'][$match[1]] ?? '') === $seo['canonical'], 'Alternate includes self: ' . $key);
        if (isset($seo['alternates'][$primary])) {
            $check($seo['alternates']['x-default'] === $seo['alternates'][$primary], 'x-default points to primary: ' . $key);
        }
    }
    foreach ($seo['schemas'] as $schema) {
        if (($schema['@type'] ?? '') !== 'Organization') {
            $check(($schema['url'] ?? '') === $seo['canonical'] && ($schema['inLanguage'] ?? '') === $match[1], 'Structured data locale/URL: ' . $key);
        }
    }
    foreach ($seo['switches'] as $locale => $url) {
        $check(str_starts_with($url, $locale . '/') && !str_contains($url, 'interface'), 'Direct language link: ' . $key);
    }
    foreach ($seo['alternates'] as $locale => $url) {
        if ($locale === 'x-default') {
            continue;
        }
        $targetPath = (string)parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);
        $targets['alternate:' . $url] = [$targetPath . ($query === null ? '' : '?' . $query), 'lang=' . ($locale === 'ru' ? 'en' : 'ru')];
    }
}
$check($parsed['ru/contacts/']['alternates'] === $parsed['en/contacts/']['alternates'], 'RU/EN contact cluster is reciprocal');
$check(isset($parsed['en/contacts/']['alternates']['ru'], $parsed['en/contacts/']['alternates']['en']), 'Actual static RU/EN translations advertised');
$check(str_ends_with($parsed['seo:page']['canonical'], '/en/news/?page=2'), 'Canonical preserves functional pagination');
foreach (['seo:login-ru' => 'ru', 'seo:login-en' => 'en'] as $key => $locale) {
    $seo = $parse($results[$key]['body']);
    $check($seo['language'] === $locale && $seo['canonical_count'] === 0 && $seo['alternates'] === [], 'Private cookie language without SEO duplicates');
}
$run($targets);
foreach ($parsed as $key => $seo) {
    foreach ($seo['alternates'] as $locale => $url) {
        if ($locale === 'x-default') {
            continue;
        }
        $response = $results['alternate:' . $url];
        $check($response['status'] === 200, 'Advertised alternate returns 200: ' . $url);
        $alternate = $parse($response['body']);
        $check(!str_contains($alternate['robots'], 'noindex') && $alternate['canonical'] === $url, 'Alternate is indexable and self-canonical: ' . $url);
        $check($alternate['alternates'] === $seo['alternates'], 'Full reciprocal alternate cluster: ' . $key . ' -> ' . $url);
    }
}
echo 'Public SEO HTTP tests passed (' . count($results) . ' requests; ' . count($targets) . " unique alternate targets).\n";
