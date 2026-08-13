<?php

use HScript\Template\View;

$module = 'Bal';
require_once('module/admin/setup.php');

View::setPage('lastupdate', View::timeToStr(stampToTime($_cfg['Bal_LastUpdate']), 2));

View::showPage();

?>