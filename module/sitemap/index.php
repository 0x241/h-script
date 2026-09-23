<?php

use HScript\Content\PublicCatalog;
use HScript\Http\LocalizedSitemap;
use HScript\Template\View;

$_GS['stateless'] = true;
$_smode = 2;
require_once('module/auth.php');

header('Content-Type: application/xml; charset=utf-8');
// Always revalidate: publication windows and editorial visibility can change.
header('Cache-Control: no-cache');

$catalog = new PublicCatalog($db, $catalogCache);
$news = [];
$listingRows = [];
if (empty($_cfg['Sys_LockSite']))
{
    $news = $catalog->news();
    $listingRows = [
        'news' => array_slice($news, 0, (int)($_cfg['News_ShowCount'] ?: 10)),
        'faq' => array_slice($catalog->faq(), 0, (int)($_cfg['FAQ_ShowCount'] ?: 10)),
        'review' => $catalog->reviews((int)($_cfg['Review_ShowCount'] ?: 10)),
    ];
}
$translations = [];
foreach ($_cfg['UI__Langs'] as $locale)
    $translations[$locale] = View::translationReadFile($locale);
$sitemap = new LocalizedSitemap($_localeRouter, $_rwlinks, getRootURL(true), $_cfg,
    $translations, View::translationReadBundledFile('en'));
foreach (LocalizedSitemap::xml($sitemap->entries($listingRows, $news, timeToStamp())) as $chunk)
    echo $chunk;
