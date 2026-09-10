<?php

namespace HScript\Update;

use HScript\Application;
use HScript\Database\Connection;
use RuntimeException;
use Throwable;

final class MigrationRunner
{
	private Connection $database;
	private SchemaStateRepository $state;
	private MigrationLoader $loader;

	public function __construct(
		Connection $database,
		SchemaStateRepository $state,
		MigrationLoader $loader
	) {
		$this->database = $database;
		$this->state = $state;
		$this->loader = $loader;
	}

	/** @return VersionedMigration[] */
	public function preflight(string $targetVersion): array
	{
		$targetVersion = SchemaVersion::requireValid($targetVersion, 'target schema version');
		$currentVersion = $this->state->currentVersion();
		if ($currentVersion === null)
			throw new RuntimeException('Current schema version is not initialized');
		if (SchemaVersion::compare($targetVersion, $currentVersion) < 0)
			throw new RuntimeException('Schema downgrade is not supported');

		$migrations = $this->loader->all();
		foreach ($migrations as $migration)
		{
			$this->state->assertKnownChecksum($migration);
			$row = $this->state->migration($migration->id());
			if ($row && $row['smStatus'] !== 'applied'
				&& SchemaVersion::compare($currentVersion, $migration->toVersion()) >= 0)
				throw new RuntimeException('Migration state is incomplete after schema advanced: ' . $migration->id());
		}

		$plan = array();
		$cursor = $currentVersion;
		$visited = array();
		while (SchemaVersion::compare($cursor, $targetVersion) < 0)
		{
			$candidates = array_values(array_filter(
				$migrations,
				static fn(VersionedMigration $migration): bool => $migration->fromVersion() === $cursor
			));
			if (count($candidates) !== 1)
				throw new RuntimeException('Migration chain is missing or ambiguous after schema ' . $cursor);
			$migration = $candidates[0];
			if (SchemaVersion::compare($migration->toVersion(), $targetVersion) > 0)
				throw new RuntimeException('Migration chain overshoots target schema version');
			if (isset($visited[$migration->id()]))
				throw new RuntimeException('Migration chain contains a cycle');
			$visited[$migration->id()] = true;
			$plan[] = $migration;
			$cursor = $migration->toVersion();
		}
		if ($cursor !== $targetVersion)
			throw new RuntimeException('Migration chain does not reach target schema version');
		return $plan;
	}

	public function run(string $targetVersion, bool $backupAcknowledged = false): array
	{
		$lock = new UpdateLock($this->database);
		$lock->acquire();
		try
		{
			return $this->runLocked($targetVersion, $backupAcknowledged);
		}
		finally
		{
			$lock->release();
		}
	}

	public function runLocked(
		string $targetVersion,
		bool $backupAcknowledged = false,
		?string $applicationVersion = null,
		?callable $onMigration = null
	): array {
		$plan = $this->preflight($targetVersion);
		foreach ($plan as $migration)
			if (UpdateClassification::requiresBackup($migration->classification()) && !$backupAcknowledged)
				throw new RuntimeException('A verified backup is required before migration ' . $migration->id());

		$applicationVersion = $applicationVersion ?? Application::version();
		$applied = array();
		foreach ($plan as $migration)
		{
			if ($onMigration !== null)
				$onMigration($migration->id());
			if (!$this->state->beginMigration($migration, $applicationVersion))
				throw new RuntimeException('Applied migration conflicts with current schema state: ' . $migration->id());
			try
			{
				$this->database->beginJob();
				$migration->apply($this->database);
				$transactionActive = $this->database->link instanceof \PDO && $this->database->link->inTransaction();
				if (!$transactionActive) $this->database->beginJob();
				$this->state->completeMigrationAndSetVersion($migration);
				$this->database->endJob();
				$applied[] = $migration->id();
			}
			catch (Throwable $exception)
			{
				try { $this->database->cancelJob(); } catch (Throwable) {}
				$this->state->failMigration(
					$migration->id(),
					'migration_failed',
					'Migration failed; inspect the protected server log'
				);
				error_log('Migration ' . $migration->id() . ' failed: ' . $exception->getMessage());
				throw new RuntimeException('Migration failed: ' . $migration->id(), 0, $exception);
			}
		}
		return $applied;
	}
}
