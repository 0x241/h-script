<?php

use HScript\Template\View;

function opResetPass($uid)
{
	$usr = opReadUser($uid);
	if (!$usr['uID'])
		return false;
	global $db, $_cfg;
	$pass = '';
	for ($i = 1; $i <= 8; $i++)
		$pass .= rand($i == 1, 9);
	$db->update('Users', array('uPass' => hashPassword($pass), 'uPTS' => 1), '', 'uID=?d', array($uid));
	$db->update('AddInfo', array('aGA' => ''), '', 'auID=?d', array($uid));
	opAddHist('RST_PASS', $uid);
	View::SendMailToUser($usr['uMail'],
		'PassChanged', 
		opUserConsts($usr, array('pass' => $pass)),
		$usr['uLang']
	);
	return true;
}

function opResetPassConfirm($uid, $params)
{
	if (opResetPass($uid))
		View::showInfo('Completed', moduleToLink('account/reset_pass') . '?done');
}

?>
