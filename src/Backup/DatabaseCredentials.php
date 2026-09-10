<?php

namespace HScript\Backup;

use InvalidArgumentException;

final class DatabaseCredentials
{
	private string $host;
	private int $port;
	private string $socket;
	private string $database;
	private string $username;
	private string $password;

	public function __construct(string $host, string $database, string $username, string $password)
	{
		$host = trim($host);
		$database = trim($database);
		$username = trim($username);
		if ($host === '' || $database === '' || $username === '')
			throw new InvalidArgumentException('Database host, name, and user are required');
		if (!preg_match('/^[A-Za-z0-9_$.-]{1,64}$/', $database))
			throw new InvalidArgumentException('Database name contains unsupported characters');

		$this->port = 3306;
		$this->socket = '';
		if (str_starts_with($host, '/'))
		{
			$this->host = 'localhost';
			$this->socket = $host;
		}
		elseif (preg_match('/^\[([^]]+)](?::([0-9]+))?$/', $host, $match))
		{
			$this->host = $match[1];
			$this->port = isset($match[2]) ? (int)$match[2] : 3306;
		}
		elseif (substr_count($host, ':') === 1 && preg_match('/^([^:]+):([0-9]+)$/', $host, $match))
		{
			$this->host = $match[1];
			$this->port = (int)$match[2];
		}
		else
			$this->host = $host;
		if ($this->port < 1 || $this->port > 65535)
			throw new InvalidArgumentException('Database port is invalid');

		$this->database = $database;
		$this->username = $username;
		$this->password = $password;
	}

	public static function fromConfig(array $config, string $domain): self
	{
		if (!empty($config['db_credentials_env']))
		{
			$username = self::environmentValue('DB_USER');
			$password = self::environmentValue('DB_PASSWORD');
		}
		else
		{
			$key = md5($domain . ($config['sys_id'] ?? ''));
			$username = decode1((string)($config['db_login'] ?? ''), $key, false, 1);
			$password = decode1((string)($config['db_pass'] ?? ''), $key, false, 2);
		}
		return new self(
			(string)($config['db_host'] ?? ''),
			(string)($config['db_name'] ?? ''),
			(string)$username,
			(string)$password
		);
	}

	public static function environmentValue(string $name): string
	{
		$file = getenv($name . '_FILE');
		if (is_string($file) && $file !== '' && is_readable($file))
			return trim((string)file_get_contents($file));
		$value = getenv($name);
		return is_string($value) ? $value : '';
	}

	public function host(): string { return $this->host; }
	public function port(): int { return $this->port; }
	public function socket(): string { return $this->socket; }
	public function database(): string { return $this->database; }
	public function username(): string { return $this->username; }
	public function password(): string { return $this->password; }
	public function connectionHost(): string
	{
		if ($this->socket !== '')
			throw new InvalidArgumentException('Socket target validation is not supported');
		return str_contains($this->host, ':')
			? '[' . $this->host . ']:' . $this->port
			: $this->host . ':' . $this->port;
	}

	public function databaseHash(): string
	{
		return hash('sha256', strtolower($this->database));
	}
}
