<?php

use HScript\Database\Connection;
use HScript\Payment\PaymentManager;

require dirname(__DIR__) . '/vendor/autoload.php';

function paymentAssert(bool $condition, string $message): void
{
	if (!$condition)
		throw new RuntimeException($message);
}

$manager = new PaymentManager(new Connection());
$expected = array(
	'AC', 'ACR', 'PKAU', 'PKAR', 'WM', 'YM', 'YMC', 'SB', 'BW',
	'CP', 'CPE', 'CPL', 'CPR', 'PKB', 'PKL', 'PKE',
	'CCAB', 'CCAL', 'CCAD', 'CCAG', 'CCAH',
	'EA', 'EAT', 'EAT1', 'BSC', 'BSCT', 'XRPA', 'TRA', 'TRAT',
);

paymentAssert(array_keys($manager->definitions()) === $expected, 'Live gateway registry differs from the approved list');
foreach ($expected as $id)
{
	paymentAssert($manager->has($id), "Gateway $id is not registered");
	paymentAssert($manager->gateway($id)->getName() !== '', "Gateway $id has no name");
	paymentAssert(is_string($manager->gateway($id)->getFormFields(array('type' => 'pay'))), "Gateway $id form fields are invalid");
}
foreach (PaymentManager::DEPRECATED_IDS as $id)
	paymentAssert(!$manager->has($id), "Deprecated gateway $id is still registered");

$volet = $manager->processDeposit('AC', array(
	'sci' => array('name' => 'merchant@example.test', 'sci' => 'Shop', 'key' => 'secret'),
	'sum' => 12.3,
	'memo' => 'Order',
	'tag' => 42,
	'url_ok' => 'https://example.test/ok',
	'url_fail' => 'https://example.test/fail',
	'url_callback' => 'https://example.test/callback',
));
paymentAssert($volet['url'] === 'https://account.volet.com/sci/', 'Volet SCI endpoint is stale');
paymentAssert($volet['ac_amount'] === '12.30', 'Volet fiat amount format changed');

$webMoney = $manager->processDeposit('WM', array(
	'pay' => array('acc' => 'Z123456789012'),
	'sum' => 5,
	'memo' => 'Order',
	'tag' => 7,
	'url_ok' => 'https://example.test/ok',
	'url_fail' => 'https://example.test/fail',
	'url_callback' => 'https://example.test/callback',
));
paymentAssert($webMoney['LMI_PAYMENT_AMOUNT'] === '5.00', 'WebMoney amount format changed');

$yooMoney = $manager->processDeposit('YMC', array(
	'sci' => array('acc' => '4100111222333'),
	'sum' => 20,
	'memo' => 'Order',
	'tag' => 9,
));
paymentAssert($yooMoney['url'] === 'https://yoomoney.ru/quickpay/confirm.xml', 'YooMoney endpoint is stale');
paymentAssert($yooMoney['paymentType'] === 'AC', 'YooMoney card mode is missing');

$coinPayments = $manager->processDeposit('CPE', array(
	'sci' => array('id' => 'merchant'),
	'sum' => 0.5,
	'memo' => 'Order',
	'tag' => 11,
	'url_callback' => 'https://example.test/callback',
));
paymentAssert($coinPayments['currency'] === 'ETH', 'CoinPayments currency mapping changed');
paymentAssert(str_ends_with($coinPayments['ipn_url'], '?its=CPE'), 'CoinPayments callback routing changed');

$detectors = array(
	array(array('ac_transfer' => '1', 'ac_merchant_currency' => 'RUR'), array(), 'ACR'),
	array(array('operation_id' => '1', 'notification_type' => 'card-incoming'), array(), 'YMC'),
	array(array('bnbapi.net' => 1, 'token' => 'USDT'), array(), 'BSCT'),
	array(array('etherapi.net' => 1, 'token' => 'DAI'), array(), 'EAT1'),
	array(array('tronapi.net' => 1, 'token' => 'USDT'), array(), 'TRAT'),
	array(array('cryptocurrencyapi.net' => 1, 'currency' => 'DOGE'), array(), 'CCAG'),
	array(array('txn_id' => '1'), array('its' => 'CPR'), 'CPR'),
	array(array('private_hash' => '1', 'system' => 'bitcoin', 'currency' => 'BTC'), array(), 'PKB'),
);
foreach ($detectors as [$request, $query, $expectedId])
	paymentAssert($manager->detectCallback($request, $query) === $expectedId, "Callback detector failed for $expectedId");

$voletCallback = array(
	'ac_transfer' => 'tx-1',
	'ac_start_date' => '2026-07-24 10:00:00',
	'ac_sci_name' => 'Shop',
	'ac_src_wallet' => 'U1',
	'ac_dest_wallet' => 'U2',
	'ac_buyer_email' => 'buyer@example.test',
	'ac_order_id' => '42',
	'ac_amount' => '12.30',
	'ac_fee' => '0.10',
	'ac_merchant_currency' => 'USD',
);
$voletCallback['ac_hash'] = hash('sha256', implode(':', array(
	$voletCallback['ac_transfer'],
	$voletCallback['ac_start_date'],
	$voletCallback['ac_sci_name'],
	$voletCallback['ac_src_wallet'],
	$voletCallback['ac_dest_wallet'],
	$voletCallback['ac_order_id'],
	$voletCallback['ac_amount'],
	$voletCallback['ac_merchant_currency'],
	'secret',
)));
paymentAssert(!empty($manager->handleCallback('AC', $voletCallback, array('key' => 'secret'))['correct']), 'Volet callback validation failed');

