<?php

declare(strict_types=1);

// Read-only acceptance against a running installation. Optional argv[3]: comma-separated enabled locales.
require __DIR__ . '/helpers/seo_http.php';
require __DIR__ . '/helpers/seo_document.php';
require dirname(__DIR__) . '/module/_config.php';
$htmlDirectory = getenv('SEO_HTML_DIR') ?: '';
if ($htmlDirectory !== '' && !is_dir($htmlDirectory)) {
    $check(mkdir($htmlDirectory, 0700, true), 'Create HTML validation output directory');
}
$saveHtml = static function (string $url, string $html) use ($htmlDirectory, $check): void {
    if ($htmlDirectory !== '') {
        $check(file_put_contents($htmlDirectory . '/' . hash('sha256', $url) . '.html', $html) !== false, 'Save rendered HTML');
    }
};
$rootPath = rtrim((string)parse_url($base, PHP_URL_PATH), '/') . '/';
$httpsRoot = 'https://' . $host . $rootPath;
$relative = static function (string $url) use ($rootPath, $host, $check): string {
    $urlHost = parse_url($url, PHP_URL_HOST);
    $check($urlHost === null || $urlHost === $host, 'No cross-origin target: ' . $url);
    $path = (string)parse_url($url, PHP_URL_PATH);
    $check(str_starts_with($path, $rootPath), 'Target stays inside installation: ' . $url);
    $query = parse_url($url, PHP_URL_QUERY);
    return '/' . substr($path, strlen($rootPath)) . ($query === null ? '' : '?' . $query);
};
$run(['root' => ['/'], 'login' => ['/login'], 'sitemap' => ['/sitemap.xml'], 'robots' => ['/robots.txt']]);
$check($results['root']['status'] === 301, 'Legacy root redirects once');
$primary = trim($relative($results['root']['location']), '/');
$check((bool)preg_match('/^[a-z]{2,3}(?:-[a-z]{2})?$/D', $primary), 'Primary locale discovered from legacy redirect');
$locales = [];
if (!empty($argv[3])) {
    $locales = explode(',', $argv[3]);
} else {
    // Private language links expose configured locales even when public translations are incomplete.
    preg_match_all('/[?&](?:amp;)?lang=([a-z]{2,3}(?:-[a-z]{2})?)(?:[&"\'])/', $results['login']['body'], $matches);
    $locales = $matches[1];
}
$locales = array_values(array_unique(array_merge([$primary], $locales)));
foreach ($locales as $locale) {
    $check((bool)preg_match('/^[a-z]{2,3}(?:-[a-z]{2})?$/D', $locale), 'Valid test locale');
}
$baselineSitemap = $results['sitemap'];
$baselineRobots = $results['robots']['body'];
preg_match_all('/^Sitemap: (.+)$/m', $baselineRobots, $sitemapLinks);
$check(array_map('trim', $sitemapLinks[1]) === [$httpsRoot . 'sitemap.xml'], 'Exactly one installation sitemap in robots');
$document = new DOMDocument();
$check($document->loadXML($baselineSitemap['body'], LIBXML_NONET), 'Well-formed sitemap');
$xpath = new DOMXPath($document);
$xpath->registerNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
$xpath->registerNamespace('x', 'http://www.w3.org/1999/xhtml');
$entries = [];
$itemPaths = [];
foreach ($xpath->query('/s:urlset/s:url') as $node) {
    $url = $xpath->evaluate('string(s:loc)', $node);
    $check(str_starts_with($url, $httpsRoot) && !isset($entries[$url]), 'Unique HTTPS installation URL');
    $alternates = [];
    foreach ($xpath->query('x:link', $node) as $link) {
        $lang = $link->getAttribute('hreflang');
        $check(!isset($alternates[$lang]) && $link->getAttribute('rel') === 'alternate', 'Unique sitemap alternate');
        $alternates[$lang] = $link->getAttribute('href');
    }
    $entries[$url] = $alternates;
    if (preg_match('~^/[^/]+/(show/[0-9]+/.*)$~', $relative($url), $item)) {
        $itemPaths[$item[1]] = true;
    }
}
$check($entries !== [], 'Acceptance requires an unlocked public installation');
$requestCount = count($results);
$results = [];
// Crawl every sitemap target without cookies like a crawler, without assuming its size or IDs.
foreach ($entries as $url => $alternates) {
    $run(['target' => [$relative($url)]]);
    $response = $results['target'];
    $seo = $parse($response['body']);
    $saveHtml($url, $response['body']);
    $check($response['status'] === 200 && $response['location'] === '', 'Direct sitemap target: ' . $url);
    $check($seo['canonical_count'] === 1 && $seo['canonical'] === $url && $seo['alternates'] === $alternates, 'Sitemap/HTML agreement: ' . $url);
    $check(!str_contains($seo['robots'], 'noindex') && !preg_match('/^X-Robots-Tag:.*noindex/mi', $response['headers']), 'Indexable sitemap target');
    foreach ($alternates as $alternate) {
        $check(isset($entries[$alternate]) && $entries[$alternate] === $alternates, 'Reciprocal sitemap cluster');
    }
    $requestCount++;
    $results = [];
}
$publicPaths = [];
foreach ($_rwlinks as $module => $route) {
    if (($route['indexable'] ?? false) && isset($route['sitemap'])) {
        $publicPaths[] = $module === 'index' ? '' : $route[0] . '/';
        if (in_array($module, ['news', 'faq', 'review'], true)) {
            $publicPaths[] = $route[0] . '/?page=2';
        }
    }
}
// Matrix one real article if available; the complete item inventory was crawled above.
$articleId = $itemPaths === [] ? null : explode('/', array_key_first($itemPaths))[1];
$articleTargets = [];
$fingerprint = static function (string $html) use ($parse, $check): array {
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="utf-8"?>' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($document);
    $main = $xpath->query('//main');
    $check($main->length === 1, 'Exactly one main content element');
    // Forms contain per-session CSRF tokens. Compare visible content and metadata, not secrets.
    foreach ($xpath->query('//main//script | //main//style') as $node) {
        $node->parentNode->removeChild($node);
    }
    return [$parse($html), preg_replace('/\s+/u', ' ', trim($main->item(0)->textContent))];
};
$matrixCount = 0;
foreach ($locales as $locale) {
    $localePaths = $publicPaths;
    if ($articleId !== null) {
        // Resolve the actual title in this locale; never copy another language's slug.
        $probePath = '/' . $locale . '/show/' . $articleId . '/';
        $run(['article' => [$probePath]]);
        $probe = $results['article'];
        $check(in_array($probe['status'], [200, 301], true), 'Article ID resolves to its localized path');
        $target = $probe['status'] === 301 ? $relative($probe['location']) : $probePath;
        $check(str_starts_with($target, '/' . $locale . '/show/' . $articleId . '/'), 'Article redirect preserves locale and ID');
        $articleTargets[$locale] = $target;
        $localePaths[] = substr($target, strlen('/' . $locale . '/'));
        $requestCount++;
        $results = [];
    }
    foreach ($localePaths as $path) {
        $url = '/' . $locale . '/' . $path;
        $cases = ['baseline' => [$url]];
        foreach (array_merge([''], $locales) as $cookie) {
            foreach (array_merge(['', 'zz;q=1,*;q=0.1'], $locales) as $accept) {
                $cases['matrix:' . $cookie . ':' . $accept] = [$url, $cookie === '' ? '' : 'lang=' . $cookie, $accept];
            }
        }
        $run($cases);
        $baseline = $results['baseline'];
        if ($path === 'intro/' && $baseline['status'] === 302) {
            $check($relative($baseline['location']) === '/' . $locale . '/', 'Disabled intro preserves locale');
        } else {
            $check($baseline['status'] === 200, 'Public response: ' . $url);
        }
        $expected = $baseline['status'] === 200 ? $fingerprint($baseline['body']) : null;
        if ($expected !== null) {
            $saveHtml($httpsRoot . ltrim($url, '/'), $baseline['body']);
        }
        foreach ($results as $key => $result) {
            $check($result['status'] === $baseline['status'] && $result['location'] === $baseline['location'], 'Preference-independent status: ' . $url . ' ' . $key);
            if ($expected !== null) {
                $actual = $fingerprint($result['body']);
                $check($actual === $expected, 'URL determines main text and metadata: ' . $url . ' ' . $key);
                $check($actual[0]['language'] === $locale, 'URL language');
                $check($actual[0]['canonical_count'] === 1 && str_starts_with($actual[0]['canonical'], $httpsRoot . $locale . '/'), 'Same-locale HTTPS canonical');
                $check(!preg_match('/^Vary:.*\bCookie\b/mi', $result['headers']), 'Public locale content does not require Vary: Cookie');
                // Existing pages contain session/user chrome: shared caches must obey no-store.
                $check((bool)preg_match('/^Cache-Control:.*\bno-store\b/mi', $result['headers']), 'Personalized response protected from shared caching');
            }
        }
        $matrixCount += count($results);
        $requestCount += count($results);
        $results = [];
    }
    $run([
        'legacy' => ['/news?page=2&utm_source=seo&url=https%3A%2F%2Fevil.example', 'lang=' . $locale, $locale],
        'private' => ['/login', 'lang=' . $locale],
        'sitemap' => ['/sitemap.xml', 'lang=' . $locale, $locale],
        'robots' => ['/robots.txt', 'lang=' . $locale, $locale],
    ]);
    $check($results['legacy']['status'] === 301 && $relative($results['legacy']['location']) === '/' . $primary . '/news/?page=2&utm_source=seo', 'Safe deterministic redirect');
    $private = $parse($results['private']['body']);
    $check($private['language'] === $locale && $private['canonical_count'] === 0 && $private['alternates'] === [], 'Private cookie workflow preserved');
    $check($results['sitemap']['body'] === $baselineSitemap['body'] && !preg_match('/^Set-Cookie:/mi', $results['sitemap']['headers']), 'Stateless sitemap');
    $check($results['robots']['body'] === $baselineRobots, 'Stateless robots');
    $requestCount += count($results);
    $results = [];
}
// Security/query normalization and a real encoded slug without assuming an item ID.
$checks = [
    'unknown' => ['/zzzz/news/'],
    'query' => ['/' . $primary . '/news/?page=2&utm_source=test&lang=zz&url=https%3A%2F%2Fevil.example'],
    'slash' => ['/' . $primary . '/news?page=2'],
    'missing' => ['/' . $primary . '/show/?id=2147483647'],
    'api' => ['/api/v1/user'],
    'admin' => ['/admin'],
    'cabinet' => ['/cabinet'],
    'configurator' => ['/_cfg?login'],
];
if ($itemPaths !== []) {
    preg_match('~^show/([0-9]+)/~', array_key_first($itemPaths), $item);
    $encodedPath = '/show/' . $item[1] . '/%D0%BD%D0%BE%D0%B2%D0%BE%D1%81%D1%82%D1%8C/';
    $checks['encoded'] = ['/' . $primary . $encodedPath];
    $checks['encoded-old'] = [$encodedPath, 'lang=' . $locales[count($locales) - 1]];
}
$run($checks);
$check($results['unknown']['status'] === 404 && $results['missing']['status'] === 404, 'Unknown locale and missing article');
$check($parse($results['query']['body'])['canonical'] === $httpsRoot . $primary . '/news/?page=2', 'Only functional parameters in canonical');
$check($results['slash']['status'] === 301 && $relative($results['slash']['location']) === '/' . $primary . '/news/?page=2', 'One slash normalization redirect preserves page');
if (isset($checks['encoded'])) {
    foreach (['encoded', 'encoded-old'] as $key) {
        $check($results[$key]['status'] === 301 && $relative($results[$key]['location']) === $articleTargets[$primary], 'Old/encoded slug redirects directly to the localized canonical: ' . $key);
    }
}
foreach (['api', 'admin', 'cabinet', 'configurator'] as $key) {
    $response = $results[$key];
    $check($response['status'] < 500, 'Technical/private route unavailable: ' . $key . ' (HTTP ' . $response['status'] . ')');
    if (str_contains($response['body'], '<html')) {
        $seo = $parse($response['body']);
        $check($seo['canonical_count'] === 0 && $seo['alternates'] === [], 'No SEO metadata on private response: ' . $key);
    }
    if ($response['location'] !== '') {
        $check(!preg_match('~/(?:' . implode('|', $locales) . ')/~', $response['location']), 'Private redirect has no locale prefix');
    }
}
$requestCount += count($results);
$results = [];

