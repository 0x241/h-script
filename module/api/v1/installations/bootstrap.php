<?php

use HScript\Http\ApiRateLimiter;
use HScript\Http\ApiRequest;
use HScript\Http\ApiResponse;
use HScript\Observability\CorrelationContext;
use HScript\Telemetry\CollectorMode;
use HScript\Telemetry\CollectorSchema;
use HScript\Telemetry\InstallationRepository;
use HScript\Telemetry\TelemetryIngestionCounterRepository;
use HScript\Telemetry\TelemetryPayloadValidator;
use HScript\Telemetry\TelemetryValidationException;

$_smode = 2;
$_auth = 0;
require_once('module/auth.php');

$telemetryDomain = (string)($_GS['domain'] ?? '');
if (!CollectorMode::enabled($_cfg, $telemetryDomain))
	ApiResponse::error('route_not_found', 'API route not found', 404);

$telemetrySchemaReady = CollectorSchema::ready($db);
$telemetryRepository = new InstallationRepository($db);
$telemetryValidator = new TelemetryPayloadValidator();
$telemetryRejections = $telemetrySchemaReady ? new TelemetryIngestionCounterRepository($db) : null;
$telemetryRateLimiter = null;

function telemetryApiRequireSchema(): void
{
	global $telemetrySchemaReady;
	if (!$telemetrySchemaReady)
		ApiResponse::error('collector_schema_unavailable', 'Collector schema is not ready', 503);
}

function telemetryApiRequireIngestion(): void
{
	global $_cfg, $telemetryDomain, $telemetrySchemaReady;
	if (!CollectorMode::ingestionFlagEnabled($_cfg))
		ApiResponse::error('ingestion_disabled', 'Telemetry ingestion is disabled', 503);
	if (CollectorMode::ingestionEnabled($_cfg, $telemetryDomain, $telemetrySchemaReady))
		return;
	if (!$telemetrySchemaReady)
		ApiResponse::error('collector_schema_unavailable', 'Collector schema is not ready', 503);
	ApiResponse::error('ingestion_disabled', 'Telemetry ingestion is disabled', 503);
}

function telemetryApiRequireMethod(array $methods, bool $recordRejection = false): string
{
	$method = ApiRequest::method();
	if (!in_array($method, $methods, true))
	{
		if (!headers_sent())
			header('Allow: ' . implode(', ', $methods));
		telemetryApiReject('method_not_allowed', 'Method not allowed', 405, array(), $recordRejection);
	}
	return $method;
}

function telemetryApiInput(): array
{
	try
	{
		return ApiRequest::json(TelemetryPayloadValidator::MAX_BODY_BYTES, true);
	}
	catch (InvalidArgumentException $exception)
	{
		$code = $exception->getMessage();
		$status = $code === 'request_too_large' ? 413 : ($code === 'content_type_invalid' ? 415 : 400);
		telemetryApiReject($code, 'Invalid request body', $status);
	}
}

function telemetryApiBearer(): string
{
	$token = ApiRequest::bearerToken();
	if ($token === null || !preg_match('/^hsi_[a-f0-9]{64}$/', $token))
	{
		if (!headers_sent())
			header('WWW-Authenticate: Bearer realm="H-Script installation registry"');
		telemetryApiReject('invalid_token', 'A valid installation token is required', 401);
	}
	CorrelationContext::setActorClass('service');
	return $token;
}

function telemetryApiValidate(callable $validator, array $input): array
{
	try
	{
		$result = $validator($input);
		return is_array($result) ? $result : array();
	}
	catch (TelemetryValidationException $exception)
	{
		$details = $exception->field() !== '' ? array('field' => $exception->field()) : array();
		telemetryApiReject($exception->errorCode(), $exception->getMessage(), 422, $details);
	}
}

function telemetryApiApplyRateLimit(string $dimension, string $identifier): void
{
	global $db, $_cfg, $telemetryRateLimiter;
	$telemetryRateLimiter ??= new ApiRateLimiter($db, max(1, min(10000, (int)($_cfg['telemetry_rate_limit'] ?? 30))));
	$rate = $telemetryRateLimiter->consume($dimension, $identifier);
	ApiResponse::setRateLimitHeaders($rate['limit'], $rate['remaining'], $rate['reset']);
	if (!$rate['allowed'])
	{
		if (!headers_sent())
			header('Retry-After: ' . $rate['retry_after']);
		telemetryApiReject('rate_limit_exceeded', 'Too many requests', 429);
	}
}

function telemetryApiReject(
	string $code,
	string $message,
	int $status,
	array $details = array(),
	bool $record = true
): never {
	global $telemetryRejections;
	if ($record && $telemetryRejections instanceof TelemetryIngestionCounterRepository)
		$telemetryRejections->record($code);
	ApiResponse::error($code, $message, $status, $details);
}
