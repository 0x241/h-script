<?php

use HScript\Util\StringHelper;

use HScript\Template\View;
/*
	Module: account/register
*/

function opRegisterUserCheck(&$params, $uid = 0, $from_admin = false) // !!! Pass2 must be set
{
	global $db, $_cfg;
	// PHP 8.4 strict array keys initialization
	$fields = array('aName', 'uLogin', 'uMail', 'uPass', 'Pass2', 'uPIN', 'aTel', 'uRef', 'Invite', 'aSQuestion', 'aSAnswer', 'Agree', 'aCountry');
	foreach ($fields as $f) 
	{
		if (!isset($params[$f])) 
			$params[$f] = '';
	}

	if (!$from_admin and ($_cfg['Account_UseName'] == 1) and (StringHelper::sEmpty($params['aName'])))
		return 'name_empty';
	if (!$_cfg['Const_NoLogins'])
	{	
		if (StringHelper::sEmpty($params['uLogin']))
			return 'login_empty';
		if (($_cfg['Account_MinLogin'] > 0) and (strlen($params['uLogin']) < $_cfg['Account_MinLogin']))
			return 'login_short';
		if ($_cfg['Account_LoginRegx'] and !preg_match(StringHelper::exValue('/[^\s]+/', $_cfg['Account_LoginRegx']), $params['uLogin']))
			return 'login_wrong';
		if ($db->count('Users', 'uID<>?d and uLogin=?', array($uid, $params['uLogin'])) > 0)
			return 'login_used';
	}
	if (StringHelper::sEmpty($params['uMail']))
		return 'mail_empty';
	if (!validMail($params['uMail']))
		return 'mail_wrong';
	if ($db->count('Users', 'uID<>?d and uMail=?', array($uid, $params['uMail'])) > 0)
		return 'mail_used';
	if (StringHelper::sEmpty($params['uLogin']) or $_cfg['Const_NoLogins'])
		$params['uLogin'] = $params['uMail'];
	if (StringHelper::sEmpty($params['aName']))
		$params['aName'] = StringHelper::get1ElemL($params['uLogin'], '@');
	if (!$uid or !StringHelper::sEmpty($params['uPass']))
	{
		if (StringHelper::sEmpty($params['uPass']))
			return 'pass_empty';
		if (($_cfg['Account_MinPass'] > 0) and (strlen($params['uPass']) < $_cfg['Account_MinPass']))
			return 'pass_short';
		if ($_cfg['Account_PassRegx'] and !preg_match($_cfg['Account_PassRegx'], $params['uPass']))
			return 'pass_wrong';
		if (!$from_admin and !$uid and ($params['Pass2'] != $params['uPass']))
			return 'pass_not_equal';
		$params['uPass'] = hashPassword($params['uPass']);
		$params['uPTS'] = timeToStamp();
	}
	else
	{
		unset($params['uPass']);
		unset($params['uPTS']);
	}
	if ($uid) // from admin
	{
		if (!StringHelper::sEmpty($params['uPIN']))
		{
			if (($_cfg['Sec_MinPIN'] > 0) and (strlen($params['uPIN']) < $_cfg['Sec_MinPIN']))
				return 'pin_short';
			$params['uPIN'] = hashPassword($params['uPIN']);
		} 
		else
			unset($params['uPIN']);
	}
	if (!$from_admin)
	{
		if ($_cfg['SMS_REG'])
		{
			$params['aTel'] = preg_replace('|[^\d]|', '', (string)$params['aTel']);
			if (StringHelper::textLen($params['aTel']) < 11)
				return 'tel_wrong';
		}
	}
	if (!$from_admin and ($_cfg['Account_RegMode'] == 2) and !$params['uRef'])
		return 'ref_empty';
	if ($params['uRef'])
	{
		$ruid = $db->fetch1($db->select('Users', 'uID', 'uLogin=?', array($params['uRef'])));
		if (!$ruid)
			return 'ref_not_found';
		if ($uid and ($ruid == $uid))
			return 'ref_is_self';
	}
	else
		$ruid = 0;
	$params['uRef'] = $ruid;
	if (!$uid) // from reg
	{
		if (($_cfg['Account_RegMode'] == 3) and StringHelper::sEmpty($params['Invite']))
			return 'inv_empty';
		// ??? check Invite
	}
	if (($_cfg['Sec_MinSQA'] > 0) and (!$from_admin))
	{
		if (!$params['aSQuestion'])
			return 'secq_empty';
		if (strlen($params['aSQuestion']) < $_cfg['Sec_MinSQA'])
			return 'secq_short';
		if (!$uid and StringHelper::sEmpty($params['aSAnswer']))
			return 'seca_empty';
		if (!StringHelper::sEmpty($params['aSAnswer']))
		{
			if (strlen($params['aSAnswer']) < $_cfg['Sec_MinSQA'])
				return 'seca_short';
			if ($params['aSAnswer'] == $params['aSQuestion'])
				return 'seqa_equal_secq';
			$params['aSAnswer'] = hashPassword($params['aSAnswer']);
		} 
		else
			unset($params['aSAnswer']);
	}
	return true;
}

