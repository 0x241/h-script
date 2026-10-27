<?php

use HScript\Finance\WalletPortfolio;

use HScript\Template\View;

$_auth = 1;
require_once('module/auth.php');

View::setPage('archived_holdings',HScript\Finance\ArchivedHoldings::forUser($db,(int)_uid()));
$portfolio = WalletPortfolio::project($_currs);
$dashboard_currs = $portfolio['currencies'];
$currency_balances = $portfolio['groups'];
View::setPage('currs', $dashboard_currs);
View::setPage('currency_balances', $currency_balances);
View::setPage('primary_currency_balance', $portfolio['primary']);

useLib('balance');
useLib('depo');
prepareStat();

$active_deposits = $db->fetchRows($db->select(
	'Deps LEFT JOIN Plans ON pID=dpID LEFT JOIN Currs ON cID=dcID',
	'*',
	'duID=?d and dState=1',
	array(_uid()),
	'dCTS desc',
	4
));
opDepoListPrepare($active_deposits);
foreach ($active_deposits as $id => $dep) {
	$start = stampToTime($dep['dCTS']);
	$end = stampToTime($dep['dETS']);
	if ($start && $end && ($end > $start)) {
		$progress = round(((time() - $start) / ($end - $start)) * 100, 1);
		$active_deposits[$id]['progress_pct'] = max(0, min(100, $progress));
	} else {
		$active_deposits[$id]['progress_pct'] = 0;
	}
}
View::stampTableToStr($active_deposits, 'dCTS, dETS, dLTS, dNTS');
View::setPage('active_deposits', $active_deposits);

$recent_opers = $db->fetchRows($db->select(
	'Opers LEFT JOIN Currs ON cID=ocID',
	'*',
	'ouID=?d',
	array(_uid()),
	'oCTS desc',
	6
));
View::stampTableToStr($recent_opers, 'oCTS, oTS, oNTS');
foreach ($recent_opers as $id => $oper)
	$recent_opers[$id]['oParams'] = strToArray($oper['oParams']);
View::setPage('recent_opers', $recent_opers);

$earned_by_currency = $db->fetchRows($db->select(
	'Opers LEFT JOIN Currs ON cID=ocID',
	'Currs.cCurr, Currs.cID, COALESCE(SUM(oSum),0) as zSum',
	"ouID=?d and oState=3 and oOper in ('CALCIN','REF','BONUS')",
	array(_uid()),
	'Currs.cCurr',
	0,
	'Currs.cCurr, Currs.cID'
));
View::setPage('earned_by_currency', $earned_by_currency);

$dashboard_metrics = array(
	'currency_count' => count($currency_balances),
	'active_deposit_count' => (int)$db->fetch1($db->select('Deps', 'COUNT(*)', 'duID=?d and dState=1', array(_uid()))),
	'pending_ops' => (int)$db->fetch1($db->select('Opers', 'COUNT(*)', 'ouID=?d and oState in (0,1,2)', array(_uid()))),
	'recent_ops' => count($recent_opers),
	'payment_systems' => count($dashboard_currs)
);
View::setPage('dashboard_metrics', $dashboard_metrics);

View::showPage();

?>
