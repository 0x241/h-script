<?php

use HScript\Util\StringHelper;
use HScript\Payment\PaymentManager;

use HScript\Template\View;

/*
BONUS - by system						0	0	0		0	0	0		+$	0	0
PENALTY									0	0	0		0	0	0		-$	0	0

CASHIN - add funds from p.sys			0	0	0		0	0	0		+$	0	0
	[needs 'oParams2' filled date, batch or set via opConfirm 'params']
CASHOUT - withdraw to p.sys				$$	0	0		-$	0	+$		0	0	-$

EX - exchange							$$	0	0		-$	+$	0		0	-$	0
EXIN									0	0	0		0	0	0		+$	0	0 (cid2)

TR - send								$$	0	0		-$	0	+$		0	0	-$
TRIN - receive							0	0	0		0	0	0		+$	0	0 (uid2)

BUY - buy item							$$	0	0		-$	+$	0		0	-$	0
SELL - sell item 						0	0	0		0	0	0		+$	0	0
BUY2 - use service 						$$	0	0		-$	+$	0		0	-$	0
SELL2 - provide service					0	0	0		0	0	0		+$	0	0

REF - referral comission N				0	0	0		0	0	0		+$	0	0

GIVE - make deposit						$$	0	0		-$	+$	0		0	0	0
TAKE - withdraw to balance				0	0	0		0	0	0		+$	-$	0

CALCIN - periodically by system			0	0	0		0	0	0		+$	0	0
CALCOUT									$$	0	0		0	0	0		-$	0	0
----------------------------------
0 = Ожидание (подтверждения)
1 = Ожидает пополнения
2 = Ожидает обработки (выполнения)
3 = Выполнена
4 = Отклонена
5 = Отменена

Двойные операции:
Пополнение+обмен (cid2, sum2)
Пополнение+покупка (vid, amount)
пополнение+вклад (pid)
Обмен (cid2, sum2)+вывод
*/

function opUser($uid)
{
	global $db;
	return $db->count('Users', 'uID=?d and (uState=1 or uState=2)', array($uid));
}

function _zr($z, $cid)
{
	global $_cfg, $_currs;
	$r = isset($_currs[$cid]['cNumDec']) ? $_currs[$cid]['cNumDec'] : 0;
	if ($r <= 0)
		$r = isset($_cfg['UI_NumDec']) ? $_cfg['UI_NumDec'] : 2;
	return round((float)$z, $r);
}

function opCalcComis($cid, $oper, $sum, $by_admin)
{
	global $db, $_cfg, $_currs;
	$c = isset($_currs[$cid]) ? $_currs[$cid] : null;
	if (!$c)
		return 'psys_wrong';
	$prfx = 'c' . $oper;
	if (!$by_admin and empty($c[$prfx . 'Mode']) and ($oper != 'EXIN') and ($oper != 'TRIN'))
		return 'oper_disabled';
	if (!empty($c[$prfx . 'Int']) and (round($sum) != $sum))
		return 'sum_not_int';
	if (isset($c[$prfx . 'Min']) and $sum < $c[$prfx . 'Min'])
		return 'sum_min';
	if (isset($c[$prfx . 'Max']) and ($c[$prfx . 'Max'] > 0) and ($sum > $c[$prfx . 'Max']))
		return 'sum_max';
	// Calculate with extra precision first, then round to the currency scale and
	// clamp the result to the configured absolute commission bounds.
	$csum = _zr(calcPerc($sum, isset($c[$prfx . 'Comis']) ? $c[$prfx . 'Comis'] : 0, 6), $cid);
	if (isset($c[$prfx . 'ComisMin']) and $csum < $c[$prfx . 'ComisMin'])
		$csum = 0 + $c[$prfx . 'ComisMin'];
	elseif (isset($c[$prfx . 'ComisMax']) and ($c[$prfx . 'ComisMax'] > 0) and ($csum > $c[$prfx . 'ComisMax']))
		$csum = 0 + $c[$prfx . 'ComisMax'];
	return _zr($csum, $cid);
}

function opCalcExSum($is_out, $cid, $sum)
{
	global $db, $_cfg, $_currs;
	if ($sum <= 0)
		return 'ex_sum_wrong';
	$c = $_currs[$cid];
	$zc = isset($_cfg['Bal_Rate' . $c['cCurrID']]) ? $_cfg['Bal_Rate' . $c['cCurrID']] : 0;
	if ($zc <= 0)
		return 'ex_rate_not_set';
	try
	{
		// Rates are stored as base-currency value per source unit. Converting out
		// multiplies by the rate; converting in divides and applies the inverse fee.
		if ($is_out)
			return _zr($sum * $zc * (1 + (isset($c['cEXOut']) ? $c['cEXOut'] : 0) / 100), $cid);
		else
			return _zr($sum / ($zc * (1 - (isset($c['cEXIn']) ? $c['cEXIn'] : 0) / 100)), $cid);
	}
	catch (ArithmeticError $e)
	{
	}
	return 'ex_overflow';
}

