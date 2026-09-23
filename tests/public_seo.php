<?php

declare(strict_types=1);

use HScript\Http\LocaleRouter;
use HScript\Http\PublicSeo;
use HScript\Template\View;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/module/_config.php';
$check = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
};
$router = new LocaleRouter($_rwlinks);
$router->setLocales(['ru', 'en', 'de']);
$both = '{!ru!}Русский текст{!en!}English text{!!}';
$check(PublicSeo::textAvailable($both, 'en', 'ru'), 'Explicit translated field');
$check(!PublicSeo::textAvailable($both, 'de', 'ru'), 'Missing marker');
$check(!PublicSeo::textAvailable('Only source text', 'en', 'ru'), 'Unmarked text is not a translation');
$check(PublicSeo::textAvailable('Only source text', 'ru', 'ru'), 'Unmarked primary text retained');
$check(!PublicSeo::textAvailable('{!ru!}Русский{!en!}<p>&nbsp;</p>{!!}', 'en', 'ru'), 'Empty rich text translation');
$check(!PublicSeo::textAvailable($both . '{!ru!}Вторая секция{!!}', 'en', 'ru'), 'All independent blocks must be translated');
$check(!PublicSeo::textAvailable('{!EN!}Not rendered by the legacy parser{!!}', 'en', 'ru'), 'Marker case matches actual renderer');
$check(PublicSeo::textAvailable('', 'en', 'ru', true), 'Absent optional content');
$check(!PublicSeo::textAvailable('', 'en', 'ru'), 'Missing required content');
$rows = [['nTopic' => $both, 'nAnnounce' => '', 'nText' => '<p>' . $both . '</p>']];
$fields = PublicSeo::contentFields('news/show', 'el');
$check(PublicSeo::contentLocales($rows, $fields, ['ru', 'en', 'de'], 'ru') === ['ru', 'en'], 'News requires full content translation');
$rows[] = ['nTopic' => $both, 'nAnnounce' => '', 'nText' => 'Source only'];
$check(PublicSeo::contentLocales($rows, $fields, ['ru', 'en'], 'ru') === ['ru'], 'Mixed listing excludes untranslated page variant');
$check(PublicSeo::contentLocales([], $fields, ['ru', 'en'], 'ru') === ['ru', 'en'], 'Empty listing can use translated UI');
$reference = ['contacts.heading' => 'Contacts', 'nav.main' => 'Home'];
$catalogs = ['ru' => ['contacts.heading' => 'Контакты', 'nav.main' => 'Главная'], 'en' => $reference, 'de' => ['nav.main' => 'Start']];
$check(PublicSeo::catalogLocales('contact', $catalogs, $reference) === ['ru', 'en'], 'Missing catalog key cannot use fallback as proof');
$catalogs['en']['contacts.heading'] = '';
$check(PublicSeo::catalogLocales('contact', $catalogs, $reference) === ['ru'], 'Empty override is unavailable');

$query = ['page' => '2', 'utm_source' => 'mail', 'ref' => 'alice', 'url' => '//evil.test'];
$ru = PublicSeo::metadata($router, 'https://example.test/cms/', 'news', 'news/', 'ru', ['ru', 'en'], $query, 'ref');
$en = PublicSeo::metadata($router, 'https://example.test/cms/', 'news', 'news/', 'en', ['ru', 'en'], $query, 'ref');
$check($ru['canonical'] === 'https://example.test/cms/ru/news/?page=2', 'HTTPS canonical, subdirectory and pagination');
$check($en['canonical'] === 'https://example.test/cms/en/news/?page=2', 'Self-canonical cannot cross language');
$check($ru['alternates'] === $en['alternates'], 'Reciprocal identical cluster');
$check($ru['alternates']['x-default'] === $ru['canonical'], 'Primary x-default');
$check($ru['languages']['en'] === 'en/news/?page=2&utm_source=mail&ref=alice', 'Direct language switch preserves safe parameters');
$check(!isset($ru['alternates']['de']), 'Unavailable language excluded');
$incomplete = PublicSeo::metadata($router, 'https://example.test/', 'news', 'news/', 'en', ['ru']);
$check(!$incomplete['indexable'] && $incomplete['alternates'] === [], 'Untranslated current page is noindex without alternates');
$noindex = PublicSeo::metadata($router, 'https://example.test/', 'news', 'news/', 'en', ['ru', 'en'], [], '', false);
$check(!$noindex['indexable'] && $noindex['alternates'] === [], 'Non-200 or noindex page suppresses alternates');
$missingPrimary = PublicSeo::metadata($router, 'https://example.test/', 'news', 'news/', 'en', ['en']);
$check(!isset($missingPrimary['alternates']['x-default']), 'Do not advertise missing primary translation');
$item = PublicSeo::metadata($router, 'https://example.test/', 'news/show', 'show/42/existing-slug/', 'en', ['ru', 'en'], ['id' => '9', 'utm_source' => 'mail']);
$check($item['canonical'] === 'https://example.test/en/show/42/existing-slug/', 'Path ID wins and slug preserved');
$check($item['languages']['ru'] === 'ru/show/42/existing-slug/?utm_source=mail', 'Equivalent item switch preserves slug');

$paths = PublicSeo::newsPaths('show', ['nID' => 42, 'nTopic' => '{!ru!}Новая статья{!en!}New article{!!}'], ['ru', 'en', 'de'], 'ru');
$check($paths === ['ru' => 'show/42/novaya-stat-ya', 'en' => 'show/42/new-article', 'de' => 'show/42/'], 'Translated slugs; missing translation uses only ID');
$ruItem = PublicSeo::metadata($router, 'https://example.test/', 'news/show', 'show/42/old-slug/', 'ru', ['ru', 'en'], [], '', true, $paths);
$enItem = PublicSeo::metadata($router, 'https://example.test/', 'news/show', 'show/42/old-slug/', 'en', ['ru', 'en'], [], '', true, $paths);
$check($enItem['canonical'] === 'https://example.test/en/show/42/new-article/', 'Canonical uses translated title, not request slug');
$check($ruItem['alternates'] === $enItem['alternates'] && $enItem['languages']['ru'] === 'ru/show/42/novaya-stat-ya/', 'Switch and hreflang use target-language slug');
$check(PublicSeo::newsPath('show', 42, 'News &amp; updates') === PublicSeo::newsPath('show', 42, 'News & updates'), 'Escaped listing title and raw sitemap title agree');

// Exercise the real View boundary: capture raw multilingual data before prepVal.
global $_localeRouter, $_cfg, $_GS;
$_localeRouter = $router;
$_cfg['UI__Langs'] = ['ru', 'en', 'de'];
$_GS['module'] = 'news/show';
$_GS['url_locale'] = $_GS['lang'] = 'en';
View::setPage('el', ['nTopic' => $both, 'nAnnounce' => '', 'nText' => $both], 1);
$property = new ReflectionProperty(View::class, 'publicContentLocales');
$check($property->getValue() === ['ru', 'en'], 'View captures availability before marker filtering');
$context = (new ReflectionProperty(View::class, 'context'))->getValue();
$check($context['el']['nTopic'] === 'English text', 'Actual display language agrees with availability');

echo "Public SEO component tests passed.\n";
