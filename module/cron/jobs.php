<?php

use HScript\Mail\Mailer;
use HScript\Util\StringHelper;

useLib('sms');

$jobQueue->registerHandler('email', static function (array $payload): array
{
	$sent = Mailer::sendNow(
		(string)($payload['to'] ?? ''),
		(string)($payload['subject'] ?? ''),
		(string)($payload['message'] ?? ''),
		(string)($payload['from'] ?? ''),
		(string)($payload['from_name'] ?? '')
	);
	if (!$sent)
		throw new RuntimeException(Mailer::lastError() ?: 'Email delivery failed');
	return array('delivered' => true);
});

$jobQueue->registerHandler('sms', static function (array $payload, array $job): array
{
	return smsSendJob($payload, (int)$job['jID']);
});

$jobQueue->registerHandler('financial.withdraw', static function (array $payload) use ($db): array
{
    useLib('balance');
    return HScript\Finance\WithdrawalDispatcher::dispatch($db, (int)($payload['operation_id'] ?? 0));
});

$jobQueue->recoverStale(10 * HS2_UNIX_MINUTE);
$jobQueue->retryFailed(10, HS2_UNIX_MINUTE);
$jobQueue->processBatch(10);
HScript\Finance\PawPayout::poll($db);
smsUpdateDeliveryStatuses(
	(int)StringHelper::valueIf($_cfg['SMS_UpdateCount'] > 0, $_cfg['SMS_UpdateCount'], 20)
);
$jobQueue->cleanup(30);