function opCalcEx($cid, $cid2, $sum)
{
	if ($cid != 1)
		if (is_string($sum = opCalcExSum(true, $cid, $sum)))
			return $sum;
	if ($cid2 != 1)
		$sum = opCalcExSum(false, $cid2, $sum);
	return $sum;
}

// Operations

function opOperChkAWD($o)
{
	global $db, $_cfg;
	$oper = $o['oOper'];
	$uid = $o['ouID'];
	$cid = $o['ocID'];
	$sum = $o['oSum'] - $o['oComis'];
	if (empty($_cfg["Bal_AWD$oper"]) or (($oper == 'EXIN') and ($cid == 1)))
		return false;
	if ($cid == 1)
	{
		$params = array(
			'cid2' => $db->fetch1($db->select('AddInfo', 'aDefCurr', 'auID=?d', array($uid)))
		);
		return opOperCreate($uid, 'EX', 1, $sum, $params, 'Auto', true, true);
	}
	else
		return opOperCreate($uid, 'CASHOUT', $cid, $sum, array(), 'Auto', true, true);
}

function opOperCreateInfo($uid, $oper, $cid, $sum, $params = array(), $memo = '')
{
	global $db, $_currs;
	$params = asArray($params);
	$a = array(
		'oCTS' => timeToStamp(),
		'oATS' => timeToStamp(time() + HS2_UNIX_HOUR),
		'ouID' => $uid,
		'oOper' => $oper,
		'ocID' => $cid,
		'oSum' => $sum,
		'oComis' => StringHelper::valueIf(isset($params['comis']) && $params['comis'] > 0, _zr(calcPerc($sum, isset($params['comis']) ? $params['comis'] : 0, 6), $cid), 0),
		'oParams' => arrayToStr($params),
		'oBatch' => isset($params['batch']) ? $params['batch'] : '',
		'oTag' => isset($params['tag']) ? $params['tag'] : '',
		'oTS' => timeToStamp(),
		'oState' => 3,
		'oMemo' => $memo,
		'oNTS' => timeToStamp()
	);
	$oid = $db->insert('Opers', $a);
	$n = array(
		'oid' => $oid,
		'oper' => $oper,
		'cid' => $cid,
		'sum' => $sum,
		'psys' => isset($_currs[$cid]['cName']) ? $_currs[$cid]['cName'] : '',
		'curr' => trim((string)($_currs[$cid]['cCurr'] ?? '')) ?: trim((string)($_currs[$cid]['cCurrID'] ?? ''))
	);
	$params = array_merge($params, $n);
	$params['url'] = fullURL(moduleToLink('balance/oper'));
	if ($usr = opReadUser($uid))
	{
		View::SendMailToUser($usr['uMail'], "Oper$oper",
			opUserConsts($usr, $params),
			$usr['uLang']
		);
		View::sendMailToAdmin("Oper$oper", opUserConsts($usr, $params));
	}
	opEvent('Oper', $uid, $params);
	opOperChkAWD($a);
	return $oid;
}

