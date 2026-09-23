<?php

declare(strict_types=1);

namespace HScript\Http;

use HScript\Content\HtmlSanitizer;
use HScript\Content\PublicCatalog;

final class LocalizedSitemap
{
    public function __construct(
        private LocaleRouter $router,
        private array $routes,
        private string $httpsRoot,
        private array $config,
        private array $catalogs,
        private array $reference
    ) {
    }

    /** Keep the existing sitemap scope: registered landing pages and news items. */
    public function entries(array $listingRows, iterable $news, string $now): iterable
    {
        if (!empty($this->config['Sys_LockSite'])) {
            return;
        }
        foreach ($this->routes as $module => $route) {
            if (!$this->router->isIndexable($module) || empty($route['sitemap']) || !is_array($route['sitemap'])) {
                continue;
            }
            if ($module === 'udp/intro' && empty($this->config['UI_ShowIntro'])) {
                continue;
            }
            $fields = PublicSeo::contentFields($module, 'list');
            // A missing dataset must not be mistaken for an empty public page.
            if ($fields !== [] && !array_key_exists($module, $listingRows)) {
                continue;
            }
            $available = $this->available($module, $fields, $listingRows[$module] ?? []);
            yield from $this->variants($module, $route[0], $available, $route['sitemap']);
        }
        if (!$this->router->isIndexable('news/show')) {
            return;
        }
        $seen = [];
        foreach ($news as $item) {
            $id = (int)($item['nID'] ?? 0);
            if ($id <= 0 || isset($seen[$id]) || !PublicCatalog::newsIsPublished($item, $now)) {
                continue;
            }
            $seen[$id] = true;
            // The detail controller sanitizes the body before checking translations.
            $item['nText'] = HtmlSanitizer::sanitize((string)($item['nText'] ?? ''));
            $available = $this->available('news/show', PublicSeo::contentFields('news/show', 'el'), [$item]);
            if ($available === []) {
                continue;
            }
            $paths = PublicSeo::newsPaths($this->routes['news/show'][0], $item, $available, $this->router->primaryLocale());
            $path = $paths[$available[0]];
            $lastmod = self::lastModified((string)($item['nTS'] ?? ''));
            yield from $this->variants('news/show', $path, $available,
                ['changefreq' => 'monthly', 'priority' => '0.6', 'lastmod' => $lastmod], $paths);
        }
    }

    private function available(string $module, array $fields, array $rows): array
    {
        $locales = PublicSeo::catalogLocales($module, $this->catalogs, $this->reference);
        return $fields === [] ? $locales
            : PublicSeo::contentLocales($rows, $fields, $locales, $this->router->primaryLocale());
    }

    private function variants(string $module, string $path, array $available, array $properties, array $paths = []): iterable
    {
        foreach ($available as $locale) {
            $seo = PublicSeo::metadata($this->router, $this->httpsRoot, $module, $path, $locale, $available, [], '', true, $paths);
            if ($seo['indexable']) {
                yield [
                    'loc' => $seo['canonical'],
                    'alternates' => $seo['alternates'],
                    'lastmod' => $properties['lastmod'] ?? '',
                    'changefreq' => $properties['changefreq'] ?? 'monthly',
                    'priority' => $properties['priority'] ?? '0.5',
                ];
            }
        }
    }

    private static function lastModified(string $stamp): string
    {
        $date = \DateTimeImmutable::createFromFormat('!YmdHis', $stamp, new \DateTimeZone('UTC'));
        return $date && $date->format('YmdHis') === $stamp ? $date->format('c') : '';
    }

    /** Yield escaped XML chunks so the controller need not buffer the document. */
    public static function xml(iterable $entries): iterable
    {
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        yield "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        yield "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\" xmlns:xhtml=\"http://www.w3.org/1999/xhtml\">\n";
        foreach ($entries as $entry) {
            yield "  <url>\n    <loc>" . $escape($entry['loc']) . "</loc>\n";
            foreach ($entry['alternates'] as $locale => $url) {
                yield '    <xhtml:link rel="alternate" hreflang="' . $escape($locale)
                    . '" href="' . $escape($url) . '" />' . "\n";
            }
            if ($entry['lastmod'] !== '') {
                yield '    <lastmod>' . $escape($entry['lastmod']) . "</lastmod>\n";
            }
            yield '    <changefreq>' . $escape($entry['changefreq']) . "</changefreq>\n";
            yield '    <priority>' . $escape($entry['priority']) . "</priority>\n  </url>\n";
        }
        yield "</urlset>\n";
    }
}
