<?php

use HScript\Observability\CorrelationContext;
use HScript\Observability\OperationalStateRepository;
use HScript\Observability\StructuredLogger;
use HScript\Cron\ModuleSchedule;

/** Called after either scheduler-token or administrator/CSRF authorization. */
function opCronRun(): string
{
	global $_cfg, $_GS, $_currs, $_oncron, $db, $jobQueue;
	ignore_user_abort(true);
	if (function_exists('set_time_limit')) @set_time_limit(60);
	CorrelationContext::setActorClass('system');
	$cronStarted = microtime(true);
	$cronState = new OperationalStateRepository(dirname(__DIR__, 2));
	if (empty($_cfg['Cron_Enabled'])) {
		$cronState->recordCron('disabled');
		StructuredLogger::event('notice', 'cron', 'cron_disabled', 'noop');
		return 'disabled';
	}
	$schedule = new ModuleSchedule($db);
	if (!$schedule->acquire()) return 'busy';
	try {
		$cronState->recordCron('started');
		StructuredLogger::event('info', 'cron', 'cron_started', 'started');
		$failed = false;
		$cron_ts = timeToStamp();
		// Accruals and local checks must not wait for rate providers or delivery.
		$cronModules = array_merge(array_fill_keys(['depo', 'integrity', 'telemetry'], 0), $_oncron);
		if (isset($cronModules['balance'])) {
			$balanceInterval = $cronModules['balance'];
			unset($cronModules['balance']);
			$cronModules['balance'] = $balanceInterval;
		}
		foreach ($cronModules as $cronModule => $cronInterval) {
			if ($cronInterval <= 0 || !is_file($cronFile = $_GS['module_dir'] . $cronModule . '/oncron.php')) continue;
			if (microtime(true) - $cronStarted > 50) { $failed = true; break; }
			if ($schedule->claim($cronModule, $cron_ts, timeToStamp(time() + $cronInterval * HS2_UNIX_MINUTE))) {
				try { include $cronFile; }
				catch (Throwable $failure) {
					$failed = true;
					StructuredLogger::event('error', 'cron', 'cron_module_failed', 'failure', 0, '', ['module' => $cronModule, 'error_class' => $failure::class]);
				}
			}
		}
		if (microtime(true) - $cronStarted <= 50) require __DIR__ . '/jobs.php';
		if ($failed) throw new RuntimeException('cron_incomplete');
		$cronState->recordCron('success');
		StructuredLogger::event('info', 'cron', 'cron_completed', 'success', (int)round((microtime(true) - $cronStarted) * 1000));
		return 'success';
	} catch (Throwable $failure) {
		$cronState->recordCron('failure');
		StructuredLogger::event('error', 'cron', 'cron_failed', 'failure', 0, '', array('error_class' => $failure::class));
		throw $failure;
	} finally { $schedule->release(); }
}
