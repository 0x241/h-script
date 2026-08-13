<?php

use HScript\Util\StringHelper;

use HScript\Cache\CatalogCache;
use HScript\Template\View;

function newsGetBlock($n = 0)
{
	global $db, $_cfg, $catalogCache;
	if ($n <= 0)
		$n = StringHelper::exValue(5, $_cfg['News_InBlock']);
	$list = $catalogCache->remember(
		CatalogCache::NEWS,
		'block:' . $n,
		static fn(): array => $db->fetchIDRows($db->select('News', '*',
			'(nDBegin=0 or nDBegin<=?) and (nDEnd=0 or nDEnd>=?)', array(timeToStamp(), timeToStamp()),
			'nAttn desc, nTS desc, nID desc', $n), false, 'nID')
	);
	View::stampTableToStr($list, 'nTS', 0);
	return $list;
}

?>
