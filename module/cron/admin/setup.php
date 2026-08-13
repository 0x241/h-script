<?php

use HScript\Template\View;

$module = 'Cron';
require_once('module/admin/setup.php');

$list = array();
foreach ($_oncron as $m => $n)
	$list[] = array(
		'module' => $m,
		'interval' => $n,
		'has_run' => !empty($_cfg['Cron_' . $m]),
		'minutes' => !empty($_cfg['Cron_' . $m])
			? max(0, floor(subStamps($_cfg['Cron_' . $m]) / HS2_UNIX_MINUTE) + $n)
			: 0
	);
View::setPage('cronlist', $list);
View::showPage();

?>
