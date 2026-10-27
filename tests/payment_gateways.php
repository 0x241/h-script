<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

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
	'AC', 'VEUR', 'VUST', 'VUSC', 'VBTC', 'VETH', 'VSOL', 'VTRX', 'VXRP', 'VTON', 'VLTC', 'ACR', 'PKAU', 'PKAR', 'WM', 'YM', 'YMC', 'SB', 'BW',
	'CP', 'CPE', 'CPL', 'CPR', 'CPN', 'PKB', 'PKE', 'PKL',
    'PKDG', 'PKDA', 'PKBC', 'PKXR', 'PKTR', 'PKXL', 'PKBN', 'PKBB', 'PKBE', 'PKBD', 'PKBT', 'PKBZ', 'PKEZ', 'PKBU', 'PKBO', 'PKBA', 'PKBS', 'PKES', 'PKTN', 'PKTT',
	'CCT','CCTU','CCTC','PKTU','PKTC','CCAB', 'CCAL', 'CCAD', 'CCAG', 'CCAH',
	'CCAE', 'CCAM', 'CCAN', 'CCAT', 'CCAX', 'CCAO', 'CCAS', 'PAW', 'ST',
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
	'api' => array('name'=>'merchant@example.test','api'=>'Synthetic API','apipass'=>'synthetic-secret','acc'=>'USD123456789012'),
	'callback_fee_policy'=>['cCASHINComis'=>'0','cCASHINComisMin'=>'0','cCASHINComisMax'=>'0','oComis'=>'0'],
	'sum' => '12.30',
	'memo' => 'Order',
	'tag' => 42,
	'url_ok' => 'https://example.test/ok',
	'url_fail' => 'https://example.test/fail',
	'url_callback' => 'https://example.test/callback',
));
paymentAssert($volet['url'] === 'https://account.volet.com/api/public/payment-gateway', 'Volet SCI endpoint is stale');
paymentAssert($volet['ac_amount'] === '12.30', 'Volet fiat amount format changed');

$webMoney = $manager->processDeposit('WM', array(
	'pay' => array('acc' => 'Z123456789012'),
	'sci' => array('key' => 'secret', 'timezone' => 'UTC'),
	'sum' => 5,
	'memo' => 'Order',
	'tag' => 7,
	'url_ok' => 'https://example.test/ok',
	'url_fail' => 'https://example.test/fail',
	'url_callback' => 'https://example.test/callback',
));
paymentAssert($webMoney['LMI_PAYMENT_AMOUNT'] === '5.00', 'WebMoney amount format changed');
paymentAssert($webMoney['url']==='https://merchant.wmtransfer.com/lmi/payment_utf.asp', 'WebMoney UTF-8 checkout endpoint');
paymentAssert($webMoney['LMI_SUCCESS_METHOD']==='1' && $webMoney['LMI_FAIL_METHOD']==='1', 'WebMoney POST URL method is not protocol value 1');
paymentAssert(base64_decode($webMoney['LMI_PAYMENT_DESC_BASE64'],true)==='Order' && !isset($webMoney['LMI_PAYMENT_DESC']), 'WebMoney effective description has competing fields');

$yooMoney = $manager->processDeposit('YMC', array(
	'sci' => array('acc' => '4100111222333', 'key' => 'secret'),
	'sum' => 20,
	'memo' => 'Order',
	'tag' => 9,
));
paymentAssert($yooMoney['url'] === 'https://yoomoney.ru/quickpay/confirm.xml', 'YooMoney endpoint is stale');
paymentAssert($yooMoney['paymentType'] === 'AC', 'YooMoney card mode is missing');

