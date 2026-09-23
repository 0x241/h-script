<?php

declare(strict_types=1);

namespace HScript\Http;

use HScript\Util\StringHelper;

/** Availability and URL metadata shared by public HTML and future sitemap output. */
final class PublicSeo
{
    private const PREFIXES = [
        'index' => ['home.', 'news.', 'reviews.', 'faq.'],
        'contact' => ['contacts.'],
        'news' => ['news.'],
        'news/show' => ['news.'],
        'faq' => ['faq.'],
        'review' => ['reviews.'],
        'udp/rules' => ['rules.'],
        'udp/intro' => ['intro.'],
        'udp/about' => ['about.'],
    ];

    /** Only raw editorial fields, before View removes multilingual markers. */
    public static function contentFields(string $module, string $variable): array
    {
        return match (true) {
            ($module === 'news' && $variable === 'list'),
            ($module === 'news/show' && $variable === 'el') => ['nTopic' => false, 'nAnnounce' => true, 'nText' => false],
            ($module === 'faq' && $variable === 'list') => ['fQuestion' => false, 'fAnswer' => false, 'fCat' => true],
            ($module === 'review' && $variable === 'list') => ['oText' => false],
            default => [],
        };
    }

    public static function textAvailable(string $text, string $locale, string $primary, bool $optional = false): bool
    {
        if (self::emptyContent($text)) {
            return $optional;
        }
        if (!preg_match('/(?<!\/)\{![a-z]{2,3}(?:-[a-z]{2})?!\}/i', $text)) {
            // Legacy records have no source-locale field: unmarked content is
            // assigned to the configured primary language, never guessed.
            return $locale === $primary;
        }
        // Every independent marker block must be translated, not just the first.
        $found = false;
        foreach (preg_split('/(?<!\/)\{!(?:\/[^!]*|)!\}/', $text) as $block) {
            preg_match_all('/(?<!\/)\{!([a-z]{2,3}(?:-[a-z]{2})?)!\}(.*?)(?=(?<!\/)\{!.*?!\}|$)/is', $block, $parts, PREG_SET_ORDER);
            if ($parts === []) {
                continue;
            }
            $translated = false;
            foreach ($parts as $part) {
                if ($part[1] === $locale && !self::emptyContent($part[2])) {
                    $translated = true;
                }
            }
            if (!$translated) {
                return false;
            }
            $found = true;
        }
        return $found;
    }

    private static function emptyContent(string $text): bool
    {
        return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \t\n\r\0\x0B\xc2\xa0") === ''
            && !preg_match('/<(?:img|video|audio|iframe)\b/i', $text);
    }

    public static function contentLocales(array $rows, array $fields, array $locales, string $primary): array
    {
        return array_values(array_filter($locales, static function (string $locale) use ($rows, $fields, $primary): bool {
            foreach ($rows as $row) {
                foreach ($fields as $field => $optional) {
                    if (!self::textAvailable((string)($row[$field] ?? ''), $locale, $primary, $optional)) {
                        return false;
                    }
                }
            }
            return true;
        }));
    }

    /** No fallback catalogs: their presence would not prove an actual translation. */
    public static function catalogLocales(string $module, array $catalogs, array $reference): array
    {
        if (!isset(self::PREFIXES[$module])) {
            return [];
        }
        $prefixes = array_merge(['nav.', 'site.', 'theme.', 'pagination.'], self::PREFIXES[$module]);
        $required = array_filter(array_keys($reference), static function (string $key) use ($prefixes): bool {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    return true;
                }
            }
            return false;
        });
        return array_keys(array_filter($catalogs, static function (array $catalog) use ($required): bool {
            foreach ($required as $key) {
                if (!is_string($catalog[$key] ?? null) || trim($catalog[$key]) === '') {
                    return false;
                }
            }
            return $required !== [];
        }));
    }

    /** The ID identifies the article; each locale uses its own translated title. */
    public static function newsPath(string $alias, int $id, string $title): string
    {
        $title = html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return $alias . '/' . $id . '/' . StringHelper::toTranslitURL($title);
    }

    public static function newsPaths(string $alias, array $item, array $locales, string $primary): array
    {
        $paths = [];
        $topic = (string)($item['nTopic'] ?? '');
        foreach ($locales as $locale) {
            $title = self::textAvailable($topic, $locale, $primary)
                ? StringHelper::textLangFilter($topic, $locale) : '';
            $paths[$locale] = self::newsPath($alias, (int)$item['nID'], $title);
        }
        return $paths;
    }

    public static function metadata(LocaleRouter $router, string $httpsRoot, string $module, string $path, string $locale, array $available, array $query = [], string $referralKey = '', bool $eligible = true, array $paths = []): array
    {
        $root = rtrim($httpsRoot, '/') . '/';
        $indexable = $eligible && $router->isIndexable($module) && in_array($locale, $available, true);
        $canonical = $root . $router->url($module, $paths[$locale] ?? $path, $locale, $query, $referralKey, true);
        $alternates = [];
        $languages = [];
        foreach ($available as $language) {
            if (!$router->supports($language)) {
                continue;
            }
            $languages[$language] = $router->url($module, $paths[$language] ?? $path, $language, $query, $referralKey);
            if ($indexable) {
                $alternates[$language] = $root . $router->url($module, $paths[$language] ?? $path, $language, $query, $referralKey, true);
            }
        }
        // A missing primary translation cannot be advertised as an x-default.
        if (isset($alternates[$router->primaryLocale()])) {
            $alternates['x-default'] = $alternates[$router->primaryLocale()];
        }
        return [
            'canonical' => $canonical,
            'alternates' => $alternates,
            'languages' => $languages,
            'indexable' => $indexable,
            'locale' => $locale,
        ];
    }
}
