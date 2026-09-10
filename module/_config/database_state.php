<?php

use HScript\Database\Connection;
use HScript\Application;
use HScript\Update\UpdateStatusService;

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
	function cfg_installed_database_version(array $config, string $domain): ?string
	{
		return cfg_update_status($config, $domain)['installed_schema_version'];
	}
}

if (!function_exists('cfg_update_status'))
{
	function cfg_update_status(array $config, string $domain): array
	{
		if (!hsHasDatabaseConfiguration($config))
			return array(
				'application_version' => Application::version(),
				'installed_application_version' => null,
				'installed_schema_version' => null,
				'target_schema_version' => Application::schemaVersion(),
				'framework_ready' => false,
				'schema_gate' => null,
				'latest_run' => null,
			);

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
			return array(
				'application_version' => Application::version(),
				'installed_application_version' => null,
				'installed_schema_version' => null,
				'target_schema_version' => Application::schemaVersion(),
				'framework_ready' => false,
				'schema_gate' => null,
				'latest_run' => null,
			);

		try
		{
			return (new UpdateStatusService($database))->snapshot();
		}
		catch (Throwable)
		{
			return array(
				'application_version' => Application::version(),
				'installed_application_version' => null,
				'installed_schema_version' => null,
				'target_schema_version' => Application::schemaVersion(),
				'framework_ready' => false,
				'schema_gate' => null,
				'latest_run' => null,
			);
		}
		finally
		{
			$database->close();
		}
	}
}

?>
