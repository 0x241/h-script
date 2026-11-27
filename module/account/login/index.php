<?php

use HScript\Util\StringHelper;

use HScript\Template\View;

require_once('module/auth.php');

if (!function_exists('loginReturnUrl'))
{
	function loginReturnUrl($url): string
	{
		$url = (string)$url;
		if (preg_match('/[\x00-\x1f\x7f]/', $url))
			return '';
		$url = trim($url);
		$parts = parse_url($url);
		if ($url === '' || !is_array($parts) || isset($parts['scheme']) || isset($parts['host']) || str_starts_with($url, '//') || str_contains($url, '\\'))
			return '';
		return $url;
	}
}

try 
{
	if (View::sendedForm('', 'return_admin'))
	{
		View::checkFormSecurity('return_admin');
		$impersonator_id = intval($_SESSION['_impersonator_uid'] ?? 0);
		$impersonator = $impersonator_id > 0 ? opReadUser($impersonator_id) : array();
		if (!$impersonator or (intval($impersonator['uLevel'] ?? 0) < 90) or (intval($impersonator['uState'] ?? 0) != 1))
		{
			unset(
				$_SESSION['_impersonator_uid'],
				$_SESSION['_impersonator_login'],
				$_SESSION['_impersonator_started']
			);
			View::showInfo('*Denied', moduleToLink('cabinet'));
		}
		View::setError(opLogin($impersonator_id, moduleToLink('admin'), true, false, false, false), 'return_admin');
	}

	if (View::sendedForm('logout', 'logout'))
	{
		View::checkFormSecurity('logout');
		opLoginOut('user');
		View::showInfo('LogOut', moduleToLink('index'));
	}

	if (_uid())
		goToURL(StringHelper::exValue(moduleToLink('cabinet'), loginReturnUrl(urldecode((string)_RQ('url')))));

	if (View::sendedForm('', 'login_frm'))
	{
		View::checkFormSecurity('login_frm');

		$gaCode = !StringHelper::sEmpty(_IN('GA')) ? _IN('GA') : _IN('GACode');
		View::setError($uid = opLoginPrepare(_IN('Login'), _IN('Pass'), $gaCode), 'login_frm');
		View::setError(opLogin($uid, loginReturnUrl(_IN('URL')), false, false, _IN('Remember')), 'login_frm');
		View::showInfo('*Error');
	}

} 
catch (FormAbortException $e)
{
}

View::setPage('url', urldecode((string)_RQ('url')));

$demoAccessAccount = array();
if (!empty($_GS['demo']))
{
	$login = trim((string)($_cfg['demo_access_admin_login'] ?? ''));
	$password = trim((string)($_cfg['demo_access_admin_password'] ?? ''));
	if ($login !== '' && $password !== '')
		$demoAccessAccount = array('login' => $login, 'password' => $password);
}
View::setPage('demo_access_account', $demoAccessAccount, 0);

// External authentication providers may resume the originally requested URL.
$_SESSION['_go_after_login'] = _RQ('url');

View::showPage();

?>
