<?php

use HScript\Http\ApiRequest;
use HScript\Http\ApiResponse;
use HScript\Observability\CorrelationContext;
use HScript\Telemetry\InstallationListQuery;
use HScript\Telemetry\TelemetryServiceTokenRepository;

require dirname(__DIR__) . '/bootstrap.php';
telemetryApiRequireMethod(array('GET'));
telemetryApiRequireSchema();
telemetryApiApplyRateLimit('telemetry-ip', ApiRequest::clientIp());

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
CorrelationContext::setActorClass('service');

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

$queryInput = array();
foreach (array('page', 'per_page', 'q', 'version', 'connection', 'sharing', 'dns_status', 'ip') as $field)
	if (array_key_exists($field, $_GET))
		$queryInput[$field] = $_GET[$field];
try
{
	$listQuery = InstallationListQuery::fromArray($queryInput);
}
catch (InvalidArgumentException $exception)
{
	$field = str_replace('query_invalid:', '', $exception->getMessage());
	ApiResponse::error('validation_error', 'Invalid query parameter', 422, array('field' => $field));
}
$dashboard = $telemetryRepository->dashboard($listQuery, false);
ApiResponse::success($dashboard, 200, array('pagination' => $dashboard['pagination']));