$coinPayments = $manager->processDeposit('CPE', array(
	'sci' => array('id' => 'merchant', 'key' => 'secret'),
	'sum' => '0.5',
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
	array(array('ac_transfer' => '1', 'ac_merchant_currency' => 'RUR'), array(), ''),
	array(array('operation_id' => '1', 'notification_type' => 'card-incoming'), array(), 'YMC'),
	array(array('bnbapi.net' => 1, 'token' => 'USDT'), array(), ''),
	array(array('etherapi.net' => 1, 'token' => 'DAI'), array(), ''),
	array(array('tronapi.net' => 1, 'token' => 'USDT'), array(), ''),
	array(array('cryptocurrencyapi.net' => 3, 'chain' => 'dogecoin', 'currency' => 'DOGE'), array(), 'CCAG'),
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
paymentAssert(empty($manager->handleCallback('AC', $voletCallback, array('key' => 'secret'))['correct']), 'Legacy SCI-only callback can settle without an immutable API proof');
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
$yooCallback['test_notification']='false';
$yooCallback['unaccepted']='false';
$yooCallback['withdraw_amount']='10.00';
ksort($yooCallback, SORT_STRING);
$yooCallback['sign']=hash_hmac('sha256', http_build_query($yooCallback,'','&',PHP_QUERY_RFC3986),'secret');
paymentAssert(!empty($manager->handleCallback('YM', $yooCallback, array('key' => 'secret'))['correct']), 'YooMoney callback validation failed');
$invalidYoo = $yooCallback;
$invalidYoo['sign'] = str_repeat('0', 64);
paymentAssert(empty($manager->handleCallback('YM', $invalidYoo, array('key' => 'secret'))['correct']), 'YooMoney accepted a bad signature');

$webMoneyCallback = array(
	'LMI_PAYEE_PURSE' => 'Z123456789012', 'LMI_PAYER_PURSE' => 'Z987654321012',
	'LMI_PAYER_WM' => '123456789012', 'LMI_PAYMENT_AMOUNT' => '5.00',
	'LMI_PAYMENT_NO' => '48', 'LMI_MODE' => '0', 'LMI_SYS_INVS_NO' => '100',
	'LMI_SYS_TRANS_NO' => '200', 'LMI_SYS_TRANS_DATE' => '20260724 10:00:00',
    'LMI_PAYMENT_DESC' => 'Order',
);
$webMoneyCallback['LMI_HASH2'] = strtoupper(hash('sha256', implode(';', array(
	$webMoneyCallback['LMI_PAYEE_PURSE'], $webMoneyCallback['LMI_PAYMENT_AMOUNT'],
	$webMoneyCallback['LMI_PAYMENT_NO'], $webMoneyCallback['LMI_MODE'],
	$webMoneyCallback['LMI_SYS_INVS_NO'], $webMoneyCallback['LMI_SYS_TRANS_NO'],
	$webMoneyCallback['LMI_SYS_TRANS_DATE'], 'secret',
	$webMoneyCallback['LMI_PAYER_PURSE'], $webMoneyCallback['LMI_PAYER_WM'],
))));
paymentAssert(!empty($manager->handleCallback('WM', $webMoneyCallback, array('key' => 'secret', 'timezone' => 'UTC'))['correct']), 'WebMoney callback validation failed');
$webMoneyCallback['LMI_HASH2'] = str_repeat('0', 64);
paymentAssert(empty($manager->handleCallback('WM', $webMoneyCallback, array('key' => 'secret', 'timezone' => 'UTC'))['correct']), 'WebMoney accepted a bad signature');

foreach (['EA','EAT','EAT1','BSC','BSCT','XRPA','TRA','TRAT'] as $id) {
    $disabled=$manager->handleCallback($id, ['type'=>'in','sign'=>sha1('synthetic-unaccepted-profile')], ['apipass'=>'synthetic-key']);
    paymentAssert($disabled['outcome']==='unsupported' && !$disabled['correct'], $id.' old contract can settle automatically');
    paymentAssert(!$manager->has($id),$id.' retired adapter remains executable');
}

$cryptoFixture=json_decode(file_get_contents(__DIR__.'/fixtures/ccapi_v3_native.json'),true,32,JSON_THROW_ON_ERROR);
$cryptoRequest=['_raw_body'=>json_encode($cryptoFixture['payload'],JSON_THROW_ON_ERROR)];
$cryptoConfig=['protocol'=>'3','v3_key'=>$cryptoFixture['secret']];
paymentAssert(!empty($manager->handleCallback('CCAB',$cryptoRequest,$cryptoConfig)['correct']), 'CryptoCurrencyAPI v3 independent vector failed');
$badCrypto=$cryptoFixture['payload']; $badCrypto['sign']=str_repeat('0',40);
paymentAssert(empty($manager->handleCallback('CCAB',['_raw_body'=>json_encode($badCrypto,JSON_THROW_ON_ERROR)],$cryptoConfig)['correct']), 'CryptoCurrencyAPI v3 accepted bad signature');
paymentAssert($manager->detectCallback(['cryptocurrencyapi.net'=>1,'currency'=>'BTC'])==='', 'Legacy CryptoCurrencyAPI was routed automatically');

$coinBody = 'ipn_version=1.0&ipn_id=synthetic-contract-ipn&ipn_mode=hmac&ipn_type=simple&merchant=merchant&amount1=1.5&currency1=BTC&invoice=47&status=100&txn_id=tx-cp';
parse_str($coinBody, $coinCallback);
$coinCallback['_raw_body'] = $coinBody;
$coinCallback['_server'] = array('HTTP_HMAC' => hash_hmac('sha512', $coinBody, 'secret'));
paymentAssert(!empty($manager->handleCallback('CP', $coinCallback, array('id' => 'merchant', 'key' => 'secret'))['correct']), 'CoinPayments callback validation failed');
$coinCallback['_server']['HTTP_HMAC'] = str_repeat('0', 128);
paymentAssert(empty($manager->handleCallback('CP', $coinCallback, array('id' => 'merchant', 'key' => 'secret'))['correct']), 'CoinPayments accepted a bad HMAC');

echo "Payment gateway smoke tests passed (" . count($expected) . " IDs).\n";
