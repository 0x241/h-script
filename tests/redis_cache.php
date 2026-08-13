<?php

use HScript\Cache\CatalogCache;
use HScript\Cache\RedisCache;

require dirname(__DIR__) . '/vendor/autoload.php';

function cacheAssert(bool $condition, string $message): void
{
	if (!$condition)
		throw new RuntimeException($message);
}

final class CacheFakeRedis
{
	public array $values = array();
	public array $ttls = array();
	private string $prefix = '';

	public function setOption(int $option, mixed $value): bool
	{
		if (defined('Redis::OPT_PREFIX') && $option === constant('Redis::OPT_PREFIX'))
			$this->prefix = (string)$value;
		return true;
	}

	public function get(string $key): string|false
	{
		return $this->values[$this->key($key)] ?? false;
	}

	public function setex(string $key, int $ttl, string $value): bool
	{
		$key = $this->key($key);
		$this->values[$key] = $value;
		$this->ttls[$key] = $ttl;
		return true;
	}

	public function set(string $key, string $value): bool
	{
		$this->values[$this->key($key)] = $value;
		return true;
	}

	public function del(string $key): int
	{
		$key = $this->key($key);
		$exists = array_key_exists($key, $this->values);
		unset($this->values[$key], $this->ttls[$key]);
		return $exists ? 1 : 0;
	}

	public function incr(string $key): int
	{
		$key = $this->key($key);
		$value = (int)($this->values[$key] ?? 0) + 1;
		$this->values[$key] = (string)$value;
		return $value;
	}

	public function flushDB(): bool
	{
		$this->values = array();
		$this->ttls = array();
		return true;
	}

	private function key(string $key): string
	{
		return $this->prefix . $key;
	}
}

$fake = new CacheFakeRedis();
$redis = new RedisCache(prefix: 'test:', client: $fake);
$catalog = new CatalogCache($redis);
$loads = 0;
$loader = static function () use (&$loads): array
{
	$loads++;
	return array('load' => $loads);
};

$first = $catalog->remember(CatalogCache::CONFIG, 'all', $loader);
$second = $catalog->remember(CatalogCache::CONFIG, 'all', $loader);
cacheAssert($first === array('load' => 1), 'Initial cache load failed');
cacheAssert($second === $first, 'Cache hit returned different data');
cacheAssert($loads === 1, 'Cache hit executed the database callback');
cacheAssert(
	in_array(300, $fake->ttls, true),
	'Reference-data TTL is not 300 seconds'
);

$catalog->invalidateTable('`Cfg`');
$third = $catalog->remember(CatalogCache::CONFIG, 'all', $loader);
cacheAssert($third === array('load' => 2), 'Cfg invalidation did not rotate the cache generation');

$catalog->remember(CatalogCache::NEWS, 'block:5', static fn(): array => array('news'));
cacheAssert(
	in_array(600, $fake->ttls, true),
	'Content TTL is not 600 seconds'
);

$disabled = new RedisCache(enabled: false);
$fallbackLoads = 0;
$fallback = $disabled->get('missing', static function () use (&$fallbackLoads): string
{
	$fallbackLoads++;
	return 'database';
});
cacheAssert($fallback === 'database', 'Disabled Redis did not fall back to the callback');
cacheAssert($fallbackLoads === 1, 'Fallback callback was not executed exactly once');

echo "Redis cache component tests passed.\n";
