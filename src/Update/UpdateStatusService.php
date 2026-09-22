<?php

namespace HScript\Update;

use HScript\Database\Connection;
use Throwable;

final class UpdateStatusService
{
	/** A deployed image is not an installed release until its lifecycle finishes. */
	public static function isReconciled(array $status): bool
	{
		$run = $status['latest_run'] ?? null;
		return !empty($status['framework_ready'])
			&& ($status['installed_application_version'] ?? null) !== null
			&& ($status['installed_application_version'] ?? null) === ($status['application_version'] ?? null)
			&& ($status['installed_schema_version'] ?? null) !== null
			&& ($status['installed_schema_version'] ?? null) === ($status['target_schema_version'] ?? null)
			&& ($status['schema_gate'] ?? null) === null
			&& ($run === null || ($run['urState'] ?? '') === UpdateRunState::COMPLETED);
	}

	private Connection $database;
	private string $projectRoot;

	public function __construct(Connection $database, ?string $projectRoot = null)
	{
		$this->database = $database;
		$this->projectRoot = $projectRoot ?? dirname(__DIR__, 2);
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
			'application_version' => $this->version('VERSION'),
			'installed_application_version' => $installedApplication,
			'installed_schema_version' => $installedSchema,
			'target_schema_version' => $this->version('SCHEMA_VERSION'),
			'framework_ready' => $ready,
			'schema_gate' => $this->schemaGate(),
			'latest_run' => $latestRun,
		);
	}

	private function schemaGate(): ?array
	{
		$path = $this->projectRoot . '/.cfg/schema-update-required.json';
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

	private function version(string $file): string
	{
		$path = $this->projectRoot . '/' . $file;
		return is_readable($path) ? SchemaVersion::requireValid(trim((string)file_get_contents($path))) : '0.0.0';
	}
}