function opRegisterPrepare($params, $from_admin = false)
{
	$res = opRegisterUserCheck($params, 0, $from_admin);
	if ($res !== true)
		return $res;
	if (!$from_admin and empty($params['Agree']))
		return 'must_agree';
	
	global $_GS, $db, $_cfg;
	$client_ip = $_GS['client_ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
	$regCheck = $_cfg['Account_RegCheck'] ?? 0;
	if (!$from_admin and $regCheck)
	{
		if ($regCheck & 1)
			if ($db->count('AddInfo', 'aCIP=?', array($client_ip)))
				return 'multi_reg';
		if ($regCheck & 2)
			if (_COOKIE('active'))
				return 'multi_reg';
	}
	$params['uState'] = 0;
	$params['uLevel'] = 1;
	$params['uLang'] = $_GS['lang'] ?? 'ru';
	$params['uMode'] = $_GS['mode'] ?? '';
	$params['uTheme'] = $_GS['theme'] ?? 'dark';
	$params['aCountry'] = $params['aCountry'] ?? '';
	$params['aTel'] = $params['aTel'] ?? '';
	$params['auID'] = $db->insert('Users', $params, 
		'uLogin, uPass, uMail, uState, uLevel, uLang, uMode, uTheme, uRef');
	if (!$params['auID'])
		return false;
	$params['aCTS'] = timeToStamp();
	$params['aCIP'] = $client_ip;
	$db->insert('AddInfo', $params, 'auID, aName, aCTS, aCIP, aSQuestion, aSAnswer, aCountry, aTel');
	if (!$from_admin)
	{
		setcookie('active', $params['auID'], time() + 365 * 86400, '/'); // mark 'registered'
		$params['uID'] = $params['auID'];
		View::SendMailToAdmin(
			'NewUser',
			opUserConsts($params)
		);
	}
	return $params['auID'];
}

function opRegisterComplete($uid, $pass = '', $url = '') // $pass (for autoregister) is send to mail
{
	$usr = opReadUser($uid);
	if (!$usr or ($usr['uID'] ?? 0) <= 1) // except admin ;)
		return 'user_not_found';
	global $_GS, $db, $_cfg;
	$pin = '';
	$minPIN = $_cfg['Sec_MinPIN'] ?? 4;
	for ($i = 1; $i <= $minPIN; $i++)
		$pin .= rand(1, 9);
	$db->update('Users', array('uPIN' => hashPassword($pin), 'uState' => 1), '', 'uID=?d', array($uid));
	opAddHist('REG', $uid);
	View::SendMailToUser($usr['uMail'] ?? '',
		'RegComplete' . StringHelper::valueIf($pass, '2'),
		opUserConsts($usr, array('pass' => $pass, 'pin' => $pin)),
		$usr['uLang'] ?? 'ru'
	);
	if (isset($usr['uRef']) and ($rusr = opReadUser($usr['uRef'])) and !empty($rusr['uID']))
		View::SendMailToUser($rusr['uMail'] ?? '',
			'NewRef', 
			opUserConsts($rusr, array('reflogin' => $usr['uLogin'] ?? '')),
			$rusr['uLang'] ?? 'ru'
		);
	opEvent('RegComplete', $uid);
	if (!empty($_cfg['Account_RegLogin']))
	{
		useLib('account/login');
		opLogin($uid, $url);
	}
	return true;
}

function opRegisterMail($uid, $mail)
{
	$usr = opReadUser($uid);
	if (!$usr or empty($usr['uID']))
		return false;
	global $db;
	if ($db->count('Users', 'uMail=? and uID<>?d', array($mail, $uid)) > 0)
		View::showInfo('*AlreadyUsed', moduleToLink('account/change_mail') . '?already_used');
	$db->update('Users', array('uMail' => $mail), '', 'uID=?d', array($uid));
	opAddHist('REG_MAIL', $uid);
	View::SendMailToUser($mail,
		'MailChanged', 
		opUserConsts($usr),
		$usr['uLang'] ?? 'ru'
	);
	return true;
}

function opRegisterTel($uid, $tel)
{
	$usr = opReadUser($uid);
	if (!$usr or empty($usr['uID']))
		return false;
	global $db;
	if ($db->count('AddInfo', 'aTel=? and auID<>?d', array($tel, $uid)) > 0)
		View::showInfo('*AlreadyUsed', moduleToLink('account/register') . '?tel_already_used');
	$db->update('AddInfo', array('aTel' => $tel), '', 'auID=?d', array($uid));
	opAddHist('REG_TEL', $uid);
	return true;
}

function opRegisterConfirm($uid, $params)
{
	$tel = $params['tel'] ?? '';
	$mail = $params['mail'] ?? '';
	if ($tel)
		if (opRegisterTel($uid, $tel))
		{
			opRegisterComplete($uid);
			View::showInfo('Completed', moduleToLink('account/register') . '?done');
		}
	if ($mail)
	{
		if (opRegisterMail($uid, $mail))
		{
			opRegisterComplete($uid);
			View::showInfo('Completed', moduleToLink('account/register') . '?done');
		}
	}
}

?>
