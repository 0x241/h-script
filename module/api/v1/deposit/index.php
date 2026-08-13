<?php

use HScript\Http\ApiRequest;
use HScript\Http\ApiResponse;
use HScript\Payment\PaymentManager;

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
$currencyId = apiV1RequirePositiveInt($input, 'currency_id');
$amount = apiV1RequirePositiveNumber($input, 'amount');
$memo = apiV1String($input, 'memo', 120);
$currency = $_currs[$currencyId] ?? null;
if (!$currency || (int)$currency['cCASHINMode'] <= 0 || (int)$currency['cID'] === 1)
	ApiResponse::error('currency_unavailable', 'The selected deposit currency is unavailable', 422);

require_once('module/balance/lib.php');
$params = array(
	'pid' => isset($input['plan_id']) ? (int)$input['plan_id'] : 0,
	'compnd' => isset($input['compound_percent']) ? (float)$input['compound_percent'] : 0,
);
if (!empty($_cfg['Depo_AutoDepo']))
{
	require_once('module/depo/lib.php');
	$validation = opDepoCreate(
		$apiAuth['user_id'],
		$currencyId,
		$amount,
		$params['compnd'],
		$params['pid'],
		false,
		2
	);
	if ($validation !== 'passed')
		apiV1OperationError((string)$validation);
}

$operationId = opOperCreate(
	$apiAuth['user_id'],
	'CASHIN',
	$currencyId,
	$amount,
	$params,
	$memo
);
if (is_string($operationId))
	apiV1OperationError($operationId);

$operation = $db->fetch1Row($db->select(
	'Opers LEFT JOIN Currs ON cID=ocID',
	'Opers.*, Currs.*',
	'oID=?d and ouID=?d',
	array($operationId, $apiAuth['user_id']),
	'',
	1
));
$payment = array(
	'mode' => array(1 => 'manual', 2 => 'gateway', 3 => 'hybrid')[(int)$currency['cCASHINMode']] ?? 'unknown',
	'form' => null,
);

if (in_array((int)$currency['cCASHINMode'], array(2, 3), true))
{
	try
	{
		opDecodeCurrParams($operation, $payConfig, $sciConfig, $apiConfig);
		$userWallet = $db->fetch1Row($db->select(
			'Users LEFT JOIN Wallets ON wcID=?d and wuID=uID',
			'*',
			'uID=?d',
			array($currencyId, $apiAuth['user_id']),
			'',
			1
		));
		$userPayment = opDecodeUserCurrParams($userWallet);
		$operationParams = strToArray($operation['oParams']);
		$operationData = strToArray($operation['oParams2']);
		$userPayment['psysorder'] = $operationParams['psysorder'] ?? null;
		$userPayment['html'] = $operationParams['html'] ?? null;

		$paymentManager = new PaymentManager($db);
		$form = $paymentManager->processDeposit($currency['cCID'], array(
			'pay' => $payConfig,
			'sci' => $sciConfig,
			'sum' => $operation['oSum'],
			'memo' => $operationData['memo'] ?? $memo,
			'tag' => $operationId,
			'url_ok' => fullURL(moduleToLink('balance/oper')) . '?id=' . $operationId . '&check',
			'url_fail' => fullURL(moduleToLink('balance')) . '?fail',
			'url_callback' => empty($sciConfig['hideurl'])
				? fullURL(moduleToLink('balance/status'))
				: '',
			'user' => $userPayment,
			'force_payer' => $_cfg['Bal_ForcePayer'],
		));
		if (isset($form['psysorder']) || isset($form['html']))
		{
			$operationParams['psysorder'] = $form['psysorder'] ?? null;
			$operationParams['html'] = $form['html'] ?? null;
			$db->update('Opers', array('oParams' => arrayToStr($operationParams)), '', 'oID=?d', array($operationId));
			unset($form['psysorder']);
		}
		$payment['form'] = $form;
	}
	catch (Throwable $e)
	{
		error_log('API deposit form failed for operation ' . $operationId . ': ' . $e->getMessage());
		$payment['form_error'] = 'payment_form_unavailable';
	}
}

ApiResponse::success(array(
	'operation' => apiV1Operation($operation),
	'payment' => $payment,
), 201);
