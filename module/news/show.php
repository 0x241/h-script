<?php

use HScript\Template\View;
use HScript\Cache\CatalogCache;
use HScript\Content\HtmlSanitizer;
use HScript\Content\PublicCatalog;
use HScript\Http\PublicSeo;

require_once('module/auth.php');

$table = 'News';
$id_field = 'nID';
$el = [];

if (_GETN('id'))
{
	$id = _GETN('id');
	$el = $catalogCache->remember(
		CatalogCache::NEWS,
		'item:' . $id,
		static fn(): array => $db->fetch1Row(
			$db->select('News', '*', 'nID=?d', array($id))
		)
	);
}
// A cached record still needs its publication window checked on each request.
$now = timeToStamp();
if (!PublicCatalog::newsIsPublished($el, $now))
	hsRouteNotFound();
// Resolve locale paths from raw markers, before View filters the displayed fields.
$_GS['public_paths'] = PublicSeo::newsPaths($_rwlinks['news/show'][0], $el, $_cfg['UI__Langs'], $_localeRouter->primaryLocale());
$target = $_localeRouter->url('news/show', $_GS['public_paths'][$_GS['url_locale']], $_GS['url_locale'], $_GET, (string)$_cfg['Ref_Word']);
$https = (int)$_cfg['Sec_HTTPSMode'] === 1 || $_GS['https'];
if (explode('?', $_GS['uri'], 2)[0] !== explode('?', $target, 2)[0] || ($https && !$_GS['https']))
{
    $status = in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true) ? 301 : 308;
    header('Location: ' . fullURL($target, $https), true, $status);
    exit;
}
$el['nText'] = HtmlSanitizer::sanitize((string)($el['nText'] ?? ''));
View::stampArrayToStr($el, 'nTS', 0);
View::setPage('el', $el, 1);

View::showPage();

?>
