<?php

use HScript\Template\View;

$_auth = 1;
require_once('module/auth.php');

$n = $_cfg['Msg_ShowCount'];
if (!$n)
	$n = 10;
		
$list = messageUserConversationSummaries(_uid(), _GETN('page'), $n);
View::stampTableToStr($list, 'mTS, bRTS', 2);

View::setPage('list', $list);

View::showPage();

?>
