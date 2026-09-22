<?php

use HScript\Security\SensitiveDataRedactor;

require dirname(__DIR__) . '/vendor/autoload.php';

$input = array(
	'amount' => '12.30',
	'token' => 'token-value',
	'nested' => array(
		'api_key' => 'key-value',
		'HTTP_HMAC' => 'hmac-value',
		'status' => 'ok',
	),
	'cookie' => 'PHPSESSID=cookie-value',
	'authorization' => 'Bearer authorization-value',
	'service_token' => 'hst_' . str_repeat('a', 64),
	'installation_token' => 'hsi_' . str_repeat('b', 64),
	'pin' => '1234',
	'secret_answer' => 'answer-value',
	'gateway_key' => 'gateway-value',
	'payout_destination' => 'wallet-value',
	'callback_payload' => 'callback-value',
	'dsn' => 'mysql://user:password@database/private',
);
$result = SensitiveDataRedactor::redact($input);
if ($result['amount'] !== '12.30' || $result['nested']['status'] !== 'ok')
	throw new RuntimeException('Safe diagnostic values were modified');
foreach (array(
	$result['token'], $result['nested']['api_key'], $result['nested']['HTTP_HMAC'],
	$result['cookie'], $result['authorization'], $result['service_token'],
	$result['installation_token'], $result['pin'], $result['secret_answer'],
	$result['gateway_key'], $result['payout_destination'], $result['callback_payload'], $result['dsn'],
) as $value)
	if ($value !== '[REDACTED]')
		throw new RuntimeException('Sensitive diagnostic value was not redacted');

$text = SensitiveDataRedactor::redactText(
	'Cookie: PHPSESSID=cookie-value; Authorization: Bearer bearer-value-123456; '
	. 'pin=1234 secret_answer=answer-value gateway_key=gateway-value '
	. 'payout_destination=wallet-value callback_payload=callback-value '
	. 'dsn=mysql://user:password@database/private'
);
foreach (array('cookie-value', 'bearer-value-123456', '1234', 'answer-value', 'gateway-value', 'wallet-value', 'callback-value', 'user:password') as $secret)
	if (str_contains($text, $secret))
		throw new RuntimeException('Sensitive inline value was not redacted');

echo "Sensitive-data redaction tests passed.\n";
