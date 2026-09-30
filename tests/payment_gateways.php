<?php

use HScript\Database\Connection;
use HScript\Payment\AbstractGateway;
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

$probe = new class('TEST', array('Probe', 'USD'), array(), new Connection()) extends AbstractGateway {
	public array $form = array();
	public function processDeposit(array $params): array { return $this->form; }
};
$manager->register('TEST', $probe);
foreach (array(
	array('html' => '<form action="https://evil.example">'),
	array('url' => 'javascript:alert(1)'),
	array('url' => 'https://example.test/pay', 'bad onmouseover' => 'x'),
) as $unsafeForm)
{
	$probe->form = $unsafeForm;
	paymentAssert($manager->processDeposit('TEST', array()) === array('error' => 'payment_form_unavailable'),
		'Unsafe gateway form was exposed to the caller');
}
$probe->form = array('url' => 'https://example.test/pay', 'memo' => '<script>signed bytes</script>');
paymentAssert($manager->processDeposit('TEST', array()) === $probe->form,
	'Gateway form validation changed signed field values');

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
$invalidVolet = $voletCallback;
$invalidVolet['ac_hash'] = str_repeat('0', 64);
paymentAssert(empty($manager->handleCallback('AC', $invalidVolet, array('key' => 'secret'))['correct']), 'Volet accepted a bad signature');

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
$invalidYoo = $yooCallback;
$invalidYoo['sha1_hash'] = str_repeat('0', 40);
paymentAssert(empty($manager->handleCallback('YM', $invalidYoo, array('key' => 'secret'))['correct']), 'YooMoney accepted a bad signature');

$webMoneyCallback = array(
	'LMI_PAYEE_PURSE' => 'Z123456789012', 'LMI_PAYER_PURSE' => 'Z987654321012',
	'LMI_PAYER_WM' => '123456789012', 'LMI_PAYMENT_AMOUNT' => '5.00',
	'LMI_PAYMENT_NO' => '48', 'LMI_MODE' => '0', 'LMI_SYS_INVS_NO' => '100',
	'LMI_SYS_TRANS_NO' => '200', 'LMI_SYS_TRANS_DATE' => '20260724 10:00:00',
);
$webMoneyCallback['LMI_HASH'] = strtoupper(hash('sha256', implode('', array(
	$webMoneyCallback['LMI_PAYEE_PURSE'], $webMoneyCallback['LMI_PAYMENT_AMOUNT'],
	$webMoneyCallback['LMI_PAYMENT_NO'], $webMoneyCallback['LMI_MODE'],
	$webMoneyCallback['LMI_SYS_INVS_NO'], $webMoneyCallback['LMI_SYS_TRANS_NO'],
	$webMoneyCallback['LMI_SYS_TRANS_DATE'], 'secret',
	$webMoneyCallback['LMI_PAYER_PURSE'], $webMoneyCallback['LMI_PAYER_WM'],
))));
paymentAssert(!empty($manager->handleCallback('WM', $webMoneyCallback, array('key' => 'secret'))['correct']), 'WebMoney callback validation failed');
$webMoneyCallback['LMI_HASH'] = str_repeat('0', 64);
paymentAssert(empty($manager->handleCallback('WM', $webMoneyCallback, array('key' => 'secret'))['correct']), 'WebMoney accepted a bad signature');

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
	$request['_json']['sign'] = str_repeat('0', 40);
	paymentAssert(empty($manager->handleCallback($id, $request, array('apipass' => 'token'))['correct']), "$id accepted a bad signature");
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
$cryptoRequest['_json']['sign2'] = str_repeat('0', 40);
paymentAssert(empty($manager->handleCallback('CCAB', $cryptoRequest, array('apipass' => 'token'))['correct']), 'CryptoCurrencyAPI accepted a bad signature');

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
$xrpRequest['_json']['sign'] = str_repeat('0', 40);
paymentAssert(empty($manager->handleCallback('XRPA', $xrpRequest, array('apipass' => 'token'))['correct']), 'XRPAPI accepted a bad signature');

$outboundDb = new class extends Connection {
	public array $updates = array();
	public function update($table, $fields_and_values, $fields = '', $filter = '', $ph_values = null)
	{
		$this->updates[] = array($table, $fields_and_values, $filter, $ph_values);
		return 1;
	}
};
$outboundManager = new PaymentManager($outboundDb);
$outboundRequest = array(
	'bnbapi.net' => 1, 'type' => 'out', 'date' => 1721815200,
	'from' => 'source', 'to' => 'destination', 'token' => '',
	'amount' => '1.25', 'txid' => 'tx-out', 'confirmations' => 2, 'tag' => '44',
	'sign' => 'invalid',
);
$outboundRequest['_json'] = $outboundRequest;
$invalidOutbound = $outboundManager->handleCallback('BSC', $outboundRequest, array('apipass' => 'token'));
paymentAssert(empty($invalidOutbound['correct']) && !empty($invalidOutbound['handled']), 'Unsigned outbound callback was not rejected');
paymentAssert($outboundDb->updates === array(), 'Unsigned outbound callback changed an operation');
$signedParts = array(
	$outboundRequest['type'], $outboundRequest['date'], $outboundRequest['from'],
	$outboundRequest['to'], $outboundRequest['amount'], $outboundRequest['txid'],
	$outboundRequest['confirmations'], $outboundRequest['tag'], 'token',
);
$outboundRequest['sign'] = sha1(implode(':', $signedParts));
$outboundRequest['_json'] = $outboundRequest;
$validOutbound = $outboundManager->handleCallback('BSC', $outboundRequest, array('apipass' => 'token'));
paymentAssert(!empty($validOutbound['correct']) && count($outboundDb->updates) === 1, 'Signed outbound callback was not applied');
paymentAssert($outboundDb->updates[0][1]['oBatch'] === 'tx-out', 'Signed outbound batch changed');

$coinBody = 'merchant=merchant&amount1=1.5&currency1=BTC&invoice=47&status=100&txn_id=tx-cp';
parse_str($coinBody, $coinCallback);
$coinCallback['_raw_body'] = $coinBody;
$coinCallback['_server'] = array('HTTP_HMAC' => hash_hmac('sha512', $coinBody, 'secret'));
paymentAssert(!empty($manager->handleCallback('CP', $coinCallback, array('id' => 'merchant', 'key' => 'secret'))['correct']), 'CoinPayments callback validation failed');
$coinCallback['_server']['HTTP_HMAC'] = str_repeat('0', 128);
paymentAssert(empty($manager->handleCallback('CP', $coinCallback, array('id' => 'merchant', 'key' => 'secret'))['correct']), 'CoinPayments accepted a bad HMAC');

echo "Payment gateway smoke tests passed (" . count($expected) . " IDs).\n";
