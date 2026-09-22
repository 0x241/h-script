<?php

use HScript\Http\ApiRequest;
use HScript\Http\ApiResponse;

require dirname(__DIR__) . '/bootstrap.php';
telemetryApiRequireIngestion();
telemetryApiRequireMethod(array('POST'), true);
telemetryApiApplyRateLimit('telemetry-ip', ApiRequest::clientIp());
$token = telemetryApiBearer();
$input = telemetryApiInput();
$payload = telemetryApiValidate([$telemetryValidator, 'registration'], $input);

$result = $telemetryRepository->register($payload, $token, ApiRequest::clientIp());
if (in_array($result['state'], array('identity_conflict', 'token_conflict'), true))
	telemetryApiReject($result['state'], 'Installation identity or token is already registered', 409);

$cache->delete('telemetry:public-metrics');
ApiResponse::success(array(
	'installation_id' => $payload['installation_id'],
	'state' => $result['state'],
	'dns_status' => $result['dns_status'],
	'dns_checked_at' => $result['dns_checked_at'],
	'dns_error_code' => $result['dns_error_code'],
	'data_classification' => 'self-reported',
	'public_metrics' => $telemetryRepository->publicMetrics(),
), $result['state'] === 'created' ? 201 : 200);
