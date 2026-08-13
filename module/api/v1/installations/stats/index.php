<?php

use HScript\Http\ApiRequest;
use HScript\Http\ApiResponse;
use HScript\Telemetry\TelemetryServiceTokenRepository;

require dirname(__DIR__) . '/bootstrap.php';
telemetryApiRequireMethod(array('GET'));

$providedToken = ApiRequest::bearerToken();
$serviceToken = $providedToken === null
	? null
	: (new TelemetryServiceTokenRepository($db))->authenticate(
		$providedToken,
		ApiRequest::clientIp()
	);
if ($serviceToken === null || $serviceToken['scope'] !== TelemetryServiceTokenRepository::SCOPE)
{
	if (!headers_sent())
		header('WWW-Authenticate: Bearer realm="H-Script installation statistics"');
	ApiResponse::error('invalid_token', 'A valid service token is required', 401);
}

$telemetryTokenRate = $telemetryRateLimiter->consume(
	'telemetry-service-token',
	(string)$serviceToken['token_id']
);
ApiResponse::setRateLimitHeaders(
	$telemetryTokenRate['limit'],
	$telemetryTokenRate['remaining'],
	$telemetryTokenRate['reset']
);
if (!$telemetryTokenRate['allowed'])
{
	if (!headers_sent())
		header('Retry-After: ' . $telemetryTokenRate['retry_after']);
	ApiResponse::error('rate_limit_exceeded', 'Too many requests', 429);
}

ApiResponse::success($telemetryRepository->dashboard());