function opOperCreate($uid, $oper, $cid, $sum, $params = array(), $memo = '', $and_complete = false, $by_admin = false)
{
	global $db, $_cfg, $_currs;
	if ($uid < 0)
		$uid = _uid();
	$sum = _zr($sum, $cid);
	$params = asArray($params);
	if (!$oper)
		return 'oper_empty';
	if (!$uid)
		return 'user_empty';
	if (!opUser($uid))
		return 'user_wrong';
	if (!$cid)
		return 'psys_empty';
	if (!isset($_currs[$cid]))
		return 'psys_wrong';
	if (!$by_admin and empty($_currs[$cid]['c' . $oper . 'Mode']) and ($oper != 'EXIN') and ($oper != 'TRIN'))
		return 'oper_disabled';
	if ($sum <= 0)
		return 'sum_wrong';
	if (is_string($csum = opCalcComis($cid, $oper, $sum, $by_admin)))
		return $csum; // 'sum_wrong'
	if (isset($params['comis']) && $params['comis'] > 0)
		$csum += _zr(calcPerc($sum, $params['comis'], 6), $cid);
	if (($sum - $csum) < 0)
		return 'sum_wrong';
	if (in_array($oper, array('CASHOUT', 'EX', 'TR', 'BUY', 'BUY2', 'GIVE')))
		if ((0 + $db->fetch1($db->select('Wallets', 'wBal', 'wuID=? and wcID=?', array($uid, $cid)))) < $sum)
			return 'low_bal1';
	if ($oper == 'EX')
	{
		if (isset($params['cid2']) && $params['cid2'] == $cid)
			return 'psys2_equal_psys';
		if (empty($params['cid2']))
			return 'psys2_empty';
		$params['psys'] = isset($_currs[$params['cid2']]['cName']) ? $_currs[$params['cid2']]['cName'] : '';
		if (!$params['psys'])
			return 'psys2_wrong';
		$params['sum2'] = opCalcEx($cid, $params['cid2'], $sum - $csum);
		if (is_string($params['sum2']))
			return $params['sum2']; // 'sum2_wrong'
	}
	if ($oper == 'TR')
	{
		if (isset($params['uid2']) && $params['uid2'] == $uid)
			return 'user2_equal_user';
		if (empty($params['uid2']))
			return 'user2_empty';
		$params['user'] = $db->fetch1($db->select('Users', 'uLogin', 'uID=? and (uState=1 or uState=2)', array($params['uid2'])));
		if (!$params['user'])
			return 'user2_not_found';
	}
	if (($oper == 'CASHOUT') or (($oper == 'EX') and ($cid == 1)))
		if (($usr = opReadUser($uid)) and !empty($usr['uWDDisable']))
			return 'wd_disable';
	$oid = $db->insert('Opers', 
		array(
			'oCTS' => timeToStamp(),
			'oATS' => timeToStamp(time() + HS2_UNIX_HOUR),
			'ouID' => $uid,
			'oOper' => $oper,
			'ocID' => $cid,
			'oSum' => $sum,
			'oComis' => $csum,
			'oParams' => arrayToStr($params),
			'oBatch' => isset($params['batch']) ? $params['batch'] : '',
			'oTag' => isset($params['tag']) ? $params['tag'] : '',
			'oTS' => 0, // complete date
			'oState' => 0,
			'oMemo' => $memo,
			'oNTS' => 0 // date of modify (by admin / system)
		)
	);
	if (!$oid)
		return 'db_error';
	if (in_array($oper, array('CASHIN', 'CASHOUT')))
	{
		$w = $db->fetch1Row($db->select('Users LEFT JOIN Wallets ON (wcID=?d AND wuID=uID)', 
			'*', 'uID=?d', array($cid, $uid)));
		$params2 = opDecodeUserCurrParams($w);
		$params2['date'] = timeToStamp();
		$params2['memo'] = StringHelper::textVarReplace(
				StringHelper::exValue('Invoice ##id#, #user#', StringHelper::textLangFilter(StringHelper::exValue(isset($_cfg['Bal_' . $oper . 'Text']) ? $_cfg['Bal_' . $oper . 'Text'] : '', $memo), isset($w['uLang']) ? $w['uLang'] : '')),
				array('id' => $oid, 'user' => isset($w['uLogin']) ? $w['uLogin'] : '')
			);
		$db->update('Opers', array('oParams2' => arrayToStr($params2)), '', 'oID=?d', array($oid));
	}
	if ($and_complete)
	{
		if (is_string($err = opOperConfirm($uid, $oid, array(), $by_admin)))
			return $err;
		if (is_string($err = opOperComplete($uid, $oid, array(), $by_admin)))
			return $err;
	}
	return $oid;
}

function opOperConfirm($uid, $oid, $params = array(), $by_admin = false)
{
	global $db, $_cfg, $_currs;
	if (is_array($oid))
	{
		if ($o = $db->fetch1Row($db->select('Opers', '*', 'oID=?d and ouID=?d', array($id = $oid['oid'], $uid))))
			if ($o['oState'] <= 1)
			{
				$err = opOperConfirm($uid, $id, array());
				if (($o['ocID'] == 1) or (isset($_currs[$o['ocID']]['c' . $o['oOper'] . 'Mode']) && $_currs[$o['ocID']]['c' . $o['oOper'] . 'Mode'] == 2))
				{
					if ($err === 'limit_exceeded')
						$err = opOperConfirm($uid, $id, array(), true);
					elseif (!is_string($err) and ($o['oOper'] != 'CASHIN'))
							opOperComplete($uid, $id, array());
					if (is_string($err))
						View::sendMailToAdmin('OperRequired',
							opUserConsts(opReadUser($uid), array('oid' => $id, 'url' => fullURL(moduleToLink('balance/admin/oper')))));
				}
				$oid = StringHelper::exValue($id, $o['oTag']);
				View::showInfo('Completed', moduleToLink('balance/oper') . "?id=$oid");
			}
		View::showInfo('*Error', moduleToLink('balance'));
	}
	$params = asArray($params);
	if (!($o = $db->fetch1Row($db->select('Opers LEFT JOIN Currs ON cID=ocID', '*', 'oID=?d' . StringHelper::valueIf($uid > 0, ' and ouID=?d'), array($oid, $uid)))))
		return 'oper_not_found';
	if (!(($o['oState'] < 2) or (($o['oState'] == 2) and ($o['oOper'] == 'CASHIN'))))
		return 'oper_state_wrong';
//	if (!$by_admin and (stampToTime($o['oATS']) < time()))
//		return 'oper_expired';
	$uid = $o['ouID'];
	$oparams = strToArray($o['oParams']);
	$oparams2 = strToArray($o['oParams2']);
	$a = array(
		'oTS' => timeToStamp(),
		'oState' => 2
	);
	$cid = $o['ocID'];
	$sum = $o['oSum'];
	$res = 'oper_unknown';
	switch ($o['oOper'])
	{
	case 'BONUS':
	case 'PENALTY':
	case 'EXIN':
	case 'TRIN':
	case 'SELL':
	case 'SELL2':
	case 'REF':
	case 'CALCIN':
	case 'CALCOUT':
		$res = true;
		break;
	case 'CASHIN':
		$res = true;
		if ($params) // from status
		{
			if (isset($params['sum']) && $params['sum'] === '?')
				$params['sum'] = $sum;
			if (isset($params['date'])) $params['date'] = timeToStamp($params['date']);
			$a['oParams2'] = arrayToStr($params);
			$oparams2 = $params;
		}
		if (empty($oparams2['date']))
			$res = 'data_date_wrong';
		elseif (empty($oparams2['batch']))
			$res = 'data_batch_wrong';
		// A completed gateway batch may credit a currency only once.
		elseif ($db->count('Opers', 'ocID=?d and oBatch=? and oState=3', array($cid, $oparams2['batch'])))
			$res = 'batch_exists';
		$a['oBatch'] = '?' . (isset($oparams2['batch']) ? $oparams2['batch'] : '');
		break;
	case 'CASHOUT':
		if (!$by_admin and !empty($o['cCASHOUTLimitPer']) and ($o['cCASHOUTLimitPer'] > 0))
		{
			$outsum = (float)$db->fetch1($db->select('Opers', 'SUM(oSum)',
				"(ouID=?d) and (oOper=?) and (ocID=?d) and (oState=3) and (oTS>?)", 
				array($uid, 'CASHOUT', $cid, timeToStamp(time() - ($o['cCASHOUTLimitPer'] * HS2_UNIX_HOUR)))));
			if (($sum + $outsum) > (isset($o['cCASHOUTLimit']) ? $o['cCASHOUTLimit'] : 0))
			{
				$res = 'limit_exceeded';
				break;
			}
		}
		$res = opChangeBalance($uid, $cid, -$sum, 0, $sum, true, $oid, '');
		break;
	case 'EX':
		if ($params)
		{
			$a['oParams'] = arrayToStr($params);
			$oparams = $params;
		}
		$res = opChangeBalance($uid, $cid, -$sum, 0, $sum, true, $oid, '');
		break;
	case 'TR':
		if ($params)
		{
			$a['oParams'] = arrayToStr($params);
			$oparams = $params;
		}
		if (isset($oparams['uid2']) && opUser($oparams['uid2']))
			$res = opChangeBalance($uid, $cid, -$sum, 0, $sum, true, $oid, '');
		else
			$res = 'user2_wrong';
		break;
	case 'BUY':
	case 'GIVE':
		$res = opChangeBalance($uid, $cid, -$sum, 0, $sum, empty($oparams['nocheck']), $oid, '');
		break;
	case 'TAKE':
		$res = opChangeBalance($uid, $cid, 0, -$sum, $sum, empty($oparams['nocheck']), $oid, '');
		break;
	}
	if (is_string($res))
		return $res;
	$db->update('Opers', $a, '', 'oID=?d', array($oid));
	return true;
}

