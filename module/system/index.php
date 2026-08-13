<?php

use HScript\Util\StringHelper;

use HScript\Template\View;

require('module/auth.php');

$lang = (string)_RQ('lang');
$requestedMode = trim((string)_RQ('mode'));
$mode = View::normalizeTemplateMode($requestedMode);
$theme = strtolower(trim((string)_RQ('theme')));
if (!in_array($theme, array('light', 'dark'), true))
	$theme = '';
$url = StringHelper::exValue(isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '', _RQ('url'));
if (fullURL(StringHelper::get1ElemL($url, '?')) == fullURL(moduleToLink('system')))
	$url = moduleToLink('index');
if (StringHelper::sEmpty($lang))
{
	View::setPage('url', $url);
	View::setPage('langs', $_cfg['UI__Langs']);
	View::showPage();
}

if ($lang)
{
	$_SESSION['_lang'] = $lang;
	setcookie('lang', $lang, time() + 30 * HS2_UNIX_DAY, '/');
	if ($requestedMode !== '' && View::templateModeExists($mode))
		setcookie('mode', $mode, time() + 30 * HS2_UNIX_DAY, '/');
	if ($theme !== '')
		setcookie('theme', $theme, time() + 30 * HS2_UNIX_DAY, '/');
	if (_uid())
	{
		$upd = array('uLang' => $lang);
		if ($requestedMode !== '' && View::templateModeExists($mode)) $upd['uMode'] = $mode;
		if ($theme !== '') $upd['uTheme'] = $theme;
		$db->update('Users', $upd, '', 'uID=?d', array(_uid()));
	}
}		

goToURL($url);

?>
