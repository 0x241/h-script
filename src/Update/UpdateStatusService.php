<?php

namespace HScript\Update;

use HScript\Application;
use HScript\Database\Connection;
use Throwable;

final class UpdateStatusService
{
	private Connection $database;

	public function __construct(Connection $database)
	{
		$this->database = $database;
	}

	public function snapshot(): array
	{
		$schemaState = new SchemaStateRepository($this->database);
		$ready = false;
		$installedSchema = null;
		$installedApplication = null;
		$latestRun = null;
		try
		{
			$ready = $schemaState->storageReady();
			$installedSchema = $schemaState->currentVersion();
			$installedApplication = $schemaState->installedApplicationVersion();
			if ($ready)
				$latestRun = (new UpdateRunRepository($this->database))->latest();
		}
		catch (Throwable)
		{
			$ready = false;
		}
		return array(
			'application_version' => Application::version(),
			'installed_application_version' => $installedApplication,
			'installed_schema_version' => $installedSchema,
			'target_schema_version' => Application::schemaVersion(),
			'framework_ready' => $ready,
			'schema_gate' => $this->schemaGate(),
			'latest_run' => $latestRun,
		);
	}

	private function schemaGate(): ?array
	{
		$path = dirname(__DIR__, 2) . '/.cfg/schema-update-required.json';
		if (!is_file($path) || is_link($path) || !is_readable($path)) return null;
		try { $data = json_decode((string)file_get_contents($path), true, 16, JSON_THROW_ON_ERROR); }
		catch (Throwable) { return array('reason' => 'invalid_marker'); }
		if (!is_array($data) || !is_string($data['reason'] ?? null)) return array('reason' => 'invalid_marker');
		return array(
			'reason' => $data['reason'],
			'installed_application_version' => $data['installed_application_version'] ?? null,
			'image_application_version' => $data['image_application_version'] ?? null,
			'installed_schema_version' => $data['installed_schema_version'] ?? null,
			'image_schema_version' => $data['image_schema_version'] ?? null,
		);
	}
}
