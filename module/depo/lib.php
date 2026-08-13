<?php

use HScript\Util\StringHelper;

use HScript\Cache\CatalogCache;
use HScript\Template\View;

function opDepoGetPlanList($uid, $by_admin = false, $only_ids = false, $cid = 0)
{
	global $db, $_cfg;
	$plans = $db->fetchIDRows($db->select('Plans P',
		'P.*,
		(SELECT COUNT(dID) FROM Deps LEFT JOIN Plans P2 on P2.pID=dpID WHERE P2.pGroup=P.pGroupReq and duID=?d and dState>=2 LIMIT 1) AS DCL,
		(SELECT COUNT(dID) FROM Deps WHERE duID=?d and dState=1 and dpID=P.pID' . StringHelper::valueIf($cid, ' and dcID=?d') . ') AS DCNT',
		StringHelper::valueIf(!$by_admin, 'pHidden=0'), array($uid, $uid, $cid), 'pGroup, pID'), false, 'pID');
	$bg = array();
	foreach ($plans as $p) {
		if (!isset($bg[$p['pGroup']])) $bg[$p['pGroup']] = 0;
		$bg[$p['pGroup']] += $p['DCNT'];
	}
	$pids = array();
	foreach ($plans as $pid => $p)
		if (
			(($p['pMaxCount'] > 0) and ($p['DCNT'] >= $p['pMaxCount'])) or
			(($p['pGroupReq'] > 0) and !$p['DCL']) or
			(!empty($_cfg['Depo_OneFromGroup']) and !empty($bg[$p['pGroup']]))
		)
			$plans[$pid]['Disabled'] = true;
		else
			$pids[] = $pid;
	if ($only_ids)
		return $pids;
	return $plans;
}

function opDepoUSD($sum, $curr)
{
	global $_cfg, $_currs;
	$c1 = isset($_currs[1]) ? $_currs[1] : (is_array($_currs) ? reset($_currs) : null);
	$c1_id = ($c1 && isset($c1['cCurrID'])) ? $c1['cCurrID'] : 'USD';
	if ($curr == $c1_id)
		return $sum;
	useLib('balance');
	$rate = isset($_cfg["Bal_Rate$curr"]) ? (float)$_cfg["Bal_Rate$curr"] : 1.0;
	return _zr($sum * $rate, 1);
}

