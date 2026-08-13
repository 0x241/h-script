<?php

use HScript\Telemetry\PublicStats;
use HScript\Telemetry\TelemetryReporter;

$publicStats = null;
if (!empty($_cfg['Telemetry_SharePublicStats']))
{
	useLib('depo');
	$publicStats = PublicStats::fromDepositStats(depoGetStat(), $_currs);
}

$telemetryReporter = new TelemetryReporter(
	$db,
	$_cfg,
	(string)($_GS['domain'] ?? '')
);
$telemetryReporter->report($publicStats);
