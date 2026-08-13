<?php

use HScript\Util\StringHelper;

use HScript\Application;
use HScript\Cache\CatalogCache;
use HScript\Template\View;

hsConfigureErrorHandling();

$_smode = intval(isset($_smode) ? $_smode : 0); // show mode: 0-user / 1-ajax / 2-bot (cron, captcha..)
$_auth = intval(isset($_auth) ? $_auth : 0); // required access level

if (empty($_GS['is_api']) && !headers_sent() && session_status() === PHP_SESSION_NONE) {
    startSessionSafely();
}

// Anti-DDoS

if ($_smode < 2)
{
	if (is_file('a-ddos/a-ddos.php'))
		include('a-ddos/a-ddos.php');
}

global $_user, $_currs;
$_user = array('uID' => 0, 'uLevel' => 0, 'aTZ' => 0); // guest
$_currs = array();
$impersonator = array();
$is_admin_impersonation = false;
function _uid()
{
	return intval(isset($_SESSION['_uid']) ? $_SESSION['_uid'] : 0);
}

// Connect DB

require_once('module/dbinit.php');

// Load config

$configRows = $catalogCache->remember(
	CatalogCache::CONFIG,
	'all',
	static fn(): array => $db->fetchRows($db->select('Cfg'))
);
foreach ($configRows as $c)
{
	if (substr($c['Prop'], 0, 1) == '_') // array
		$c['Val'] = asArray($c['Val'], HS2_NL);
	if ($c['Module'])
		$c['Prop'] = $c['Module'] . '_' . $c['Prop'];
	$_cfg[$c['Prop']] = $c['Val'];
}
function authLoadCurrencies(int $uid = 0): array
{
	global $db, $catalogCache;
	$currencies = $catalogCache->remember(
		CatalogCache::CURRENCIES,
		'enabled',
		static fn(): array => $db->fetchIDRows(
			$db->select('Currs', '*', 'cDisabled=0', null, 'cID'),
			false,
			'cID'
		)
	);
	if ($uid <= 0)
		return $currencies;
	$wallets = $db->fetchIDRows(
		$db->select('Wallets', '*', 'wuID=?d', array($uid), 'wcID'),
		false,
		'wcID'
	);
	$walletDefaults = array(
		'wcID' => null,
		'wuID' => null,
		'wBal' => null,
		'wLock' => null,
		'wOut' => null,
		'wParams' => null,
		'wMTS' => null,
	);
	foreach ($currencies as $cid => $currency)
		$currencies[$cid] = array_merge(
			$currency,
			$walletDefaults,
			isset($wallets[$cid]) ? $wallets[$cid] : array()
		);
	return $currencies;
}
function authApplyConfigDefaults(&$cfg)
{
	$defaults = array(
		'Sys_SiteName' => '',
		'Sys_NotifyMail' => '',
		'Sys_LockSite' => 0,
		'Sys_NeedReConfig' => 0,
		'Sys_ForceCharset' => 1,
		'UI__Langs' => array('en'),
		'UI_DefaultTZ' => '0:00',
		'UI_NumDec' => 2,
		'UI_ShowIntro' => 0,
		'Demo_Mode' => 0,
		'Const_Salt' => '',
		'Const_IntCurr' => 0,
		'Const_NoLogins' => 0,
		'Cron_Enabled' => 0,
		'Cron_telemetry' => 0,
		'Sec_HTTPSMode' => 0,
		'Sec_TimeOut' => 0,
		'Sec_PassTime' => 0,
		'Sec_ForceReConfig' => 0,
		'Sec__IPs' => array(),
		'Sec_LockSite' => 0,
		'Sec_BFC' => 0,
		'Sec_IP' => 0,
		'Sec_MinPIN' => 0,
		'Sec_MinSQA' => 0,
		'Ref_Word' => '',
		'Ref_Force' => 0,
		'Ref_Levels' => 1,
		'Ref_Type' => 0,
		'Ref_DType' => 0,
		'Ref_PType' => 0,
		'Ref__Perc' => array(),
		'Ref__DPerc' => array(),
		'Ref__PPerc' => array(),
		'Account_UseName' => 0,
		'Account_MinLogin' => 0,
		'Account_MinPass' => 0,
		'Account_LoginRegx' => '',
		'Account_PassRegx' => '',
		'Account_RegMode' => 0,
		'Account_RegCheck' => 0,
		'Account_RegConfirm' => 0,
		'Account_RegLogin' => 0,
		'Account_ChangeMailConfirm' => 0,
		'Account_ChangeMailCaptcha' => 0,
		'Account_ResetPassCaptcha' => 0,
		'Bal_LastUpdate' => 0,
		'Bal_UpdateRates' => 0,
		'Bal_RegBonus' => 0,
		'Bal_NeedPIN' => 0,
		'Bal_SumInInternal' => 0,
		'Bal_AFMMode' => 0,
		'Bal_ForcePayer' => 0,
		'Bal_LockWallets' => 0,
		'Depo_AutoDepo' => 0,
		'Depo_ChargeMode' => 0,
		'Depo_Interval' => 0,
		'Depo_LastTime' => 0,
		'Depo_ManualDay' => 0,
		'Depo_ManualPeriod' => 0,
		'Depo_OneFromGroup' => 0,
		'Depo_ShowStat' => 0,
		'Depo__Compnd' => array(),
			'News_InBlock' => 0,
			'News_ShowCount' => 10,
			'News_SocialTelegram' => '',
			'News_SocialX' => '',
			'News_SocialVK' => '',
			'News_SocialFacebook' => '',
			'News_SocialInstagram' => '',
			'News_SocialYouTube' => '',
		'FAQ_InBlock' => 0,
		'FAQ_ShowCount' => 10,
		'FAQ__Cats' => array(),
		'Review_InBlock' => 0,
		'Review_ShowCount' => 10,
		'Review_Mode' => 0,
		'Msg_ShowCount' => 20,
		'Msg_NoMail' => 0,
		'Msg_Mode' => 0,
		'Msg__Cats' => array(),
		'Confirm_Expire' => 0,
		'Confirm_DifIP' => 0,
		'Captcha_View' => 0,
		'Mail_Host' => '',
		'Mail_Port' => 25,
		'Mail_Secure' => 0,
		'Mail_Username' => '',
		'Mail_Password' => '',
		'SMS_Prov' => 0,
		'SMS_From' => '',
		'SMS_REG' => 0,
		'SMS_CASHOUT' => 0,
		'SMS_UpdateCount' => 20,
		'SMS_EP_PublicKey' => '',
		'SMS_EP_PrivateKey' => '',
		'API_Enabled' => 1,
		'API_RateLimitIP' => 60,
		'API_RateLimitToken' => 60,
		'Telemetry_Enabled' => 1,
		'Telemetry_SharePublicStats' => 1,
		'Telemetry_Registered' => 0,
		'Telemetry_InstallationID' => '',
		'Telemetry_Token' => '',
		'Telemetry_Domain' => '',
		'Telemetry_InstalledAt' => 0,
		'Telemetry_LastAttemptAt' => 0,
		'Telemetry_LastSuccessAt' => 0,
		'Telemetry_LastStatus' => 0,
		'Telemetry_LastError' => '',
		'Telemetry_PublicMetrics' => '',
		'HTTP_UserAgent' => Application::userAgent(),
		'Sec_ProxyHost' => '',
		'Sec_ProxyAuth' => ''
	);
	foreach ($defaults as $key => $value)
		if (!array_key_exists($key, $cfg))
			$cfg[$key] = $value;
	foreach (array('USD', 'EUR', 'RUB', 'BTC', 'ETH', 'LTC', 'XRP') as $curr)
	{
		if (!array_key_exists('Bal_Rate' . $curr, $cfg))
			$cfg['Bal_Rate' . $curr] = 1;
		if (!array_key_exists('Bal_Update' . $curr . 'Rate', $cfg))
			$cfg['Bal_Update' . $curr . 'Rate'] = 0;
		if (!array_key_exists('Depo_S2' . $curr, $cfg))
			$cfg['Depo_S2' . $curr] = 0;
		if (!array_key_exists('Depo_S3' . $curr, $cfg))
			$cfg['Depo_S3' . $curr] = 0;
	}
	for ($i = 0; $i <= 12; $i++)
		if (!array_key_exists('Depo_S' . $i, $cfg))
			$cfg['Depo_S' . $i] = 0;
}
authApplyConfigDefaults($_cfg);
$_GS['site_name'] = $_cfg['Sys_SiteName'];
if (!$_cfg['UI__Langs'])
	$_cfg['UI__Langs'] = array($_GS['default_lang']);