function opDepoCreate($uid, $cid, $sum, $compnd = 0, $pid = 0, $by_admin = false, $auto = false)
{
	global $db, $_cfg;
	$usr = opReadUser($uid);
	if (!$usr)
		return 'user_not_found';
	if (!$cid)
		return 'psys_empty';
	$curr = $db->fetch1Row($db->select('Currs', '*', 'cID=?d', array($cid)));
	if (!$curr)
		return 'psys_wrong';
	if (!is_numeric($sum))
		return 'sum_wrong';
	if (!is_numeric($compnd))
		$compnd = 0;
	useLib('balance');
	$sum += 0;
	$sum = _zr($sum, $cid);
	$usum = opDepoUSD($sum, $curr['cCurrID']);
	if ($usum < 0.01)
		return 'sum_wrong';
	$compnd = round($compnd, 2);
	// Plan limits use the base-currency value so every accepted payment currency
	// is compared against the same pMin/pMax thresholds.
	$pl = opDepoGetPlanList($uid, $by_admin, true, $cid); // enabled plans
	$pl = $db->fetch1Row($db->select('Plans', '*', 
		'(pID ?i) and pMin<=? and pMax>?' . StringHelper::valueIf($pid, ' and pID=?d'),
		array($pl, $usum, $usum, $pid), 'pMax', 1));
	if (empty($pl) || !isset($pl['pID']))
		return 'plan_wrong';
	if (($pid == 0) and ($compnd == 0))
		$compnd = $pl['pCompndMin'];
	if ((($pl['pCompndMin'] > 0) and ($pl['pCompndMin'] > $compnd)) or
		($pl['pCompndMax'] < $compnd) or ($compnd < 0))
	{
		View::setPage('cmin', $pl['pCompndMin']);
		View::setPage('cmax', $pl['pCompndMax']);
		View::setPage('cplan', $pl['pName']);
		return 'compnd_wrong';
	}
	if ($auto === 2)
		return 'passed';
	$bsum = _zr(calcPerc($sum, $pl['pBonus'], 6), $cid);
	if (is_string($oid = opOperCreate($uid, 'GIVE', $cid, $sum, array('bonus' => $bsum, 'auto' => $auto), StringHelper::valueIf($auto, 'Auto '), false, true)))
		return $oid;
	if (is_string($err = opOperConfirm($uid, $oid, array(), true)))
		return $err;
	// The first accrual boundary depends on manual, interval, or immediate mode;
	// all subsequent boundaries advance by the selected plan period.
	if ($_cfg['Depo_ChargeMode'] == 2)
		$t = opDepoLastManualChargeDate();
	elseif ($_cfg['Depo_Interval'] > 0)
	{
		$t = stampToTime($_cfg['Depo_LastTime']) + HS2_UNIX_MINUTE * $_cfg['Depo_Interval'];
		if ($t < time())
			$t = time();
	}
	else
		$t = time();
	$ets = $t + $pl['pNPer'] * $pl['pPer'] * HS2_UNIX_HOUR;
	$nts = $t + $pl['pPer'] * HS2_UNIX_HOUR;
	$did = $db->insert('Deps', array(
		'duID' => $uid,
		'dState' => 1,
		'dpID' => $pl['pID'],
		'dCTS' => timeToStamp($t),
		'dETS' => timeToStamp($ets),
		'dcID' => $cid,
		'dZ0' => $sum,
		'dZD' => $sum + $bsum,
		'dCompnd' => $compnd,
		'dNTS' => timeToStamp($nts),
		'dLTS' => 0));
	opOperComplete($uid, $oid, array('tag' => $did), true);
	$db->replace('Cfg',
		array(
			'Module' => 'Depo',
			'Prop' => 'LastTime',
			'Val' => timeToStamp($t)
		),
		'', 'Module=? and Prop=?', array('Depo', 'LastTime')
	);
	$a = array(
		'did' => $did,
		'pid' => $pl['pID'],
		'cid' => $cid,
		'sum' => $sum,
		'percs' => asArray($pl['pDPerc'], HS2_NL)
	);
	opEvent('DepoCreate', $uid, $a);
	return $did;
}

function opDepoAdd($uid, $cid, $sum, $did = 0, $is_compnd = false)
{
	global $db;
	$d = $db->fetch1Row($db->select('Deps LEFT JOIN Plans on pID=dpID LEFT JOIN Currs ON cID=dcID', 
		'Deps.*, Plans.*, Currs.cCurrID', 
		'duID=?d and dcID=?d and dState=1' . StringHelper::valueIf($did, ' and dID=?d'), array($uid, $cid, $did), 'dID', 1));
	if (!$d)
		return 'depo_wrong';
	useLib('balance');
	$sum = _zr($sum, $cid);
	$usum = opDepoUSD($sum, $d['cCurrID']);
	if ($usum < 0.01)
		return 'sum_wrong';
	if (!$is_compnd)
	{
		if (!$d['pEnAdd'])
			return 'cant_add';
		if (($d['pMinAdd'] > 0) and ($usum < $d['pMinAdd']))
			return 'sum_wrong';
	}
	$dsum = $d['dZD'] + $sum;
	$csum = $d['dZC'] + StringHelper::valueIf($is_compnd, $sum, 0);
	$a = array(
		'dZD' => $dsum,
		'dZC' => $csum
	);
	$udsum = opDepoUSD($dsum, $d['cCurrID']);
	if (($d['pMin'] > $udsum) or ($d['pMax'] <= $udsum)) // change plan
	{
		$pl = opDepoGetPlanList($uid, false, true, $cid); // enabled plans
		$pl = $db->fetch1Row($db->select('Plans', '*', 
			'(pID ?i) and pMin<=? and pMax>? and pHidden=0 and pGroup=?d',
			array($pl, $udsum, $udsum, $d['pGroup']), 'pMax', 1));
		if (!$pl['pID'])
			return 'plan_not_found';
		$a['dpID'] = $pl['pID'];
		$z = ($udsum - $pl['pMin']); // max compnd
		if ($csum > $z)
			$a['dZC'] = $z;
	}
	if (is_string($oid = opOperCreate($uid, 'GIVE', $cid, $sum, array('tag' => $d['dID']), '', false, $is_compnd)))
		return $oid;
	if (is_string($err = opOperConfirm($uid, $oid, array(), true)))
		return $err;
	// Compare the previous principal in the update to prevent concurrent top-ups
	// from overwriting each other. Cancel the reserved balance operation on loss.
	if ($db->update('Deps', $a, '', 'dID=?d and dState=1 and dZD=?', array($d['dID'], $d['dZD'])))
		return opOperComplete($uid, $oid, array(), true);
	else
		opOperCancel($uid, $oid);
	return 'depo_wrong';
}

