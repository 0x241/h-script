<?php

use HScript\Template\View;

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
		View::checkFormSecurity('new');

		cn($_IN['Sum']);		
		if ($_cfg['Const_IntCurr'])
			$_IN['PSys'] = 1;
		View::setError($id = opDepoCreate(_uid(), _INN('PSys'), _IN('Sum'), _IN('Compnd'), _INN('Plan'), true), 'new');
		View::showInfo('Completed', moduleToLink() . "?id=$id");
	}

	if (View::sendedForm('chg'))
	{
		View::checkFormSecurity();
		
		View::setError(opDepoChangeCompnd(_uid(), $id = _INN($id_field), _IN('Compnd')));
		View::showInfo('Completed', moduleToLink() . "?id=$id");
	}
	
	if (View::sendedForm('add'))
	{
		View::checkFormSecurity();
		
		cn($_IN['Sum']);		
		View::setError(opDepoAdd(_uid(), _INN('dcID'), _IN('Sum'), $id = _INN($id_field)));
		View::showInfo('Completed', moduleToLink() . "?id=$id");
	}
	if (View::sendedForm('sub'))
	{
		View::checkFormSecurity();
		
		cn($_IN['Sum']);		
		View::setError(opDepoSub(_uid(), _INN('dcID'), _IN('Sum'), $id = _INN($id_field)));
		View::showInfo('Completed', moduleToLink() . "?id=$id");
	}

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
