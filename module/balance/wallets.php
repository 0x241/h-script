<?php

use HScript\Template\View;
use HScript\Payment\PaymentManager;

$_auth = 1;
require_once('module/auth.php');
$paymentManager = new PaymentManager($db);

try 
{

	if (View::sendedForm())
	{
		View::checkFormSecurity();

		$a = $_IN;
		if (($_cfg['Sec_MinPIN'] > 0) and !verifyPasswordWithLegacyDigest($a['PIN'], $_user['uPIN'], $_cfg['Const_Salt'], false))
			View::setError('pin_wrong');
			
		if ($_cfg['Const_IntCurr'])
			if ((_INN('DefCurr') <= 1) or !$_currs[_INN('DefCurr')])
				View::setError('psys_wrong');
		$db->update('AddInfo', array('aDefCurr' => _INN('DefCurr')), '', 'auID=?d', array(_uid()));
			
		$t = time();
			foreach ($_currs as $cid => $c)
			{
				$a = opDecodeUserCurrParams($c);
				if ($_cfg['Bal_LockWallets'] and !empty($a['acc']))
					continue;
			$pf = $paymentManager->fields($c['cCID']);
			$key = $cid . _uid() . $t;
			View::setError($p = opEditToCurrParams($pf, array(), (array)_IN($c['cCID']), $c['cCID']));
			$a = array(
				'wParams' => encodeArrayToStr($p, $key),
				'wMTS' => timetostamp($t)
			);
			if (!$c['wcID'])
			{
				$a['wcID'] = $cid;
				$a['wuID'] = _uid();
				$db->insert('Wallets', $a);
			}
			else
				$db->update('Wallets', $a, '', 'wcID=?d and wuID=?d', array($cid, _uid()));
		}
		View::showInfo('Saved');
	}

}
catch (FormAbortException $e)
{
}

$defcurr = array();
$wfields = array();
$wdata = array();
$showbutton = false;
foreach ($_currs as $cid => $c)
{
	if ($cid > 1)
		$defcurr[$cid] = $c['cName'];
		$wdata[$c['cCID']] = opDecodeUserCurrParams($c);
		$acc = isset($wdata[$c['cCID']]['acc']) ? $wdata[$c['cCID']]['acc'] : '';
		$l = ($acc and $_cfg['Bal_LockWallets']);
	if ($a = opCurrParamsToEdit($paymentManager->fields($c['cCID']), $c['cCID'], $l))
	{
		$wfields[$cid] = $c['cName'];
		$wfields = array_merge($wfields, $a);
		$showbutton = ($showbutton or !$l);
	}
}
View::setPage('defcurr', $defcurr);
View::setPage('wfields', $wfields);
View::setPage('wdata', $wdata);
View::setPage('showbutton', $showbutton);

View::showPage();

?>
