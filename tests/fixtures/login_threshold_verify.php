<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

if (getenv('H_SCRIPT_LOGIN_THRESHOLD_TEST') !== '1' || getenv('DB_NAME') !== 'fixture') exit(2);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$db = new HScript\Database\Connection();
if (!$db->open(getenv('DB_HOST'), getenv('DB_NAME'), getenv('DB_USER'), getenv('DB_PASSWORD'))) exit(1);
foreach (array('policy-user' => array(2, 2), 'policy-peer' => array(100, 2), 'policy-demo' => array(null, 0), 'policy-admin-peer' => array(null, 0), 'policy-owner' => array(null, 0)) as $login => [$threshold, $counter])
{
	$row = $db->fetch1Row($db->select('Users LEFT JOIN AddInfo ON auID=uID', 'aFailedLoginThreshold, uBFC', 'uLogin=?', array($login)));
	if (($row['aFailedLoginThreshold'] === null ? null : (int)$row['aFailedLoginThreshold']) !== $threshold || (int)$row['uBFC'] !== $counter)
		throw new RuntimeException('Browser edits changed an unauthorized account or independent attempt counter');
}
$events = array();
foreach (glob(dirname(__DIR__, 2) . '/logs/observability.ndjson*') ?: array() as $path)
{
	if (!is_file($path) || str_ends_with($path, '.lock') || str_ends_with($path, '.state.json')) continue;
	foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $line)
	{
		$record = json_decode($line, true);
		if (($record['event_code'] ?? '') === 'account_login_threshold_changed') $events[] = $record;
	}
}
foreach (array('account_owner', 'administrator') as $reason)
	if (!array_filter($events, static fn(array $event): bool => ($event['context']['reason'] ?? '') === $reason)) throw new RuntimeException('Successful policy edit audit is missing');
foreach ($events as $event)
{
	$encoded = json_encode($event);
	if (str_contains($encoded, '@fixture.invalid') || str_contains($encoded, 'policy-user') || str_contains($encoded, 'synthetic-login')) throw new RuntimeException('Policy audit exposed account credentials');
}
$cronEnabled = $db->fetch1Row($db->select('Cfg', 'Val', 'Module=? AND Prop=?', array('Cron', 'Enabled')));
if (($cronEnabled['Val'] ?? null) !== '0') throw new RuntimeException('Cron disabled setting was not persisted');
foreach (['balance' => 300, 'depo' => 60, 'integrity' => 86400, 'telemetry' => 86400] as $module => $interval) {
    $deadline = $db->fetch1($db->query("SELECT Val FROM Cfg WHERE Module='Cron' AND Prop=?", [$module]));
    $last = $db->fetch1($db->query("SELECT Val FROM Cfg WHERE Module='Cron' AND Prop=?", ['Last_' . $module]));
    if (abs(time() - stampToTime($last)) > 60) throw new RuntimeException('Missing module start timestamp: ' . $module);
    $due = stampToTime($deadline);
    // The incremental integrity scanner reschedules unfinished work after five minutes.
    $valid = abs($due - time() - $interval) <= 60 || ($module === 'integrity' && abs($due - time() - 300) <= 60);
    if (!$valid)
        throw new RuntimeException('Manual cron skipped a module or settings reset/forged its schedule: ' . $module);
}
$cronEvents = array();
foreach (glob(dirname(__DIR__, 2) . '/logs/observability.ndjson*') ?: array() as $path) {
    if (!is_file($path) || str_ends_with($path, '.lock') || str_ends_with($path, '.state.json')) continue;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $line) {
        $event = json_decode($line, true);
        if (in_array($event['event_code'] ?? '', array('cron_manual_requested', 'cron_completed', 'cron_disabled'), true)) $cronEvents[] = $event['event_code'];
    }
}
foreach (array('cron_manual_requested', 'cron_completed', 'cron_disabled') as $code)
    if (!in_array($code, $cronEvents, true)) throw new RuntimeException('Cron audit event missing: ' . $code);
$accrual = $db->fetch1Row($db->select('Deps JOIN Users ON uID=duID JOIN Wallets ON wuID=uID AND wcID=dcID',
    'dState,dNPer,dZP,wBal,wLock,wOut,uBal', 'uLogin=?', array('ui-user')));
if ($accrual !== array('dState' => 1, 'dNPer' => 1, 'dZP' => '2.000000', 'wBal' => '2.000000',
    'wLock' => '100.000000', 'wOut' => '0.000000', 'uBal' => '102.000000'))
    throw new RuntimeException('Manual cron did not execute the due deposit once with correct balances');
echo "Login policy browser DB ownership, independent counters and redacted audit verified.\n";
echo "Cron all four modules, exactly-once due deposit, balances, preserved schedules, disabled configuration and audit verified.\n";
