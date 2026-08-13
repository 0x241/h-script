<?php

namespace HScript\Cache;

use InvalidArgumentException;

/**
 * Caches versioned configuration and content catalogs in Redis.
 *
 * Catalog invalidation increments a version key instead of scanning Redis.
 */
class CatalogCache
{
	public const CONFIG = 'config';
	public const CURRENCIES = 'currencies';
	public const PLANS = 'plans';
	public const FAQ = 'faq';
	public const NEWS = 'news';

	private const TTL = array(
		self::CONFIG => 300,
		self::CURRENCIES => 300,
		self::PLANS => 300,
		self::FAQ => 600,
		self::NEWS => 600,
	);

	private const TABLES = array(
		'cfg' => self::CONFIG,
		'currs' => self::CURRENCIES,
		'plans' => self::PLANS,
		'faq' => self::FAQ,
		'news' => self::NEWS,
	);

	private RedisCache $cache;
	private array $versions = array();

	public function __construct(RedisCache $cache)
	{
		$this->cache = $cache;
	}

	public function remember(string $catalog, string $variant, callable $callback): mixed
	{
		$this->assertCatalog($catalog);
		$version = $this->version($catalog);
		$key = sprintf(
			'catalog:%s:v%d:%s',
			$catalog,
			$version,
			hash('sha256', $variant)
		);
		return $this->cache->get($key, $callback, self::TTL[$catalog]);
	}

	public function invalidate(string $catalog): void
	{
		$this->assertCatalog($catalog);
		$version = $this->cache->increment($this->versionKey($catalog));
		unset($this->versions[$catalog]);
		if ($version !== null)
			$this->versions[$catalog] = $version;
	}

	public function invalidateTable(string $table): void
	{
		$table = strtolower(trim($table, " \t\n\r\0\x0B`"));
		if (isset(self::TABLES[$table]))
			$this->invalidate(self::TABLES[$table]);
	}

	public function isAvailable(): bool
	{
		return $this->cache->isAvailable();
	}

	private function version(string $catalog): int
	{
		if (!array_key_exists($catalog, $this->versions))
		{
			$this->versions[$catalog] = (int)$this->cache->get(
				$this->versionKey($catalog),
				static fn(): int => 0,
				0
			);
		}
		return $this->versions[$catalog];
	}

	private function versionKey(string $catalog): string
	{
		return 'catalog:' . $catalog . ':version';
	}

	private function assertCatalog(string $catalog): void
	{
		if (!isset(self::TTL[$catalog]))
			throw new InvalidArgumentException('Unknown cache catalog: ' . $catalog);
	}
}
