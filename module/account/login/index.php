<?php

use HScript\Util\StringHelper;

use HScript\Template\View;

require_once('module/auth.php');

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

	if (isset($_GET['out']))
		{
			if (!_uid())
				goToURL(moduleToLink('index'));
			opLoginOut(_GET('out'));
			View::showInfo('LogOut');
	}

	if (_uid())
		goToURL(StringHelper::exValue(moduleToLink('cabinet'), urldecode((string)_RQ('url'))));

	if (View::sendedForm('', 'login_frm'))
	{
		View::checkFormSecurity('login_frm');

		View::setError($uid = opLoginPrepare(_IN('Login'), _IN('Pass')), 'login_frm');
		View::setError(opLogin($uid, _IN('URL'), false, false, _IN('Remember')), 'login_frm');
		View::showInfo('*Error');
	}

} 
catch (FormAbortException $e)
{
}

View::setPage('url', urldecode((string)_RQ('url')));

// External authentication providers may resume the originally requested URL.
$_SESSION['_go_after_login'] = _RQ('url');

View::showPage();

?>
