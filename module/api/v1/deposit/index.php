<?php

use HScript\Http\ApiRequest;
use HScript\Http\ApiResponse;
use HScript\Finance\IdempotencyResult;
use HScript\Finance\FinancialOperationException;

require dirname(__DIR__) . '/bootstrap.php';
$method = ApiRequest::method();
apiV1Require($method === 'GET' ? 'balance:read' : 'deposit:write', array('GET', 'POST'));

if ($method === 'GET')
{
	$currencies = array();
	foreach ($_currs as $currency)
		if ((int)$currency['cCASHINMode'] > 0 && (int)$currency['cID'] !== 1 && empty($currency['cHidden']))
			$currencies[] = apiV1Currency($currency, 'CASHIN');
	ApiResponse::success(array('currencies' => $currencies));
}

$input = apiV1Input();
apiV1FinancialFields($input, ['currency_id', 'amount', 'memo', 'plan_id', 'compound_percent']);
$currencyId = apiV1RequirePositiveInt($input, 'currency_id');
$amount = apiV1FinancialDecimal($input, 'amount');
$memo = apiV1String($input, 'memo', 120);
$planId = isset($input['plan_id']) && $input['plan_id'] !== 0 ? apiV1RequirePositiveInt($input, 'plan_id') : 0;
$compound = apiV1FinancialDecimal($input, 'compound_percent', true);
if (HScript\Finance\Decimal::of($compound)->compareTo(HScript\Finance\Decimal::of(100)) > 0) ApiResponse::error('validation_error', 'compound_percent must be between 0 and 100', 422);
require_once('module/balance/lib.php');
apiV1FinancialWrite('rest:deposit', [
    'currency_id' => $currencyId, 'amount' => $amount, 'memo' => $memo,
    'plan_id' => $planId, 'compound_percent' => $compound,
], static function () use ($currencyId, $amount, $memo, $planId, $compound): IdempotencyResult {
    global $db, $_currs, $_cfg, $apiAuth;
    $currency = $_currs[$currencyId] ?? null;
    if (!$currency || (int)$currency['cCASHINMode'] <= 0 || $currencyId === 1)
        throw new FinancialOperationException('currency_unavailable');
    $params = ['pid' => $planId, 'compnd' => $compound];
    if (!empty($_cfg['Depo_AutoDepo'])) {
        require_once('module/depo/lib.php');
        $validation = opDepoCreate($apiAuth['user_id'], $currencyId, $amount, $compound, $planId, false, 2);
        if ($validation !== 'passed') throw new FinancialOperationException((string)$validation);
    }
    $operationId = FinancialOperationException::check(opOperCreate($apiAuth['user_id'], 'CASHIN', $currencyId, $amount, $params, $memo));
    $operation = $db->fetch1Row($db->select('Opers LEFT JOIN Currs ON cID=ocID', 'Opers.*, cName, cCurr', 'oID=?d', [$operationId]));
    // Checkout is an authenticated browser action. No provider request or payment
    // credentials are replayed from an API financial response.
    $payment = [
        'mode' => [1 => 'manual', 2 => 'gateway', 3 => 'hybrid'][(int)$currency['cCASHINMode']],
        'checkout_url' => fullURL(moduleToLink('balance/oper')) . '?id=' . $operationId,
    ];
    return new IdempotencyResult(['operation' => apiV1Operation($operation), 'payment' => $payment], 201, $operationId);
});