function opOperComplete($uid, $oid, $params = array(), $by_admin = false, $aparams = array())
{
	global $db, $_cfg;
	// oPTS acts as a short processing lease. Only one callback or worker may
	// complete a confirmed operation during the lease window.
	if (!$db->update('Opers', array('oPTS' => timeToStamp()), '', 'oID=?d and oState=2 and oPTS<?' . StringHelper::valueIf($uid > 0, ' and ouID=?d'),
		array($oid, timeToStamp(time() - 3 * HS2_UNIX_MINUTE), $uid)))
		return 'oper_not_found';
	$o = $db->fetch1Row($db->select('Opers LEFT JOIN Currs ON cID=ocID LEFT JOIN Users ON uID=ouID', 
		'Opers.*, Currs.*, Users.uLogin', 'oID=?d', array($oid)));
	if ($o['oState'] != 2)
		return 'oper_state_wrong';
	$uid = $o['ouID'];
	$params = asArray($params);
	$oparams = strToArray($o['oParams']);
	$oparams2 = strToArray($o['oParams2']);
	$a = array(
		'oTS' => timeToStamp(),
		'oState' => 3,
		'oPTS' => 0
	);
	if (!empty($params['tag']))
	{
		$o['oTag'] = $params['tag'];
		$a['oTag'] = $params['tag'];
	}
	if ($by_admin)
		$a['oNTS'] = timeToStamp();
	$cid = $o['ocID'];
	$sum = $o['oSum'] - $o['oComis'];
	if ($sum < 0)
		return 'oper_sum_wrong';
	$n = array(
		'oid' => $oid,
		'oper' => $o['oOper'],
		'tag' => isset($o['oTag']) ? $o['oTag'] : '',
		'cid' => $cid,
		'sum' => $sum,
		'psys' => isset($o['cName']) ? $o['cName'] : '',
		'curr' => trim((string)($o['cCurr'] ?? '')) ?: trim((string)($o['cCurrID'] ?? ''))
	);
	$res = 'oper_unknown';
	switch ($o['oOper'])
	{
	case 'CALCIN':
		if (!empty($o['oTag']) and $o['oTag'] > 0)
			$a['oMemo'] = (isset($o['oMemo']) ? $o['oMemo'] : '') . '#' . $o['oTag'];
	case 'EXIN':
	case 'TRIN':
	case 'BONUS':
	case 'SELL':
	case 'SELL2':
	case 'REF':
		$res = opChangeBalance($uid, $cid, $sum, 0, 0, false, $oid, isset($o['oMemo']) ? $o['oMemo'] : '');
		if (($o['oOper'] == 'EXIN') and !empty($_cfg['Const_IntCurr']) and isset($oparams['cid2']) and ($oparams['cid2'] == 1))
			if (!is_string($woid = opOperCreate($uid, 'CASHOUT', $cid, $sum, array(), 'Auto', false, $by_admin)))
			{
				opOperConfirm($uid, $woid, array(), $by_admin);
				if (isset($o['cCASHOUTMode']) && $o['cCASHOUTMode'] == 2)
					opOperComplete($uid, $woid, array(), $by_admin);
				$p = strToArray($db->fetch1($db->select('Opers', 'oParams2', 'oID=?d', array(isset($oparams['initid']) ? $oparams['initid'] : 0))));
				$p['newid'] = $woid;
				$db->update('Opers', array('oParams2' => arrayToStr($p)), '', 'oID=?d', array(isset($oparams['initid']) ? $oparams['initid'] : 0));
			}
		break;
	case 'PENALTY':
	case 'CALCOUT':
		$res = opChangeBalance($uid, $cid, -$sum, 0, 0, !$by_admin, $oid, isset($o['oMemo']) ? $o['oMemo'] : '');
		break;
	case 'CASHOUT':
		if (!empty($params['date'])) // fake send ??? direct params
		{
			$params['date'] = timeToStamp($params['date']);
			$a['oParams2'] = arrayToStr($params);
			$oparams2 = $params;
		}
		elseif (empty($oparams2['batch']) or ($oparams2['batch'] == '-')) // real send
		{
			if (empty($aparams['apipass'])) // NO psys direct access params
				opDecodeCurrParams($o, $r, $r, $aparams);
			$paymentManager = new PaymentManager($db);
			$c = $paymentManager->definitions($o['cCID']);
			if (!empty($c[3]) and !empty($aparams['apipass'])) // psys access password is set?
			{
				// pay SUM-COMIS from $aparams to $oparams2
				$r = $paymentManager->processWithdrawal($o['cCID'], array(
					'from' => $aparams,
					'to' => $oparams2,
					'sum' => $sum,
					'memo' => isset($oparams2['memo']) ? $oparams2['memo'] : '',
					'tag' => $oid,
					'url_callback' => fullURL(moduleToLink('balance/status')),
				));
				$db->update('Opers', array('oMemo' => '~' . (isset($r['result']) ? $r['result'] : '')), '', 'oID=?d', array($oid));
				if (empty($r['batch']))
				{
					$res = 'send_error';
					break;
				}
				$oparams2['date'] = timeToStamp();
				$oparams2['batch'] = $r['batch'];
			}
		}
		$n['acc'] = isset($oparams2['acc']) ? $oparams2['acc'] : ''; // payee acc
		$n['batch'] = isset($oparams2['batch']) ? $oparams2['batch'] : '';
	case 'CASHIN':
		if (!empty($oparams2['cid']) and ($oparams2['cid'] != $cid))
			return 'psys_wrong';
		if (isset($oparams2['sum'])) // SUM from SCI
		{
			$sum = _zr($oparams2['sum'], $cid);
			$a['oSum'] = $sum;
			if (is_string($res = opCalcComis($cid, $o['oOper'], $sum, $by_admin)))
				break;
			$a['oComis'] = $res;
			if (($sum -= $res) < 0)
			{
				$res = 'data_sum_wrong';
				break;
			}
			$n['sum'] = $sum;
		}
		$r_batch = isset($r) && isset($r['batch']) ? $r['batch'] : null;
		if (empty($oparams2['date']))
			$res = 'data_date_wrong';
		elseif (empty($oparams2['batch']))
			$res = 'data_batch_wrong';
		elseif (!$r_batch and $db->count('Opers', 'ocID=?d and oBatch=? and oState=3', array($cid, $oparams2['batch'])))
			$res = 'batch_exists';
		else
		{
			$a['oCTS'] = $oparams2['date'];
			$a['oBatch'] = $oparams2['batch'];
			$a['oMemo'] = isset($oparams2['memo']) ? $oparams2['memo'] : '';
			if ($o['oOper'] == 'CASHIN')
			{
				$res = opChangeBalance($uid, $cid, $sum, 0, 0, false, $oid, $a['oMemo']);
				if (!empty($oparams2['acc'])) // try fill currency property 'acc'
				{
					$w = $db->fetch1Row($db->select('AddInfo LEFT JOIN Wallets ON (wcID=?d AND wuID=auID)', 
						'AddInfo.aDefCurr, Wallets.*', 'auID=?d', array($cid, $uid)));
					$p = opDecodeUserCurrParams($w);
					if (empty($p['acc'])) // first time ('acc' empty)
					{
						$t = time();
						$key = $cid . $uid . $t;
						$p = array(
							'wParams' => encodeArrayToStr(array('acc' => $oparams2['acc']), $key),
							'wMTS' => timetostamp($t)
						);
						$db->update('Wallets', $p, '', 'wcID=?d and wuID=?d', array($cid, $uid));
					}
					if (empty($w['aDefCurr']))
						$db->update('AddInfo', array('aDefCurr' => $cid), '', 'auID=?d', array($uid));
				}
				if (!is_string($res) and !empty($_cfg['Const_IntCurr']) and ($cid > 1))
				{
					$err = opOperCreate($uid, 'EX', $cid, $sum, array('cid2' => 1), 'Auto', true, true);
					if (!is_string($err))
					{
						$o2 = $db->fetch1Row($db->select('Opers', 'ocID, oSum, oComis, oParams', 'oID=?d', array($err)));
						$o2params = strToArray($o2['oParams']);
						$n['cid2'] = isset($o2params['cid2']) ? $o2params['cid2'] : 0;
						$n['sum2'] = isset($o2params['sum2']) ? $o2params['sum2'] : 0;
						global $_currs;
						$n['psys2'] = isset($_currs[$n['cid2']]['cName']) ? $_currs[$n['cid2']]['cName'] : '';
						$n['curr2'] = isset($_currs[$n['cid2']]['cCurr']) ? $_currs[$n['cid2']]['cCurr'] : '';
					}
				}
			}
			else
				$res = opChangeBalance($uid, $cid, 0, 0, -$o['oSum'], false, $oid, isset($o['oMemo']) ? $o['oMemo'] : '');
		}
		break;
	case 'EX':
		$res = opChangeBalance($uid, $cid, 0, 0, -$o['oSum'], false, $oid, isset($o['oMemo']) ? $o['oMemo'] : '');
		if ($res === true)
		{
			$cid2 = isset($oparams['cid2']) ? $oparams['cid2'] : 0;
			$sum2 = isset($oparams['sum2']) ? $oparams['sum2'] : 0;
			$batch = 'EX' . str_pad($oid, 6, '0', STR_PAD_LEFT);
			$a['oBatch'] = $batch;
			$n['batch'] = $batch;
			$n['course'] = $sum > 0 ? round($sum2 / $sum, 4) : 0;
			global $_currs;
			$n['psys2'] = isset($_currs[$cid2]['cName']) ? $_currs[$cid2]['cName'] : '';
			$n['curr2'] = isset($_currs[$cid2]['cCurr']) ? $_currs[$cid2]['cCurr'] : '';
			opOperCreate($uid, 'EXIN', $cid2, $sum2, 
				array('oid' => $oid, 'sum2' => $sum, 'cid2' => $cid, 'batch' => $batch, 'initid' => isset($params['initid']) ? $params['initid'] : 0), isset($o['oMemo']) ? $o['oMemo'] : '', true, true);
		}
		break;
	case 'TR':
		if (!empty($oparams['uid2']) and $usr2 = opReadUser($oparams['uid2']))
		{
			$res = opChangeBalance($uid, $cid, 0, 0, -$o['oSum'], false, $oid, isset($o['oMemo']) ? $o['oMemo'] : '');
			if ($res === true)
			{
				$batch = 'TR' . str_pad($oid, 6, '0', STR_PAD_LEFT);
				$a['oBatch'] = $batch;
				$n['to'] = isset($usr2['uLogin']) ? $usr2['uLogin'] : '';
				$n['batch'] = $batch;
				opOperCreate($oparams['uid2'], 'TRIN', $cid, $sum, 
					array('oid' => $oid, 'uid2' => $uid, 'user' => isset($o['uLogin']) ? $o['uLogin'] : '', 'batch' => $batch), isset($o['oMemo']) ? $o['oMemo'] : '', true, $by_admin);
			}
		}
		else
			$res = 'user2_wrong';
		break;
	case 'BUY':
		$res = opChangeBalance($uid, $cid, 0, 0, -$o['oSum'], false, $oid, isset($o['oMemo']) ? $o['oMemo'] : '');
		break;
	case 'GIVE':
		if (!empty($oparams['bonus']) and $oparams['bonus'] > 0)
			opOperCreateInfo($uid, 'BONUS', $cid, $oparams['bonus']);
		$res = opChangeBalance($uid, $cid, 0, $sum + (isset($oparams['bonus']) ? $oparams['bonus'] : 0), -$o['oSum'], false, $oid, isset($o['oMemo']) ? $o['oMemo'] : '');
		if (!empty($o['oTag']) and $o['oTag'] > 0)
			$a['oMemo'] = (isset($o['oMemo']) ? $o['oMemo'] : '') . '#' . $o['oTag'];
		break;
	case 'TAKE':
		$res = opChangeBalance($uid, $cid, $sum, 0, -$o['oSum'], false, $oid, isset($o['oMemo']) ? $o['oMemo'] : '');
		if (!empty($o['oTag']) and $o['oTag'] > 0)
			$a['oMemo'] = (isset($o['oMemo']) ? $o['oMemo'] : '') . '#' . $o['oTag'];
		break;
	}
	if (is_string($res))
	{
		$db->update('Opers', array('oPTS' => 0), '', 'oID=?d', array($oid));
//		if (!$by_admin)
			View::sendMailToAdmin('OperNotComplete',
				opUserConsts(opReadUser($uid), array('oid' => $oid, 'url' => fullURL(moduleToLink('balance/admin/oper')))));
		return $res;
	}
	$db->update('Opers', $a, '', 'oID=?d', array($oid));
	$oparams = array_merge($oparams, $n);
	$oparams['url'] = fullURL(moduleToLink('balance/oper'));
	if ($usr = opReadUser($uid))
	{
		useLib('sms');
		if (function_exists('smsToUser'))
			smsToUser($uid, isset($usr['aTel']) ? $usr['aTel'] : '', 'Oper' . $o['oOper'], opUserConsts($usr, $oparams), isset($usr['uLang']) ? $usr['uLang'] : '');
		View::sendMailToUser(isset($usr['uMail']) ? $usr['uMail'] : '', 'Oper' . $o['oOper'],
			opUserConsts($usr, $oparams),
			isset($usr['uLang']) ? $usr['uLang'] : ''
		);
		View::sendMailToAdmin('Oper' . $o['oOper'],
			opUserConsts($usr, $oparams));
	}
	opEvent('Oper', $uid, $oparams);
	opOperChkAWD($o);
	return true;
}

