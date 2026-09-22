<?php

namespace HScript\Telemetry;

/** Strictly validates the self-reported installation telemetry contract. */
final class TelemetryPayloadValidator
{
	public const SCHEMA_VERSION = 1;
	public const MAX_BODY_BYTES = 65536;
	private const MAX_COUNTER = 1000000000;
	private const MAX_AMOUNT = 1000000000000000.0;

	public function registration(array $input, ?int $now = null): array
	{
		$this->exactFields($input, array(
			'schema_version', 'installation_id', 'domain', 'version', 'installed_at', 'stats_consent',
		));
		$now ??= time();
		return array(
			'schema_version' => $this->schemaVersion($input),
			'installation_id' => $this->installationId($input),
			'domain' => $this->domain($input),
			'version' => $this->version($input),
			'installed_at' => $this->timestamp($input, 'installed_at', 946684800, $now + 300),
			'stats_consent' => $this->boolean($input, 'stats_consent'),
		);
	}

	public function report(array $input, ?int $now = null): array
	{
		$this->exactFields($input, array(
			'schema_version', 'installation_id', 'domain', 'version', 'reported_at', 'sequence',
			'stats_consent', 'public_stats?',
		));
		$now ??= time();
		$consent = $this->boolean($input, 'stats_consent');
		$stats = null;
		if (array_key_exists('public_stats', $input))
		{
			if (!$consent)
				$this->fail('field_not_allowed', 'Public statistics require consent', 'public_stats');
			$stats = $this->publicStats($input['public_stats']);
		}
		$sequence = $this->integer($input, 'sequence', 1, intdiv($now + 300, 86400));
		$reportedAt = $this->timestamp($input, 'reported_at', $now - 172800, $now + 300);
		if ($sequence !== intdiv($reportedAt, 86400))
			$this->fail('sequence_invalid', 'Sequence must match the UTC report day', 'sequence');
		return array(
			'schema_version' => $this->schemaVersion($input),
			'installation_id' => $this->installationId($input),
			'domain' => $this->domain($input),
			'version' => $this->version($input),
			'reported_at' => $reportedAt,
			'sequence' => $sequence,
			'stats_consent' => $consent,
			'public_stats' => $stats,
		);
	}

	private function publicStats(mixed $value): array
	{
		if (!is_array($value) || array_is_list($value))
			$this->fail('field_type_invalid', 'Public statistics must be an object', 'public_stats');
		$this->exactFields($value, array(
			'worked_days', 'users_total', 'users_online', 'active_deposits', 'closed_deposits',
			'cash_in_base', 'cash_out_base', 'referral_paid_base', 'reinvested_base',
			'base_currency', 'cash_in_by_currency', 'cash_out_by_currency',
		), 'public_stats.');

		$result = array();
		foreach (array('worked_days', 'users_total', 'users_online', 'active_deposits', 'closed_deposits') as $field)
			$result[$field] = $this->integer($value, $field, 0, self::MAX_COUNTER, 'public_stats.');
		foreach (array('cash_in_base', 'cash_out_base', 'referral_paid_base', 'reinvested_base') as $field)
			$result[$field] = $this->number($value, $field, 'public_stats.');

		$currency = $this->string($value, 'base_currency', 0, 10, 'public_stats.');
		$currency = strtoupper($currency);
		if ($currency !== '' && !preg_match('/^[A-Z0-9]{2,10}$/', $currency))
			$this->fail('field_format_invalid', 'Invalid base currency', 'public_stats.base_currency');
		$result['base_currency'] = $currency;
		foreach (array('cash_in_by_currency', 'cash_out_by_currency') as $field)
			$result[$field] = $this->amountMap($value[$field], 'public_stats.' . $field);
		return $result;
	}

