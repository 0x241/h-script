<?php

namespace HScript\Backup;

use ErrorException;
use PDO;
use RuntimeException;
use Throwable;

final class PdoStreamingRestoreAdapter
{
	private int $maximumStatementBytes;

	public function __construct(?int $maximumStatementBytes = null)
	{
		if ($maximumStatementBytes === null)
		{
			$value = getenv('RESTORE_MAX_STATEMENT_BYTES');
			$maximumStatementBytes = is_string($value) && ctype_digit($value) ? (int)$value : 16777216;
		}
		$this->maximumStatementBytes = max(1048576, min($maximumStatementBytes, 268435456));
	}

	public function restore(string $path, string $compression, DatabaseCredentials $target): void
	{
		$database = $this->connect($target);
		$reader = $this->openReader($path, $compression);
		$buffer = '';
		$state = 'normal';
		$pending = '';
		$escaped = false;
		$blockStar = false;
		try
		{
			while (true)
			{
				$chunk = $compression === 'gzip' ? gzread($reader, 65536) : fread($reader, 65536);
				if ($chunk === false)
					throw new RuntimeException('Backup archive could not be read for PDO restore');
				if ($chunk === '')
				{
					if ($compression === 'gzip' ? gzeof($reader) : feof($reader)) break;
					continue;
				}
				$length = strlen($chunk);
				for ($index = 0; $index < $length; $index++)
					$this->consume($chunk[$index], $database, $buffer, $state, $pending, $escaped, $blockStar);
			}
			$buffer .= $pending;
			$pending = '';
			if (in_array($state, array('single', 'double', 'backtick', 'block'), true))
				throw new RuntimeException('Backup SQL ended inside a quoted value or comment');
			$this->execute($database, $buffer);
		}
		catch (Throwable $exception)
		{
			throw new RuntimeException('PDO restore failed', 0, $exception);
		}
		finally
		{
			if ($compression === 'gzip') gzclose($reader); else fclose($reader);
		}
	}

	private function consume(
		string $character,
		PDO $database,
		string &$buffer,
		string &$state,
		string &$pending,
		bool &$escaped,
		bool &$blockStar
	): void {
		if ($state === 'line')
		{
			$this->append($buffer, $character);
			if ($character === "\n") $state = 'normal';
			return;
		}
		if ($state === 'block')
		{
			$this->append($buffer, $character);
			if ($blockStar && $character === '/')
			{
				$state = 'normal';
				$blockStar = false;
			}
			else
				$blockStar = $character === '*';
			return;
		}
		if (in_array($state, array('single', 'double', 'backtick'), true))
		{
			$this->append($buffer, $character);
			if ($escaped)
			{
				$escaped = false;
				return;
			}
			if ($character === '\\' && $state !== 'backtick')
			{
				$escaped = true;
				return;
			}
			$delimiter = $state === 'single' ? "'" : ($state === 'double' ? '"' : '`');
			if ($character === $delimiter) $state = 'normal';
			return;
		}

		if ($pending !== '')
		{
			if ($pending === '/' && $character === '*')
			{
				$this->append($buffer, '/*');
				$pending = '';
				$state = 'block';
				$blockStar = false;
				return;
			}
			if ($pending === '-' && $character === '-')
			{
				$pending = '--';
				return;
			}
			if ($pending === '--' && ctype_space($character))
			{
				$this->append($buffer, '--' . $character);
				$pending = '';
				$state = 'line';
				return;
			}
			$this->append($buffer, $pending);
			$pending = '';
			$this->consume($character, $database, $buffer, $state, $pending, $escaped, $blockStar);
			return;
		}

		if ($character === '/' || $character === '-')
		{
			$pending = $character;
			return;
		}
		$this->append($buffer, $character);
		if ($character === '#') $state = 'line';
		elseif ($character === "'") $state = 'single';
		elseif ($character === '"') $state = 'double';
		elseif ($character === '`') $state = 'backtick';
		elseif ($character === ';')
		{
			$this->execute($database, substr($buffer, 0, -1));
			$buffer = '';
		}
	}

	private function append(string &$buffer, string $value): void
	{
		if (strlen($buffer) + strlen($value) > $this->maximumStatementBytes)
			throw new RuntimeException('Backup SQL statement exceeds RESTORE_MAX_STATEMENT_BYTES');
		$buffer .= $value;
	}

	private function execute(PDO $database, string $statement): void
	{
		$statement = trim($statement);
		if ($statement === '') return;
		$withoutComments = preg_replace(array('#/\*.*?\*/#s', '/^\s*(?:--[^\n]*|#[^\n]*)(?:\n|$)/m'), '', $statement);
		if (trim((string)$withoutComments) === '') return;
		$database->exec($statement);
	}

	private function connect(DatabaseCredentials $credentials): PDO
	{
		$dsn = $credentials->socket() !== ''
			? 'mysql:unix_socket=' . $credentials->socket() . ';dbname=' . $credentials->database() . ';charset=utf8mb4'
			: 'mysql:host=' . $credentials->host() . ';port=' . $credentials->port() . ';dbname=' . $credentials->database() . ';charset=utf8mb4';
		return new PDO($dsn, $credentials->username(), $credentials->password(), array(
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_EMULATE_PREPARES => false,
		));
	}

	private function openReader(string $path, string $compression): mixed
	{
		if (!in_array($compression, array('plain', 'gzip'), true))
			throw new RuntimeException('Backup compression is invalid');
		set_error_handler(static function (int $severity, string $message): never {
			throw new ErrorException($message, 0, $severity);
		});
		try
		{
			$reader = $compression === 'gzip' ? gzopen($path, 'rb') : fopen($path, 'rb');
		}
		finally
		{
			restore_error_handler();
		}
		if ($reader === false)
			throw new RuntimeException('Backup archive could not be opened for PDO restore');
		return $reader;
	}
}
