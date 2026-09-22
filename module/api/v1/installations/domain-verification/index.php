<?php

use HScript\Http\ApiRequest;
use HScript\Http\ApiResponse;
use HScript\Telemetry\DomainProofService;
use HScript\Telemetry\PassiveDnsResolver;

require dirname(__DIR__) . '/bootstrap.php';
telemetryApiRequireIngestion();
telemetryApiRequireMethod(array('POST'), true);
telemetryApiApplyRateLimit('telemetry-ip', ApiRequest::clientIp());
$token = telemetryApiBearer();
telemetryApiApplyRateLimit('telemetry-installation-token', hash('sha256', $token));
$input = telemetryApiInput();
$keys = array_keys($input);
sort($keys);
if ($keys !== array('domain', 'installation_id', 'operation')
	|| !is_string($input['installation_id'])
	|| !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $input['installation_id'])
	|| !is_string($input['domain']) || $input['domain'] === ''
	|| PassiveDnsResolver::normalizeDomain($input['domain']) !== $input['domain']
	|| !in_array($input['operation'], array('challenge', 'verify'), true))
	telemetryApiReject('proof_input_invalid', 'Invalid domain verification request', 422);

$result = (new DomainProofService($db))->execute($input['operation'], $input['installation_id'], $input['domain'], $token);
if ($result['state'] === 'invalid_token')
	telemetryApiReject('invalid_token', 'The installation token is invalid', 401);
if ($result['state'] === 'domain_registration_required')
	telemetryApiReject('domain_registration_required', 'Register the domain first', 409);
if ($result['state'] === 'verified')
{
	$cache->delete('telemetry:public-metrics');
	$result['public_metrics'] = $telemetryRepository->publicMetrics();
}
ApiResponse::success($result);