function opDepoSub($uid, $cid, $sum, $did = 0)
{
	global $db;
	$d = $db->fetch1Row($db->select('Deps LEFT JOIN Plans on pID=dpID LEFT JOIN Currs ON cID=dcID', 
		'Deps.*, Plans.*, Currs.cCurrID', 
		'duID=?d and dcID=?d and dState=1' . StringHelper::valueIf($did, ' and dID=?d'), array($uid, $cid, $did), 'dID', 1));
	if (!$d)
		return 'depo_wrong';
	useLib('balance');
	$sum = _zr($sum, $cid);
	$usum = opDepoUSD($sum, $d['cCurrID']);
	if ($usum < 0.01)
		return 'sum_wrong';
	if (($d['pClComis'] >= 100) or (($d['pMPer'] > 0) and ($d['dNPer'] < $d['pMPer'])))
		return 'cant_sub';
	$dsum = $d['dZD'] - $sum;
	if ($dsum < 0)
		return 'sum_wrong'; // more than depo
	if ($dsum == 0) // close
		$a = array(
			'dState' => 3,
			//'dZD' => 0,
			'dNTS' => timeToStamp(0)
		);
	else
	{
		$csum = $d['dZC'] - $sum;
		if ($csum < 0)
			$csum = 0;
		$a = array(
			'dZD' => $dsum,
			'dZC' => $csum
		);
		$udsum = opDepoUSD($dsum, $d['cCurrID']);
		if (($d['pMin'] > $udsum) or ($d['pMax'] <= $udsum))
		{
			$pl = opDepoGetPlanList($uid, false, true, $cid); // enabled plans
			$pl = $db->fetch1Row($db->select('Plans', '*', 
				'(pID ?i) and pMin<=? and pMax>? and pHidden=0 and pGroup=?d', 
				array($pl, $udsum, $udsum, $d['pGroup']), 'pMax', 1));
			if (!$pl['pID'])
				return 'plan_not_found';
			$a['dpID'] = $pl['pID'];
			$z = ($udsum - $pl['pMin']); // max compnd
			if ($csum > $z)
				$a['dZC'] = $z;
		}
	}
	if (is_string($oid = opOperCreate($uid, 'TAKE', $cid, $sum, 
		array('comis' => StringHelper::valueIf(($d['pClPer'] > 0) and ($d['dNPer'] >= $d['pClPer']), 0, $d['pClComis']), 'tag' => $d['dID']))))
		return $oid;
	if (is_string($err = opOperConfirm($uid, $oid, array(), true)))
		return $err;
	if ($db->update('Deps', $a, '', 'dID=?d and dState=1 and dZD=?', array($d['dID'], $d['dZD'])))
	{
		$err = opOperComplete($uid, $oid, array(), true);
		if (!is_string($err) and ($a['dState'] >= 2))
			opEvent('DepoClose', $uid, array('did' => $d['dID'], 'cid' => $cid, 'state' => $a['dState']));
		return $err;
	}
	else
		opOperCancel($uid, $oid);
	return 'depo_wrong';
}

