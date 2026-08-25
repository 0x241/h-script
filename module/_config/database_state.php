<?php

use HScript\Database\Connection;

if (!function_exists('cfg_database_environment_value'))
{
	function cfg_database_environment_value(string $name): string
	{
		$file = getenv($name . '_FILE');
		if ($file !== false && $file !== '' && is_readable($file))
			return trim((string)file_get_contents($file));
		$value = getenv($name);
		return $value === false ? '' : (string)$value;
	}
}

if (!function_exists('cfg_installed_database_version'))
{
	function cfg_installed_database_version(array $config, string $domain): ?int
	{
		if (!hsHasDatabaseConfiguration($config))
			return null;

		if (!empty($config['db_credentials_env']))
		{
			$login = cfg_database_environment_value('DB_USER');
			$password = cfg_database_environment_value('DB_PASSWORD');
		}
		else
		{
			$key = md5($domain . ($config['sys_id'] ?? ''));
			$login = decode1((string)($config['db_login'] ?? ''), $key, false, 1);
			$password = decode1((string)($config['db_pass'] ?? ''), $key, false, 2);
		}

		$database = new Connection();
		if (!$database->open(
			(string)$config['db_host'],
			(string)$config['db_name'],
			(string)$login,
			(string)$password
		))
			return null;

		try
		{
			$version = $database->fetch1($database->select(
				'Cfg',
				'Val',
				'Module=? and Prop=?',
				array('Const', 'DBVer'),
				'',
				1
			));
			if ($version === null || $version === false || !is_numeric($version))
				return null;
			$version = (int)$version;
			return $version > 0 ? $version : null;
		}
		catch (Throwable)
		{
			return null;
		}
		finally
		{
			$database->close();
		}
	}
}

?>
