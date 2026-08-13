<?php

use HScript\Http\ApiRequest;
use HScript\Http\ApiResponse;

require dirname(__DIR__) . '/bootstrap.php';
$method = ApiRequest::method();
apiV1Require($method === 'GET' ? 'balance:read' : 'withdraw:write', array('GET', 'POST'));
require_once('module/balance/lib.php');

if ($method === 'GET')
{
	$currencies = array();
	foreach ($_currs as $currency)
		if ((int)$currency['cCASHOUTMode'] > 0 && (int)$currency['cID'] !== 1 && empty($currency['cHidden']))
		{
			$item = apiV1Currency($currency, 'CASHOUT');
			$item['destination_configured'] = !empty(opDecodeUserCurrParams($currency)['acc']);
			$currencies[] = $item;
		}
	ApiResponse::success(array('currencies' => $currencies));
}

$input = apiV1Input();
$currencyId = apiV1RequirePositiveInt($input, 'currency_id');
$amount = apiV1RequirePositiveNumber($input, 'amount');
$memo = apiV1String($input, 'memo', 120);
$currency = $_currs[$currencyId] ?? null;
if (!$currency || (int)$currency['cCASHOUTMode'] <= 0 || (int)$currency['cID'] === 1)
	ApiResponse::error('currency_unavailable', 'The selected withdrawal currency is unavailable', 422);

$destination = opDecodeUserCurrParams($currency);
if (empty($destination['acc']))
	apiV1OperationError('wallet_not_defined');

if (!empty($_cfg['Bal_NeedPIN']))
{
	$pin = apiV1String($input, 'pin', 255);
	$userPin = $db->fetch1($db->select('Users', 'uPIN', 'uID=?d', array($apiAuth['user_id'])));
	if ($pin === '' || !verifyPasswordWithLegacyDigest($pin, $userPin, $_cfg['Const_Salt'], false))
		ApiResponse::error('pin_invalid', 'The account PIN is invalid', 403);
}
if (!empty($_cfg['SMS_CASHOUT']))
	ApiResponse::error(
		'second_factor_required',
		'SMS confirmation is enabled; create this withdrawal in the web cabinet',
		409
	);

$operation = 'CASHOUT';
$operationCurrencyId = $currencyId;
$params = array();
if (!empty($_cfg['Const_IntCurr']))
{
	$operation = 'EX';
	$operationCurrencyId = 1;
	$params['cid2'] = $currencyId;
}

$operationId = opOperCreate(
	$apiAuth['user_id'],
	$operation,
	$operationCurrencyId,
	$amount,
	$params,
	$memo
);
if (is_string($operationId))
	apiV1OperationError($operationId);

$confirmation = opOperConfirm($apiAuth['user_id'], $operationId, array());
if (is_string($confirmation))
{
	opOperCancel($apiAuth['user_id'], $operationId);
	apiV1OperationError($confirmation);
}

$warning = null;
$operationMode = (int)($_currs[$operationCurrencyId]['c' . $operation . 'Mode'] ?? 0);
if ($operationMode === 2)
{
	$completion = opOperComplete($apiAuth['user_id'], $operationId, array());
	if (is_string($completion))
		$warning = $completion;
}

$row = $db->fetch1Row($db->select(
	'Opers LEFT JOIN Currs ON cID=ocID',
	'Opers.*, cName, cCurr',
	'oID=?d and ouID=?d',
	array($operationId, $apiAuth['user_id']),
	'',
	1
));
$response = array('operation' => apiV1Operation($row));
if ($warning !== null)
	$response['processing_warning'] = $warning;

ApiResponse::success($response, (int)$row['oState'] === 3 ? 201 : 202);