function opDepoClose($uid, $cid, $did = 0)
{
	global $db;
	$d = $db->fetch1Row($db->select('Deps LEFT JOIN Plans on pID=dpID', '*', 
		'duID=?d and dcID=?d and dState=1' . StringHelper::valueIf($did, ' and dID=?d'), array($uid, $cid, $did), 'dID', 1));
	if (!$d)
		return 'depo_wrong';
	$dsum = $d['dZD'];
	$a = array(
		'dState' => 3,
		'dZD' => 0,
		'dNTS' => timeToStamp(0)
	);
	if (!$db->update('Deps', $a, '', 'dID=?d and dState=1 and dZD=?', array($d['dID'], $d['dZD'])))
		return 'depo_wrong';
	useLib('balance');
	return opOperCreate($uid, 'TAKE', $cid, $dsum, array('tag' => $d['dID']), '', true, true);
}

function opDepoChangePlan($uid, $pid, $did)
{
	global $db;
	$d = $db->fetch1Row($db->select('Deps LEFT JOIN Plans on pID=dpID', '*', 
		'dID=?d and duID=?d and dState=1', array($did, $uid)));
	if (!$d)
		return 'depo_wrong';
	return $db->update('Deps', array('dpID' => $pid), '', 'dID=?d and dState=1', array($did));
}