function opOperCancel($uid, $oid, $params = array(), $by_admin = false)
{
	global $db, $_cfg;
	$params = asArray($params);
	if (!($o = $db->fetch1Row($db->select('Opers', '*', 'oID=?d' . StringHelper::valueIf($uid > 0, ' and ouID=?d'), array($oid, $uid)))))
		return 'oper_not_found';
	if ($o['oState'] >= 3)
		return 'oper_state_wrong';
	$uid = $o['ouID'];
	$oparams = strToArray($o['oParams']);
	$oparams2 = strToArray($o['oParams2']);
	$a = array(
		'oTS' => timeToStamp(),
		'oState' => ($by_admin ? 4 : 5)
	);
	if ($by_admin)
		$a['oNTS'] = timeToStamp();
	$cid = $o['ocID'];
	$sum = $o['oSum'];
	if ($o['oState'] == 2)
	{
		$res = 'oper_unknown';
		switch ($o['oOper'])
		{
		case 'BONUS':
		case 'PENALTY':
		case 'CASHIN':
		case 'EXIN':
		case 'TRIN':
		case 'SELL':
		case 'SELL2':
		case 'REF':
		case 'CALCIN':
		case 'CALCOUT':
			$res = true;
			break;
		case 'CASHOUT':
		case 'EX':
		case 'TR':
		case 'BUY':
		case 'GIVE':
			$res = opChangeBalance($uid, $cid, $sum, 0, -$sum, false, $oid, '');
			if (!is_string($res) and ($o['oOper'] == 'CASHOUT') and !empty($_cfg['Const_IntCurr']) and ($cid > 1))
				opOperCreate($uid, 'EX', $cid, $sum, array('cid2' => 1), 'Auto', true, true);
			break;
		case 'TAKE':
			$res = opChangeBalance($uid, $cid, 0, $sum, -$sum, false, $oid, '');
			break;
		}
		if (is_string($res))
			return $res;
	}
	$db->update('Opers', $a, '', 'oID=?d', array($oid));
	return true;
}

