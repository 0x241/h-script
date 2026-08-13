<?php

use HScript\Sms\EpochtaClient;

require dirname(__DIR__) . '/vendor/autoload.php';

function epochtaAssert(bool $condition, string $message): void
{
	if (!$condition)
		throw new RuntimeException($message);
}

$requests = array();
$responses = array(
	json_encode(array('result' => array('id' => 1853174, 'price' => 0.13)), JSON_THROW_ON_ERROR),
	json_encode(array('result' => array('status' => array('DELIVERED'))), JSON_THROW_ON_ERROR),
);
$client = new EpochtaClient('public-key', 'private-key', static function (string $url, array $params) use (&$requests, &$responses): string {
	$requests[] = array('url' => $url, 'params' => $params);
	return array_shift($responses);
});

$sent = $client->send('HScript', 'Test message', '+7 (900) 123-45-67', true);
epochtaAssert($sent['id'] === '1853174', 'Campaign ID was not parsed');
epochtaAssert($sent['price'] === 0.13, 'SMS price was not parsed');
epochtaAssert($requests[0]['url'] === EpochtaClient::BASE_URL . 'sendSMS', 'Unexpected sendSMS endpoint');
epochtaAssert($requests[0]['params']['phone'] === '79001234567', 'Phone was not normalized');
epochtaAssert($requests[0]['params']['test'] === 1, 'API v3 test flag is missing');

$signed = $requests[0]['params'];
$sum = $signed['sum'];
unset($signed['sum'], $signed['key']);
epochtaAssert(
	$sum === EpochtaClient::checksum('sendSMS', $signed, 'public-key', 'private-key'),
	'API v3 checksum differs from the documented algorithm'
);

$statuses = $client->deliveryStatuses(array($sent['id']));
epochtaAssert($statuses['1853174'] === 'OK', 'Delivered status was not normalized');
epochtaAssert($requests[1]['url'] === EpochtaClient::BASE_URL . 'getCampaignDeliveryStats', 'Unexpected delivery-status endpoint');
epochtaAssert($requests[1]['params']['id'] === '1853174', 'Campaign ID is missing from status request');

$errorClient = new EpochtaClient('public-key', 'private-key', static fn(): string => '{"error":"Wrong public key.","code":"-1","result":"false"}');
try
{
	$errorClient->send('HScript', 'Test', '79001234567');
	throw new RuntimeException('Provider error was accepted');
}
catch (RuntimeException $exception)
{
	epochtaAssert(str_contains($exception->getMessage(), 'ePochta error -1'), 'Provider error was not normalized');
}

echo "ePochta SMS API v3 tests passed.\n";
