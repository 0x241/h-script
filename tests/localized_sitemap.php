<?php

declare(strict_types=1);

use HScript\Cache\CatalogCache;
use HScript\Content\PublicCatalog;
use HScript\Database\Connection;
use HScript\Http\LocaleRouter;
use HScript\Http\LocalizedSitemap;
use HScript\Http\PublicSeo;
use HScript\Template\View;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/module/_config.php';
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$router = new LocaleRouter($_rwlinks);
$router->setLocales(['ru', 'en']);
$catalogs = ['ru' => View::translationReadBundledFile('ru'), 'en' => View::translationReadBundledFile('en')];
$both = '{!ru!}Новость{!en!}News{!!}';
$now = '20260922120000';
$item = ['nID' => 42, 'nTopic' => $both, 'nAnnounce' => '', 'nText' => $both, 'nTS' => '20260920103000', 'nDBegin' => '0', 'nDEnd' => '0'];
$news = [
    $item,
    array_replace($item, ['nID' => 43, 'nTopic' => 'Source only', 'nText' => 'Source body']),
    array_replace($item, ['nID' => 44, 'nDEnd' => '20260921120000']),
    array_replace($item, ['nID' => 45, 'nDBegin' => '20260923120000']),
    array_replace($item, ['nID' => 46, 'nText' => '<script>alert(1)</script>']),
    $item, // Duplicate records must not produce duplicate loc entries.
];
$lists = ['news' => [$item], 'faq' => [['fQuestion' => $both, 'fAnswer' => $both, 'fCat' => '']], 'review' => [['oText' => $both]]];
$create = static fn(array $config = [], ?array $routes = null): LocalizedSitemap => new LocalizedSitemap(
    $router, $routes ?? $_rwlinks, 'https://example.test/cms/', $config, $catalogs, $catalogs['en']
);
$entries = iterator_to_array($create()->entries($lists, $news, $now), false);
$byUrl = array_column($entries, null, 'loc');
$check(count($byUrl) === count($entries), 'Unique canonical URLs');
foreach ($entries as $entry) {
    $check(str_starts_with($entry['loc'], 'https://example.test/cms/'), 'HTTPS/subdirectory URL');
    $check(!str_contains($entry['loc'], '/intro/'), 'Disabled intro excluded');
    foreach (['/login', '/cabinet', '/api/', '/cron', '/admin', '/sitemap.xml', '/robots.txt'] as $technical) {
        $check(!str_contains($entry['loc'], $technical), 'Private/technical routes excluded');
    }
    foreach ($entry['alternates'] as $locale => $alternate) {
        $check(isset($byUrl[$alternate]), 'Each alternate has its own entry');
        $check($byUrl[$alternate]['alternates'] === $entry['alternates'], 'Reciprocal sitemap cluster');
    }
    $relative = substr($entry['loc'], strlen('https://example.test/cms/'));
    $route = $router->parse($relative);
    $available = array_values(array_diff(array_keys($entry['alternates']), ['x-default']));
    $article = $route['id'] === null ? null : $news[array_search((int)$route['id'], array_column($news, 'nID'), true)];
    $localePaths = $article === null ? [] : PublicSeo::newsPaths('show', $article, ['ru', 'en'], 'ru');
    $html = PublicSeo::metadata($router, 'https://example.test/cms/', $route['module'], $route['path'], $route['locale'], $available, [], '', true, $localePaths);
    $check($entry['loc'] === $html['canonical'] && $entry['alternates'] === $html['alternates'], 'Same HTML metadata contract');
}
$check(isset($byUrl['https://example.test/cms/ru/show/42/novost/'], $byUrl['https://example.test/cms/en/show/42/news/']), 'Each article locale has its translated slug');
$paths = implode("\n", array_keys($byUrl));
$check(str_contains($paths, '/ru/show/42/') && str_contains($paths, '/en/show/42/'), 'Translated news versions included');
$check(str_contains($paths, '/ru/show/43/') && !str_contains($paths, '/en/show/43/'), 'Unmarked article limited to primary');
foreach ([44, 45, 46] as $id) {
    $check(!str_contains($paths, '/show/' . $id . '/'), 'Expired/future/empty-sanitized article excluded: ' . $id);
}
$check(iterator_to_array($create(['Sys_LockSite' => 1])->entries($lists, $news, $now)) === [], 'Closed site has no indexable entries');
$withIntro = iterator_to_array($create(['UI_ShowIntro' => 1])->entries($lists, [], $now), false);
$check(count(array_filter($withIntro, static fn(array $entry): bool => str_contains($entry['loc'], '/intro/'))) === 2, 'Enabled translated intro included');
$missingLists = iterator_to_array($create()->entries([], [], $now), false);
$check(!array_filter($missingLists, static fn(array $entry): bool => (bool)preg_match('~/(news|faq|reviews)/$~', $entry['loc'])), 'Unknown listing availability fails closed');
$withoutItems = $_rwlinks;
$withoutItems['news/show']['indexable'] = false;
$disabledRouter = new LocaleRouter($withoutItems);
$disabledRouter->setLocales(['ru', 'en']);
$disabledSitemap = new LocalizedSitemap($disabledRouter, $withoutItems, 'https://example.test/', [], $catalogs, $catalogs['en']);
$check(!array_filter(iterator_to_array($disabledSitemap->entries($lists, $news, $now), false), static fn(array $entry): bool => str_contains($entry['loc'], '/show/')), 'Route indexable flag controls dynamic entries');
$xml = implode('', iterator_to_array(LocalizedSitemap::xml($entries), false));
$document = new DOMDocument();
$check($document->loadXML($xml, LIBXML_NONET), 'Well-formed sitemap XML');
$xpath = new DOMXPath($document);
$xpath->registerNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
$xpath->registerNamespace('x', 'http://www.w3.org/1999/xhtml');
$check($xpath->query('/s:urlset/s:url')->length === count($entries), 'Sitemap namespace and entry count');
$check($xpath->query('//x:link[@rel="alternate"]')->length > count($entries), 'XHTML alternate namespace');
$escaped = $entries[0];
$escaped['loc'] = 'https://example.test/ru/news/?a=1&b=2';
$escaped['alternates'] = ['ru' => $escaped['loc']];
$check($document->loadXML(implode('', iterator_to_array(LocalizedSitemap::xml([$escaped]), false)), LIBXML_NONET), 'XML escapes query ampersands');
$check(PublicCatalog::newsIsPublished(array_replace($item, ['nDBegin' => $now, 'nDEnd' => $now]), $now), 'Publication boundary is inclusive');
$check(!PublicCatalog::newsIsPublished([], $now), 'Missing article is not published');

// Simulate a stale Redis snapshot: expired records must be filtered after cache read.
$cached = new class extends CatalogCache {
    public array $rows = [];
    public function __construct() {}
    public function remember(string $catalog, string $variant, callable $callback): mixed { return $this->rows; }
};
$cached->rows = [42 => $item, 44 => array_replace($item, ['nID' => 44, 'nDEnd' => gmdate('YmdHis', time() - 5)])];
$check(array_keys((new PublicCatalog(new Connection(), $cached))->news()) === [42], 'Expired cached entry removed from shared public catalog');
echo 'Localized sitemap component tests passed (' . count($entries) . " synthetic entries).\n";
