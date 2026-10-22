<?php

use HScript\Template\View;

$_auth = 90;
require_once('module/auth.php');

if (View::sendedForm('run')) {
	View::checkFormSecurity();
	if ($_GS['demo'] && $_user['uLevel'] < 99) {
		http_response_code(403);
		xSysStop('Access denied', true);
	}
	useLib('cron');
	HScript\Observability\StructuredLogger::event('info', 'cron', 'cron_manual_requested', 'requested');
	try { View::setPage('cron_run_status', opCronRun()); }
	catch (Throwable) { View::setPage('cron_run_status', 'failure'); }
	finally { HScript\Observability\CorrelationContext::setActorClass('administrator'); }
}
if (View::sendedForm()) {
	View::checkFormSecurity();
	if ($_GS['demo'] && $_user['uLevel'] < 99) {
		http_response_code(403);
		xSysStop('Access denied', true);
	}
	// Save only the switch, preserving module claims and ignoring forged timers.
	if ($db->replace('Cfg', ['Module' => 'Cron', 'Prop' => 'Enabled', 'Val' => empty($_IN['Enabled']) ? 0 : 1]) === false)
		throw new RuntimeException('cron_settings_storage_failed');
	View::showInfo('Saved');
}
$module = 'Cron'; // Set after authentication, whose admin menu uses $module as a loop variable.
require_once('module/admin/setup.php');

$list = array();
$cronConfig = $db->fetchIDRows($db->select('Cfg', '*', 'Module=?', ['Cron']), 'Val', 'Prop');
foreach ($_oncron as $m => $n) {
	$last = $cronConfig['Last_' . $m] ?? null;
	// Older installations stored only the next scheduled deadline.
	if (!$last && !empty($cronConfig[$m])) $last = timeToStamp(stampToTime($cronConfig[$m]) - $n * HS2_UNIX_MINUTE);
	$list[] = array(
		'module' => $m,
		'interval' => $n,
		'has_run' => !empty($last),
		'minutes' => !empty($last)
			? max(0, floor(subStamps($last) / HS2_UNIX_MINUTE))
			: 0
	);
}
View::setPage('cronlist', $list);
View::showPage();

?>
