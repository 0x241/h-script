<?php

use HScript\Http\ApiRateLimiter;
use HScript\Http\ApiRequest;
use HScript\Http\ApiResponse;
use HScript\Http\ApiTokenRepository;
use HScript\Observability\CorrelationContext;
use HScript\Observability\StructuredLogger;
use HScript\Finance\Decimal;
use HScript\Finance\DecimalOutput;

$_smode = 2;
$_auth = 0;
require_once('module/auth.php');

try
{
	if (empty($_cfg['API_Enabled']))
		ApiResponse::error('api_disabled', 'The API is disabled', 503);

	$apiIpRateLimiter = new ApiRateLimiter($db, (int)$_cfg['API_RateLimitIP']);
	$apiIpLimit = $apiIpRateLimiter->consume('ip', ApiRequest::clientIp());
	ApiResponse::setRateLimitHeaders(
		$apiIpLimit['limit'],
		$apiIpLimit['remaining'],
		$apiIpLimit['reset']
	);
	if (!$apiIpLimit['allowed'])
	{
		if (!headers_sent())
			header('Retry-After: ' . $apiIpLimit['retry_after']);
		ApiResponse::error('rate_limit_exceeded', 'Too many requests', 429);
	}

	$apiBearerToken = ApiRequest::bearerToken();
	if ($apiBearerToken === null)
	{
		if (!headers_sent())
			header('WWW-Authenticate: Bearer realm="H-Script API"');
		ApiResponse::error('authentication_required', 'A Bearer token is required', 401);
	}

	$apiTokenRateLimiter = new ApiRateLimiter($db, (int)$_cfg['API_RateLimitToken']);
	$apiTokenLimit = $apiTokenRateLimiter->consume('token', ApiTokenRepository::hash($apiBearerToken));
	ApiResponse::setRateLimitHeaders(
		min($apiIpLimit['limit'], $apiTokenLimit['limit']),
		min($apiIpLimit['remaining'], $apiTokenLimit['remaining']),
		min($apiIpLimit['reset'], $apiTokenLimit['reset'])
	);
	if (!$apiTokenLimit['allowed'])
	{
		if (!headers_sent())
			header('Retry-After: ' . $apiTokenLimit['retry_after']);
		ApiResponse::error('rate_limit_exceeded', 'Too many requests', 429);
	}

	$apiTokenRepository = new ApiTokenRepository($db);
	$apiAuth = $apiTokenRepository->authenticate($apiBearerToken, ApiRequest::clientIp());
	if ($apiAuth === null)
	{
		if (!headers_sent())
			header('WWW-Authenticate: Bearer realm="H-Script API", error="invalid_token"');
		ApiResponse::error('invalid_token', 'The Bearer token is invalid or expired', 401);
	}
	CorrelationContext::setActorClass('service');

	$_currs = $db->fetchIDRows($db->select(
		'Currs LEFT JOIN Wallets ON wcID=cID and wuID=?d',
		'*',
		'cDisabled=0',
		array($apiAuth['user_id']),
		'cID'
	), false, 'cID');
}
catch (Throwable $e)
{
	StructuredLogger::event('error', 'api', 'api_bootstrap_failed', 'failure', 0, '', array('error_class' => $e::class));
	ApiResponse::error('api_unavailable', 'The API is temporarily unavailable', 503);
}

function apiV1Require(string $scope, array $methods): void
{
	global $apiAuth;
	$method = ApiRequest::method();
	if (!in_array($method, $methods, true))
	{
		if (!headers_sent())
			header('Allow: ' . implode(', ', $methods));
		ApiResponse::error('method_not_allowed', 'Method not allowed', 405);
	}
	if (!ApiTokenRepository::allows($apiAuth, $scope))
		ApiResponse::error('insufficient_scope', 'The token does not grant the required scope', 403);
}

