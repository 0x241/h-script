<?php

namespace HScript\Update;

use InvalidArgumentException;

final class MigrationLoader
{
	private string $directory;

	public function __construct(string $directory)
	{
		$this->directory = rtrim($directory, '/');
	}

	/** @return VersionedMigration[] */
	public function all(): array
	{
		if (!is_dir($this->directory))
			return array();
		$paths = glob($this->directory . '/*.php');
		if ($paths === false)
			throw new InvalidArgumentException('Migration directory could not be read');
		sort($paths, SORT_STRING);
		$migrations = array();
		foreach ($paths as $path)
		{
			$migration = VersionedMigration::fromFile($path);
			if (isset($migrations[$migration->id()]))
				throw new InvalidArgumentException('Duplicate migration ID: ' . $migration->id());
			$migrations[$migration->id()] = $migration;
		}
		return array_values($migrations);
	}
}
