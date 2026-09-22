<?php

namespace HScript\Update;

use HScript\Cache\RedisCache;
use HScript\Database\Connection;
use RuntimeException;

/** No writes, migrations or credential values in the measured runtime contract. */
final class RuntimeEnvironment
{
	public static function inspect(Connection $database): array
	{
		$query = $database->query('SELECT VERSION()');
		if ($query === false) throw new RuntimeException('Database runtime version is unavailable');
		$version = $database->fetch1($query);
		$databaseVersion = self::databaseVersion($version);
		$enabled = !in_array(strtolower(trim((string)getenv('REDIS_ENABLED'))), array('0', 'false', 'no', 'off'), true);
		$redis = $enabled ? RedisCache::fromEnvironment()->serverVersion() : null;
		return array(
			'php' => PHP_VERSION,
			'extensions' => array_map('strtolower', get_loaded_extensions()),
			'database_engine' => $databaseVersion['engine'],
			'database_version' => $databaseVersion['version'],
			'redis_enabled' => $enabled,
			'redis_version' => $redis,
		);
	}

	public static function databaseVersion(mixed $value): array
	{
		if (!is_string($value)) throw new RuntimeException('Database runtime version is unavailable');
		if (stripos($value, 'MariaDB') !== false)
		{
			if (!preg_match('/^(?:5\.5\.5-)?([0-9]+\.[0-9]+\.[0-9]+)-MariaDB(?:[-+].*)?$/Di', $value, $matches))
				throw new RuntimeException('MariaDB runtime version is invalid');
			return array('engine' => 'mariadb', 'version' => $matches[1]);
		}
		if (!preg_match('/^([0-9]+\.[0-9]+\.[0-9]+)(?:[-+].*)?$/D', $value, $matches))
			throw new RuntimeException('MySQL runtime version is invalid');
		return array('engine' => 'mysql', 'version' => $matches[1]);
	}
}
