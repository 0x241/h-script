<?php

use HScript\Util\StringHelper;

use HScript\Template\View;
/* 
	Module: account/login
*/

function opLoginPrepare($login, $pass)
{
	global $db, $_cfg;
	if (StringHelper::sEmpty($login) or StringHelper::sEmpty($pass))
		return 'login_empty';
	if ($a = $db->fetch1Row($db->select('Users LEFT JOIN AddInfo ON auID=uID', 
		'uID, uLogin, aGA', 'uLogin=? and aGA<>""', array($login))))
	{
		require_once('module/account/ga/class.GoogleAuthenticator.php');
		$ga = new GoogleAuthenticator();
		if ($ga->checkCode($a['aGA'], $pass))
			$usr = $a;
		else
			return 'GA_wrong';
	}
	else
	{
		$usr = $db->fetch1Row($db->select('Users', 'uID, uLogin, uPass', 'uLogin=?', array($login)));
		if (!empty($usr['uID']) and verifyPasswordWithLegacyDigest($pass, $usr['uPass'], $_cfg['Const_Salt']))
		{
			if (!verifyPassword($pass, $usr['uPass']) or passwordHashNeedsRehash($usr['uPass']))
				$db->update('Users', array('uPass' => hashPassword($pass)), '', 'uID=?d', array($usr['uID']));
		}
		else
			$usr = array();
	}
	if ((empty($usr['uID'])) or ($usr['uLogin'] != $login))
	{
		if ($_cfg['Sec_BFC'] > 0)
			$db->update('Users', array('uBFC=' => 'uBFC+1'), '', 'uLogin=?', array($login));
		return 'login_not_found';
	}
	return 0 + $usr['uID'];
}

function opLogin($uid, $url, $set_new_ip = false, $from_admin = false, $remember = false, $notify_admin_login = true)
{
	$usr = opReadUser($uid);
	if (!$usr['uID'])
		return 'user_not_found';
	global $_GS, $db, $_cfg, $_user;
	$impersonator = array();
	if ($from_admin)
	{
		$impersonator_id = _uid();
		if (($impersonator_id < 1) or (intval($_user['uLevel'] ?? 0) < 90))
			return 'access_denied';
		$impersonator = array(
			'uID' => $impersonator_id,
			'uLogin' => (string)($_user['uLogin'] ?? '')
		);
	}
	if (!$from_admin)
	{
		if ($usr['uState'] == 0)
			return 'not_active';
		elseif ($usr['uState'] == 2)
		{
			$t = stampToTime($usr['uBTS']);
			if (time() < $t)
			{
				View::setPage('ban_date', View::timeToStr($t));
				return 'banned';
			}
			else
				$db->update('Users', array('uState' => 1, 'uBTS' => 0), 'uID=?d', array($uid));
		}
		elseif ($usr['uState'] == 3)
			return 'blocked';
		if ($_cfg['Sec_LockSite'] and ($usr['uLevel'] < 90))
			View::showInfo('*Denied', moduleToLink('account/login'));
		if (!$set_new_ip)
		{
			if (($_cfg['Sec_BFC'] > 0) and ($usr['uBFC'] >= $_cfg['Sec_BFC']))
			{
				useLib('confirm');
				opConfirmPrepare($uid, 'BFLOGIN', array('url' => $url, 'remember' => $remember), 'account/login');
				View::showInfo('Saved', moduletolink('account/login') . '?brute_force');
			}
			$ip_security = isset($usr['aIPSec']) ? intval($usr['aIPSec']) : 0;
			if (($_cfg['Sec_IP'] > 0) or ($ip_security > 0))
				if (!compare_ip($usr['uLIP'], $_GS['client_ip'], max($_cfg['Sec_IP'], $ip_security)))
				{
					useLib('confirm');
					opConfirmPrepare($uid, 'SECLOGIN', array('url' => $url, 'remember' => $remember), 'account/login');
					View::showInfo('Saved', moduletolink('account/login') . '?ip_changed');
				}
		}
	}
	resetSessionSafely();
	$now = time();
	$_SESSION['_sid'] = $_cfg['sys_id'];
	$_SESSION['_remember'] = $remember;
	$_SESSION['_uid'] = $uid;
	$_SESSION['_lts'] = $now;
	$_SESSION['_lip'] = $_GS['client_ip'];
	// uLSess is varchar(32), so keep the stored format while using a
	// cryptographically secure 128-bit token instead of uniqid()/MD5.
	$_SESSION['_lsess'] = bin2hex(random_bytes(16));
	if ($impersonator)
	{
		$_SESSION['_impersonator_uid'] = $impersonator['uID'];
		$_SESSION['_impersonator_login'] = $impersonator['uLogin'];
		$_SESSION['_impersonator_started'] = $now;
	}
	// Keep a session-only recovery token even when "Remember me" is disabled.
	// If PHP rotates or temporarily loses its session cookie, auth.php can restore
	// the same standard-user login without turning it into a persistent login.
	$session_cookie = ($remember ? 'r:' : 's:') . $_SESSION['_lsess'];
	hsSetCookie('sess', $session_cookie, $remember ? time() + 30 * HS2_UNIX_DAY : 0);
	setcookie('lang', $usr['uLang'], time() + 30 * HS2_UNIX_DAY, '/');
	setcookie('mode', $usr['uMode'], time() + 30 * HS2_UNIX_DAY, '/');
	setcookie('theme', $usr['uTheme'], time() + 30 * HS2_UNIX_DAY, '/');
	if (!$from_admin)
	{
		$db->update('Users', array('uLIP' => $_SESSION['_lip'], 'uLSess' => $_SESSION['_lsess'], 'uBFC' => 0), '', 'uID=?d', array($uid));
		opAddHist('LOGIN', $uid);
		if (($usr['uLevel'] >= 90) and $notify_admin_login)
			View::SendMailToAdmin(
				'AdminIn', 
				opUserConsts($usr)
			);
	}
	View::showInfo('LogIn', StringHelper::exValue(moduleToLink('cabinet'), $url));
}

function opLoginOut($text)
{
	if ($uid = _uid())
	{
		hsSetCookie('sess', '', time() - HS2_UNIX_DAY);
		global $db;
		$db->update('Users', array('uLTS' => 0), '', 'uID=?d', array($uid));
		opAddHist('LOGOUT', $uid, $text);
	}
	resetSessionSafely();
}

function opLoginConfirm($uid, $params)
{
	return opLogin($uid, $params['url'], true, false, $params['remember']);
}

?>
