<?php

namespace HScript\Cache;

use JsonException;
use RuntimeException;
use Throwable;

/**
 * Provides a fail-open JSON cache over the PHP Redis extension.
 *
 * Connection and runtime failures disable the cache for the current request;
 * callers can continue by supplying a fallback callback to get().
 */
class RedisCache
{
	private ?object $redis = null;
	private bool $available = false;
	private bool $failureLogged = false;

	/**
	 * Creates and configures a Redis connection.
	 *
	 * @param object|null $client Optional compatible client used by tests.
	 */
	public function __construct(
		string $host = 'redis',
		int $port = 6379,
		float $timeout = 1.0,
		string $prefix = 'hs:',
		int $database = 0,
		string $password = '',
		?object $client = null,
		bool $enabled = true
	)
	{
		if (!$enabled)
			return;
		if ($client !== null)
		{
			$this->redis = $client;
			$this->available = true;
			$this->configure($prefix, $database, $password);
			return;
		}
		if (!class_exists(\Redis::class))
		{
			$this->logFailure('PHP Redis extension is not loaded');
			return;
		}
		try
		{
			$this->redis = new \Redis();
			if (!$this->redis->connect($host, $port, $timeout))
				throw new RuntimeException('Unable to connect to Redis');
			$this->available = true;
			$this->configure($prefix, $database, $password);
		}
		catch (Throwable $e)
		{
			$this->available = false;
			$this->redis = null;
			$this->logFailure($e->getMessage());
		}
	}

	/**
	 * Builds a cache client from the REDIS_* environment variables.
	 */
	public static function fromEnvironment(): self
	{
		$enabled = getenv('REDIS_ENABLED');
		if ($enabled !== false && $enabled !== '' && self::flagIsDisabled($enabled))
			return new self(enabled: false);
		return new self(
			self::envString('REDIS_HOST', 'redis'),
			self::envInt('REDIS_PORT', 6379),
			self::envFloat('REDIS_CONNECT_TIMEOUT', 1.0),
			self::envString('REDIS_PREFIX', 'hs:'),
			self::envInt('REDIS_DB', 0),
			self::envString('REDIS_PASSWORD')
		);
	}

	/**
	 * Reports whether Redis is available for this request.
	 */
	public function isAvailable(): bool
	{
		return $this->available;
	}

	/**
	 * Reads a JSON value, optionally loading and caching a fallback value.
	 *
	 * @param callable|null $callback Invoked only after a cache miss or failure.
	 * @param int $ttl Lifetime in seconds; zero stores without expiration.
	 */
	public function get(string $key, ?callable $callback = null, int $ttl = 300): mixed
	{
		if ($this->available)
		{
			try
			{
				$encoded = $this->redis->get($key);
				if ($encoded !== false)
				{
					try
					{
						return json_decode((string)$encoded, true, 512, JSON_THROW_ON_ERROR);
					}
					catch (JsonException)
					{
						$this->redis->del($key);
					}
				}
			}
			catch (Throwable $e)
			{
				$this->disable($e);
			}
		}
		if ($callback === null)
			return null;
		$value = $callback();
		$this->set($key, $value, $ttl);
		return $value;
	}

	/**
	 * JSON-encodes and stores a value when Redis is available.
	 */
	public function set(string $key, mixed $value, int $ttl = 300): void
	{
		if (!$this->available)
			return;
		try
		{
			$encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
			if ($ttl > 0)
				$this->redis->setex($key, $ttl, $encoded);
			else
				$this->redis->set($key, $encoded);
		}
		catch (Throwable $e)
		{
			$this->disable($e);
		}
	}

	/**
	 * Removes a key without failing the request when Redis is unavailable.
	 */
	public function delete(string $key): void
	{
		if (!$this->available)
			return;
		try
		{
			$this->redis->del($key);
		}
		catch (Throwable $e)
		{
			$this->disable($e);
		}
	}

	/**
	 * Atomically increments a key or returns null when the cache is unavailable.
	 */
	public function increment(string $key): ?int
	{
		if (!$this->available)
			return null;
		try
		{
			return (int)$this->redis->incr($key);
		}
		catch (Throwable $e)
		{
			$this->disable($e);
			return null;
		}
	}

	/**
	 * Flushes the selected Redis database.
	 *
	 * This operation is intentionally scoped by the configured Redis database.
	 */
	public function flush(): void
	{
		if (!$this->available)
			return;
		try
		{
			$this->redis->flushDB();
		}
		catch (Throwable $e)
		{
			$this->disable($e);
		}
	}

	private function configure(string $prefix, int $database, string $password): void
	{
		try
		{
			if ($password !== '')
				if (!$this->redis->auth($password))
					throw new RuntimeException('Redis authentication failed');
			if ($database > 0)
				if (!$this->redis->select($database))
					throw new RuntimeException('Unable to select Redis database');
			if (defined('Redis::OPT_PREFIX'))
				$this->redis->setOption(constant('Redis::OPT_PREFIX'), $prefix);
		}
		catch (Throwable $e)
		{
			$this->disable($e);
		}
	}

	private function disable(Throwable $e): void
	{
		$this->available = false;
		$this->logFailure($e->getMessage());
	}

	private function logFailure(string $message): void
	{
		if ($this->failureLogged)
			return;
		$this->failureLogged = true;
		error_log('Redis cache unavailable: ' . $message);
	}

	private static function envString(string $name, string $default = ''): string
	{
		$value = getenv($name);
		return ($value === false || $value === '') ? $default : (string)$value;
	}

	private static function envInt(string $name, int $default): int
	{
		$value = getenv($name);
		return ($value === false || $value === '') ? $default : (int)$value;
	}

	private static function envFloat(string $name, float $default): float
	{
		$value = getenv($name);
		return ($value === false || $value === '') ? $default : (float)$value;
	}

	private static function flagIsDisabled(mixed $value): bool
	{
		return in_array(strtolower((string)$value), array('0', 'false', 'no', 'off'), true);
	}
}