if (!isset($_GS['demo']))
	$_GS['demo'] = file_exists('tpl_c/demo');
if (!empty($_cfg['Demo_Mode']))
	$_GS['demo'] = true;

$_GS['mode'] = View::normalizeTemplateMode(_COOKIE('mode'));
$_GS['theme'] = trim((string)_COOKIE('theme'));

require_once('module/lib.php');
	
if ($_smode < 2) // user mode
{
	if (($_cfg['Sec_HTTPSMode'] == 1) and !$_GS['https'])
		goToURL(fullURL('*', true));

	$login_link = moduleToLink('account/login');
	if ($_smode < 1)
	{
		if (!isset($_SESSION['_uid'])) // first time or new session
		{
			$_SESSION['_sid'] = $_cfg['sys_id'];
			$_SESSION['new_session'] = true;
			$_SESSION['_uid'] = 0;
			$_SESSION['show_intro'] = true;
			if ($sess_cookie = _COOKIE('sess'))
			{
				$remember_session = true; // legacy unprefixed cookies were persistent
				$sess = $sess_cookie;
				if (substr($sess_cookie, 0, 2) == 's:')
				{
					$remember_session = false;
					$sess = substr($sess_cookie, 2);
				}
				elseif (substr($sess_cookie, 0, 2) == 'r:')
					$sess = substr($sess_cookie, 2);
				if ($uid = $db->fetch1($db->select('Users', 'uID', 'uLSess=? and uLevel=1', array($sess))))
				{
					useLib('account/login');
					opLogin($uid, fullURL('*'), false, false, $remember_session);
				}
				hsSetCookie('sess', '', time() - HS2_UNIX_DAY);
			}
		}
		else
		{
			if ($_SESSION['_sid'] != $_cfg['sys_id']) // wrong systemID
			{
				resetSessionSafely();
				goToURL();
			}
			unset($_SESSION['new_session']);
		}
			
		// Language (Interface)

		if (_COOKIE('lang'))
			$_SESSION['_lang'] = _COOKIE('lang');
		elseif (!isset($_SESSION['_lang']))
		{
			$langs = is_array($_cfg['UI__Langs']) ? $_cfg['UI__Langs'] : array($_GS['default_lang']);
			$_SESSION['_lang'] = reset($langs); // default primary
			$a = explode(',', str_replace(';', ',', $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')); // detect from browser
			foreach ($a as $s)
				if (in_array($l = StringHelper::Get1ElemL($s, '-'), $langs))
				{
					$_SESSION['_lang'] = $l;
					break;
				}
		}
			
	   // Reflink

    if ($_cfg['Ref_Word'] and (($ref = _GET($_cfg['Ref_Word']) or !_SESSION('_ref'))))
    {
      if (!_SESSION('_ref'))
        $ref = StringHelper::exValue(_COOKIE('ref'), $ref);
      elseif (!$_cfg['Ref_Force'])
        $ref = '';
      if ($ref)
				if ($db->count('Users', 'uLogin=? and uState=1', array($ref))) // only active inviter
				{
					setcookie('ref', $ref, time() + 14 * HS2_UNIX_DAY, '/');
					$_SESSION['_ref'] = $ref;
				}
    }

		if (($_GS['module'] != 'account/login') and !_uid() and ($_auth > 0)) // auth required!
			goToURL($login_link . '?url=' . urlencode($_GS['uri']));
	
	}
	$tz = explode(':', (string)$_cfg['UI_DefaultTZ'], 2);
	$h = intval($tz[0]);
	$m = intval(isset($tz[1]) ? $tz[1] : 0);
	$_GS['TZ'] = $h * HS2_UNIX_HOUR + $m * HS2_UNIX_MINUTE;

	if (_uid()) // already logged
	{
		$now = time();
		$lt = intval(_SESSION('_lts')); // last time
		$_SESSION['_lts'] = $now;
		if (isset($_COOKIE['tz'])) // auto timezone
		{
			$db->update('AddInfo', array('aTZ' => -_COOKIEN('tz')), '', 'auID=?d', array(_uid()));
			setcookie('tz', '', time() - HS2_UNIX_DAY, '/');
		}
		$_user = $db->fetch1Row($db->select('Users LEFT JOIN AddInfo ON auID=uID', '*', 'uID=?d', array(_uid())));
		$impersonator_id = intval($_SESSION['_impersonator_uid'] ?? 0);
		if (($impersonator_id > 0) and ($impersonator_id != _uid()))
		{
			$impersonator = $db->fetch1Row($db->select(
				'Users LEFT JOIN AddInfo ON auID=uID',
				'uID, uLogin, uLevel, uState, aName',
				'uID=?d and uLevel>=90 and uState=1',
				array($impersonator_id)
			));
			$is_admin_impersonation = !empty($impersonator['uID']);
		}
		if (!$is_admin_impersonation)
		{
			unset(
				$_SESSION['_impersonator_uid'],
				$_SESSION['_impersonator_login'],
				$_SESSION['_impersonator_started']
			);
			$impersonator = array();
		}
		$_GS['mode'] = View::normalizeTemplateMode($userMode = (string)($_user['uMode'] ?? ''));
		if ($userMode !== '' && !View::templateModeExists($_GS['mode']))
			$_GS['mode'] = '';
		$_GS['theme'] = trim((string)($_user['uTheme'] ?? ''));
		if (subStamps($_user['uLTS']) > HS2_UNIX_MINUTE)
			$db->update('Users', array('uLTS' => timeToStamp()), '', 'uID=?d', array(_uid()));
		if ($_GS['module'] != 'account/login')
		{
			if (!$_user)
				goToURL($login_link . '?out=not_found');
			if ($_user['aSessIP'] and (_SESSION('_lip') != $_GS['client_ip']))  // !!!IP changed!!!
				goToURL($login_link . '?out=ip_changed');
			if ($_user['aSessUniq'] and !$is_admin_impersonation and (_SESSION('_lsess') != $_user['uLSess'])) // !!!session changed!!!
				goToURL($login_link . '?out=session_changed');
			if (($_auth >= 90) and ($_user['uLevel'] >= 90))
			{
				$admin_lt = intval(_SESSION('_admin_lts'));
				if ($admin_lt <= 0)
					$admin_lt = $lt;
				if (($admin_lt > 0) and (($now - $admin_lt) > (15 * HS2_UNIX_MINUTE)))
					goToURL($login_link . '?out=admin_time_out');
				$_SESSION['_admin_lts'] = $now;
			}
			$t = StringHelper::exValue($_cfg['Sec_TimeOut'], $_user['aTimeOut']);
			if (!$_GS['is_local'] and ($t > 0)) // !!!auto logout (sess exp)!!!
			{
				$t = $lt + ($t * HS2_UNIX_MINUTE) - $now;
				if ($t <= 0)
					goToURL($login_link . '?out=time_out');
			}
			if (($_cfg['Sec_PassTime'] > 0) and (subStamps($_user['uPTS']) > (0 + $_cfg['Sec_PassTime']) * HS2_UNIX_DAY))
			{
				if ($_GS['module'] != 'account/change_pass')
					goToURL(moduleToLink('account/change_pass') . '?need_change');
			}
			elseif ($_cfg['Sec_ForceReConfig'] and $_user['aNeedReConfig'])
			{
				if ($_GS['module'] != 'account')
					goToURL(moduleToLink('account'));
			}
			elseif ($_cfg['Sys_NeedReConfig'] and ($_user['uLevel'] >= 90))
			{
				if ($_GS['module'] != 'system/admin/setup_main')
					goToURL(moduleToLink('system/admin/setup_main'));
			}
		}
				
		if (_COOKIE('lang') and _COOKIE('lang') != $_user['uLang']) {
			$_user['uLang'] = _COOKIE('lang');
			$db->update('Users', array('uLang' => $_user['uLang']), '', 'uID=?d', array(_uid()));
		}
		$_SESSION['_lang'] = $_user['uLang'];
		if ($_auth >= 50)
		{
			if ($_cfg['Sec__IPs'])
				if (!in_array($_GS['client_ip'], $_cfg['Sec__IPs']))
					View::showInfo('*Denied', $login_link); // !!!Access denied!!!
			$_GS['TZ'] = 0; // In Admin panel time zone = 0!!!
		}
		else
			$_GS['TZ'] = $_user['aTZ'] * HS2_UNIX_MINUTE;
	}
	if ($_auth > $_user['uLevel'])
		View::showInfo('*Denied', $login_link); // !!!Access denied!!!
	if ($_cfg['Sys_LockSite'] and ($_user['uLevel'] < 90) and ($_GS['module'] != 'account/login'))
		goToURL($login_link . StringHelper::valueIf(_uid(), '?out=site_locked'));
		View::setPage('user', $_user);
		View::setPage('impersonator', $impersonator, 0);

		$cabinet_menu_allowed = array(
			'overview', 'cashin', 'operations', 'wallets', 'invest', 'deposits',
			'calc', 'refsys', 'account', 'messages', 'tickets'
		);
		if ($_user['uLevel'] >= 90)
			$cabinet_menu_allowed[] = 'admin';
		$cabinet_pinned_ids = isset($_SESSION['_cabinet_pins']) && is_array($_SESSION['_cabinet_pins'])
			? array_values(array_intersect($_SESSION['_cabinet_pins'], $cabinet_menu_allowed))
			: array();
		$cabinet_pin = (string)_GET('cabinet_pin');
		$cabinet_unpin = (string)_GET('cabinet_unpin');
		if ($cabinet_pin && in_array($cabinet_pin, $cabinet_menu_allowed, true))
		{
			$cabinet_pinned_ids = array_values(array_diff($cabinet_pinned_ids, array($cabinet_pin)));
			array_unshift($cabinet_pinned_ids, $cabinet_pin);
		}
		elseif ($cabinet_unpin)
			$cabinet_pinned_ids = array_values(array_diff($cabinet_pinned_ids, array($cabinet_unpin)));
			$_SESSION['_cabinet_pins'] = $cabinet_pinned_ids;
			View::setPage('cabinet_pinned_ids', $cabinet_pinned_ids);

			$_currs = authLoadCurrencies(_uid());
	if ($_cfg['Const_IntCurr'])
		View::setPage('curr1', $_currs[1]);
	elseif (count($_currs) == 1)
		View::setPage('curr1', reset($_currs));

	// Main vars
		
	$_GS['lang'] = View::getLang($_SESSION['_lang']); // lang
	$_GS['lang_dir'] = View::getLangDir(); // tpl lang dir
	$_TRANS = View::translationLoad($_GS['lang']);
	
	View::setPage('_auth', $_auth);
	View::setPage('_cfg', $_cfg);
	View::setPage('_TRANS', $_TRANS, 0);
	View::setPage('root_url', $_GS['root_url']);
	View::setPage('clock_offset_seconds', $_GS['TZ'] ?? 0);
	View::setPage('current_lang', $_GS['lang']);
	View::setPage('img_path', 'static/images/');
	View::setPage('css_path', 'static/css/');
	View::setPage('js_path', 'static/js/');
	View::setPage('demo', !empty($_GS['demo']));
	
	if (is_file($_GS['module_dir'] . 'udf.php'))
		include_once($_GS['module_dir'] . 'udf.php');
	function _updateUserCounters()
	{
		if (function_exists('updateUserCounters'))
			updateUserCounters();
	}

	$is_admin_route = isset($_rwlinks[$_GS['module']]['admin']) && $_rwlinks[$_GS['module']]['admin'];
	if (($_auth >= 90) or (($_user['uLevel'] >= 90) and $is_admin_route))
		$_onstart['admin'] = $_auth;

	// onStart init

	foreach ($_onstart as $m => $l)
		if ($_auth >= $l)
			if (file_exists($f = $_GS['module_dir'] . $m . '/onstart.php'))
				include_once($f);
	if ($_smode < 1)
	{
		updateUserCounters();
		
		// Intro
		
		if ($_cfg['UI_ShowIntro'] and ($_GS['module'] == 'index') and !StringHelper::get1ElemL($_GS['uri'], '?'))
			if (($_cfg['UI_ShowIntro'] == 2) or _SESSION('show_intro'))
				if ($i = moduleToLink('udp/intro'))
				{
					unset($_SESSION['show_intro']);
					goToURL($i);
				}
	}
	
}
else // non-user mode
{
	$_currs = authLoadCurrencies();
}

$_vcurrs = array(
	'USD' => array(),
	'EUR' => array(),
	'RUB' => array(),
	'BTC' => array(),
	'ETH' => array(),
	'LTC' => array(),
	'XRP' => array()
);
foreach ($_currs as $cid => $c)
	$_vcurrs[$c['cCurrID']][] = $cid;
View::SetPage('vcurrs', $_vcurrs);

?>
