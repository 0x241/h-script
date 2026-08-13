<?php

use HScript\Template\View;

require_once('module/auth.php');

if (!$_cfg['UI_ShowIntro'])
	goToURL(moduleToLink('index'));

View::showPage();

?>