// Change balance

function opChangeBalance($uid, $cid, $z1, $z2, $z3, $chk_before = true, $oid = 0, $memo = '')
{
	if (($uid <= 0) or ($cid <= 0))
		return 'bal_wrong';
	$z1 = _zr($z1, $cid);
	$z2 = _zr($z2, $cid);
	$z3 = _zr($z3, $cid);
	if (($z1 == 0) and ($z2 == 0) and ($z3 == 0))
		return true;
	// z1, z2, and z3 change the available, locked, and outgoing buckets. uBal
	// mirrors their combined value for fast account-level reads.
	$z = $z1 + $z2 + $z3;
	global $db;
	$w = $db->fetch1Row(
		$db->select('Wallets', 'wBal, wLock, wOut', 'wcID=?d and wuID=?d', array($cid, $uid))
	);
	if (!$w) {
		$db->query('INSERT INTO Wallets (wcID, wuID) VALUES (?d, ?d) ON DUPLICATE KEY UPDATE wMTS=wMTS', array($cid, $uid));
		$w = array('wBal' => 0, 'wLock' => 0, 'wOut' => 0);
	}
	if (($w['wBal'] + $z1) < 0)
		return 'low_bal1';
	if (($w['wLock'] + $z2) < 0)
		return 'low_bal2';
	if (($w['wOut'] + $z3) < 0)
		return 'low_bal3';
	try
	{
		$db->beginJob();
		// The non-negative predicates provide an atomic overdraft guard even when
		// concurrent requests passed the earlier informational balance check.
		if (!$db->update('Wallets', 
			array(
				'wBal=' => "wBal+$z1", 
				'wLock=' => "wLock+$z2", 
				'wOut=' => "wOut+$z3"
			), 
			'', "wcID=?d and wuID=?d and (wBal+$z1>=0) and (wLock+$z2>=0) and (wOut+$z3>=0)", array($cid, $uid)
		))
			xAbort();
		if ($z != 0)
			if (!$db->update('Users', array('uBal=' => "uBal+$z"), '', "uID=?d and (uBal+$z>=0)", array($uid)))
				xAbort();
	}
	catch (FormAbortException $e)
	{
		$db->cancelJob();
		return 'low_bal';
	}
	catch (Throwable $e)
	{
		$db->cancelJob();
		throw $e;
	}
	$db->endJob();
	opAddHist('BAL', $uid, '', $memo, 0, $cid, $z, $oid);
	return true;
}