	private function amountMap(mixed $value, string $field): array
	{
		if (!is_array($value) || ($value !== array() && array_is_list($value)) || count($value) > 20)
			$this->fail('field_type_invalid', 'Currency statistics must be an object with at most 20 entries', $field);
		$result = array();
		foreach ($value as $currency => $amount)
		{
			if (!is_string($currency) || !preg_match('/^[A-Z0-9]{2,10}$/', $currency))
				$this->fail('field_format_invalid', 'Invalid currency code', $field);
			if ((!is_int($amount) && !is_float($amount)) || !is_finite((float)$amount) || $amount < 0 || $amount > self::MAX_AMOUNT)
				$this->fail('field_range_invalid', 'Invalid currency amount', $field . '.' . $currency);
			$result[$currency] = (float)$amount;
		}
		ksort($result);
		return $result;
	}

	private function exactFields(array $input, array $allowed, string $prefix = ''): void
	{
		$optional = array();
		$known = array();
		foreach ($allowed as $field)
		{
			$isOptional = str_ends_with($field, '?');
			$field = rtrim($field, '?');
			$known[$field] = true;
			$optional[$field] = $isOptional;
		}
		foreach ($input as $field => $_)
			if (!is_string($field) || !isset($known[$field]))
				$this->fail('field_unknown', 'Unknown telemetry field', $prefix . (string)$field);
		foreach ($known as $field => $_)
			if (!$optional[$field] && !array_key_exists($field, $input))
				$this->fail('field_required', 'Required telemetry field is missing', $prefix . $field);
	}

	private function schemaVersion(array $input): int
	{
		$value = $this->integer($input, 'schema_version', self::SCHEMA_VERSION, self::SCHEMA_VERSION);
		return $value;
	}

	private function installationId(array $input): string
	{
		$value = strtolower($this->string($input, 'installation_id', 36, 36));
		if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value))
			$this->fail('field_format_invalid', 'Invalid installation id', 'installation_id');
		return $value;
	}

	private function domain(array $input): string
	{
		$value = strtolower(rtrim($this->string($input, 'domain', 1, 253), '.'));
		if (!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $value))
			$this->fail('field_format_invalid', 'Invalid installation domain', 'domain');
		return $value;
	}

	private function version(array $input): string
	{
		$value = $this->string($input, 'version', 5, 32);
		if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?$/', $value))
			$this->fail('field_format_invalid', 'Invalid application version', 'version');
		return $value;
	}

	private function boolean(array $input, string $field): bool
	{
		if (!array_key_exists($field, $input) || !is_bool($input[$field]))
			$this->fail('field_type_invalid', 'Telemetry field must be boolean', $field);
		return $input[$field];
	}

	private function string(array $input, string $field, int $minimum, int $maximum, string $prefix = ''): string
	{
		$value = $input[$field] ?? null;
		if (!is_string($value))
			$this->fail('field_type_invalid', 'Telemetry field must be a string', $prefix . $field);
		$length = strlen($value);
		if ($length < $minimum || $length > $maximum || trim($value) !== $value)
			$this->fail('field_range_invalid', 'Telemetry string length is invalid', $prefix . $field);
		return $value;
	}

	private function integer(array $input, string $field, int $minimum, int $maximum, string $prefix = ''): int
	{
		$value = $input[$field] ?? null;
		if (!is_int($value))
			$this->fail('field_type_invalid', 'Telemetry field must be an integer', $prefix . $field);
		if ($value < $minimum || $value > $maximum)
			$this->fail('field_range_invalid', 'Telemetry integer is outside the allowed range', $prefix . $field);
		return $value;
	}

	private function timestamp(array $input, string $field, int $minimum, int $maximum): int
	{
		return $this->integer($input, $field, $minimum, $maximum);
	}

	private function number(array $input, string $field, string $prefix): float
	{
		$value = $input[$field] ?? null;
		if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value))
			$this->fail('field_type_invalid', 'Telemetry field must be a finite number', $prefix . $field);
		if ($value < 0 || $value > self::MAX_AMOUNT)
			$this->fail('field_range_invalid', 'Telemetry number is outside the allowed range', $prefix . $field);
		return (float)$value;
	}

	private function fail(string $code, string $message, string $field = ''): never
	{
		throw new TelemetryValidationException($code, $message, $field);
	}
}