function apiV1Input(): array
{
	try
	{
		return ApiRequest::json();
	}
	catch (InvalidArgumentException $e)
	{
		$code = $e->getMessage();
		$messages = array(
			'request_too_large' => array('Request body is too large', 413),
			'content_type_invalid' => array('Content-Type must be application/json', 415),
			'json_invalid' => array('Request body contains invalid JSON', 400),
			'json_object_required' => array('Request body must be a JSON object', 400),
		);
		$message = $messages[$code] ?? array('Invalid request body', 400);
		ApiResponse::error($code, $message[0], $message[1]);
	}
	return array();
}

function apiV1QueryInt(string $name, int $default, int $min, int $max): int
{
	try
	{
		return ApiRequest::queryInt($name, $default, $min, $max);
	}
	catch (InvalidArgumentException $e)
	{
		ApiResponse::error('validation_error', 'Invalid query parameter', 422, array(
			'field' => $name,
			'min' => $min,
			'max' => $max,
		));
	}
	return $default;
}

function apiV1RequirePositiveInt(array $input, string $field): int
{
	$value = $input[$field] ?? null;
	if (filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value <= 0)
		ApiResponse::error('validation_error', 'Invalid request data', 422, array('field' => $field));
	return (int)$value;
}

function apiV1String(array $input, string $field, int $maxLength = 120): string
{
	$value = $input[$field] ?? '';
	if (is_array($value) || is_object($value))
		ApiResponse::error('validation_error', 'Invalid request data', 422, array('field' => $field));
	$value = trim((string)$value);
	if (mb_strlen($value) > $maxLength)
		ApiResponse::error('validation_error', 'Invalid request data', 422, array(
			'field' => $field,
			'max_length' => $maxLength,
		));
	return $value;
}

function apiV1Stamp($stamp): ?string
{
	$timestamp = stampToTime($stamp);
	return $timestamp ? gmdate('c', $timestamp) : null;
}

function apiV1OperationState(int $state): string
{
	return array(
		0 => 'awaiting_confirmation',
		1 => 'awaiting_payment',
		2 => 'processing',
		3 => 'completed',
		4 => 'rejected',
		5 => 'cancelled',
	)[$state] ?? 'unknown';
}

function apiV1Operation(array $row): array
{
	$amount = Decimal::of($row['oSum'] ?? 0);
	$fee = Decimal::of($row['oComis'] ?? 0);
	return array(
		'id' => (int)$row['oID'],
		'type' => (string)$row['oOper'],
		'currency' => array(
			'id' => (int)$row['ocID'],
			'code' => (string)($row['cCurr'] ?? ''),
			'name' => (string)($row['cName'] ?? ''),
		),
		'amount' => DecimalOutput::stored($amount),
		'fee' => DecimalOutput::stored($fee),
		'net_amount' => DecimalOutput::stored($amount->minus($fee)),
		'state' => apiV1OperationState((int)$row['oState']),
		'batch' => (string)($row['oBatch'] ?? ''),
		'memo' => (string)($row['oMemo'] ?? ''),
		'created_at' => apiV1Stamp($row['oCTS'] ?? 0),
		'completed_at' => apiV1Stamp($row['oTS'] ?? 0),
	);
}

function apiV1OperationError(string $code, int $status = 422): void
{
	$messages = array(
		'oper_disabled' => 'This operation is disabled for the selected currency',
		'sum_not_int' => 'The amount must be an integer',
		'sum_min' => 'The amount is below the allowed minimum',
		'sum_max' => 'The amount exceeds the allowed maximum',
		'sum_wrong' => 'The amount is invalid',
		'sum_precision' => 'The amount or commission has more precision than this installation can store',
		'sum_overflow' => 'The amount or commission exceeds the supported range',
		'ex_sum_wrong' => 'The exchange amount rounds to zero or is invalid',
		'ex_rate_not_set' => 'The exchange rate or adjustment is invalid',
		'ex_overflow' => 'The exchange result exceeds the supported range',
		'psys2_wrong' => 'Target currency not found',
		'bal_precision' => 'The balance change has more precision than this installation can store',
		'bal_overflow' => 'The resulting balance exceeds the supported range',
		'low_bal' => 'Insufficient available balance',
		'low_bal1' => 'Insufficient available balance',
		'low_bal2' => 'Insufficient locked balance',
		'low_bal3' => 'Insufficient outgoing balance',
		'psys_wrong' => 'Currency not found',
		'psys_empty' => 'Currency is required',
		'wallet_not_defined' => 'A withdrawal destination is not configured',
		'wd_disable' => 'Withdrawals are disabled for this account',
		'limit_exceeded' => 'The withdrawal limit has been exceeded',
		'oper_not_found' => 'Operation not found',
		'oper_state_wrong' => 'Operation state does not allow this action',
		'depo_principal_mismatch' => 'Deposit principal does not match its locked backing; reconciliation is required',
		'depo_not_saved' => 'The deposit could not be saved',
		'db_error' => 'The operation could not be saved',
	);
	ApiResponse::error($code, $messages[$code] ?? 'The operation could not be completed', $status);
}

