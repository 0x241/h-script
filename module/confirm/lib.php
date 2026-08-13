<?php

use HScript\Util\StringHelper;

use HScript\Template\View;
/*
	Module: confirm
*/

function opConfirmGenerateCode($length = 32)
{
	global $db;

	for ($attempt = 0; $attempt < 20; $attempt++)
	{
		if ($length === 32)
			$code = bin2hex(random_bytes(16));
		elseif ($length === 6)
			$code = (string) random_int(100000, 999999);
		else
			throw new InvalidArgumentException('Unsupported confirmation code length');
		$memo = opConfirmCodeMemo($code);
		if (!$db->count('Hist', 'hOper=? and (hMemo=? or hMemo=?)', array('CONFIRM', $memo, $code)))
			return $code;
	}

	throw new RuntimeException('Unable to generate a unique confirmation code');
}

function opConfirmCodeMemo($code)
{
	global $_cfg;

	$code = trim((string) $code);
	if (!preg_match('/^(?:[0-9]{6}|[a-f0-9]{32})$/Di', $code))
		return $code;

	$key = (string) ($_cfg['sys_id'] ?? '') . "\0" . (string) ($_cfg['Const_Salt'] ?? '');
	if ($key === "\0")
		throw new RuntimeException('Confirmation code HMAC key is not configured');

	return substr(hash_hmac('sha256', $code, $key), 0, 32);
}

function opConfirmPrepare($uid, $oper, $params = array(), $module = '', $mail = '')
{
	global $_GS;
	$usr = opReadUser($uid);
	if (!$usr['uID'])
		return false;
	$params['oper'] = $oper;
	if (!$module)
		$module = $_GS['module'];
	$params['module'] = $module;
	$params['mname'] = StringHelper::textReplace(StringHelper::cutElemR($module, '/'), '_', '');
	$params['channel'] = 'email';
	$code = opConfirmGenerateCode(32);
	opAddHist('CONFIRM', $uid, $params, opConfirmCodeMemo($code)); // tag = 0
	$sent = View::sendMailToUser(StringHelper::exValue($usr['uMail'], $mail),
		'AskConfirm' . $oper,
		opUserConsts($usr, array('code' => $code, 'url' => fullURL(moduleToLink('confirm')))),
		$usr['uLang']
	);
	if ($sent)
		$_SESSION['_confirm_code_mode'] = 'long';
	return $sent;
}

function opConfirmPrepareSMS($uid, $oper, $params = array(), $module = '', $tel = '')
{
	global $_GS;
	$usr = opReadUser($uid);
	if (!$usr['uID'])
		return false;
	$params['oper'] = $oper;
	if (!$module)
		$module = $_GS['module'];
	$params['module'] = $module;
	$params['mname'] = StringHelper::textReplace(StringHelper::cutElemR($module, '/'), '_', '');
	$params['channel'] = 'sms';
	$code = opConfirmGenerateCode(6);
	opAddHist('CONFIRM', $uid, $params, opConfirmCodeMemo($code)); // tag = 0
	useLib('sms');
	$params['code'] = $code;
	$params['url'] = fullURL(moduleToLink('confirm'));
	$sent = smsPush($uid, StringHelper::exValue($usr['aTel'], $tel), "Code: $code", '', false, 2);
	if ($sent)
		$_SESSION['_confirm_code_mode'] = 'short';
	return $sent;
}

function opConfirmResend($uid)
{
	global $db;
	$op = $db->fetch1Row($db->select('Hist', '*', 'huID=? and hOper=?', array($uid, 'CONFIRM'), 'hID desc', 1));
	$t = subStamps($op['hTS']);
	$a = strToArray($op['hParams']);
	$channel = $a['channel'] ?? '';
	if (!in_array($channel, array('email', 'sms'), true))
		$channel = !empty($a['tel']) || _SESSION('_confirm_code_mode') === 'short' ? 'sms' : 'email';
	$redirect = moduleToLink('confirm') . ($channel === 'sms' ? '?need_confirm_sms' : '?resent');
	if (!$op['hTag'] and (($t > 1 * HS2_UNIX_MINUTE) and ($t < 10 * HS2_UNIX_MINUTE)))
	{
		$a['count'] = intval($a['count'] ?? 0) + 1;
		if ($a['count'] <= 3)
		{
			$sent = $channel === 'sms'
				? opConfirmPrepareSMS($uid, $a['oper'], $a, $a['module'], $a['tel'] ?? '')
				: opConfirmPrepare($uid, $a['oper'], $a, $a['module'], $a['mail'] ?? '');
			if ($sent)
				View::showInfo('Completed', $redirect);
		}
	}
	View::showInfo('*Error', $redirect);
}

function opConfirmTry($uid, $params)
{
	useLib($m = $params['module']);
	$f = 'op' . $params['mname'] . 'Confirm';
	if (function_exists($f))
		return $f($uid, $params);
	return false;
}

?>
