<?php

namespace HScript\Update;

use HScript\Database\Connection;
use RuntimeException;

/**
 * One-time, non-destructive adoption of explicit application and schema metadata.
 */
final class SchemaStorageInstaller
{
	private Connection $database;

	public function __construct(Connection $database)
	{
		$this->database = $database;
	}

	public function install(string $acknowledgedApplication, string $acknowledgedSchema, array $definitions): void
	{
		$application = SchemaVersion::requireValid($acknowledgedApplication, 'acknowledged application version');
		$schema = SchemaVersion::requireValid($acknowledgedSchema, 'acknowledged schema version');
		UpdateCompatibility::fromRoot(dirname(__DIR__, 2))->assertSource($application, $schema);
		if (!$this->tableExists('Cfg') || $this->database->count('Cfg') < 1)
			throw new RuntimeException('A populated H-Script Cfg table is required');
		foreach (array('SchemaState', 'SchemaMigrations', 'UpdateRuns') as $table)
			if (!isset($definitions[$table]))
				throw new RuntimeException('Missing storage definition for ' . $table);

		$lock = new UpdateLock($this->database);
		$lock->acquire();
		try
		{
			foreach (array('SchemaState', 'SchemaMigrations', 'UpdateRuns') as $table)
				if (!$this->tableExists($table))
					$this->database->query(
						'CREATE TABLE ' . $this->database->field($table) . ' (' . $definitions[$table] . ') '
						. 'ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
					);
			$state = new SchemaStateRepository($this->database);
			$current = $state->currentVersion();
			if ($current !== null && $current !== $schema)
				throw new RuntimeException('Existing explicit schema version differs from acknowledged schema version');
			$installedApplication = $state->installedApplicationVersion();
			if ($installedApplication !== null && $installedApplication !== $application)
				throw new RuntimeException('Existing explicit application version differs from acknowledged application version');
			$state->setCurrentVersion($schema);
			$state->setInstalledApplicationVersion($application);
			$this->database->delete('Cfg', 'Module=? and Prop=?', array('Const', 'DBVer'));
		}
		finally
		{
			$lock->release();
		}
	}

	private function tableExists(string $table): bool
	{
		return (bool)$this->database->fetch1($this->database->query(
			'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
			array($table)
		));
	}
}
