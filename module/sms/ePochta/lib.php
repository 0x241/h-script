<?php

use HScript\Sms\EpochtaClient;

function epochtaClient(): EpochtaClient
{
	global $_cfg;
	return new EpochtaClient(
		(string)($_cfg['SMS_EP_PublicKey'] ?? ''),
		(string)($_cfg['SMS_EP_PrivateKey'] ?? '')
	);
}

function smsSend($q)
{
	try
	{
		$result = epochtaClient()->send(
			(string)($q['qFrom'] ?: ($GLOBALS['_cfg']['SMS_From'] ?? '')),
			(string)$q['qText'],
			(string)$q['qTo'],
			!empty($q['qTest'])
		);
		return array(
			$result['id'],
			'OK',
			smsCount((string)$q['qText']),
			$result['price'],
		);
	}
	catch (Throwable $exception)
	{
		return array(0, $exception->getMessage(), 0, 0);
	}
}

function smsCheck($ids)
{
	try
	{
		return epochtaClient()->deliveryStatuses((array)$ids);
	}
	catch (Throwable)
	{
		return null;
	}
}

?>