// Currs support

function opDecodeCurrParams($crec, &$p, &$p_sci, &$p_api)
{
	$key = (isset($crec['cID']) ? $crec['cID'] : '') . (isset($crec['cCID']) ? $crec['cCID'] : '') . stampToTime(isset($crec['cMTS']) ? $crec['cMTS'] : '');
	$p = decodeArrayFromStr(isset($crec['cParams']) ? $crec['cParams'] : '', $key);
	$p_sci = decodeArrayFromStr(isset($crec['cParamsSCI']) ? $crec['cParamsSCI'] : '', $key, 2);
	$p_api = decodeArrayFromStr(isset($crec['cParamsAPI']) ? $crec['cParamsAPI'] : '', $key, 3);
}

function opDecodeUserCurrParams($wrec)
{
	$key = (isset($wrec['wcID']) ? $wrec['wcID'] : '') . (isset($wrec['wuID']) ? $wrec['wuID'] : '') . stampToTime(isset($wrec['wMTS']) ? $wrec['wMTS'] : '');
	return decodeArrayFromStr(isset($wrec['wParams']) ? $wrec['wParams'] : '', $key);
}

function opEditToCurrParams($fields, $old, $new, $fn = '')
{
	if (!is_array($old))
		$old = array();
	foreach ($fields as $f => $v)
		if (!is_int($f))
		{
			$is_pass = ((substr($f, -3) == 'key') or (substr($f, -4) == 'pass'));
			if (!empty($new[$f]) or !$is_pass)
			{
				if (!empty($new[$f]) and !empty($v[1]) and !preg_match('/^' . $v[1] . '$/', $new[$f]))
					return $fn . $f . '_wrong';
				if (!$is_pass)
					$new[$f] = strip_tags(isset($new[$f]) ? $new[$f] : '');
				elseif (isset($new[$f]) && $new[$f] == '-')
					$new[$f] = '';
				$old[$f] = isset($new[$f]) ? $new[$f] : '';
			}
		}
	foreach ($old as $f => $v)
		if (StringHelper::sEmpty($v))
			unset($old[$f]);
	return $old;
}

