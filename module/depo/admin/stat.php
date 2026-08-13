<?php

use HScript\Template\View;

$_auth = 90;
require_once('module/auth.php');

// Historical statistics must include disabled payment systems as well.
$stat_currs = $db->fetchIDRows(
	$db->select('Currs', '*', '', null, 'cID'),
	false,
	'cID'
);

$vcurrs = array(
	'USD' => array(),
	'EUR' => array(),
	'RUB' => array(),
	'BTC' => array(),
	'ETH' => array(),
	'XRP' => array()
);
foreach ($stat_currs as $cid => $c)
	$vcurrs[$c['cCurrID']][] = $cid;
$op_names = array('BONUS', 'PENALTY', 'CASHIN', 'REF', 'GIVE', 'TAKE', 'CALCIN', 'CALCOUT', 'CASHOUT');
$extra_names = array('GIVE2', 'CASHOUT2', 'DEPO', 'DEPO2', 'BAL', 'LOCK', 'OUT');
$filter_sql = array(
	'ud1' => '',
	'ud2' => '',
	'od1' => '',
	'od2' => '',
	'dd1' => '',
	'dd2' => ''
);

function depoStatNum($value)
{
	return is_numeric($value) ? 0 + $value : 0;
}

function depoStatNormalize(&$data, $vcurrs, $names)
{
	foreach (array_keys($vcurrs) as $curr)
		foreach ($names as $name)
			if (!isset($data[$name][$curr]) || !is_numeric($data[$name][$curr]))
				$data[$name][$curr] = 0;
}

function depoStatDateInputToStamp($value, $end_of_day = false)
{
	$value = trim((string)$value);
	if ($value === '')
		return '';
	$date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
	$errors = DateTimeImmutable::getLastErrors();
	if (
		$date === false or
		($errors !== false and ($errors['warning_count'] > 0 or $errors['error_count'] > 0)) or
		$date->format('Y-m-d') !== $value
	)
		return '';
	$timestamp = $date->getTimestamp();
	if ($end_of_day)
		$timestamp += HS2_UNIX_DAY - 1;
	return timeToStamp($timestamp);
}

try 
{
	$is_submitted = View::sendedForm();
	if ($is_submitted)
		View::checkFormSecurity();

	$a = $_IN;
	if (!$is_submitted)
		$a['D1'] = gmdate('Y-m-d');
	$a['D1'] = isset($a['D1']) ? $a['D1'] : '';
	$a['D2'] = isset($a['D2']) ? $a['D2'] : '';
	$a['D1'] = depoStatDateInputToStamp($a['D1']);
	if ($d1 = $a['D1'])
	{
		$filter_sql['ud1'] = " and aCTS>='$d1'";
		$filter_sql['od1'] = " and oCTS>='$d1'";
		$filter_sql['dd1'] = " and dCTS>='$d1'";
	}
	$a['D2'] = depoStatDateInputToStamp($a['D2'], true);
	if ($d2 = $a['D2'])
	{
		$filter_sql['ud2'] = " and aCTS<='$d2'";
		$filter_sql['od2'] = " and oCTS<='$d2'";
		$filter_sql['dd2'] = " and dCTS<='$d2'";
	}
	$res = array();
	if (($psys = _INN('PSys')) && isset($stat_currs[$psys]))
	{
		$focurr = " and ocID=$psys";
		$fdcurr = " and dcID=$psys";
		$curr = $stat_currs[$psys]['cCurrID'];
		foreach ($op_names as $o)
			$res[$o][$curr] = depoStatNum($db->fetch1($db->select('Opers', 'SUM(oSum)', "oOper='$o' and oState=3$focurr" . $filter_sql['od1'] . $filter_sql['od2'])));
//		$res['CASHOUT2'][$curr] = $db->fetch1($db->select('Opers', 'SUM(oSum)', "oOper='CASHOUT' and oState=2$focurr$od1$od2"));
		$res['DEPO'][$curr] = depoStatNum($db->fetch1($db->select('Deps', 'SUM(dZD)', "1$fdcurr" . $filter_sql['dd1'] . $filter_sql['dd2'])));
		$res['DEPO2'][$curr] = depoStatNum($db->fetch1($db->select('Deps', 'SUM(dZD)', "dState=1$fdcurr" . $filter_sql['dd1'] . $filter_sql['dd2'])));
	}
	else
	{
		foreach ($vcurrs as $curr => $cids)
		{
			foreach ($op_names as $o)
				$res[$o][$curr] = depoStatNum($db->fetch1($db->select('Opers', 'SUM(oSum)', "oOper='$o' and oState=3" . $filter_sql['od1'] . $filter_sql['od2'] . " and ocID ?i", array($cids))));
//			$res['CASHOUT2'][$curr] = $db->fetch1($db->select('Opers', 'SUM(oSum)', "oOper='CASHOUT' and oState=2$od1$od2 and ocID ?i", array($cids)));
			$res['DEPO'][$curr] = depoStatNum($db->fetch1($db->select('Deps', 'SUM(dZD)', "1" . $filter_sql['dd1'] . $filter_sql['dd2'] . " and dcID ?i", array($cids))));
			$res['DEPO2'][$curr] = depoStatNum($db->fetch1($db->select('Deps', 'SUM(dZD)', "dState=1" . $filter_sql['dd1'] . $filter_sql['dd2'] . " and dcID ?i", array($cids))));
		}
	}
	depoStatNormalize($res, $vcurrs, array_merge($op_names, array('DEPO', 'DEPO2')));
	$res['REG'] = 0 + $db->count('AddInfo', "1" . $filter_sql['ud1'] . $filter_sql['ud2']);
	View::setPage('res', $res);

}
catch (Throwable $e)
{
	error_log('Deposit statistics failed: ' . $e->getMessage());
	View::setPage('stat_error', true, 0);
}

