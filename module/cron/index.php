<?php

use HScript\Security\CronAuthentication;

if (!CronAuthentication::authorized($_SERVER, getenv('CRON_TOKEN')))
{
	http_response_code(403);
	exit;
}

ignore_user_abort(true);
if (function_exists('set_time_limit')) {
	try {
		set_time_limit(60);
	} catch (Throwable $e) {
		error_log($e->getMessage());
	}
}
header('Connection: close');
header('Content-Length: 0');
if (ob_get_level() > 0) ob_end_clean();
if (ob_get_level() > 0) ob_end_flush();
flush();

$_smode = 2;
require_once('module/auth.php');
useLib('cron');
try { opCronRun(); } catch (Throwable) { /* Failure is recorded by the common runner. */ }
