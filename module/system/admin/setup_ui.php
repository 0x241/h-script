<?php

use HScript\Template\View;

$module = 'UI';
require_once('module/admin/setup.php');

$langs = array();
if (!empty($_cfg['_Langs'])) {
	$cfg_langs = is_array($_cfg['_Langs']) ? $_cfg['_Langs'] : explode("\n", str_replace("\r", "", $_cfg['_Langs']));
	foreach ($cfg_langs as $l) {
		$l = trim($l);
		if ($l) $langs[] = $l;
	}
}
if (!empty($_POST['UI__Langs'])) {
	$post_langs = is_array($_POST['UI__Langs']) ? $_POST['UI__Langs'] : explode("\n", str_replace("\r", "", $_POST['UI__Langs']));
	foreach ($post_langs as $l) {
		$l = trim($l);
		if ($l && !in_array($l, $langs)) $langs[] = $l;
	}
}

foreach ($langs as $l) {
	$json_path = 'lang/' . $l . '.json';
	if (!file_exists($json_path)) {
		if (file_exists('lang/en.json')) {
			if (!copy('lang/en.json', $json_path))
				xAddToLog('Unable to copy lang/en.json to ' . $json_path, 'translations');
		} else {
			if (file_put_contents($json_path, "{\n\n}", LOCK_EX) === false)
				xAddToLog('Unable to initialize ' . $json_path, 'translations');
		}
	}
}

View::showPage();

?>
