<?php

declare(strict_types=1);

$parse = static function (string $html): array {
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="utf-8"?>' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($document);
    $value = static fn(string $expression): string => $xpath->evaluate('string(' . $expression . ')');
    $alternates = [];
    foreach ($xpath->query('//head/link[@rel="alternate"][@hreflang]') as $node) {
        $language = $node->getAttribute('hreflang');
        if (isset($alternates[$language])) {
            throw new RuntimeException('Duplicate hreflang: ' . $language);
        }
        $alternates[$language] = $node->getAttribute('href');
    }
    $switches = [];
    foreach ($xpath->query('//a[@data-public-language]') as $node) {
        $switches[$node->getAttribute('data-public-language')] = $node->getAttribute('href');
    }
    $schemas = [];
    foreach ($xpath->query('//script[@type="application/ld+json"]') as $node) {
        $schemas[] = json_decode($node->textContent, true, 512, JSON_THROW_ON_ERROR);
    }
    return [
        'canonical' => $value('//head/link[@rel="canonical"]/@href'),
        'canonical_count' => $xpath->query('//head/link[@rel="canonical"]')->length,
        'language' => $value('//html/@lang'),
        'robots' => $value('//meta[@name="robots"]/@content'),
        'og_locale' => $value('//meta[@property="og:locale"]/@content'),
        'og_url' => $value('//meta[@property="og:url"]/@content'),
        'title' => $value('//title'),
        'og_title' => $value('//meta[@property="og:title"]/@content'),
        'description' => $value('//meta[@name="description"]/@content'),
        'og_description' => $value('//meta[@property="og:description"]/@content'),
        'alternates' => $alternates,
        'switches' => $switches,
        'schemas' => $schemas,
    ];
};
