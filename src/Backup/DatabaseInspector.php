<?php

namespace HScript\Backup;

use HScript\Database\Connection;
use RuntimeException;

final class DatabaseInspector
{
	private Connection $database;

	public function __construct(Connection $database)
	{
		$this->database = $database;
	}

	public function tables(): array
	{
		$rows = $this->database->fetchRows($this->database->query(
			"SHOW FULL TABLES WHERE Table_type='BASE TABLE'"
		));
		$tables = array();
		foreach ($rows as $row)
		{
			$table = (string)reset($row);
			if (!preg_match('/^[A-Za-z0-9_]+$/', $table))
				throw new RuntimeException('Database contains an unsupported table name');
			$tables[] = $table;
		}
		sort($tables, SORT_STRING);
		if (!$tables)
			throw new RuntimeException('Database has no tables to back up');
		foreach (array('Cfg', 'Users') as $coreTable)
			if (!in_array($coreTable, $tables, true))
				throw new RuntimeException('Core table is missing: ' . $coreTable);
		return $tables;
	}

	public function estimatedBytes(): int
	{
		$value = $this->database->fetch1($this->database->query(
			'SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()'
		));
		return max(0, (int)$value);
	}

	/** @return array<string,int> */
	public function unsupportedObjects(): array
	{
		$queries = array(
			'views' => 'SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE()',
			'triggers' => 'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()',
			'routines' => 'SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()',
			'events' => 'SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE()',
		);
		$result = array();
		foreach ($queries as $type => $query)
		{
			$count = (int)$this->database->fetch1($this->database->query($query));
			if ($count > 0) $result[$type] = $count;
		}
		return $result;
	}
}
