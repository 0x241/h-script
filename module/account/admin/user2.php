<?php

use HScript\Util\StringHelper;
use HScript\Security\FailedLoginPolicy;
use HScript\Observability\StructuredLogger;
use HScript\Payment\PaymentManager;

use HScript\Template\View;

$_auth = 90;
require_once('module/auth.php');
$paymentManager = new PaymentManager($db);

$table = 'Users';
$id_field = 'uID';
$out_link = moduleToLink('account/admin/users');

useLib('balance');

$require_editable_user = static function (int $uid) use ($db, $_user): void
{
	$target = $uid > 0 ? $db->fetch1Row($db->select('Users', 'uLevel', 'uID=?d', array($uid))) : array();
	if (!$target || ((int)$_user['uLevel'] < 99 && (int)$target['uLevel'] >= (int)$_user['uLevel']))
	{
		http_response_code(403);
		View::setError('access_denied');
	}
};

try 
{

	if (View::sendedForm())
	{
		View::checkFormSecurity();
		
		$a = $_IN;
		if (!($id = $a['auID']))
			View::showInfo('*Error', $out_link);
		$require_editable_user((int)$id);
		
		if ($_GS['demo'] and ($_user['uLevel'] < 99) and ($id <= 3))
			View::showInfo('*Denied');
			
		if (($_cfg['Account_UseName'] == 2) and !$a['aName'])
			View::setError('name_empty');
		list($h, $m) = explode(':', $a['aTZ'], 2);
		if ((abs($h) > 12) or ($m < 0) or ($m >= 60))
			View::setError('tz_wrong');
		$a['aTZ'] = $h * 60 + $m;
		$a['aTimeOut'] = abs($a['aTimeOut']);
		$thresholdChanged = false;
		$thresholdField = '';
		if (array_key_exists('aFailedLoginThreshold', $a))
		{
			try { $threshold = FailedLoginPolicy::parse($a['aFailedLoginThreshold']); }
			catch (InvalidArgumentException) { View::setError('login_threshold_wrong'); }
			$previousThreshold = $db->fetch1($db->select('AddInfo', 'aFailedLoginThreshold', 'auID=?d', array($id)));
			$thresholdChanged = FailedLoginPolicy::parse($previousThreshold) !== $threshold;
			unset($a['aFailedLoginThreshold']);
			$a['aFailedLoginThreshold='] = FailedLoginPolicy::assignment($threshold);
			$thresholdField = ', aFailedLoginThreshold=';
		}

		if ($_cfg['Sec_MinSQA'] > 0)
		{
			if (!$a['aSQuestion'])
				View::setError('secq_empty');
			if (strlen($a['aSQuestion']) < $_cfg['Sec_MinSQA'])
				View::setError('secq_short');
			$f = 'aSQuestion, ';
			if (!StringHelper::sEmpty($a['aSAnswer']))
			{
				if (strlen($a['aSAnswer']) < $_cfg['Sec_MinSQA'])
					View::setError('seca_short');
				if ($a['aSAnswer'] == $a['aSQuestion'])
					View::setError('seqa_equal_secq');
				$a['aSAnswer'] = hashPassword($a['aSAnswer']);
				$f .= 'aSAnswer, ';
			} 
			else
				unset($a['aSAnswer']);
		}
		else
			$f = '';
		View::strArrayToStamp($a, 'aBD', 1);
		$a['aSessIP'] = (int)($a['aSessIP'] ?? 0);
		$a['aSessUniq'] = (int)($a['aSessUniq'] ?? 0);
		$updated = $db->update('AddInfo', $a,
			'aName, aTZ, aBD, aCountry, aCity, aTel, aNoMail, aPerc, aDPerc, aPPerc, aGA, aIPSec, aSessIP, aSessUniq, aTimeOut, ' . $f . $thresholdField,
			'auID=?d', array($id));
		if ($thresholdChanged && $updated < 1) View::setError('login_threshold_save_failed');
		if ($thresholdChanged)
			StructuredLogger::event('notice', 'security', 'account_login_threshold_changed', 'success', 0,
				StructuredLogger::resourceId('account', $id), array('reason' => 'administrator', 'owner' => StructuredLogger::resourceId('account', _uid()), 'state' => FailedLoginPolicy::effective($_cfg['Sec_BFC'], $threshold), 'attempt' => (int)$_cfg['Sec_BFC']));
		View::showInfo('Saved', $out_link . "?id=$id");
	}

	if (View::sendedForm('', 'wallets'))
	{
		View::checkFormSecurity('wallets');

		$a = $_IN;
		$require_editable_user((int)($a['auID'] ?? 0));
		
		if ($_cfg['Const_IntCurr'])
			if ((_INN('DefCurr') <= 1) or !$_currs[_INN('DefCurr')])
				View::setError('psys_wrong', 'wallets');
		$db->update('AddInfo', array('aDefCurr' => _INN('DefCurr')), '', 'auID=?d', array($a['auID']));
			
		$t = time();
		$_ucurrs = $db->fetchIDRows($db->select('Currs LEFT JOIN Wallets ON wcID=cID and wuID=?d',
			'*', 'cDisabled=0', array($a['auID']), 'cID'), false, 'cID');
		foreach ($_ucurrs as $cid => $c)
		{
			$p = opDecodeUserCurrParams($c);
			$pf = $paymentManager->fields($c['cCID']);
			$key = $cid . $a['auID'] . $t;
			View::setError($p = opEditToCurrParams($pf, array(), (array)_IN($c['cCID']), $c['cCID']), 'wallets');
			$p = array(
				'wParams' => encodeArrayToStr($p, $key),
				'wMTS' => timetostamp($t)
			);
			if (!$c['wcID'])
			{
				$p['wcID'] = $cid;
				$p['wuID'] = $a['auID'];
				$db->insert('Wallets', $p);
			}
			else
				$db->update('Wallets', $p, '', 'wcID=?d and wuID=?d', array($cid, $a['auID']));
		}
		View::showInfo('Saved');
	}

} 
catch (FormAbortException $e)
{
}

if ($uid = _GETN('id'))
	$el = $db->fetch1Row($db->select("$table LEFT JOIN AddInfo ON auID=uID", 
		'*', "$id_field=?d", array(_GETN('id'))));
if (!$el)
	goToURL($out_link);
View::stampArrayToStr($el, 'aCTS');
View::stampArrayToStr($el, 'aBD', 1);
$el['aTZ'] = sprintf("%+02d:%02d", floor($el['aTZ'] / 60), abs($el['aTZ'] % 60));
View::setPage('el', $el, 2);
View::setPage('account_login_policy', FailedLoginPolicy::describe($_cfg['Sec_BFC'], $el['aFailedLoginThreshold'] ?? null));

$defcurr = array();
$wfields = array();
$wdata = array('auID' => $uid);
$_ucurrs = $db->fetchIDRows($db->select('Currs LEFT JOIN Wallets ON wcID=cID and wuID=?d',
	'*', 'cDisabled=0', array($uid), 'cID'), false, 'cID');
foreach ($_ucurrs as $cid => $c)
{
	if ($cid > 1)
		$defcurr[$cid] = $c['cName'];
	$wdata[$c['cCID']] = opDecodeUserCurrParams($c);
	if ($a = opCurrParamsToEdit($paymentManager->fields($c['cCID']), $c['cCID']))
	{
		$wfields[$cid] = $c['cName'];
		$wfields = array_merge($wfields, $a);
	}
}
View::setPage('defcurr', $defcurr);
View::setPage('wfields', $wfields);
View::setPage('wdata', $wdata);

View::showPage();

?>
