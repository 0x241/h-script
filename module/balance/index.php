<?php

use HScript\Template\View;

$_auth = 1;
require_once('module/auth.php');

$table = 'Opers';
$id_field = 'oID';
$uid_field = 'ouID';

View::setPage('archived_holdings',HScript\Finance\ArchivedHoldings::forUser($db,(int)_uid()));
View::setPage('currs', $_currs);

$operationTypes = array('all' => array(), 'in' => array('CASHIN'),
	'out' => array('CASHOUT'), 'calc' => array('CALCIN', 'REF', 'BONUS'));
$operationFilter = $_GET['type'] ?? 'all';
if (!is_string($operationFilter) || !isset($operationTypes[$operationFilter])) $operationFilter = 'all';
$filter = "$uid_field=?d";
$values = array(_uid());
if ($operationTypes[$operationFilter]) {
	$filter .= ' AND oOper IN (' . implode(',', array_fill(0, count($operationTypes[$operationFilter]), '?')) . ')';
	$values = array_merge($values, $operationTypes[$operationFilter]);
}
View::setPage('operation_filter', $operationFilter);

$list = opPageGet(max(1, _GETN('page')), 10, "$table LEFT JOIN Users on uID=ouID LEFT JOIN Currs on cID=ocID",
	"$table.*, uLogin, cName, cCurr, (oNTS>0) AS _Marked",
	$filter, $values,
	array(
		'oTS' => array('oID desc', 'oID'),
		'oState' => array()
	), 
	_GET('sort'), $id_field
);
View::stampTableToStr($list, 'oCTS, oTS, oNTS');
$list = HScript\Payment\Provider\CurrencyPresentation::annotate($db, $list);
foreach ($list as $id => $r)
	$list[$id]['oParams'] = strToArray($r['oParams']);
View::setPage('list', $list);

View::showPage();

?>