$yooCallback = array(
	'notification_type' => 'p2p-incoming',
	'operation_id' => 'op-1',
	'amount' => '10.00',
	'currency' => '643',
	'datetime' => '2026-07-24T10:00:00Z',
	'sender' => '4100111222333',
	'codepro' => 'false',
	'label' => '43',
	'test_notification' => false,
);
$yooCallback['sha1_hash'] = sha1(implode('&', array(
	$yooCallback['notification_type'],
	$yooCallback['operation_id'],
	$yooCallback['amount'],
	$yooCallback['currency'],
	$yooCallback['datetime'],
	$yooCallback['sender'],
	$yooCallback['codepro'],
	'secret',
	$yooCallback['label'],
)));
paymentAssert(!empty($manager->handleCallback('YM', $yooCallback, array('key' => 'secret'))['correct']), 'YooMoney callback validation failed');

$hostedCallbacks = array(
	'BSC' => array('bnbapi.net', '', 2),
	'EAT' => array('etherapi.net', 'USDT', 12),
	'TRAT' => array('tronapi.net', 'USDT', 10),
);
foreach ($hostedCallbacks as $id => [$marker, $token, $confirmations])
{
	$callback = array(
		$marker => 1,
		'type' => 'in',
		'date' => 1721815200,
		'from' => 'source',
		'to' => 'destination',
		'token' => $token,
		'amount' => '1.25',
		'txid' => 'tx-' . $id,
		'confirmations' => $confirmations,
		'tag' => '44',
	);
	$signatureParts = array(
		$callback['type'], $callback['date'], $callback['from'], $callback['to'],
		$callback['token'], $callback['amount'], $callback['txid'],
		$callback['confirmations'], $callback['tag'], 'token',
	);
	if ($id === 'BSC')
		unset($signatureParts[4]);
	$callback['sign'] = sha1(implode(':', $signatureParts));
	$request = $callback;
	$request['_json'] = $callback;
	paymentAssert(!empty($manager->handleCallback($id, $request, array('apipass' => 'token'))['correct']), "$id callback validation failed");
}

$cryptoCallback = array(
	'cryptocurrencyapi.net' => 1,
	'currency' => 'BTC',
	'type' => 'in',
	'date' => 1721815200,
	'address' => 'address',
	'amount' => '0.5',
	'txid' => 'tx-cca',
	'pos' => 0,
	'confirmations' => 3,
	'tag' => '45',
);
$cryptoCallback['sign2'] = sha1(implode(':', array(
	$cryptoCallback['currency'], $cryptoCallback['type'], $cryptoCallback['date'],
	$cryptoCallback['address'], $cryptoCallback['amount'], $cryptoCallback['txid'],
	$cryptoCallback['pos'], $cryptoCallback['confirmations'], $cryptoCallback['tag'], 'token',
)));
$cryptoRequest = $cryptoCallback;
$cryptoRequest['_json'] = $cryptoCallback;
paymentAssert(!empty($manager->handleCallback('CCAB', $cryptoRequest, array('apipass' => 'token'))['correct']), 'CryptoCurrencyAPI callback validation failed');

$xrpCallback = array(
	'xrpapi.net' => 1,
	'type' => 'in',
	'date' => 1721815200,
	'from' => 'source',
	'to' => 'destination',
	'tag' => '123',
	'amount' => '3.5',
	'fee' => '0.01',
	'txid' => 'tx-xrp',
	'label' => '46',
);
$xrpCallback['sign'] = sha1(implode(':', array(
	$xrpCallback['type'], $xrpCallback['date'], $xrpCallback['from'], $xrpCallback['to'],
	$xrpCallback['tag'], $xrpCallback['amount'], $xrpCallback['fee'],
	$xrpCallback['txid'], $xrpCallback['label'], 'token',
)));
$xrpRequest = $xrpCallback;
$xrpRequest['_json'] = $xrpCallback;
paymentAssert(!empty($manager->handleCallback('XRPA', $xrpRequest, array('apipass' => 'token'))['correct']), 'XRPAPI callback validation failed');

$coinBody = 'merchant=merchant&amount1=1.5&currency1=BTC&invoice=47&status=100&txn_id=tx-cp';
parse_str($coinBody, $coinCallback);
$coinCallback['_raw_body'] = $coinBody;
$coinCallback['_server'] = array('HTTP_HMAC' => hash_hmac('sha512', $coinBody, 'secret'));
paymentAssert(!empty($manager->handleCallback('CP', $coinCallback, array('id' => 'merchant', 'key' => 'secret'))['correct']), 'CoinPayments callback validation failed');

echo "Payment gateway smoke tests passed (" . count($expected) . " IDs).\n";
