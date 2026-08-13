<?php

use HScript\Template\View;

function opChangeMail($uid, $mail) 
{
	$usr = opReadUser($uid);
	if (!$usr['uID'])
		return false;
	global $db, $_cfg;
	if ($db->count('Users', 'uMail=? and uID<>?d', array($mail, $uid)) > 0)
		View::showInfo('*AlreadyUsed', moduleToLink('account/change_mail') . '?already_used');
	$a = array('uMail' => $mail);
	if ($_cfg['Const_NoLogins'])
		$a['uLogin'] = $mail;
	$db->update('Users', $a, '', 'uID=?d', array($uid));
	opAddHist('CHG_MAIL', $uid);
	View::SendMailToUser($mail,
		'MailChanged', 
		opUserConsts($usr),
		$usr['uLang']
	);
	return true;
}

function opChangeMailConfirm($uid, $params)
{
	if (opChangeMail($uid, $params['mail']))
		View::showInfo('Completed', moduleToLink('account/change_mail') . '?done');
}

?>