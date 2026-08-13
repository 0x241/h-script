<?php

error_reporting(7);
startSessionSafely();

if (isset($_GET['lang'])) {
	$cfgRequestedLang = strtolower((string)$_GET['lang']);
	$_SESSION['cfg_lang'] = preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $cfgRequestedLang) ? $cfgRequestedLang : 'en';
	$redir = '?' . $_SERVER['QUERY_STRING'];
	$redir = preg_replace('/&?lang=[a-z]+/i', '', $redir);
	$redir = str_replace('?&', '?', $redir);
	header("Location: " . ($redir === '?' ? '?modules' : $redir));
	exit;
}
if (isset($_GET['theme'])) {
	$_SESSION['cfg_theme'] = ($_GET['theme'] === 'light') ? 'light' : 'dark';
	$redir = '?' . $_SERVER['QUERY_STRING'];
	$redir = preg_replace('/&?theme=(light|dark)/i', '', $redir);
	$redir = str_replace('?&', '?', $redir);
	header("Location: " . ($redir === '?' ? '?modules' : $redir));
	exit;
}
if (!isset($_SESSION['cfg_lang'])) {
	global $_cfg;
	$_SESSION['cfg_lang'] = (isset($_cfg['Sys_AdminLang']) && $_cfg['Sys_AdminLang'] === 'ru') ? 'ru' : 'en';
}
if (!function_exists('cfg_t')) {
	function cfg_t($ru, $en) {
		return ($_SESSION['cfg_lang'] === 'ru') ? $ru : $en;
	}
}

function getMsg()
{
	return '' . (isset($_SESSION['cfg_info_message']) ? $_SESSION['cfg_info_message'] : '');
}

function addMsg($s)
{
	if (!isset($_SESSION['cfg_info_message']))
		$_SESSION['cfg_info_message'] = '';
	$_SESSION['cfg_info_message'] .= "$s<br>";
}

function showMsg()
{
	if ($s = getMsg())
	{
		unset($_SESSION['cfg_info_message']);
		echo '<div class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-bold text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-100">' . $s . '</div>';
	}
}

if (empty($_SESSION['cfg_logged']))
	if (!isset($_GET['login']))
		goToURL($_cfg['cfg_link'] . '?login');

$pass = is_readable('module/_config/pass') ? trim(file_get_contents('module/_config/pass')) : '';
if (!$pass)
	$_GET['pass'] = 1;
elseif (!$_cfg['cfg_link'])
	$_GET['setup'] = 1;
foreach (array('login', 'pass', 'setup', 'install', 'modules', 'update') as $m)
	if (isset($_GET[$m]))
	{
		include("module/_config/$m.php");
		exit;
	}
	
include("module/_config/modules.php");

?>
