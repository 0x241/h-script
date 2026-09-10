<?php

namespace HScript\Update;

use HScript\Database\Connection;
use RuntimeException;

final class SchemaStateRepository
{
	private Connection $database;

	public function __construct(Connection $database)
	{
		$this->database = $database;
	}

	public function storageReady(): bool
	{
		foreach (array('SchemaState', 'SchemaMigrations', 'UpdateRuns') as $table)
			if (!$this->tableExists($table))
				return false;
		return true;
	}

	public function currentVersion(): ?string
	{
		if (!$this->tableExists('SchemaState'))
			return null;
		$version = $this->database->fetch1($this->database->select(
			'SchemaState',
			'ssVersion',
			'ssKey=?',
			array('current'),
			'',
			1
		));
		if (!is_string($version) || !SchemaVersion::isValid($version))
			return null;
		return $version;
	}

	public function setCurrentVersion(string $version): void
	{
		$this->assertStorageReady();
		$this->database->replace('SchemaState', array(
			'ssKey' => 'current',
			'ssVersion' => SchemaVersion::requireValid($version),
			'ssUpdatedAt' => time(),
		));
		$this->database->replace('Cfg', array(
			'Module' => 'Const',
			'Prop' => 'SchemaVersion',
			'Val' => $version,
		));
	}

	public function installedApplicationVersion(): ?string
	{
		if (!$this->tableExists('Cfg')) return null;
		$version = $this->database->fetch1($this->database->select(
			'Cfg',
			'Val',
			'Module=? and Prop=?',
			array('Const', 'AppVersion'),
			'',
			1
		));
		return is_string($version) && SchemaVersion::isValid($version) ? $version : null;
	}

	public function setInstalledApplicationVersion(string $version): void
	{
		$this->database->replace('Cfg', array(
			'Module' => 'Const',
			'Prop' => 'AppVersion',
			'Val' => SchemaVersion::requireValid($version, 'installed application version'),
		));
	}

	public function migration(string $id): ?array
	{
		if (!$this->tableExists('SchemaMigrations'))
			return null;
		$row = $this->database->fetch1Row($this->database->select(
			'SchemaMigrations',
			'*',
			'smID=?',
			array($id),
			'',
			1
		));
		return $row ?: null;
	}

	public function assertKnownChecksum(VersionedMigration $migration): void
	{
		$row = $this->migration($migration->id());
		if ($row && !hash_equals((string)$row['smChecksum'], $migration->checksum()))
			throw new RuntimeException('Checksum changed for known migration ' . $migration->id());
	}

	public function beginMigration(VersionedMigration $migration, string $applicationVersion): bool
	{
		$this->assertStorageReady();
		$this->assertKnownChecksum($migration);
		$row = $this->migration($migration->id());
		if ($row && $row['smStatus'] === 'applied')
			return false;
		$values = array(
			'smChecksum' => $migration->checksum(),
			'smFromVersion' => $migration->fromVersion(),
			'smToVersion' => $migration->toVersion(),
			'smClassification' => $migration->classification(),
			'smStatus' => 'running',
			'smAppVersion' => SchemaVersion::requireValid($applicationVersion, 'application version'),
			'smStartedAt' => time(),
			'smFinishedAt' => 0,
			'smErrorCode' => '',
			'smErrorSummary' => '',
		);
		if ($row)
			$this->database->update('SchemaMigrations', $values, '', 'smID=?', array($migration->id()));
		else
		{
			$values = array('smID' => $migration->id()) + $values;
			$this->database->insert('SchemaMigrations', $values);
		}
		return true;
	}

	public function completeMigrationAndSetVersion(VersionedMigration $migration): void
	{
		$this->setCurrentVersion($migration->toVersion());
		$this->database->update('SchemaMigrations', array(
			'smStatus' => 'applied',
			'smFinishedAt' => time(),
			'smErrorCode' => '',
			'smErrorSummary' => '',
		), '', 'smID=?', array($migration->id()));
	}

	public function failMigration(string $id, string $code, string $summary): void
	{
		$this->database->update('SchemaMigrations', array(
			'smStatus' => 'failed',
			'smFinishedAt' => time(),
			'smErrorCode' => substr(preg_replace('/[^a-z0-9._-]/', '_', strtolower($code)), 0, 64),
			'smErrorSummary' => substr(trim($summary), 0, 500),
		), '', 'smID=?', array($id));
	}

	private function assertStorageReady(): void
	{
		if (!$this->storageReady())
			throw new RuntimeException('Migration storage is not initialized');
	}

	private function tableExists(string $table): bool
	{
		return (bool)$this->database->fetch1($this->database->query(
			'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
			array($table)
		));
	}
}
