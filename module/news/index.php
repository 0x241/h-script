<?php

use HScript\Template\View;
use HScript\Content\PublicCatalog;

require_once('module/auth.php');

$table = 'News';
$id_field = 'nID';
	
$n = $_cfg['News_ShowCount'];
if (!$n)
	$n = 10;
$list = opArrayPageGet(_GETN('page'), $n, (new PublicCatalog($db, $catalogCache))->news());
View::stampTableToStr($list, 'nTS', 0);
View::setPage('list', $list);

View::showPage();

?>
