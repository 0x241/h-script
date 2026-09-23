<?php

declare(strict_types=1);

use HScript\Http\LocaleRouter;
use HScript\Template\View;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/module/_config.php';

$check = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
};
$router = new LocaleRouter($_rwlinks);
$locales = LocaleRouter::enabledLocales(" RU \r\nen\nxx\n../ru\nru", dirname(__DIR__));
$check($locales === ['ru', 'en'], 'Normalize, deduplicate and restrict installed locales');
$check(LocaleRouter::enabledLocales(["ru\nen\nxx\nzz"], dirname(__DIR__)) === ['ru', 'en'], 'LF-only legacy settings hydration');
$check(LocaleRouter::enabledLocales(['xx'], dirname(__DIR__)) === ['en'], 'Installed fallback');
$check(LocaleRouter::enabledLocales(['faq', 'udp'], dirname(__DIR__)) === ['en'], 'Template folders are not language catalogs');
$router->setLocales($locales);
$check($router->primaryLocale() === 'ru' && !$router->supports('xx'), 'Primary and unavailable locales');
$publicCount = 0;
foreach ($_rwlinks as $module => $route) {
    $check(in_array($route['audience'] ?? '', ['indexable public', 'public noindex', 'authenticated', 'technical callback'], true), 'Inventory: ' . $module);
    $check($router->parse($route[0])['module'] === $module, 'Existing alias: ' . $module);
    $check($router->isIndexable($module) === ($route['audience'] === 'indexable public'), 'Explicit indexable flag: ' . $module);
    if (!$router->isIndexable($module)) {
        $check($router->parse('en/' . $route[0]) === null, 'Reject prefixed private/technical alias: ' . $module);
        $check($router->url($module, $route[0], 'en') === $route[0], 'Preserve private/technical generator: ' . $module);
        continue;
    }
    $publicCount++;
    foreach ($locales as $locale) {
        $url = $router->url($module, $route[0], $locale);
        $match = $router->parse($url);
        $check($match['module'] === $module && $match['locale'] === $locale, 'Public round trip: ' . $url);
        $check($router->url($module, $match['path'], $match['locale']) === $url, 'No normalization loop: ' . $url);
    }
}
$check($publicCount > 0, 'Public inventory is not empty');
foreach (['balance/wallets' => 'balance/wallets', 'message/show' => 'message/show', '' => 'index', 'news/' => 'news'] as $alias => $module) {
    $check($router->parse($alias)['module'] === $module, 'Additional alias: ' . $alias);
}
foreach (['en/api/v1/user', 'ru/cron', 'en/_cfg', 'en/robots.txt', 'en/static/app.js', 'en/en/news/', 'en/../news', 'en/%2e%2e/news', 'en/show/1/a%2fb', 'en/show/1/a%252fb', 'en/show/1/a%5cb', 'en/show/1/a%0db', 'en//news', 'en/show/1/%zz'] as $path) {
    $check($router->parse($path) === null, 'Unsafe/unsupported path: ' . $path);
}
$check($router->parse('zz/news/')['locale'] === 'zz' && !$router->supports('zz'), 'Unknown locale is rejected after settings load');
$query = ['page' => '2', 'utm_source' => 'email', 'ref' => 'alice', 'url' => '//evil.test', 'lang' => 'ru'];
$check($router->url('news', 'news', 'en', $query, 'ref') === 'en/news/?page=2&utm_source=email&ref=alice', 'Safe redirect query');
$check($router->url('news', 'news', 'en', $query, 'ref', true) === 'en/news/?page=2', 'Canonical preserves pagination only');
$check($router->url('news/show', 'show', 'en', ['id' => '42']) === 'en/show/?id=42', 'News query ID');
$slug = 'show/42/%D0%BD%D0%BE%D0%B2%D0%BE%D1%81%D1%82%D1%8C';
$check($router->parse('ru/' . $slug)['id'] === '42', 'Encoded slug ID');
$check($router->url('news/show', $slug, 'ru', ['id' => '99']) === 'ru/' . $slug . '/', 'Slug bytes preserved and path ID wins');
$check($router->safeQuery('news', ['page' => ['2'], 'utm_source' => "bad\r\nLocation: evil"]) === [], 'Reject arrays/control bytes');
$check($router->safeQuery('review', ['sort' => 'nTS0', 'page' => '3']) === ['page' => '3', 'sort' => 'nTS0'], 'Review functional query');
$check($router->safeQuery('review', ['sort' => 'unexpected', 'page' => '-1']) === [], 'Reject unsupported sort and page');
$_GS['root_dir'] = 'nested/cms/';
$_GS['domain'] = 'example.test';
$check(fullURL($router->url('news', 'news', 'en', ['page' => 2]), true) === 'https://example.test/nested/cms/en/news/?page=2', 'HTTPS and installation subdirectory preserved');
$_GS['root_dir'] = '';
try {
    $router->url('news', 'news', '../ru');
    throw new RuntimeException('Invalid locale accepted');
} catch (InvalidArgumentException) {
}
try {
    $router->url('news', '//evil.test', 'en');
    throw new RuntimeException('Invalid path accepted');
} catch (InvalidArgumentException) {
}

// Public pagination ignores session history while account pagination retains it.
require dirname(__DIR__) . '/module/lib.php';
$_GS['module'] = 'news';
$_GS['url_locale'] = 'en';
$form = View::getFormName();
$_SESSION['_PL'][$form] = ['Page' => 3];
$rows = range(1, 30);
$check(opArrayPageGet(0, 10, $rows) === range(1, 10), 'Public first page must ignore session page');
$check(opArrayPageGet(2, 10, $rows) === array_combine(range(10, 19), range(11, 20)), 'Public explicit page');
unset($_GS['url_locale']);
$check(opArrayPageGet(0, 10, $rows) === array_combine(range(10, 19), range(11, 20)), 'Private session pagination retained');
echo "Locale routing tests passed (" . count($_rwlinks) . " registered routes, $publicCount public routes).\n";