// Forbidden prefixed routes are rejected before their controllers, including cron/callbacks.
$blocked = ['_cfg', 'robots.txt', 'sitemap.xml', 'static/app.js'];
foreach ($_rwlinks as $route) {
    if (!($route['indexable'] ?? false)) {
        $blocked[] = $route[0];
    }
}
foreach (array_unique($blocked) as $path) {
    $run(['blocked' => ['/' . $primary . '/' . $path]]);
    $response = $results['blocked'];
    $seo = $parse($response['body']);
    $check($response['status'] === 404 && $response['location'] === '', 'Forbidden prefix: ' . $path);
    $check($seo['canonical_count'] === 0 && $seo['alternates'] === [], 'No SEO duplicates on forbidden route');
    $requestCount++;
    $results = [];
}
foreach (['show/1/a%2fb/', 'show/1/a%252fb/', 'show/1/a%5cb/', 'show/1/%0d%0aLocation:evil/', 'news//', '%2e%2e/news/', 'show/1/%zz/'] as $path) {
    $run(['unsafe' => ['/' . $primary . '/' . $path]]);
    $check(in_array($results['unsafe']['status'], [400, 403, 404], true) && $results['unsafe']['location'] === '', 'Unsafe path rejected: ' . $path);
    $requestCount++;
    $results = [];
}
echo 'SEO acceptance passed (' . implode(',', $locales) . '; ' . count($entries) . ' discovered sitemap URLs; ' . $matrixCount . ' matrix responses; ' . $requestCount . " total requests).\n";
if ($itemPaths === []) {
    echo "News item matrix skipped: no published sitemap article; synthetic fixtures cover article counts.\n";
}
