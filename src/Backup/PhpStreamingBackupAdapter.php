<?php

namespace HScript\Backup;

use PDO;
use RuntimeException;
use Throwable;

final class PhpStreamingBackupAdapter implements DatabaseBackupAdapter
{
	private int $maximumStatementBytes;

	public function __construct(int $maximumStatementBytes = 1048576)
	{
		$this->maximumStatementBytes = max(65536, min($maximumStatementBytes, 4194304));
	}

	public function name(): string
	{
		return 'php-stream';
	}

	public function available(): bool
	{
		return true;
	}

	public function dump(DatabaseCredentials $credentials, array $tables, callable $write, string $footerMarker): void
	{
		$database = $this->connect($credentials);
		$write("-- H-Script verified database backup\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
		$database->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
		$database->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
		try
		{
			foreach ($tables as $table)
				$this->dumpTable($database, $table, $write);
			$database->exec('ROLLBACK');
			$write("SET FOREIGN_KEY_CHECKS=1;\n" . $footerMarker . "\n");
		}
		catch (Throwable $exception)
		{
			try { $database->exec('ROLLBACK'); } catch (Throwable) {}
			throw $exception;
		}
	}

	private function connect(DatabaseCredentials $credentials): PDO
	{
		$dsn = $credentials->socket() !== ''
			? 'mysql:unix_socket=' . $credentials->socket() . ';dbname=' . $credentials->database() . ';charset=utf8mb4'
			: 'mysql:host=' . $credentials->host() . ';port=' . $credentials->port() . ';dbname=' . $credentials->database() . ';charset=utf8mb4';
		return new PDO($dsn, $credentials->username(), $credentials->password(), array(
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES => false,
			PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
		));
	}

	private function dumpTable(PDO $database, string $table, callable $write): void
	{
		if (!preg_match('/^[A-Za-z0-9_]+$/', $table))
			throw new RuntimeException('Unsafe database table name');
		$quotedTable = '`' . $table . '`';
		$definitionQuery = $database->query('SHOW CREATE TABLE ' . $quotedTable);
		$definition = $definitionQuery->fetch();
		$definitionQuery->closeCursor();
		$create = (string)($definition['Create Table'] ?? $definition['Create View'] ?? '');
		if ($create === '')
			throw new RuntimeException('Could not read table definition for ' . $table);
		$write("\nDROP TABLE IF EXISTS " . $quotedTable . ";\n" . $create . ";\n");

		$query = $database->query('SELECT * FROM ' . $quotedTable);
		$prefix = '';
		$statement = '';
		while ($row = $query->fetch())
		{
			if ($prefix === '')
				$prefix = 'INSERT INTO ' . $quotedTable . ' (' . $this->fieldList(array_keys($row)) . ') VALUES ';
			$values = '(' . implode(',', array_map(array($this, 'literal'), array_values($row))) . ')';
			if ($statement !== '' && strlen($prefix) + strlen($statement) + strlen($values) + 3 > $this->maximumStatementBytes)
			{
				$write($prefix . $statement . ";\n");
				$statement = '';
			}
			$statement .= ($statement === '' ? '' : ',') . $values;
		}
		$query->closeCursor();
		if ($statement !== '')
			$write($prefix . $statement . ";\n");
	}

	private function fieldList(array $fields): string
	{
		foreach ($fields as $field)
			if (!is_string($field) || !preg_match('/^[A-Za-z0-9_]+$/', $field))
				throw new RuntimeException('Unsafe database field name');
		return implode(',', array_map(static fn(string $field): string => '`' . $field . '`', $fields));
	}

	private function literal(mixed $value): string
	{
		if ($value === null)
			return 'NULL';
		return "FROM_BASE64('" . base64_encode((string)$value) . "')";
	}
}
