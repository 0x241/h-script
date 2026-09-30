<?php

use HScript\Util\StringHelper;
use HScript\Security\SessionCsrf;

use HScript\Template\View;

require('module/auth.php');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST')
{
	View::setPage('url', (string)($_SERVER['HTTP_REFERER'] ?? moduleToLink('index')));
	View::setPage('langs', $_cfg['UI__Langs']);
	View::showPage();
}

if (View::sendedForm())
	View::checkFormSecurity();
elseif (!SessionCsrf::valid($_POST['session_csrf'] ?? null))
{
	http_response_code(403);
	exit;
}

$lang = $_POST['lang'] ?? null;
if (!is_string($lang) || !in_array($lang, (array)$_cfg['UI__Langs'], true))
{
	http_response_code(400);
	exit;
}
$requestedMode = is_string($_POST['mode'] ?? null) ? trim($_POST['mode']) : '';
$mode = View::normalizeTemplateMode($requestedMode);
$theme = is_string($_POST['theme'] ?? null) ? strtolower(trim($_POST['theme'])) : '';
if (!in_array($theme, array('light', 'dark'), true))
	$theme = '';
$url = is_string($_POST['url'] ?? null) ? $_POST['url'] : (string)($_SERVER['HTTP_REFERER'] ?? '');
if (fullURL(StringHelper::get1ElemL($url, '?')) == fullURL(moduleToLink('system')))
	$url = moduleToLink('index');

if ($lang !== '')
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
