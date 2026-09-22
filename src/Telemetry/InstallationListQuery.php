<?php

namespace HScript\Telemetry;

use InvalidArgumentException;

/** Validated pagination and filters shared by the collector UI and service API. */
final class InstallationListQuery
{
	public const PAGE_SIZES = array(25, 50, 100);
	public const CONNECTION_STATES = array('no_report', 'active', 'inactive');
	public const SHARING_STATES = array('enabled', 'disabled');
	public const DNS_STATES = array('matched', 'mismatch', 'unresolved', 'invalid');

	private function __construct(
		private int $page,
		private int $perPage,
		private string $search,
		private string $version,
		private string $connection,
		private string $sharing,
		private string $dnsStatus,
		private string $ip
	) {}

	public static function fromArray(array $input): self
	{
		$page = self::integer($input, 'page', 1, 1, 1000000);
		$perPage = self::integer($input, 'per_page', 25, 1, 100);
		if (!in_array($perPage, self::PAGE_SIZES, true))
			self::fail('per_page');

		$search = self::text($input, 'q', 253);
		if ($search !== '')
		{
			if (str_contains($search, '://'))
				$search = (string)(parse_url($search, PHP_URL_HOST) ?: '');
			$search = strtolower(rtrim(preg_replace('/:\d+$/', '', trim($search)) ?? '', '.'));
			if ($search === '' || !preg_match('/^[a-z0-9.-]+$/', $search))
				self::fail('q');
		}

		$version = self::text($input, 'version', 32);
		if ($version !== '' && !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?$/', $version))
			self::fail('version');

		$connection = self::choice($input, 'connection', self::CONNECTION_STATES);
		$sharing = self::choice($input, 'sharing', self::SHARING_STATES);
		$dnsStatus = self::choice($input, 'dns_status', self::DNS_STATES);
		$ip = self::text($input, 'ip', 45);
		if ($ip !== '')
		{
			$ip = PassiveDnsResolver::normalizeIp($ip);
			if ($ip === '')
				self::fail('ip');
		}

		return new self($page, $perPage, $search, $version, $connection, $sharing, $dnsStatus, $ip);
	}

	public function page(): int { return $this->page; }
	public function perPage(): int { return $this->perPage; }
	public function search(): string { return $this->search; }
	public function version(): string { return $this->version; }
	public function connection(): string { return $this->connection; }
	public function sharing(): string { return $this->sharing; }
	public function dnsStatus(): string { return $this->dnsStatus; }
	public function ip(): string { return $this->ip; }

	public function filters(): array
	{
		return array(
			'q' => $this->search,
			'version' => $this->version,
			'connection' => $this->connection,
			'sharing' => $this->sharing,
			'dns_status' => $this->dnsStatus,
			'ip' => $this->ip,
		);
	}

	public function queryParameters(?int $page = null): array
	{
		$parameters = array_filter(
			$this->filters(),
			static fn(string $value): bool => $value !== ''
		);
		$parameters['per_page'] = $this->perPage;
		$parameters['page'] = max(1, $page ?? $this->page);
		return $parameters;
	}

	private static function choice(array $input, string $field, array $allowed): string
	{
		$value = self::text($input, $field, 32);
		if ($value !== '' && !in_array($value, $allowed, true))
			self::fail($field);
		return $value;
	}

	private static function text(array $input, string $field, int $maximum): string
	{
		$value = $input[$field] ?? '';
		if (!is_string($value) && !is_int($value))
			self::fail($field);
		$value = trim((string)$value);
		if (strlen($value) > $maximum)
			self::fail($field);
		return $value;
	}

	private static function integer(array $input, string $field, int $default, int $minimum, int $maximum): int
	{
		$value = $input[$field] ?? $default;
		if (is_string($value) && preg_match('/^[0-9]+$/', $value))
			$value = (int)$value;
		if (!is_int($value) || $value < $minimum || $value > $maximum)
			self::fail($field);
		return $value;
	}

	private static function fail(string $field): never
	{
		throw new InvalidArgumentException('query_invalid:' . $field);
	}
}
