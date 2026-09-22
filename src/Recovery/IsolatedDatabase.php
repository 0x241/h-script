<?php

namespace HScript\Recovery;

use HScript\Backup\DatabaseCredentials;
use HScript\Database\Connection;

final class IsolatedDatabase
{
	private ?Connection $administrativeConnection = null;
	private ?DatabaseCredentials $target = null;

	public static function fromEnvironment(): self
	{
		return new self();
	}

	public function create(): DatabaseCredentials
	{
		if ($this->target !== null)
			throw new RecoveryException('drill_database_state_invalid', 'An isolated drill database already exists');
		$host = trim((string)(getenv('RECOVERY_DRILL_DB_HOST') ?: ''));
		$user = trim((string)(getenv('RECOVERY_DRILL_DB_ADMIN_USER') ?: ''));
		$password = DatabaseCredentials::environmentValue('RECOVERY_DRILL_DB_ADMIN_PASSWORD');
		$prefix = trim((string)(getenv('RECOVERY_DRILL_DB_PREFIX') ?: 'hscript_drill'));
		if ($host === '' || $user === '' || $password === '')
			throw new RecoveryException('drill_database_not_configured', 'Isolated drill database credentials are not configured');
		if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,31}$/', $prefix))
			throw new RecoveryException('drill_database_not_configured', 'RECOVERY_DRILL_DB_PREFIX is invalid');
		$name = substr($prefix . '_' . gmdate('YmdHis') . '_' . bin2hex(random_bytes(4)), 0, 64);
		$connection = new Connection();
		// Prefix-scoped drill accounts need no access to the system mysql database.
		if (!$connection->open($host, '', $user, $password))
			throw new RecoveryException('drill_database_unavailable', 'Isolated database server is unavailable');
		try
		{
			if ($connection->query('CREATE DATABASE ' . $connection->field($name) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci') === false)
				throw new \RuntimeException('Database creation was rejected');
		}
		catch (\Throwable $exception)
		{
			$connection->close();
			throw new RecoveryException('drill_database_create_failed', 'Isolated drill database could not be created', $exception);
		}
		$this->administrativeConnection = $connection;
		$this->target = new DatabaseCredentials($host, $name, $user, $password);
		return $this->target;
	}

	public function drop(): void
	{
		if ($this->target === null) return;
		if ($this->administrativeConnection === null)
			throw new RecoveryException('drill_cleanup_failed', 'Isolated drill database connection is missing');
		try
		{
			if ($this->administrativeConnection->query(
				'DROP DATABASE ' . $this->administrativeConnection->field($this->target->database())
			) === false) throw new \RuntimeException('Database cleanup was rejected');
		}
		catch (\Throwable $exception)
		{
			throw new RecoveryException('drill_cleanup_failed', 'Isolated drill database could not be removed', $exception);
		}
		finally
		{
			$this->administrativeConnection->close();
			$this->administrativeConnection = null;
			$this->target = null;
		}
	}
}
