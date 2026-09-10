<?php

namespace HScript\Update;

use HScript\Database\Connection;
use RuntimeException;

/**
 * One database-scoped lock shared by schema and package update operations.
 */
final class UpdateLock
{
	private Connection $database;
	private string $name;
	private bool $held = false;

	public function __construct(Connection $database)
	{
		$this->database = $database;
		$databaseName = (string)$database->fetch1($database->query('SELECT DATABASE()'));
		$this->name = 'hscript:update:' . substr(hash('sha256', $databaseName), 0, 32);
	}

	public function acquire(int $timeoutSeconds = 0): void
	{
		if ($this->held)
			return;
		if ($timeoutSeconds < 0 || $timeoutSeconds > 30)
			throw new RuntimeException('Update lock timeout must be between 0 and 30 seconds');
		$acquired = (int)$this->database->fetch1($this->database->query(
			'SELECT GET_LOCK(?, ?d)',
			array($this->name, $timeoutSeconds)
		));
		if ($acquired !== 1)
			throw new RuntimeException('Another H-Script update is already running');
		$this->held = true;
	}

	public function release(): void
	{
		if (!$this->held)
			return;
		$this->database->fetch1($this->database->query('SELECT RELEASE_LOCK(?)', array($this->name)));
		$this->held = false;
	}

	public function __destruct()
	{
		$this->release();
	}
}
