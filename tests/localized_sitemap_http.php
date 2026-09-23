<?php

declare(strict_types=1);

require __DIR__ . '/public_seo_http.php';
$run([
    'sitemap:ru' => ['/sitemap.xml', 'lang=ru', 'ru'],
    'sitemap:en' => ['/sitemap.xml', 'lang=en', 'en'],
    'robots:ru' => ['/robots.txt', 'lang=ru', 'ru'],
    'robots:en' => ['/robots.txt', 'lang=en', 'en'],
]);
$sitemap = $results['technical:/sitemap.xml'];
$check($sitemap['status'] === 200, 'Stable sitemap endpoint returns 200');
$check($sitemap['body'] === $results['sitemap:ru']['body'] && $sitemap['body'] === $results['sitemap:en']['body'], 'Sitemap independent of cookie/Accept-Language');
$check(!preg_match('/^Set-Cookie:/mi', $sitemap['headers']), 'Sitemap is stateless');
$check($results['robots:ru']['body'] === $results['robots:en']['body'], 'Robots independent of visitor language');
preg_match_all('/^Sitemap: (.+)$/m', $results['robots:ru']['body'], $sitemaps);
$check(count($sitemaps[1]) === 1 && trim($sitemaps[1][0]) === 'https://' . $host . '/sitemap.xml', 'One stable HTTPS sitemap in robots');
$document = new DOMDocument();
$check($document->loadXML($sitemap['body'], LIBXML_NONET), 'Actual sitemap is well-formed XML');
$xpath = new DOMXPath($document);
$xpath->registerNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
$xpath->registerNamespace('x', 'http://www.w3.org/1999/xhtml');
$entries = [];
$requests = [];
foreach ($xpath->query('/s:urlset/s:url') as $node) {
    $url = $xpath->evaluate('string(s:loc)', $node);
    $check(!isset($entries[$url]), 'No duplicate sitemap URL');
    $check(str_starts_with($url, 'https://' . $host . '/'), 'Sitemap host/HTTPS matches canonical installation');
    $alternates = [];
    foreach ($xpath->query('x:link', $node) as $link) {
        $locale = $link->getAttribute('hreflang');
        $check($link->getAttribute('rel') === 'alternate' && !isset($alternates[$locale]), 'Valid unique XHTML alternate');
        $alternates[$locale] = $link->getAttribute('href');
    }
    $entries[$url] = $alternates;
    $path = (string)parse_url($url, PHP_URL_PATH);
    $requests['sitemap-target:' . $url] = [$path, 'lang=en', 'en'];
}
$check(count($entries) > 0, 'Installed public site has sitemap entries');
$run($requests);
foreach ($entries as $url => $alternates) {
    $response = $results['sitemap-target:' . $url];
    $check($response['status'] === 200 && $response['location'] === '', 'Every sitemap loc returns direct 200: ' . $url);
    $html = $parse($response['body']);
    $check(!str_contains($html['robots'], 'noindex') && !preg_match('/^X-Robots-Tag:.*noindex/mi', $response['headers']), 'No noindex URL in sitemap: ' . $url);
    $check($html['canonical'] === $url, 'Sitemap loc equals HTML canonical: ' . $url);
    $check($html['alternates'] === $alternates, 'Sitemap and HTML alternates identical: ' . $url);
    foreach ($alternates as $locale => $alternate) {
        $check(isset($entries[$alternate]) && $entries[$alternate] === $alternates, 'Reciprocal sitemap entries: ' . $url);
    }
}
echo 'Localized sitemap HTTP tests passed (' . count($entries) . ' URLs; ' . count($results) . " total requests).\n";
