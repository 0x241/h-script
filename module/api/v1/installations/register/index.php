<?php

use HScript\Http\ApiRequest;
use HScript\Http\ApiResponse;

require dirname(__DIR__) . '/bootstrap.php';
telemetryApiRequireMethod(array('POST'));
$input = telemetryApiInput();
$token = telemetryApiBearer();
$installationId = telemetryApiInstallationId($input);

$state = $telemetryRepository->register(array(
	'installation_id' => $installationId,
	'domain' => telemetryApiDomain($input),
	'version' => telemetryApiVersion($input),
	'installed_at' => telemetryApiInstalledAt($input),
	'stats_consent' => telemetryApiBool($input, 'stats_consent'),
), $token, ApiRequest::clientIp());
if ($state === 'identity_conflict')
	ApiResponse::error('identity_conflict', 'Installation identity is already registered', 409);

$cache->delete('telemetry:public-metrics');
ApiResponse::success(array(
	'installation_id' => $installationId,
	'state' => $state,
	'public_metrics' => $telemetryRepository->publicMetrics(),
), $state === 'created' ? 201 : 200);