function opDepoChangeCompnd($uid, $did, $compnd)
{
	global $db;
	$d = $db->fetch1Row($db->select('Deps LEFT JOIN Plans on pID=dpID', '*', 
		'dID=?d and duID=?d and dState=1', array($did, $uid)));
	if (!$d)
		return 'depo_wrong';
	$compnd = round($compnd, 2);
	if ((($d['pCompndMin'] > 0) and ($d['pCompndMin'] > $compnd)) or
		($d['pCompndMax'] < $compnd) or ($compnd < 0))
	{
		View::setPage('cmin', $d['pCompndMin']);
		View::setPage('cmax', $d['pCompndMax']);
		View::setPage('cplan', $d['pName']);
		return 'compnd_wrong';
	}
	return $db->update('Deps', array('dCompnd' => $compnd), '', 'dID=?d and dState=1', array($did));
}
//=============Calculate percent from calendar========================================================================
function opDepoGetCalendarPerc()
{
    useLib('calendar');
    $perc = getDayPerc(time())+0;
    return $perc;
}
//=====================================================================================================================
function opDepoCharge($d, $perc = -1, $cdate = false) // !!! Deps JOIN Plans, Users
{
	if (!$d['dID'])
		return 'depo_wrong';
	if ($d['dState'] != 1)
		return 'depo_wrong_state';
	$perc = round($perc, 2);
	if ($perc < 0)
	{ // auto
		$perc = $d['pPerc'];
        //----------Change from calendar----------
        if ($d['pPClndr'])
        {
            if (($pClndr = opDepoGetCalendarPerc())>0)
             $perc = $pClndr;
        }
        //--------------------------------------
		$t = stampToTime($d['dNTS']);
		$nts = $t + $d['pPer'] * HS2_UNIX_HOUR;
		$nc = ($d['pNoCalc'] or ($d['uState'] != 1));
		if (!$nc and $d['pWDays'] and ($d['pPer'] <= 24))
		{
			useLib('calendar');
			$nc = (getDayType($t) > 1);
		}
	}
	else
	{ // manual
		if (!$cdate)
			return 'date_wrong';
		$nts = $cdate + $d['pPer'] * HS2_UNIX_HOUR;
		$nc = ($d['pNoCalc'] or ($d['uState'] != 1) or ($perc == 0));
	}
	global $db;
	$a = array(
		'dNTS' => timeToStamp($nts),
		'dLTS' => timeToStamp()
	);
	if ($nc) // NO Calc
	{
		$db->update('Deps', $a, '', 'dID=?d and dState=1 and dNTS=?', array($d['dID'], $d['dNTS']));
		return false;
	}
	// Calc
	useLib('balance');
	$psum = _zr(calcPerc($d['dZD'], $perc, 6), $d['dcID']); // percents (profit) + to ZP
	$a['dZP='] = "dZP+$psum";
	$a['dNPer='] = "dNPer+1";
	$d['dNPer']++;
	if (($d['pNPer'] > 0) and ($d['dNPer'] >= $d['pNPer'])) // end of depo
	{
		$dsum = $d['dZD'] - $d['dZC']; // real deposit sum
		if ($dsum < 0)
			$dsum = 0;
		$csum = $d['dZD'] - $dsum; // compnd sum
		// On the terminal period return eligible principal plus all compounded
		// profit; worked principal leaves the deposit bucket exactly once.
		$rsum = $csum + _zr(calcPerc($dsum, $d['pReturn'], 6), $d['dcID']);
		$dsum = $d['dZD'];
		$csum = 0;
		$a['dState'] = 2; // closed
		//$a['dZD'] = 0;
		$a['dNTS'] = timeToStamp(0);
	}
	else
	{
		$rsum = 0; // +return
		$dsum = 0; // -worked
		// During a non-terminal period only the configured fraction of profit is
		// reinvested; the remainder stays in the available balance.
		$csum = _zr(calcPerc($psum, $d['dCompnd'], 6), $d['dcID']); // compound deposit+
	}
	if (!$db->update('Deps', $a, '', 'dID=?d and dState=1 and dNTS=?', array($d['dID'], $d['dNTS'])))
		return 'depo_not_saved';
	// psum+rsum = +return / dsum = -worked // csum = +compaund
	opChangeBalance($d['duID'], $d['dcID'], $psum + $rsum, -$dsum, 0, false, 0, 'CALCIN');
	$a = array(
		'tag' => $d['dID'],
		'did' => $d['dID'],
		'pid' => $d['dpID'],
		'cid' => $d['dcID'],
		'sum' => $psum,
		'csum' => $csum,
		'rsum' => $rsum,
		'nper' => $d['dNPer']
	);
	if ($psum > 0)
		opOperCreateInfo($d['duID'], 'CALCIN', $d['dcID'], $psum, $a, '#' . $d['dID']);
	if ($rsum > 0) // depo closed
		opOperCreateInfo($d['duID'], 'TAKE', $d['dcID'], $rsum, $a);
	elseif ($csum > 0) // compaund
		opDepoAdd($d['duID'], $d['dcID'], $csum, $d['dID'], true);
	$a['percs'] = asArray($d['pPPerc'], HS2_NL);
	opEvent('DepoCharge', $d['duID'], $a);
	if ($a['dState'] >= 2)
		opEvent('DepoClose', $d['duID'], array('did' => $d['dID'], 'cid' => $d['dcID'], 'state' => $a['dState'], 'sum' => $d['dZD'] - $d['dZC']));
	return true;
}

function opDepoLastManualChargeDate()
{
	global $_cfg;
	$y = gmdate('Y');
	$m = gmdate('n');
	$d = gmdate('j');
	$cdate = gmmktime(0, 0, 0, $m, $d, $y);
	$md = $_cfg['Depo_ManualDay'];
	switch ($_cfg['Depo_ManualPeriod'])
	{
	case 1:
		if (($md < 1) or ($md > 7))
			$nd = 7;
		else
			$nd = $md;
		$dd = gmdate('N');
		if ($dd != $nd)
			$cdate = gmmktime(0, 0, 0, $m, $d - $dd - 7 + $nd, $y);
		break;
	case 2:
		$ld = gmdate('j', gmmktime(0, 0, 0, $m + 1, 0, $y));
		if (($md < 1) or ($md > $ld))
		{
			$md = 0;
			$nd = $ld;
		}
		else
			$nd = $md;
		if ($d != $nd)
			if ($md == 0)
				$cdate = gmmktime(0, 0, 0, $m, 0, $y);
			else
				$cdate = gmmktime(0, 0, 0, $m - 1, $md, $y);
		break;
	}
	return $cdate;
}

