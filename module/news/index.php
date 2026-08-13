<?php

use HScript\Template\View;
use HScript\Cache\CatalogCache;

require_once('module/auth.php');

$table = 'News';
$id_field = 'nID';
	
$n = $_cfg['News_ShowCount'];
if (!$n)
	$n = 10;
$list = opCachedCatalogPageGet(
	CatalogCache::NEWS,
	'public-active',
	_GETN('page'),
	$n,
	static fn(): array => $db->fetchIDRows(
		$db->select(
			'News',
			'*',
			'(nDBegin=0 or nDBegin<=?) and (nDEnd=0 or nDEnd>=?)',
			array(timeToStamp(), timeToStamp()),
			'nAttn desc, nTS desc, nID desc'
		),
		false,
		'nID'
	)
);
View::stampTableToStr($list, 'nTS', 0);
View::setPage('list', $list);

View::showPage();

?>
