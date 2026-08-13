<?php

use HScript\Util\StringHelper;

use HScript\Template\View;

require_once('module/auth.php');

try 
{

	function _resetPass($uid) 
	{
		global $_cfg;
		useLib('confirm');
		if (opConfirmPrepare($uid, 'RSTPASS'))
			View::showInfo('Saved', moduleToLink() . '?need_confirm');
		View::showInfo('*Error');
	}
	
	if (View::sendedForm())
	{
		View::checkFormSecurity();

		if (StringHelper::sEmpty(_IN('Login')))
			View::setError('login_empty');
		$usr = $db->fetch1Row($db->select('Users LEFT JOIN AddInfo ON auID=uID', 
			'uID, uLogin, uMail, aSQuestion, aSAnswer', 
			StringHelper::valueIf(!$_cfg['Const_NoLogins'], 'uLogin=? and ') . 'uMail=?', array(_IN('Login'), _IN('Mail'))));
		$uid = $usr['uID'];
		if (!$uid)
			View::setError(!$_cfg['Const_NoLogins'] ? 'login_not_found' : 'mail_not_found');

		if ($_GS['demo'] and ($uid <= 3))
			View::showInfo('*Denied');
			
		if ($_cfg['Sec_MinSQA'] == 0)
			_resetPass($uid);
		if (!$usr['aSQuestion'] or !$usr['aSAnswer'])
			View::showInfo('*CantComplete');
		$_SESSION['_freset'][$uid] = $usr;
		$_IN['uID'] = $uid;
		resetCaptcha();
	}
	else
		$uid = _INN('uID');
	if (($uid > 0) and ($uid == $_SESSION['_freset'][$uid]['uID']))
	{
		View::setPage('uid', $uid);
		View::setPage('squest', $_SESSION['_freset'][$uid]['aSQuestion']);
		View::setPage('captcha', $_cfg['Account_ResetPassCaptcha']);
		if (View::sendedForm('', 'sqa'))
		{
			View::checkFormSecurity('sqa');
			
			if (!verifyPasswordWithLegacyDigest(_IN('SAnswer'), $_SESSION['_freset'][$uid]['aSAnswer'], $_cfg['Const_Salt'], false))
				View::setError('answer_wrong', 'sqa');
			_resetPass($uid);
		}
	}

} 
catch (FormAbortException $e)
{
}

$_GS['vmodule'] = 'account';
View::showPage();

?>