function depoCalcNumber($value, $default = 0.0)
{
	$value = str_replace(',', '.', (string)$value);
	return is_numeric($value) ? (float)$value : (float)$default;
}

function depoPrepareCalc()
{
	global $_GS, $db, $_cfg, $catalogCache;
	$compounds = array();
	if ($_cfg['Depo__Compnd'])
		foreach ($_cfg['Depo__Compnd'] as $c)
			$compounds[$c] = $c;
	View::setPage('calc_compnd', $compounds, 0);
	$plans = $catalogCache->remember(
		CatalogCache::PLANS,
		'visible:pMin,pMax',
		static fn(): array => $db->fetchIDRows(
			$db->select('Plans', '*', 'pHidden=0', array(), 'pMin, pMax'),
			false,
			'pID'
		)
	);
	$select_plans = array();
	$legacy_plans = array();
	$plan_rows = array();
	foreach ($plans as $id => $pl)
	{
		$name = StringHelper::textLangFilter($pl['pName'], $_GS['lang']);
		$select_plans[] = $name;
		$legacy_plans[] = "['" . addslashes($name) . "', {$pl['pMin']}, {$pl['pMax']}, {$pl['pPerc']}, {$pl['pNPer']}, {$pl['pBonus']}, {$pl['pReturn']}, {$pl['pDays']}]";
		$plan_rows[] = array(
			'id' => (int)$id,
			'name' => $name,
			'min' => (float)$pl['pMin'],
			'max' => (float)$pl['pMax'],
			'perc' => (float)$pl['pPerc'],
			'periods' => (int)$pl['pNPer'],
			'bonus' => (float)$pl['pBonus'],
			'return' => (float)$pl['pReturn'],
			'days' => (int)$pl['pDays']
		);
	}

	$selected_plan = isset_RQ('plan') ? (int)_RQ('plan') : 0;
	if (!isset($plan_rows[$selected_plan]))
		$selected_plan = 0;
	$default_sum = isset($plan_rows[$selected_plan]) && ($plan_rows[$selected_plan]['min'] > 0) ? $plan_rows[$selected_plan]['min'] : 10;
	$sum = isset_RQ('sum') ? depoCalcNumber(_RQ('sum'), $default_sum) : $default_sum;
	if ($sum <= 0)
		$sum = $default_sum;
	$cmpd = isset_RQ('cmpd') ? depoCalcNumber(_RQ('cmpd'), 0) : 0;
	if ($compounds && !in_array($cmpd, array_map('floatval', array_values($compounds))))
		$cmpd = (float)reset($compounds);

	$result = array();
	if ($plan_rows)
	{
		$plan = $plan_rows[$selected_plan];
		$base_sum = round($sum, 2);
		$bonus_sum = round($base_sum * $plan['bonus'] / 100, 2);
		$work_sum = round($base_sum + $bonus_sum, 2);
		$capitalized = 0.0;
		$profit = 0.0;
		for ($i = 0; $i < $plan['periods']; $i++)
		{
			$period_profit = round($work_sum * $plan['perc'] / 100, 2);
			$period_capitalized = round($period_profit * $cmpd / 100, 2);
			$capitalized = round($capitalized + $period_capitalized, 2);
			$profit = round($profit + $period_profit - $period_capitalized, 2);
			$work_sum = round($work_sum + $period_capitalized, 2);
		}
		$deposit_return = round($plan['return'] * ($work_sum - $capitalized) / 100, 2);
		$total_profit = round($profit + $capitalized, 2);
		$result = array(
			'plan' => $plan,
			'bonus_sum' => $bonus_sum,
			'profit_sum' => $total_profit,
			'deposit_return_sum' => $deposit_return,
			'total_return_sum' => round($deposit_return + $total_profit, 2)
		);
	}

	View::setPage('calc_pselect', $select_plans, 0);
	View::setPage('calc_plans', implode(', ', $legacy_plans), 0);
	View::setPage('calc_plan_rows', $plan_rows, 0);
	View::setPage('calc_selected_plan', $selected_plan, 0);
	View::setPage('calc_sum_value', $sum, 0);
	View::setPage('calc_cmpd_value', $cmpd, 0);
	View::setPage('calc_result', $result, 0);
}

