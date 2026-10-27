<?php

use HScript\Http\ApiRequest;
use HScript\Http\ApiResponse;
use HScript\Finance\IdempotencyResult;
use HScript\Finance\FinancialOperationException;

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
			$wallet=opDecodeUserCurrParams($currency);
            $item['destination_configured'] = !empty($wallet['acc']) || ($currency['cCID']==='BW'
                && (int)$currency['cCASHOUTMode']===1 && HScript\Payment\Gateways\BankWire::hasDestination($wallet));
            if($currency['cCID']==='PAW') {
                $item['payout_assets']=[];
                foreach(\HScript\Finance\PawPayoutTarget::ASSETS as $asset=>$metadata)if(\HScript\Finance\PawPayoutTarget::valid($asset,$wallet[$asset]??null))$item['payout_assets'][]=$asset;
                $item['destination_configured']=(bool)$item['payout_assets'];
            }
			$currencies[] = $item;
		}
	ApiResponse::success(array('currencies' => $currencies));
}

$input = apiV1Input();
$currencyId = apiV1RequirePositiveInt($input, 'currency_id');
apiV1FinancialFields($input, ['currency_id', 'amount', 'memo', 'pin', 'payout_asset']);
$amount = apiV1FinancialDecimal($input, 'amount');
$payoutAsset = apiV1String($input,'payout_asset',40);
$memo = apiV1String($input, 'memo', 120);
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

apiV1FinancialWrite('rest:withdraw', ['currency_id' => $currencyId, 'amount' => $amount, 'memo' => $memo,'payout_asset'=>$payoutAsset], static function () use ($currencyId, $amount, $memo,$payoutAsset): IdempotencyResult {
    global $db, $_cfg, $_currs, $apiAuth;
    $currency = $_currs[$currencyId] ?? null;
    if (!$currency || (int)$currency['cCASHOUTMode'] <= 0 || $currencyId === 1)
        throw new FinancialOperationException('currency_unavailable');
    $recipient=opDecodeUserCurrParams($currency);
    $bankDestination=$currency['cCID']==='BW' && (int)$currency['cCASHOUTMode']===1
        && HScript\Payment\Gateways\BankWire::hasDestination($recipient);
    if ($currency['cCID']!=='PAW' && empty($recipient['acc']) && !$bankDestination) throw new FinancialOperationException('wallet_not_defined');
    $operation = empty($_cfg['Const_IntCurr']) ? 'CASHOUT' : 'EX';
    $operationCurrencyId = $operation === 'EX' ? 1 : $currencyId;
    $params = ($operation === 'EX' ? ['cid2' => $currencyId] : [])+['payout_asset'=>$payoutAsset];
    $operationId = FinancialOperationException::check(opOperCreate($apiAuth['user_id'], $operation, $operationCurrencyId, $amount, $params, $memo));
    FinancialOperationException::check(opOperConfirm($apiAuth['user_id'], $operationId));
    if ((int)($_currs[$operationCurrencyId]['c' . $operation . 'Mode'] ?? 0) === 2)
        FinancialOperationException::check(opOperComplete($apiAuth['user_id'], $operationId));
    $row = $db->fetch1Row($db->select('Opers LEFT JOIN Currs ON cID=ocID', 'Opers.*, cName, cCurr', 'oID=?d', [$operationId]));
    return new IdempotencyResult(['operation' => apiV1Operation($row)], (int)$row['oState'] === 3 ? 201 : 202, $operationId);
});
