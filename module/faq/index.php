<?php

use HScript\Template\View;
use HScript\Cache\CatalogCache;
use HScript\Content\HtmlSanitizer;

require_once('module/auth.php');

$table = 'FAQ';
$id_field = 'fID';
	
$n = $_cfg['FAQ_ShowCount'];
if (!$n)
	$n = 10;
$list = opCachedCatalogPageGet(
	CatalogCache::FAQ,
	'public-visible',
	_GETN('page'),
	$n,
	static fn(): array => $db->fetchIDRows(
		$db->select('FAQ', '*', 'fHidden=0', array(), 'fCat, fOrder, fID'),
		false,
		'fID'
	)
);

View::setPage('list', $list, 1);
$categories = array();
foreach ($list as $item)
{
	$item['fAnswer'] = HtmlSanitizer::sanitize((string)($item['fAnswer'] ?? ''));
	$category = trim((string)($item['fCat'] ?? ''));
	$last = count($categories) - 1;
	if ($last < 0 || $categories[$last]['name'] !== $category)
	{
		$categories[] = array('name' => $category, 'items' => array());
		$last++;
	}
	$categories[$last]['items'][] = $item;
}
View::setPage('categories', $categories, 1);

View::showPage();

?>
