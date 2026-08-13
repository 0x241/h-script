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
);
$result = SensitiveDataRedactor::redact($input);
if ($result['amount'] !== '12.30' || $result['nested']['status'] !== 'ok')
	throw new RuntimeException('Safe diagnostic values were modified');
foreach (array($result['token'], $result['nested']['api_key'], $result['nested']['HTTP_HMAC']) as $value)
	if ($value !== '[REDACTED]')
		throw new RuntimeException('Sensitive diagnostic value was not redacted');

echo "Sensitive-data redaction tests passed.\n";
