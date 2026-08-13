<?php

use HScript\Util\StringHelper;

use HScript\Template\View;

require_once('module/auth.php');

try 
{

	function _changeMail($uid, $mail) 
	{
		global $_cfg;
		useLib('confirm');
		if ($_cfg['Account_ChangeMailConfirm'])
		{
			if (opConfirmPrepare($uid, 'CHGMAIL', array('mail' => $mail), '', $mail))
				View::showInfo('Saved', moduleToLink() . '?need_confirm');
		}
		else
			if (opChangeMail($uid, $mail) !== false)
			{
				if (_uid())
					View::showInfo('Completed', moduleToLink('account'));
				else
					View::showInfo('Completed', moduleToLink('account/change_mail') . '?done');
			}
		View::showInfo('*Error');
	}
	
	if (View::sendedForm())
	{
		View::checkFormSecurity();

		if ($_GS['demo'] and ($_user['uLevel'] < 99) and (_uid() <= 3))
			View::showInfo('*Denied');
			
		$pass = _IN('Pass');
		$mail = _IN('NewMail');
		if (_uid())
		{
			if (!verifyPasswordWithLegacyDigest($pass, $_user['uPass'], $_cfg['Const_Salt']))
				View::setError('pass_not_found');
			$usr = array(
				'uID' => $_user['uID'],
				'uMail' => $_user['uMail'],
				'aSQuestion' => $_user['aSQuestion'],
				'aSAnswer' => $_user['aSAnswer']
			);
		}
		else
		{
			$login = _IN('Login');
			if (StringHelper::sEmpty($login) or StringHelper::sEmpty($pass))
				View::setError('login_empty');
			$f = (!$_cfg['Const_NoLogins'] ? 'uLogin' : 'uMail');
			$usr = $db->fetch1Row($db->select('Users LEFT JOIN AddInfo ON auID=uID', 
				'uID, uLogin, uPass, uMail, aSQuestion, aSAnswer, uState, uBTS',
				"$f=?", array($login)
				)
			);
			if ((empty($usr['uID'])) or ($usr[$f] != $login) or !verifyPasswordWithLegacyDigest($pass, $usr['uPass'], $_cfg['Const_Salt']))
				View::setError('login_not_found');
			if ($usr['uState'] == 2) 
			{
				View::setPage('ban_date', View::timeToStr(stampToTime($usr['uBTS'])));
				View::setError('banned');
			}
			if ($usr['uState'] == 3)
				View::setError('blocked');
			unset($usr['uPass']);
		}
		$uid = $usr['uID'];
		if (StringHelper::sEmpty($mail))
			View::setError('mail_empty');
		if (!validMail($mail))
			View::setError('mail_wrong');
		if ($db->count('Users', 'uID<>?d and uMail=?', array($uid, $mail)) > 0)
			View::setError('mail_used');
		if (_uid() or ($_cfg['Sec_MinSQA'] == 0))
			_changeMail($uid, $mail);
		if (!$usr['aSQuestion'] or !$usr['aSAnswer'])
			View::showInfo('*CantComplete');
		$usr['NewMail'] = $mail;
		$_SESSION['_fchange'][$uid] = $usr;
		$_IN['uID'] = $uid;
		resetCaptcha();
	}
	else
		$uid = _INN('uID');
	if (($uid > 0) and ($uid == $_SESSION['_fchange'][$uid]['uID'])) 
	{
		View::setPage('uid', $uid);
		View::setPage('squest', $_SESSION['_fchange'][$uid]['aSQuestion']);
		View::setPage('captcha', $_cfg['Account_ChangeMailCaptcha']);
		if (View::sendedForm('', 'sqa'))
		{
			View::checkFormSecurity('sqa');
			
			if (!verifyPasswordWithLegacyDigest(_IN('SAnswer'), $_SESSION['_fchange'][$uid]['aSAnswer'], $_cfg['Const_Salt'], false))
				View::setError('answer_wrong', 'sqa');
			_changeMail($uid, $_SESSION['_fchange'][$uid]['NewMail']);
		}
	}

} 
catch (FormAbortException $e)
{
}

$_GS['vmodule'] = 'account';
View::showPage();

?>
