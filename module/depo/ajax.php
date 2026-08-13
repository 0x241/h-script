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
	$sum = (string)_RQ('Sum');
	cn($sum);
	$sum = max(0, (float)$sum);
	$period_profit = $plan ? $sum * (float)$plan['pPerc'] / 100 : 0;
	$period_count = $plan && !empty($plan['pNPer']) ? (int)$plan['pNPer'] : 1;
	$total_profit = $period_profit * $period_count;

	View::setPage('summary_sum', $sum);
	View::setPage('period_profit', $period_profit);
	View::setPage('total_profit', $total_profit);
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
