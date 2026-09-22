<?php

use HScript\Http\ApiRequest;
use HScript\Http\ApiResponse;

require dirname(__DIR__) . '/bootstrap.php';
telemetryApiRequireIngestion();
telemetryApiRequireMethod(array('POST'), true);
telemetryApiApplyRateLimit('telemetry-ip', ApiRequest::clientIp());
$token = telemetryApiBearer();
telemetryApiApplyRateLimit('telemetry-installation-token', hash('sha256', $token));
$input = telemetryApiInput();
$payload = telemetryApiValidate([$telemetryValidator, 'report'], $input);

$result = $telemetryRepository->report($payload, $token, ApiRequest::clientIp());
if ($result['state'] === 'invalid_token')
	telemetryApiReject('invalid_token', 'The installation token is invalid', 401);
if ($result['state'] === 'domain_registration_required')
	telemetryApiReject('domain_registration_required', 'Register the changed domain before reporting', 409);
if ($result['state'] === 'replay_rejected')
	telemetryApiReject('replay_rejected', 'An older or conflicting report was rejected', 409);

$cache->delete('telemetry:public-metrics');
ApiResponse::success(array(
	'installation_id' => $payload['installation_id'],
	'accepted_at' => time(),
	'state' => $result['state'],
	'dns_status' => $result['dns_status'],
	'dns_checked_at' => $result['dns_checked_at'],
	'dns_error_code' => $result['dns_error_code'],
	'data_classification' => 'self-reported',
	'public_metrics' => $telemetryRepository->publicMetrics(),
));