function opDepoProgressWidthClass($value)
{
	$value = (int)round(max(0, min(100, (float)$value)));
	if ($value <= 0)
		return 'w-0';
	if ($value >= 100)
		return 'w-full';
	return 'w-[' . $value . '%]';
}

function opDepoListPrepare(&$list)
{
	useLib('balance');
	foreach ($list as &$d)
	{
		$total_periods = max(0, (int)$d['pNPer']);
		$completed_periods = max(0, (int)$d['dNPer']);
		$d['PeriodTotal'] = $total_periods;

		if ($d['dState'] != 1)
		{
			$d['PeriodNumber'] = $total_periods > 0 ? min($completed_periods, $total_periods) : $completed_periods;
			$d['PeriodProgress'] = 100;
			$d['CurrentProgress'] = 100;
			$d['PeriodProgressClass'] = 'w-full';
			continue;
		}

		$t = stampToTime($d['dNTS']);
		$period_seconds = max(1, (int)$d['pPer'] * HS2_UNIX_HOUR);
		$period_start = stampToTime($d['dLTS']);
		if (($period_start <= 0) or ($period_start >= $t))
			$period_start = stampToTime($d['dCTS']);
		if (($period_start <= 0) or ($period_start >= $t))
			$period_start = $t - $period_seconds;
		$period_duration = max(1, $t - $period_start);
		$period_elapsed = max(0, min(time() - $period_start, $period_duration));
		$current_period_progress = min(100, max(0, $period_elapsed * 100 / $period_duration));

		$d['PeriodNumber'] = $total_periods > 0
			? min($total_periods, $completed_periods + 1)
			: $completed_periods + 1;
		$d['PeriodProgress'] = $total_periods > 0
			? min(100, (($completed_periods + ($current_period_progress / 100)) * 100 / $total_periods))
			: $current_period_progress;
		$d['CurrentProgress'] = $d['PeriodProgress'];
		$d['PeriodProgressClass'] = opDepoProgressWidthClass($d['PeriodProgress']);

		$perc = $d['pPerc']/* + $d['pReturn']*/;
		//----------Change from calendar----------
		if ($d['pPClndr'])
		{
			if (($pClndr = opDepoGetCalendarPerc())>0)
			 $perc = $pClndr;
		}
		//--------------------------------------
		$zper = calcPerc($d['dZD'], $perc, 6);
		$d['NextCalcTime'] = $t;

		$dt = max(0, stampToTime($d['dNTS']) - time());
		$d['Timer']['D'] = floor($dt / 86400);
		$d['Timer']['H'] = floor($dt / 3600) % 24;
		$d['Timer']['M'] = floor($dt / 60) % 60;
		$d['Timer']['S'] = $dt % 60;

		$tcalc = min($period_elapsed, $period_seconds);
		if ($tcalc <= 1)
			$tcalc = 0;
		$d['CurrentProfit'] = round($zper * $tcalc / $period_seconds, 6);
		$d['ProfitPerHour'] = _zr($zper / $d['pPer'], $d['dcID']);
	}
}

