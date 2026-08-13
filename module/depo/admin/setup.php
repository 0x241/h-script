<?php

use HScript\Template\View;

$module = 'Depo';
require_once('module/admin/setup.php');

$depoLastTime = isset($_cfg['Depo_LastTime']) ? $_cfg['Depo_LastTime'] : 0;
View::setPage('depolasttime', View::timeToStr(stampToTime($depoLastTime), 2));
View::setPage('depolast', round(subStamps($depoLastTime) / HS2_UNIX_MINUTE));

View::showPage();

?>
