<?php

use HScript\Template\View;

$_auth = 1;
require_once('module/auth.php');
useLib('depo');

$action = _RQ('do');

if ($action == 'calc')
{
	$plans = opDepoGetPlanList(_uid());
	$plan_id = (int)_RQ('Plan');
	$plan = isset($plans[$plan_id]) && empty($plans[$plan_id]['Disabled']) ? $plans[$plan_id] : array();
	foreach (HScript\Finance\DepositSummary::project(_RQ('Sum'),$plan) as $name=>$value)
		View::setPage($name,$value,0);
	View::showPage('profit.summary', 'depo');
	exit;
}

if ($action != 'progress')
	exit;

$id = _GETN('id');
$el = $db->fetch1Row($db->select(
	'Deps LEFT JOIN Currs ON cID=dcID LEFT JOIN Plans ON pID=dpID',
	'*',
	'dID=?d and duID=?d',
	array($id, _uid())
));

if (!$el)
	exit;

$detail = array($el);
opDepoListPrepare($detail);
$el = $detail[0];
View::stampArrayToStr($el, 'dCTS, dLTS, dNTS', 0);
View::setPage('el', $el, 3);
View::showPage('progress', 'depo');

?>
