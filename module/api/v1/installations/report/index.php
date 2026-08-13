<?php

use HScript\Http\ApiRequest;
use HScript\Http\ApiResponse;

require dirname(__DIR__) . '/bootstrap.php';
telemetryApiRequireMethod(array('POST'));
$input = telemetryApiInput();
$token = telemetryApiBearer();
$installationId = telemetryApiInstallationId($input);
$version = telemetryApiVersion($input);
$statsConsent = telemetryApiBool($input, 'stats_consent');
$stats = telemetryApiPublicStats($input, $statsConsent);

if (!$telemetryRepository->report(
	$installationId,
	$token,
	$version,
	$statsConsent,
	$stats,
	ApiRequest::clientIp()
))
	ApiResponse::error('invalid_token', 'The installation token is invalid', 401);

$cache->delete('telemetry:public-metrics');
ApiResponse::success(array(
	'installation_id' => $installationId,
	'accepted_at' => time(),
	'public_metrics' => $telemetryRepository->publicMetrics(),
));
