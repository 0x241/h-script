<?php

declare(strict_types=1);

use HScript\Http\LocaleRouter;
use HScript\Http\LocalizedSitemap;
use HScript\Template\View;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/module/_config.php';
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$reference = View::translationReadBundledFile('en');
$scenarios = 0;
// Synthetic catalogs intentionally have complete keys. This tests topology, not translation quality.
foreach ([['en'], ['fr', 'de'], ['es', 'ru', 'en', 'de', 'fr']] as $languages) {
    $router = new LocaleRouter($_rwlinks);
    $router->setLocales($languages);
    $catalogs = array_fill_keys($languages, $reference);
    $text = implode('', array_map(static fn(string $language): string => '{!' . $language . '!}Article ' . $language, $languages)) . '{!!}';
    foreach (['https://tenant.example/', 'https://another.example/nested/cms/'] as $root) {
        foreach ([0, 1, 37, 203] as $count) {
            $news = [];
            for ($i = 0; $i < $count; $i++) {
                $news[] = ['nID' => 7001 + $i * 13, 'nTopic' => $text, 'nText' => $text, 'nAnnounce' => '', 'nTS' => '20260901000000', 'nDBegin' => 0, 'nDEnd' => 0];
            }
            $map = new LocalizedSitemap($router, $_rwlinks, $root, [], $catalogs, $reference);
            $lists = ['news' => array_slice($news, 0, 7), 'faq' => [], 'review' => []];
            $empty = iterator_to_array($map->entries($lists, [], '20260923000000'), false);
            $entries = iterator_to_array($map->entries($lists, $news, '20260923000000'), false);
            $check(count($entries) === count($empty) + $count * count($languages), 'Every published item/locale included without fixed limits');
            $byUrl = array_column($entries, null, 'loc');
            $check(count($entries) === count($byUrl), 'No duplicate URLs');
            foreach ($entries as $entry) {
                $check(str_starts_with($entry['loc'], $root), 'Installation host and subdirectory preserved');
                $check(count($entry['alternates']) === count($languages) + 1, 'Configured language count plus x-default');
                $check(str_starts_with($entry['alternates']['x-default'], $root . $languages[0] . '/'), 'Configured primary need not be RU');
                foreach ($entry['alternates'] as $url) {
                    $check(isset($byUrl[$url]) && $byUrl[$url]['alternates'] === $entry['alternates'], 'Reciprocal complete cluster');
                }
            }
            $scenarios++;
        }
    }
}
echo "SEO portability tests passed ($scenarios scenarios; 1/2/5 languages, 0/1/37/203 articles, two hosts and roots).\n";