View::setPage('users', array(
	'all' => $db->count('Users'),
	'active' => $db->count('Users', 'uState=1'),
	'wdepo' => $db->count('Users', 'uState=1 and EXISTS(SELECT dID FROM Deps WHERE duID=uID)')
));
View::setPage('deps', array(
	'all' => $db->count('Deps'),
	'active' => $db->count('Deps', 'dState=1')
));
View::setPage('currs', $stat_currs);
View::setPage('vcurrs', $vcurrs);
$stat = array();
foreach (array_keys($vcurrs) as $curr)
	foreach (array_merge($op_names, $extra_names) as $o)
		$stat[0][$curr][$o] = 0;
foreach ($stat_currs as $cid => $c)
{
	$curr = $c['cCurrID'];
	foreach ($op_names as $o)
	{
		$stat[$cid][$o] = depoStatNum($db->fetch1($db->select('Opers', 'SUM(oSum)', 'oOper=? and ocID=?d and oState=3', array($o, $cid))));
		$stat[0][$curr][$o] += $stat[$cid][$o];
	}
	$stat[$cid]['GIVE2'] = depoStatNum($db->fetch1($db->select('Opers', 'SUM(oSum)', 'oOper=? and ocID=?d and oState=3 and (oMemo ?%)', array('GIVE', $cid, 'Auto'))));
	$stat[$cid]['CASHOUT2'] = depoStatNum($db->fetch1($db->select('Opers', 'SUM(oSum)', 'oOper=? and ocID=?d and oState=2', array('CASHOUT', $cid))));
	$stat[$cid]['DEPO'] = depoStatNum($db->fetch1($db->select('Deps', 'SUM(dZD)', 'dcID=?d and dState=1', array($cid))));
	$o = $db->fetch1Row($db->select('Wallets', 'SUM(wBal) AS Z1, SUM(wLock) AS Z2, SUM(wOut) AS Z3', 'wcID=?d', array($cid)));
	$stat[$cid]['BAL'] = depoStatNum($o['Z1']);
	$stat[$cid]['LOCK'] = depoStatNum($o['Z2']);
	$stat[$cid]['OUT'] = depoStatNum($o['Z3']);
	foreach (array('GIVE2', 'CASHOUT2', 'DEPO', 'BAL', 'LOCK', 'OUT') as $o)
		$stat[0][$curr][$o] += $stat[$cid][$o];
}
View::setPage('stat', $stat);
$list = array();
foreach ($stat_currs as $id => $r)
	$list[$id] = $r['cName'];
View::setPage('clist', $list);
View::setPage('today', View::timeToStr(time(), 1));
View::setPage('today_input', gmdate('Y-m-d'));
View::showPage();

?>
