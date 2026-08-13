<?php

use HScript\Util\StringHelper;

use HScript\Queue\JobQueue;
use HScript\Template\View;

function smsLoadLib()
{
	global $_cfg;
	$provider = (int)($_cfg['SMS_Prov'] ?? 0);
	if ($provider === 1)
		require_once('module/sms/ePochta/lib.php');
	else
		require_once('module/sms/no/lib.php');
}

// uid: 0 - system
// mode is retained for call-site compatibility; SMS delivery is always queued
function smsPush($uid, $to, $message, $from = '', $use_translit = false, $mode = 2)
{
	global $jobQueue;
	$to = preg_replace('|[^\d]|', '', $to);
	if (StringHelper::textLen($to) < 11)
		return 'to_wrong';
	$message = strip_tags($message);
	if (StringHelper::sEmpty($message))
		return 'msg_empty';
	if (StringHelper::textLen($message) > 1600)
		return 'msg_too_long';
	if (!($jobQueue instanceof JobQueue))
		return 'queue_unavailable';
	return $jobQueue->dispatch('sms', array(
		'uid' => (int)$uid,
		'from' => (string)$from,
		'to' => $to,
		'text' => $message,
		'translit' => (bool)$use_translit,
		'test' => $mode === 0,
	), 3);
}

function smsSendJob(array $payload, int $jobId): array
{
	smsLoadLib();
	$legacyJob = array(
		'qID' => $jobId,
		'quID' => (int)($payload['uid'] ?? 0),
		'qFrom' => (string)($payload['from'] ?? ''),
		'qTo' => (string)($payload['to'] ?? ''),
		'qText' => (string)($payload['text'] ?? ''),
		'qTranslit' => !empty($payload['translit']) ? 1 : 0,
		'qTest' => !empty($payload['test']) ? 1 : 0,
	);
	$result = smsSend($legacyJob);
	if ($result === null)
		throw new RuntimeException('SMS provider timeout');
	if (empty($result[0]))
		throw new RuntimeException((string)($result[1] ?? 'SMS provider rejected the message'));
	return array(
		'provider_id' => (string)$result[0],
		'provider_status' => (string)($result[1] ?? 'OK'),
		'delivery_status' => 'sent',
		'parts' => (int)($result[2] ?? 0),
		'price' => (float)($result[3] ?? 0),
	);
}

function smsUpdateDeliveryStatuses(int $limit = 20): int
{
	global $db, $jobQueue;
	$limit = max(0, $limit);
	if ($limit === 0 || !($jobQueue instanceof JobQueue))
		return 0;
	$jobs = $db->fetchRows($db->select(
		'Jobs',
		'jID, jPayload',
		'jType=? and jState=?d and jPayload LIKE ?',
		array('sms', JobQueue::STATE_DONE, '%"delivery_status":"sent"%'),
		'jDTS, jID',
		$limit
	));
	if (!$jobs)
		return 0;
	$jobByProviderId = array();
	$payloads = array();
	foreach ($jobs as $job)
	{
		$payload = JobQueue::decodePayload((string)$job['jPayload']);
		$providerId = (string)($payload['result']['provider_id'] ?? '');
		if ($providerId === '')
			continue;
		$jobId = (int)$job['jID'];
		$jobByProviderId[$providerId] = $jobId;
		$payloads[$jobId] = $payload;
	}
	if (!$jobByProviderId)
		return 0;
	smsLoadLib();
	$statuses = smsCheck(array_keys($jobByProviderId));
	if (!is_array($statuses))
		return 0;
	$updated = 0;
	foreach ($statuses as $providerId => $status)
	{
		if (!isset($jobByProviderId[(string)$providerId]))
			continue;
		$jobId = $jobByProviderId[(string)$providerId];
		$payload = $payloads[$jobId];
		$payload['result']['provider_status'] = (string)$status;
		if ($status === 'OK')
			$payload['result']['delivery_status'] = 'delivered';
		elseif (in_array($status, array('NOT_DELIVERED', 'INVALID_PHONE_NUMBER', 'INVALID_DESTINATION_ADDRESS', 'NOT_ALLOWED', 'SPAM'), true))
			$payload['result']['delivery_status'] = 'failed';
		if ($jobQueue->updatePayload($jobId, $payload))
			$updated++;
	}
	return $updated;
}

function smsCount($text) // 70/63/66/66..   160/145/152/152..
{
	$na = (strlen($text) != mb_strlen($text)); // non-ASCII
	$l = mb_strlen($text);
	if (($l -= ($na ? 70 : 160)) <= 0)
		return 1;
	if (($l -= ($na ? 63 : 145)) <= 0)
		return 2;
	return 2 + ceil($l / ($na ? 66 : 152));
}

function smsToUser($uid, $to, $section, $consts = array(), $lang = '', $fname = 'sms')
{
	global $_GS, $_cfg;
	$lang = View::getLang($lang);
	$txt = View::loadText($section, $fname, $lang);
	if (!$txt["$section.message"])
		return false;
	$hdr = View::loadText('_header', $fname, $lang);
	$ftr = View::loadText('_footer', $fname, $lang);
	$consts['date'] = View::timeToStr(time(), 0, $lang);
	$consts['ip'] = $_GS['client_ip'];
	$consts['rooturl'] = $_GS['root_url'];
	$consts['sitename'] = $_cfg['Sys_SiteName'];
	View::prepVal($consts, 2);
//	StringHelper::textVarReplace($txt["$section.topic"], $consts)
	return smsPush($uid, $to, StringHelper::textVarReplace($txt["$section.message"], $consts));
}

?>
