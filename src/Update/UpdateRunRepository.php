<?php

namespace HScript\Update;

use HScript\Database\Connection;
use RuntimeException;

final class UpdateRunRepository
{
	private Connection $database;

	public function __construct(Connection $database)
	{
		$this->database = $database;
	}

	public function create(ReleaseManifest $manifest, string $sourceVersion, string $sourceSchemaVersion): array
	{
		$lock = new UpdateLock($this->database);
		$lock->acquire();
		try
		{
			$active = $this->latest();
			if ($active && !UpdateRunState::terminal((string)$active['urState']))
				throw new RuntimeException('An unfinished update run already exists');
			$now = time();
			$id = bin2hex(random_bytes(16));
			$this->database->insert('UpdateRuns', array(
				'urID' => $id,
				'urManifestChecksum' => $manifest->checksum(),
				'urSourceVersion' => SchemaVersion::requireValid($sourceVersion, 'source application version'),
				'urTargetVersion' => $manifest->applicationVersion(),
				'urSourceSchemaVersion' => SchemaVersion::requireValid($sourceSchemaVersion, 'source schema version'),
				'urTargetSchemaVersion' => $manifest->schemaVersion(),
				'urClassification' => $manifest->classification(),
				'urState' => UpdateRunState::PREFLIGHT,
				'urMessageCode' => '',
				'urMessageSummary' => '',
				'urCreatedAt' => $now,
				'urUpdatedAt' => $now,
				'urFinishedAt' => 0,
			));
			return $this->get($id) ?? throw new RuntimeException('Update run could not be created');
		}
		finally
		{
			$lock->release();
		}
	}

	public function transition(string $id, string $state, string $messageCode = '', string $messageSummary = ''): array
	{
		$row = $this->get($id);
		if (!$row)
			throw new RuntimeException('Update run was not found');
		UpdateRunState::assertTransition((string)$row['urState'], $state);
		$now = time();
		$this->database->update('UpdateRuns', array(
			'urState' => $state,
			'urMessageCode' => $this->safeCode($messageCode),
			'urMessageSummary' => substr(trim($messageSummary), 0, 500),
			'urUpdatedAt' => $now,
			'urFinishedAt' => UpdateRunState::terminal($state) ? $now : 0,
		), '', 'urID=?', array($id));
		return $this->get($id) ?? throw new RuntimeException('Update run state could not be read');
	}

	public function fail(string $id, string $messageCode, string $messageSummary): array
	{
		return $this->transition($id, UpdateRunState::FAILED, $messageCode, $messageSummary);
	}

	public function note(string $id, string $messageCode, string $messageSummary): array
	{
		$row = $this->get($id);
		if (!$row)
			throw new RuntimeException('Update run was not found');
		if (UpdateRunState::terminal((string)$row['urState']))
			throw new RuntimeException('Terminal update run cannot be changed');
		$this->database->update('UpdateRuns', array(
			'urMessageCode' => $this->safeCode($messageCode),
			'urMessageSummary' => substr(trim($messageSummary), 0, 500),
			'urUpdatedAt' => time(),
		), '', 'urID=?', array($id));
		return $this->get($id) ?? throw new RuntimeException('Update run state could not be read');
	}

	public function get(string $id): ?array
	{
		$row = $this->database->fetch1Row($this->database->select(
			'UpdateRuns', '*', 'urID=?', array($id), '', 1
		));
		return $row ?: null;
	}

	public function latest(): ?array
	{
		$row = $this->database->fetch1Row($this->database->select(
			'UpdateRuns', '*', '', array(), 'urCreatedAt DESC, urID DESC', 1
		));
		return $row ?: null;
	}

	private function safeCode(string $code): string
	{
		return substr(preg_replace('/[^a-z0-9._-]/', '_', strtolower(trim($code))), 0, 64);
	}
}
