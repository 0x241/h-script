<?php

use HScript\Http\Client;
use HScript\Payment\PaymentManager;

use HScript\Util\StringHelper;

use HScript\Template\View;

$_auth = 1;
require_once('module/auth.php');
$paymentManager = new PaymentManager($db);

if ($_POST and isset($_GET['resend']))
{
	Client::request(fullURL(moduleToLink('balance/status'), false), $_POST);
}

$table = 'Opers';
$id_field = 'oID';
$uid_field = 'ouID';
$out_link = moduleToLink('balance');

try 
{

	if (View::sendedForm('', 'add'))
	{
		View::checkFormSecurity('add');

		if (!_IN('Oper'))
			goToURL($out_link);
		$a = $_IN;
		$a += array(
			'Oper' => '',
			'PSys' => 0,
			'PSys2' => 0,
			'Sum' => 0,
			'PIN' => '',
			'Login2' => '',
			'Plan' => 0,
			'Compnd' => 0,
			'Memo' => ''
		);
		cn($a['Sum']);
		if (($a['Oper'] == 'CASHOUT') and $_cfg['Bal_NeedPIN'] and !verifyPasswordWithLegacyDigest($a['PIN'], $_user['uPIN'], $_cfg['Const_Salt'], false))
			View::setError('pin_wrong', 'add');
		if ($_cfg['Const_IntCurr'] and ($a['Oper'] == 'CASHIN') and $_cfg['Bal_SumInInternal'] and isset($_currs[$a['PSys']]) and
			(($crate = $_cfg['Bal_Rate' . $_currs[$a['PSys']]['cCurr']]) > 0))
			$a['Sum'] /= $crate;
		if ($a['Oper'] == 'CASHOUT')
		{
			$udata = opDecodeUserCurrParams($_currs[$a['PSys']]);
			if (!$udata['acc'])
				View::setError('wallet_not_defined', 'add');
		}
		if ($_cfg['Depo_AutoDepo'] and ($a['Oper'] == 'CASHIN'))
		{
			useLib('depo');
			$err = opDepoCreate(_uid(), $a['PSys'], $a['Sum'], $a['Compnd'], $a['Plan'], false, 2);
			if ($err != 'passed')
				View::setError($err, 'add');
		}
		if (!isset($a['PSys']))
			$a['PSys'] = 1;
		$params = array(
			'uid2' => $db->fetch1($db->select('Users', 'uID', 'uLogin=?', array($a['Login2']))),
			'cid2' => $a['PSys2'],
			'pid' => $a['Plan'],
			'compnd' => $a['Compnd']
		);
		$cid = $a['PSys'];
		if ($_cfg['Const_IntCurr'] and ($a['Oper'] == 'CASHOUT'))
		{
			$params['cid2'] = $a['PSys'];
			$a['Oper'] = 'EX';
			$a['PSys'] = 1;
		}
		View::setError($id = opOperCreate(_uid(), $a['Oper'], $a['PSys'], $a['Sum'], $params, $a['Memo']), 'add');
		View::showInfo('Saved', moduleToLink() . "?id=$id" . StringHelper::valueIf($a['Oper'] == 'CASHIN', '&pay'));
	}

	if (View::sendedForm('', 'data'))
	{
		View::checkFormSecurity('data');

		if ($o = $db->fetch1Row($db->select('Opers LEFT JOIN Currs on cID=ocID', '*', 
			"$id_field=?d and $uid_field=?d and oOper=? and oState<=1", array(_INN('oID'), _uid(), 'CASHIN'))))
		{
			$a = array(
				'date' => timeToStamp(View::textToTime(_IN('date'))),
				'batch' => strip_tags(_IN('batch')),
				'memo' => _IN('memo')
			);
			if (!$a['date'] or (stampToTime($a['date']) >= time()))
				View::setError('data_date_wrong', 'data');
			if (!$a['batch'])
				View::setError('data_batch_wrong', 'data');
			View::setError($a = opEditToCurrParams($paymentManager->fields($o['cCID']), $a, $_IN), 'data');
			$db->update('Opers', array('oParams2' => arrayToStr($a)), '', 'oID=?d', ($o['oID']));
			View::showInfo('Saved');
		}
		View::showInfo('*Error');
	}
	
	if ($id = _INN($id_field))
	{
		View::checkFormSecurity();
		
		if ($o = $db->fetch1Row($db->select('Opers', '*', "$id_field=?d and $uid_field=?d", array($id, _uid()))))
		{
			if (View::sendedForm('del') and ($o['oState'] >= 5))
			{
				$db->delete('Opers', 'oID=?d', array($id));
				View::showInfo('Deleted', $out_link);
			}
			elseif (View::sendedForm('cancel') and ($o['oState'] <= 2))
			{
				if (opOperCancel(_uid(), $id, array()) === true)
					View::showInfo('Canceled');
			}
			elseif (View::sendedForm() and ($o['oState'] <= 1))
			{
				if (($o['oOper'] == 'CASHIN') and ($_cfg['Bal_AFMMode'] > 0))
				{
					$a = array(
						'date' => timeToStamp(),
						'batch' => 'IN' . $o['oID'],
						'memo' => $o['oMemo'],
						'acc' => '???'
					);
					if ($_cfg['Bal_AFMMode'] & 1)
						if (!$a['acc'] = _IN('AFMAccount'))
							View::setError('afmacc_wrong');
						elseif ($c = $paymentManager->fields($_currs[$o['ocID']]['cCID']))
							if ($c['acc'][1] and !preg_match('/^' . $c['acc'][1] . '$/', $a['acc']))
								View::setError('afmacc_wrong');
					if ($_cfg['Bal_AFMMode'] & 2)
						if (!$a['batch'] = _IN('AFMBatch'))
							View::setError('afmbatch_wrong');
					$db->update('Opers', array('oParams2' => arrayToStr($a)), '', 'oID=?d', ($o['oID']));
				}
				if ($_cfg['SMS_CASHOUT'] and (($o['oOper'] == 'CASHOUT') or (($o['oOper'] == 'EX') and ($o['ocID'] == 1))))
				{
					useLib('confirm');
					$tel = $_user['aTel'];
					opConfirmPrepareSMS(_uid(), 'OPER', array('oid' => $id, 'tel' => $tel), '', $tel);
					View::showInfo('Saved', moduleToLink('confirm') . '?need_confirm_sms');
				}
				$err = opOperConfirm(_uid(), $id, array());
				if (($err === 'limit_exceeded') and ($_currs[$o['ocID']]['c' . $o['oOper'] . 'Mode'] == 2))
				{
					View::setError(opOperConfirm(_uid(), $id, array(), true));
					View::sendMailToAdmin('OperRequired',
						opUserConsts($_user, array('oid' => $id, 'url' => fullURL(moduleToLink('balance/admin/oper')))));
				}
				else
				{
					View::setError($err);
					if ($o['oOper'] != 'CASHIN')
					{
						if ($_currs[$o['ocID']]['c' . $o['oOper'] . 'Mode'] == 2)
						{
							View::setError(opOperComplete(_uid(), $id, array('initid' => $id)));
							if ($p = strToArray($db->fetch1($db->select('Opers', 'oParams2', "$id_field=?d", array($id)))))
								if ($id = $p['newid'])
									View::showInfo('Completed', moduleToLink() . "?id=$id");
						}
						else
							View::sendMailToAdmin('OperRequired',
								opUserConsts($_user, array('oid' => $id, 'url' => fullURL(moduleToLink('balance/admin/oper')))));
					}
				}
				View::showInfo();
			}
		}
		View::showInfo('*Error');
	}

} 
catch (FormAbortException $e)
{
}

