<?php

declare(strict_types=1);

// Smoke test using only GET/HEAD against existing content; no fixture mutations.
// php tests/locale_routing_http.php http://127.0.0.1 hs.local
require __DIR__ . '/helpers/seo_http.php';
$cases = [];
$pages = ['', 'news/', 'contacts/', 'faq/', 'reviews/', 'intro/', 'rules/', 'about/'];
foreach (['ru', 'en'] as $locale) {
    foreach ($pages as $page) {
        $cases[$locale . '/' . $page] = ['/' . $locale . '/' . $page];
    }
    $opposite = $locale === 'en' ? 'ru' : 'en';
    $cases[$locale . '-opposite'] = ['/' . $locale . '/news/', 'lang=' . $opposite, $opposite . ';q=1'];
    $cases[$locale . '-browser'] = ['/' . $locale . '/news/', '', $opposite . ';q=1'];
}
foreach (['de', 'fr', 'es'] as $locale) {
    $cases['optional:' . $locale] = ['/' . $locale . '/news/', 'lang=ru', 'ru'];
}
foreach (['/', '/home', '/news', '/news/', '/faq?page=2', '/news?page=2&utm_source=email&url=https%3A%2F%2Fevil.test'] as $path) {
    $cases['old:' . $path] = [$path];
}
$cases['old:opposite'] = ['/news', 'lang=ru', 'ru'];
$cases['old:head'] = ['/news', 'lang=en', 'en', true];
foreach (['/zz/news/', '/zz/', '/en/cron', '/en/api/v1/user', '/ru/login', '/en/_cfg', '/en/balance/status', '/en/robots.txt', '/en/sitemap.xml'] as $path) {
    $cases['404:' . $path] = [$path];
}
foreach (['/api/v1/user', '/login', '/cabinet', '/robots.txt', '/sitemap.xml'] as $path) {
    $cases['technical:' . $path] = [$path];
}
$run($cases);
foreach (['ru', 'en'] as $locale) {
    foreach ($pages as $page) {
        $result = $results[$locale . '/' . $page];
        if ($page === 'intro/' && $result['status'] === 302) {
            $check(str_ends_with($result['location'], '/' . $locale . '/'), 'Disabled intro retains locale');
            continue;
        }
        $check($result['status'] === 200, 'Public status: ' . $locale . '/' . $page . ' = ' . $result['status']);
        $check(str_contains($result['body'], '<html lang="' . $locale . '"'), 'Public language: ' . $locale . '/' . $page);
    }
    foreach (['opposite', 'browser'] as $variant) {
        $result = $results[$locale . '-' . $variant];
        $check($result['status'] === 200 && str_contains($result['body'], '<html lang="' . $locale . '"'), 'URL language priority: ' . $locale . '-' . $variant);
    }
}
$location = $results['old:/news']['location'];
$check((bool)preg_match('~/(ru|en)/news/$~', $location, $match), 'Primary redirect target');
$primary = $match[1];
foreach ($results as $key => $result) {
    if (str_starts_with($key, 'optional:')) {
        $locale = substr($key, strlen('optional:'));
        $check($result['status'] === 404 || ($result['status'] === 200 && str_contains($result['body'], '<html lang="' . $locale . '"')), 'Additional enabled locale: ' . $locale);
    }
    if (str_starts_with($key, 'old:')) {
        $check($result['status'] === 301, 'Legacy 301: ' . $key);
        $check(str_contains($result['location'], '/' . $primary . '/'), 'Deterministic primary: ' . $key);
    }
    if (str_starts_with($key, '404:')) {
        $check($result['status'] === 404 && $result['location'] === '', 'Unknown/forbidden locale route: ' . $key);
    }
}
$check($results['old:opposite']['location'] === $location && $results['old:head']['location'] === $location, 'Cookie/header/method independent redirect');
$check(str_ends_with($results['old:/faq?page=2']['location'], '/faq/?page=2'), 'Pagination survives redirect');
$check(str_ends_with($results['old:/news?page=2&utm_source=email&url=https%3A%2F%2Fevil.test']['location'], '/news/?page=2&utm_source=email'), 'Safe query redirect');
$check($results['technical:/api/v1/user']['status'] === 401, 'Existing API address');
$check($results['technical:/login']['status'] === 200, 'Existing login address');
$check($results['technical:/cabinet']['status'] === 302 && str_contains($results['technical:/cabinet']['location'], '/login'), 'Existing account authorization');
$check($results['technical:/robots.txt']['status'] === 200 && $results['technical:/sitemap.xml']['status'] === 200, 'Existing crawler endpoints');

// Follow every legacy GET target once: there must be no second redirect.
$follow = [];
foreach ($cases as $key => $case) {
    if (str_starts_with($key, 'old:') && !str_contains($key, 'head')) {
        $url = $results[$key]['location'];
        $path = (string)parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);
        $follow['follow:' . $key] = [$path . ($query === null ? '' : '?' . $query), 'lang=' . ($primary === 'en' ? 'ru' : 'en')];
    }
}
// Discover an existing item through public HTML; do not insert fixtures into the CMS.
if (preg_match('~href="(?:https?://[^/]+/)?(en/show/[^"]+)"~', $results['en/news/']['body'], $item)) {
    $itemPath = html_entity_decode($item[1]);
    $follow['item:en'] = ['/' . $itemPath, 'lang=ru', 'ru'];
    preg_match('~^en/show/([0-9]+)/~', $itemPath, $article);
    if (preg_match('~href="(?:https?://[^/]+/)?(ru/show/' . $article[1] . '/[^"]+)"~', $results['ru/news/']['body'], $ruItem)) {
        $follow['item:ru'] = ['/' . html_entity_decode($ruItem[1]), 'lang=en', 'en'];
    }
    $follow['item:old'] = ['/' . substr($itemPath, 3)];
}
$run($follow);
foreach ($follow as $key => $case) {
    $check($results[$key]['status'] === ($key === 'item:old' ? 301 : 200), 'Single-hop/item status: ' . $key);
    if ($key === 'item:en' || $key === 'item:ru') {
        $check(str_contains($results[$key]['body'], '<html lang="' . substr($key, -2) . '"'), 'Item locale: ' . $key);
    }
}
echo 'Locale HTTP tests passed (' . count($results) . ' requests; primary=' . $primary . '; news item=' . (isset($follow['item:en']) ? 'checked' : 'no published fixture') . ").\n";
