<?php

use HScript\Util\StringHelper;

use HScript\Template\View;

$_auth = 1;
require_once('module/auth.php');

try 
{

	if (View::sendedForm())
	{
		View::checkFormSecurity();

		if ($_GS['demo'] and ($_user['uLevel'] < 99) and (_uid() <= 3))
			View::showInfo('*Denied');
			
		if (!verifyPasswordWithLegacyDigest(_IN('Pass0'), $_user['uPass'], $_cfg['Const_Salt']))
			View::setError('pass0_wrong');
		if (StringHelper::sEmpty(_IN('Pass')))
			View::setError('pass_empty');
		if (($_cfg['Account_MinPass'] > 0) and (strlen(_IN('Pass')) < $_cfg['Account_MinPass']))
			View::setError('pass_short');
		if ($_cfg['Account_PassRegx'] and !preg_match($_cfg['Account_PassRegx'], _IN('Pass')))
			View::setError('pass_wrong');
		if (_IN('Pass2') != _IN('Pass'))
			View::setError('pass_not_equal');
		if (($_cfg['Sec_MinPIN'] > 0) and !verifyPasswordWithLegacyDigest(_IN('PIN'), $_user['uPIN'], $_cfg['Const_Salt'], false))
			View::setError('pin_wrong');
		$db->update('Users', array('uPass' => hashPassword(_IN('Pass')), 'uPTS' => timeToStamp()), '', 'uID=?d', array(_uid()));
		startSessionSafely(true);
		$_SESSION['_lts'] = time();
		if ($_user['uLevel'] >= 90)
			$_SESSION['_admin_lts'] = $_SESSION['_lts'];
		opAddHist('CHG_PASS');
		View::SendMailToUser($_user['uMail'],
			'PassChanged2', 
			opUserConsts($_user, array('pass' => _IN('Pass'))),
			$_user['uLang']
		);
		View::showInfo('Completed', moduleToLink('account'));
	}

	if (View::sendedForm('skip'))
	{
		View::checkFormSecurity();

		$db->update('Users', array('uPTS' => timeToStamp()), '', 'uID=?d', array(_uid()));
		goToURL(moduleToLink('account'));
	}
	
} 
catch (FormAbortException $e)
{
}

$_GS['vmodule'] = 'account';
View::showPage();

?>
