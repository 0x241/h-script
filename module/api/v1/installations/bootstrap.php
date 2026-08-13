<?php

use HScript\Http\ApiRateLimiter;
use HScript\Http\ApiRequest;
use HScript\Http\ApiResponse;
use HScript\Telemetry\CollectorMode;
use HScript\Telemetry\InstallationRepository;

$_smode = 2;
$_auth = 0;
require_once('module/auth.php');

if (!CollectorMode::enabled($_cfg, (string)($_GS['domain'] ?? '')))
	ApiResponse::error('route_not_found', 'API route not found', 404);

$telemetryRateLimiter = new ApiRateLimiter(
	$db,
	max(1, (int)($_cfg['telemetry_rate_limit'] ?? 30))
);
$telemetryRate = $telemetryRateLimiter->consume('telemetry-ip', ApiRequest::clientIp());
ApiResponse::setRateLimitHeaders(
	$telemetryRate['limit'],
	$telemetryRate['remaining'],
	$telemetryRate['reset']
);
if (!$telemetryRate['allowed'])
{
	if (!headers_sent())
		header('Retry-After: ' . $telemetryRate['retry_after']);
	ApiResponse::error('rate_limit_exceeded', 'Too many requests', 429);
}

$telemetryRepository = new InstallationRepository($db);

function telemetryApiRequireMethod(array $methods): string
{
	$method = ApiRequest::method();
	if (!in_array($method, $methods, true))
	{
		if (!headers_sent())
			header('Allow: ' . implode(', ', $methods));
		ApiResponse::error('method_not_allowed', 'Method not allowed', 405);
	}
	return $method;
}

function telemetryApiInput(): array
{
	try
	{
		return ApiRequest::json();
	}
	catch (InvalidArgumentException $exception)
	{
		$status = $exception->getMessage() === 'request_too_large' ? 413 : 400;
		ApiResponse::error($exception->getMessage(), 'Invalid request body', $status);
	}
	return array();
}

function telemetryApiBearer(): string
{
	$token = ApiRequest::bearerToken();
	if ($token === null || !preg_match('/^hsi_[a-f0-9]{64}$/', $token))
	{
		if (!headers_sent())
			header('WWW-Authenticate: Bearer realm="H-Script installation registry"');
		ApiResponse::error('invalid_token', 'A valid installation token is required', 401);
	}
	return $token;
}

function telemetryApiInstallationId(array $input): string
{
	$id = strtolower(trim((string)($input['installation_id'] ?? '')));
	if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id))
		ApiResponse::error('validation_error', 'Invalid installation id', 422);
	return $id;
}

function telemetryApiDomain(array $input): string
{
	$domain = strtolower(rtrim(trim((string)($input['domain'] ?? '')), '.'));
	if (
		$domain === ''
		|| strlen($domain) > 253
		|| !preg_match('/^[a-z0-9.-]+(?::[0-9]{1,5})?$/', $domain)
	)
		ApiResponse::error('validation_error', 'Invalid installation domain', 422);
	return $domain;
}

function telemetryApiVersion(array $input): string
{
	$version = trim((string)($input['version'] ?? ''));
	if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?$/', $version) || strlen($version) > 32)
		ApiResponse::error('validation_error', 'Invalid application version', 422);
	return $version;
}

function telemetryApiInstalledAt(array $input): int
{
	$value = filter_var($input['installed_at'] ?? null, FILTER_VALIDATE_INT);
	if ($value === false || $value < 946684800 || $value > time() + 86400)
		ApiResponse::error('validation_error', 'Invalid installation date', 422);
	return (int)$value;
}

function telemetryApiBool(array $input, string $field): bool
{
	$value = $input[$field] ?? false;
	if (!is_bool($value))
		ApiResponse::error('validation_error', 'Invalid boolean field', 422, array('field' => $field));
	return $value;
}

function telemetryApiPublicStats(array $input, bool $consent): ?array
{
	if (!$consent || !array_key_exists('public_stats', $input))
		return null;
	$stats = $input['public_stats'];
	if (!is_array($stats) || array_is_list($stats))
		ApiResponse::error('validation_error', 'Invalid public statistics', 422);

	foreach (array('worked_days', 'users_total', 'users_online', 'active_deposits', 'closed_deposits') as $field)
	{
		$value = filter_var($stats[$field] ?? 0, FILTER_VALIDATE_INT);
		if ($value === false || $value < 0)
			ApiResponse::error('validation_error', 'Invalid public statistics', 422, array('field' => $field));
		$stats[$field] = (int)$value;
	}
	foreach (array('cash_in_base', 'cash_out_base', 'referral_paid_base', 'reinvested_base') as $field)
	{
		$value = $stats[$field] ?? 0;
		if (!is_numeric($value) || (float)$value < 0)
			ApiResponse::error('validation_error', 'Invalid public statistics', 422, array('field' => $field));
		$stats[$field] = (float)$value;
	}

	$baseCurrency = strtoupper(trim((string)($stats['base_currency'] ?? '')));
	if ($baseCurrency !== '' && !preg_match('/^[A-Z0-9]{2,10}$/', $baseCurrency))
		ApiResponse::error('validation_error', 'Invalid base currency', 422);
	$stats['base_currency'] = $baseCurrency;

	foreach (array('cash_in_by_currency', 'cash_out_by_currency') as $field)
	{
		$values = $stats[$field] ?? array();
		if (!is_array($values) || count($values) > 20)
			ApiResponse::error('validation_error', 'Invalid currency statistics', 422, array('field' => $field));
		$normalized = array();
		foreach ($values as $currency => $amount)
		{
			$currency = strtoupper(trim((string)$currency));
			if (!preg_match('/^[A-Z0-9]{2,10}$/', $currency) || !is_numeric($amount) || (float)$amount < 0)
				ApiResponse::error('validation_error', 'Invalid currency statistics', 422, array('field' => $field));
			$normalized[$currency] = (float)$amount;
		}
		$stats[$field] = $normalized;
	}
	return $stats;
}
