<?php

namespace HScript\Telemetry;

use InvalidArgumentException;

/** Validated, independently namespaced filters for the collector service-token list. */
final class ServiceTokenListQuery
{
	public const PAGE_SIZES = array(25, 50, 100);
	public const STATUSES = array('active', 'paused', 'expired', 'revoked');

	private function __construct(
		private int $page,
		private int $perPage,
		private string $search,
		private string $status
	) {}

	public static function fromArray(array $input): self
	{
		$page = self::integer($input, 'token_page', 1, 1, 1000000);
		$perPage = self::integer($input, 'token_per_page', 25, 1, 100);
		if (!in_array($perPage, self::PAGE_SIZES, true))
			self::fail('token_per_page');

		$search = self::text($input, 'token_q', 100);
		if ($search !== '')
		{
			if (!mb_check_encoding($search, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/u', $search))
				self::fail('token_q');
			$search = mb_strtolower($search, 'UTF-8');
		}
		$status = self::text($input, 'token_status', 16);
		if ($status !== '' && !in_array($status, self::STATUSES, true))
			self::fail('token_status');

		return new self($page, $perPage, $search, $status);
	}

	public function page(): int { return $this->page; }
	public function perPage(): int { return $this->perPage; }
	public function search(): string { return $this->search; }
	public function status(): string { return $this->status; }

	public function filters(): array
	{
		return array(
			'token_q' => $this->search,
			'token_status' => $this->status,
		);
	}

	public function queryParameters(?int $page = null): array
	{
		$parameters = array_filter(
			$this->filters(),
			static fn(string $value): bool => $value !== ''
		);
		$parameters['token_per_page'] = $this->perPage;
		$parameters['token_page'] = max(1, $page ?? $this->page);
		return $parameters;
	}

	private static function text(array $input, string $field, int $maximum): string
	{
		$value = $input[$field] ?? '';
		if (!is_string($value) && !is_int($value))
			self::fail($field);
		$value = trim((string)$value);
		if (mb_strlen($value, 'UTF-8') > $maximum)
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
