<?php

use HScript\Template\View;
use HScript\Cache\CatalogCache;
use HScript\Content\HtmlSanitizer;

require_once('module/auth.php');

$table = 'News';
$id_field = 'nID';
$out_link = moduleToLink('newses');
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
if (!$el)
	goToURL($out_link);
$el['nText'] = HtmlSanitizer::sanitize((string)($el['nText'] ?? ''));
View::stampArrayToStr($el, 'nTS', 0);
View::setPage('el', $el, 1);

View::showPage();

?>