if (!isset($_GET['add']))
{
	if ($id = _GETN('id'))
		$el = $db->fetch1Row($db->select("$table LEFT JOIN Users on uID=ouID LEFT JOIN Currs on cID=ocID", '*', "$id_field=?d and $uid_field=?d", array($id, _uid())));
	if (!$el)
		goToURL($out_link);
	if (isset($_GET['check']) and ($el['oOper'] == 'CASHIN'))
		if ($el['oState'] >= 3)
			goToURL(moduleToLink('balance/oper') . "?id=$id");
		else
			refreshToURL(5, moduleToLink('balance/oper') . "?id=$id&check");
	View::stampArrayToStr($el, 'oCTS, oTS, oNTS');
	$el['oParams'] = strToArray($el['oParams']);
	$el['oParams2'] = strToArray($el['oParams2']);
	View::stampArrayToStr($el['oParams2'], 'date', 1);
	View::setPage('el', $el);
	if (($el['oOper'] == 'CASHIN') and ($el['oState'] <= 2))
	{
		opDecodeCurrParams($el, $p, $p_sci, $p_api);
		if (in_array($el['cCASHINMode'], array(2, 3)))
		{
			$up = opDecodeUserCurrParams($_currs[$el['cID']]);
			$up['psysorder'] = $el['oParams']['psysorder'];
			$up['html'] = $el['oParams']['html'];
			$pf = $paymentManager->processDeposit($el['cCID'], array(
				'pay' => $p,
				'sci' => $p_sci,
				'sum' => $el['oSum'],
				'memo' => $el['oParams2']['memo'],
				'tag' => $id,
				'url_ok' => fullURL(moduleToLink('balance/oper')) . "?id=$id&check",
				'url_fail' => fullURL(moduleToLink('balance')) . '?fail',
				'url_callback' => StringHelper::valueIf(!$p_sci['hideurl'], fullURL(moduleToLink('balance/status'))),
				'user' => $up,
				'force_payer' => $_cfg['Bal_ForcePayer'],
			));
			if (isset($pf['psysorder']) or isset($pf['html']))
			{
				$el['oParams']['psysorder'] = $pf['psysorder'];
				$el['oParams']['html'] = $pf['html'];
				$db->update('Opers', array('oParams' => arrayToStr($el['oParams'])), '', 'oID=?d', array($id));
				unset($pf['psysorder']);
			}
			View::setPage('pform', $pf, 0);
			if (isset($_GET['pay']))
				View::showPage('_pform');
		}
		if (in_array($el['cCASHINMode'], array(1, 3)))
		{
			View::setPage('pfields', $pf = $paymentManager->fields($el['cCID']));
			View::setPage('pvalues', $p);
			if ($a = opCurrParamsToEdit($pf, '', $el['oState'] == 2))
				View::setPage('dfields', array(1 => '') + $a, 1);
			$c = $paymentManager->definitions($el['cCID']);
			if (!$c[2])
				View::setPage('defaultbatch', 'IN' . str_pad($el['oID'], 6, '0', STR_PAD_LEFT));
		}
	}
	$db->update('Opers', array('oNTS' => ''), '', "$id_field=?d", array($id));
	updateUserCounters();
}
else
{
	$oper = _GET('add');
	if (!in_array($oper, array('CASHIN', 'CASHOUT', 'EX', 'TR')))
		goToURL($out_link);
	if (!$_cfg['Const_IntCurr'] or in_array($oper, array('CASHIN', 'CASHOUT', 'EX')))
	{
		$list = array();
		$list2 = array();
		foreach ($_currs as $id => $r)
			if (!$r['cHidden'])
			{
				switch ($oper)
				{
				case 'CASHIN':
					if (($r['cCASHINMode'] > 0) and ($id != 1))
						$list[$id] = $r['cName'];
					break;
				case 'CASHOUT':
					if (($r['cCASHOUTMode'] > 0) and ($id != 1))
						$list[$id] = $r['cName'];
					break;
				case 'EX':
					if ($r['cEXMode'] > 0)
						$list[$id] = $r['cName'] . StringHelper::valueIf($r['wBal'] > 0, '{!!} [' . _z($r['wBal'], $r['wcID'], -1) . ']');
					if ($r['cEXMode'] > 0)
						$list2[$id] = $r['cName'] . StringHelper::valueIf($r['wBal'] > 0, '{!!} [' . _z($r['wBal'], $r['wcID'], -1) . ']');
					break;
				case 'TR':
					if ($r['cTRMode'] > 0)
						$list[$id] = $r['cName'] . StringHelper::valueIf($r['wBal'] > 0, '{!!} [' . _z($r['wBal'], $r['wcID'], -1) . ']');
					break;
				}
			}
		if (!$list)
			View::showInfo('*CantComplete', $out_link);
		View::setPage('clist', $list);
		if ($oper == 'EX')
			View::setPage('clist2', $list2);
	}
	if (($oper=='CASHIN') and $_cfg['Depo_AutoDepo'])
	{
		useLib('depo');
		$plans = opDepoGetPlanList(_uid());
		$pl = array();
		$cmax = 0;
		foreach ($plans as $pid => $p)
			if (!$p['Disabled'])
			{
				$pl[$pid] = $p['pName'];
				if ($p['pCompndMax'] > $cmax)
					$cmax = $p['pCompndMax'];
			}
		if (!$pl)
			View::showInfo('*CantComplete', $out_link);
		View::setPage('plans', $pl);
		View::setPage('pcmax', $cmax);
		View::setPage('icurr', $_currs[1]['cCurr']);
	}
}

View::setPage('currs', $_currs);

$_GS['vmodule'] = 'balance';
View::showPage();

?>