function opCurrParamsToEdit($fields, $fn = '', $readonly = false)
{
	$res = array();
	foreach ($fields as $f => $v)
	{
		if (is_int($f))
			$res[$f] = $v;
		else
		{
			$fi = ($fn ? $fn . '[' . $f . ']' : $f);
			if ($f == '_url')
			{
				$url = fullURL(moduleToLink('balance/status'));
				$res[$fi] = 
					array('X', isset($v[0]) ? $v[0] : '', 0, "<a href=\"$url\" target=\"_blank\">$url</a>");
//				$res[$fn . '[hideurl]'] = 
//					array('C', 'Hide URL');
			}
			else
			{
				$is_pass = ((substr($f, -3) == 'key') or (substr($f, -4) == 'pass'));
				$res[$fi] =
					array(
						StringHelper::valueIf($is_pass, '*', StringHelper::valueIf((isset($v[3]) ? $v[3] : ""), 'S', 'T')),
						isset($v[0]) ? $v[0] : '', 
						array($fn . $f . '_wrong' => '{!en!}wrong format{!ru!}неверный формат'),
						(isset($v[3]) ? $v[3] : ""),
						'comment' => (isset($v[2]) ? $v[2] : ""),
						'readonly' => $readonly
					);
			}
		}
	}
	return $res;
}

function prepareStat()
{
	global $db;
	View::setPage('stats', $db->fetchIDRows($db->select('Currs',
		"*, 
		(SELECT SUM(oSum) FROM Opers WHERE ouID=?d AND ocID=cID AND oOper='REF' AND oState=3) AS ZREF,
		(SELECT SUM(oSum) FROM Opers WHERE ouID=?d AND ocID=cID AND oOper='CALCIN' AND oState=3) AS ZCALCIN,
		(SELECT SUM(oSum) FROM Opers WHERE ouID=?d AND ocID=cID AND ((cID=1 AND oOper='EXIN') OR (cID>1 AND oOper='CASHIN')) AND oState=3) AS ZIN,
		(SELECT SUM(oSum) FROM Opers WHERE ouID=?d AND ocID=cID AND ((cID=1 AND oOper='EX') OR (cID>1 AND oOper='CASHOUT')) AND oState=3) AS ZOUT,
		(SELECT SUM(dZD) FROM Deps WHERE duID=?d AND dcID=cID AND dState=1) AS ZINDEPO,
		(SELECT SUM(oSum) FROM Opers WHERE ouID=?d AND ocID=cID AND oOper='BONUS' AND oState=3) AS ZBONUS
		", 
		'cDisabled=0', array(_uid(), _uid(), _uid(), _uid(), _uid(), _uid()), 'cID'), false, 'cID'));
}

?>
