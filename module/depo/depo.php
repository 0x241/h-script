<?php

use HScript\Template\View;
use HScript\Finance\FinancialForm;
use HScript\Finance\RequestDecimal;

$_auth = 1;
require_once('module/auth.php');

$table = 'Deps';
$id_field = 'dID';
$uid_field = 'duID';
$out_link = moduleToLink('depo');

try 
{

	if (View::sendedForm('', 'new'))
	{
		FinancialForm::check('depo:new', 'new');

		cn($_IN['Sum']);		
		View::setError($id = FinancialForm::execute('depo:new', ['currency_id' => _INN('PSys'), 'amount' => RequestDecimal::normalize(_IN('Sum')), 'compound' => RequestDecimal::normalize(_IN('Compnd') ?: 0, true), 'plan_id' => _INN('Plan')], static fn() => opDepoCreate(_uid(), !empty($GLOBALS['_cfg']['Const_IntCurr']) ? 1 : _INN('PSys'), _IN('Sum'), _IN('Compnd'), _INN('Plan'), false)), 'new');
		View::showInfo('Completed', moduleToLink() . "?id=$id");
	}

	if (View::sendedForm('chg'))
	{
		FinancialForm::check('depo:chg');
		
		$id = _INN($id_field);
		View::setError(FinancialForm::execute('depo:chg', ['deposit_id' => $id, 'compound' => RequestDecimal::normalize(_IN('Compnd') ?: 0, true)], static fn() => opDepoChangeCompnd(_uid(), $id, _IN('Compnd'))));
		View::showInfo('Completed', moduleToLink() . "?id=$id");
	}
	
	if (View::sendedForm('add'))
	{
		FinancialForm::check('depo:add');
		
		cn($_IN['Sum']);		
		$id = _INN($id_field);
		View::setError(FinancialForm::execute('depo:add', ['deposit_id' => $id, 'currency_id' => _INN('dcID'), 'amount' => RequestDecimal::normalize(_IN('Sum'))], static fn() => opDepoAdd(_uid(), _INN('dcID'), _IN('Sum'), $id)));
		View::showInfo('Completed', moduleToLink() . "?id=$id");
	}
	if (View::sendedForm('sub'))
	{
		FinancialForm::check('depo:sub');
		
		cn($_IN['Sum']);		
		$id = _INN($id_field);
		View::setError(FinancialForm::execute('depo:sub', ['deposit_id' => $id, 'currency_id' => _INN('dcID'), 'amount' => RequestDecimal::normalize(_IN('Sum'))], static fn() => opDepoSub(_uid(), _INN('dcID'), _IN('Sum'), $id)));
		View::showInfo('Completed', moduleToLink() . "?id=$id");
	}

}
catch (InvalidArgumentException $e)
{
	http_response_code(422);
	xSysStop('Invalid financial amount', true);
} 
catch (FormAbortException $e)
{
}

if (!isset($_GET['add']))
{
	if (_GETN('id'))
		$el = $db->fetch1Row($db->select("$table LEFT JOIN Currs ON cID=dcID LEFT JOIN Plans ON pID=dpID", 
			'*', "$id_field=?d and $uid_field=?d", array(_GETN('id'), _uid())));
	if (!$el)
		goToURL(moduleToLink() . '?add');
	//--calculte next time accrual, if weekend
	$nc = $el['pNoCalc'] ;
	$t = stampToTime($el['dNTS']);
	if (!$nc and $el['pWDays'] and ($el['pPer'] <= 24) and ($el['dState']==1))
	{
		useLib('calendar');
		while (getDayType($t) > 1)	{$t += $el['pPer'] * HS2_UNIX_HOUR;}
		$el['dNTS']=timeToStamp($t);
	}//--
	$detail = array($el);
	opDepoListPrepare($detail);
	$el = $detail[0];
	View::stampArrayToStr($el, 'dCTS, dLTS, dNTS', 0);
	View::setPage('el', $el, 3);
	View::setPage('currs', $_currs);
}
else
{
	if (!$_cfg['Const_IntCurr'])
	{
		View::setPage('currs', $_currs);
		$list = array();
		foreach ($_currs as $id => $r)
			if ($r['wBal'] > 0)
				$list[$id] = $r['cName'];
		if (!$list)
			View::showInfo('*CantComplete', $out_link);
		View::setPage('clist', $list);
	}
	$plans = opDepoGetPlanList(_uid());
	$pl = array();
	$plan_rows = array();
	$default_plan_id = 0;
	$popular_plan_id = 0;
	$cmax = 0;
	foreach ($plans as $pid => $p)
		if (empty($p['Disabled']))
		{
			if (!$default_plan_id)
				$default_plan_id = $pid;
			$pl[$pid] = $p['pName'];
			$plan_rows[$pid] = $p;
			if ($p['pCompndMax'] > $cmax)
				$cmax = $p['pCompndMax'];
		}
	if (!$pl)
		View::showInfo('*CantComplete', $out_link);
	if ($plan_rows)
	{
		$popular_plan = $db->fetch1Row($db->select(
			'Deps',
			'dpID, COUNT(*) AS ActiveCount',
			'dState=1 and dpID ?i',
			array(array_keys($plan_rows)),
			'ActiveCount DESC, dpID ASC',
			1,
			'dpID'
		));
		if (!empty($popular_plan['ActiveCount']))
			$popular_plan_id = (int)$popular_plan['dpID'];
	}
	View::setPage('plans', $pl);
	View::setPage('plan_rows', $plan_rows);
	View::setPage('default_plan_id', $default_plan_id);
	View::setPage('popular_plan_id', $popular_plan_id);
	View::setPage('pcmax', $cmax);
}

View::showPage();

?>