function depoGetStat()
{
	global $db, $_cfg, $_vcurrs;
	$stat = array();
	$startedAt = stampToTime((string)($_cfg['Depo_S0'] ?? ''));
	if ($startedAt <= 0)
	{
		$installedAt = (int)($_cfg['Telemetry_InstalledAt'] ?? 0);
		$startedAt = $installedAt >= 946684800 && $installedAt <= time()
			? $installedAt
			: time();
	}
	$stat['worked'] = max(0, (int)floor((time() - $startedAt) / HS2_UNIX_DAY));
	$stat['users'] = (int)$db->count('Users', 'uState=1') + (int)(isset($_cfg['Depo_S1']) ? $_cfg['Depo_S1'] : 0);
	$stat['usersonline'] = (int)$db->count('Users', 'uState=1 and uLTS> ?', array(timeToStamp(time() - 5 * HS2_UNIX_MINUTE)));
	$stat['zin'] = (float)(isset($_cfg["Depo_S2"]) ? $_cfg["Depo_S2"] : 0);
	$stat['zin2'] = array();
	foreach ($_vcurrs as $curr => $cids)
		if ($cids){
			$stat['zin2'][$curr] = (float)$db->fetch1($db->select('Opers', 'SUM(oSum)', "oOper='CASHIN' AND oState=3 and ocID ?i", array($cids))) + (float)(isset($_cfg["Depo_S2$curr"]) ? $_cfg["Depo_S2$curr"] : 0);
			$stat['zin'] += $stat['zin2'][$curr] * (float)(isset($_cfg['Bal_Rate'.$curr]) ? $_cfg['Bal_Rate'.$curr] : 1);
		}
	$stat['zout2'] = array();
	$stat['zout'] = (float)(isset($_cfg["Depo_S3"]) ? $_cfg["Depo_S3"] : 0);
	foreach ($_vcurrs as $curr => $cids)
		if ($cids){
			$stat['zout2'][$curr] = (float)$db->fetch1($db->select('Opers', 'SUM(oSum-oComis)', "oOper='CASHOUT' AND oState=3 and ocID ?i", array($cids))) + (float)(isset($_cfg["Depo_S3$curr"]) ? $_cfg["Depo_S3$curr"] : 0);
			$stat['zout'] += $stat['zout2'][$curr] * (float)(isset($_cfg['Bal_Rate'.$curr]) ? $_cfg['Bal_Rate'.$curr] : 1);
		}
	$stat['zref'] = (float)$db->fetch1($db->select('Opers', 'SUM(oSum)', "oOper='REF' AND oState=3")) + (float)(isset($_cfg['Depo_S4']) ? $_cfg['Depo_S4'] : 0);
	$stat['zreinv'] = (float)$db->fetch1($db->select('Opers', 'SUM(oSum)', "oOper='GIVE' AND oState=3 AND NOT (oMemo ?%)", array('Auto'))) + (float)(isset($_cfg['Depo_S5']) ? $_cfg['Depo_S5'] : 0);
	$stat['deps'] = (int)$db->count('Deps', 'dState=1') + (int)(isset($_cfg['Depo_S6']) ? $_cfg['Depo_S6'] : 0);
	$stat['depsclosed'] = (int)$db->count('Deps', 'dState>=2') + (int)(isset($_cfg['Depo_S7']) ? $_cfg['Depo_S7'] : 0);
	$stat['lastuser'] = $db->fetch1Row($db->select('Users LEFT JOIN AddInfo ON auID=uID', 'uLogin, aCIP', 'uState=1', array(), 'uID desc', 1));
	if ($_cfg['Depo_S8'] > 0)
		$stat['lastinop'] = array('oSum' => $_cfg['Depo_S8'], 'uLogin' => $_cfg['Depo_S9']);
	else
		$stat['lastinop'] = $db->fetch1Row($db->select('Opers LEFT JOIN Users ON uID=ouID', 'oSum, uLogin', "oOper='CASHIN' AND oState=3", array(), 'oCTS desc', 1));
	if ($_cfg['Depo_S11'] > 0)
		$stat['lastoutop'] = array('oSum' => $_cfg['Depo_S11'], 'uLogin' => $_cfg['Depo_S12']);
	else
		$stat['lastoutop'] = $db->fetch1Row($db->select('Opers LEFT JOIN Users ON uID=ouID', 'oSum, uLogin', "oOper='CASHOUT' AND oState=3", array(), 'oCTS desc', 1));
	return $stat;
}

?>
