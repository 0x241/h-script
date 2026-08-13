<?php

use HScript\Http\ApiResponse;

require dirname(__DIR__) . '/bootstrap.php';
apiV1Require('operations:read', array('GET'));

$page = apiV1QueryInt('page', 1, 1, 1000000);
$perPage = apiV1QueryInt('per_page', 20, 1, 100);
$filter = 'ouID=?d';
$params = array($apiAuth['user_id']);

if (isset($_GET['type']) && $_GET['type'] !== '')
{
	$type = strtoupper(trim((string)$_GET['type']));
	$types = array(
		'BONUS', 'PENALTY', 'CASHIN', 'CASHOUT', 'EX', 'EXIN', 'TR', 'TRIN',
		'BUY', 'SELL', 'BUY2', 'SELL2', 'REF', 'GIVE', 'TAKE', 'CALCIN', 'CALCOUT',
	);
	if (!in_array($type, $types, true))
		ApiResponse::error('validation_error', 'Invalid query parameter', 422, array('field' => 'type'));
	$filter .= ' and oOper=?';
	$params[] = $type;
}
if (isset($_GET['state']) && $_GET['state'] !== '')
{
	$state = apiV1QueryInt('state', 0, 0, 5);
	$filter .= ' and oState=?d';
	$params[] = $state;
}
if (isset($_GET['currency_id']) && $_GET['currency_id'] !== '')
{
	$currencyId = apiV1QueryInt('currency_id', 1, 1, 9999);
	$filter .= ' and ocID=?d';
	$params[] = $currencyId;
}

$total = (int)$db->fetch1($db->select('Opers', 'COUNT(*)', $filter, $params));
$offset = ($page - 1) * $perPage;
$rows = $db->fetchRows($db->select(
	'Opers LEFT JOIN Currs ON cID=ocID',
	'Opers.*, cName, cCurr',
	$filter,
	$params,
	'oID DESC',
	$offset . ',' . $perPage
));
$operations = array_map('apiV1Operation', $rows);

ApiResponse::success(array('operations' => $operations), 200, array(
	'pagination' => array(
		'page' => $page,
		'per_page' => $perPage,
		'total' => $total,
		'total_pages' => $total > 0 ? (int)ceil($total / $perPage) : 0,
	),
));