function apiV1Currency(array $row, string $operation): array
{
	$prefix = 'c' . $operation;
	return array(
		'id' => (int)$row['cID'],
		'gateway_id' => (string)$row['cCID'],
		'code' => (string)$row['cCurr'],
		'name' => (string)$row['cName'],
		'decimals' => (int)$row['cNumDec'],
		'minimum' => DecimalOutput::stored($row[$prefix . 'Min'] ?? 0),
		'maximum' => DecimalOutput::stored($row[$prefix . 'Max'] ?? 0),
		'fee_percent' => (string)Decimal::of($row[$prefix . 'Comis'] ?? 0),
		'fee_minimum' => DecimalOutput::stored($row[$prefix . 'ComisMin'] ?? 0),
		'fee_maximum' => DecimalOutput::stored($row[$prefix . 'ComisMax'] ?? 0),
	);
}

/** Emit only after committing the operation and its safe response snapshot. */
function apiV1FinancialWrite(string $namespace, array $normalized, callable $work): never
{
    global $db, $apiAuth;
    $key = HScript\Http\ApiRequest::header('Idempotency-Key');
    try {
        HScript\Finance\IdempotencyRequest::validate($namespace, $key ?? '', (int)$apiAuth['user_id']);
    } catch (InvalidArgumentException $exception) {
        HScript\Http\ApiResponse::error('idempotency_key_invalid', 'Idempotency-Key must contain 1–128 ASCII letters, digits or : . _ -', 400);
    }
    try {
        $result = (new HScript\Finance\IdempotencyStore($db))->execute(
            $namespace, $key, (int)$apiAuth['user_id'],
            HScript\Finance\IdempotencyRequest::fingerprint($normalized), $work
        );
        header('Idempotency-Replayed: ' . ($result->replayed ? 'true' : 'false'));
        HScript\Http\ApiResponse::success($result->data, $result->status);
    } catch (HScript\Finance\IdempotencyConflict $exception) {
        HScript\Http\ApiResponse::error('idempotency_conflict', 'This Idempotency-Key was already used with different input', 409);
    } catch (HScript\Finance\FinancialOperationException $exception) {
        apiV1OperationError($exception->getMessage());
    } catch (Throwable $exception) {
        HScript\Observability\StructuredLogger::event('error', 'finance', 'financial_request_failed', 'failure', 0, '', ['error_class' => $exception::class]);
        HScript\Http\ApiResponse::error('financial_unavailable', 'The financial request could not be committed; retry with the same Idempotency-Key', 503);
    }
}

function apiV1FinancialDecimal(array $input, string $field, bool $allowZero = false): string
{
    try { return HScript\Finance\RequestDecimal::normalize($input[$field] ?? ($allowZero ? 0 : null), $allowZero); }
    catch (InvalidArgumentException $exception) { HScript\Http\ApiResponse::error('validation_error', 'A bounded positive decimal is required for ' . $field, 422); }
}

function apiV1FinancialFields(array $input, array $allowed): void
{
    if (array_diff(array_keys($input), $allowed))
        HScript\Http\ApiResponse::error('validation_error', 'Unknown request field', 422);
}
