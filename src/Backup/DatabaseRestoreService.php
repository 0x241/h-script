<?php

namespace HScript\Backup;

use ErrorException;
use HScript\Database\Connection;
use HScript\Update\SchemaVersion;
use RuntimeException;
use Throwable;

final class DatabaseRestoreService
{
	private BackupService $backups;
	private DatabaseCredentials $source;
	private ?string $mysqlBinary;

	public function __construct(BackupService $backups, DatabaseCredentials $source, ?string $mysqlBinary = null)
	{
		$this->backups = $backups;
		$this->source = $source;
		$this->mysqlBinary = $mysqlBinary ?: $this->findMysqlBinary();
	}

	public function restore(
		string $backupId,
		DatabaseCredentials $target,
		string $confirmedDatabase,
		bool $allowNonEmpty = false,
		bool $allowCurrentDatabase = false
	): array {
		if ($confirmedDatabase !== $target->database())
			throw new RuntimeException('Target confirmation does not match target database');
		if ($target->databaseHash() === $this->source->databaseHash() && !$allowCurrentDatabase)
			throw new RuntimeException('Restoring into the configured application database requires --allow-current-database');
		if (!$this->mysqlBinary)
			throw new RuntimeException('mysql client is not available');

		$targetDatabase = new Connection();
		if (!$targetDatabase->open(
			$target->connectionHost(),
			$target->database(),
			$target->username(),
			$target->password()
		))
			throw new RuntimeException('Target database connection failed');
		try
		{
			$tableCount = (int)$targetDatabase->fetch1($targetDatabase->query(
				'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()'
			));
			if ($tableCount > 0 && !$allowNonEmpty)
				throw new RuntimeException('Target database is not empty; use --allow-non-empty-target explicitly');

			$archive = $this->backups->archive($backupId);
			try
			{
				$this->streamIntoMysql($archive['path'], $archive['manifest']['compression'], $target);
			}
			catch (Throwable $mysqlError)
			{
				error_log('mysql restore client failed; using PDO stream fallback');
				(new PdoStreamingRestoreAdapter())->restore(
					$archive['path'],
					$archive['manifest']['compression'],
					$target
				);
			}
			return $this->restoredState($targetDatabase, $target);
		}
		finally
		{
			$targetDatabase->close();
		}
	}

	private function streamIntoMysql(string $path, string $compression, DatabaseCredentials $target): void
	{
		$command = array($this->mysqlBinary, '--binary-mode', '--default-character-set=utf8mb4', '--user=' . $target->username());
		if ($target->socket() !== '')
			$command[] = '--socket=' . $target->socket();
		else
		{
			$command[] = '--protocol=TCP';
			$command[] = '--host=' . $target->host();
			$command[] = '--port=' . $target->port();
		}
		$command[] = $target->database();
		$environment = getenv();
		if (!is_array($environment))
			$environment = array();
		$environment['MYSQL_PWD'] = $target->password();
		$pipes = array();
		$process = proc_open($command, array(
			0 => array('pipe', 'r'),
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w'),
		), $pipes, null, $environment, array('bypass_shell' => true));
		if (!is_resource($process))
			throw new RuntimeException('mysql restore process could not be started');
		stream_set_blocking($pipes[0], false);
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);
		$error = '';
		$reader = null;
		try
		{
			$reader = $this->openReader($path, $compression);
			$pending = '';
			$inputEnded = false;
			while (!$inputEnded || $pending !== '')
			{
				if ($pending === '' && !$inputEnded)
				{
					$pending = $compression === 'gzip' ? gzread($reader, 65536) : fread($reader, 65536);
					if ($pending === false)
						throw new RuntimeException('Backup archive could not be read for restore');
					if ($pending === '')
						$inputEnded = $compression === 'gzip' ? gzeof($reader) : feof($reader);
				}
				$read = array($pipes[1], $pipes[2]);
				$write = $pending !== '' ? array($pipes[0]) : array();
				$except = null;
				if (stream_select($read, $write, $except, 5) === false)
					throw new RuntimeException('mysql restore stream failed');
				foreach ($read as $stream)
				{
					$chunk = fread($stream, 16384);
					if ($chunk !== false && $stream === $pipes[2] && strlen($error) < 16384)
						$error .= substr($chunk, 0, 16384 - strlen($error));
				}
				if ($write && $pending !== '')
				{
					$written = $this->writeProcessInput($pipes[0], $pending);
					if ($written === false || $written === 0)
					{
						$details = stream_get_contents($pipes[2]);
						throw new RuntimeException('mysql restore process stopped accepting data: ' . $this->safeError((string)$details));
					}
					$pending = (string)substr($pending, $written);
				}
			}
			fclose($pipes[0]);
			while (!feof($pipes[1]) || !feof($pipes[2]))
			{
				$read = array();
				if (!feof($pipes[1])) $read[] = $pipes[1];
				if (!feof($pipes[2])) $read[] = $pipes[2];
				$write = null;
				$except = null;
				if (!$read || stream_select($read, $write, $except, 5) === false)
					break;
				foreach ($read as $stream)
				{
					$chunk = fread($stream, 16384);
					if ($chunk !== false && $stream === $pipes[2] && strlen($error) < 16384)
						$error .= substr($chunk, 0, 16384 - strlen($error));
				}
			}
			fclose($pipes[1]);
			fclose($pipes[2]);
			$exitCode = proc_close($process);
			$process = null;
			if ($exitCode !== 0)
				throw new RuntimeException('mysql restore failed: ' . $this->safeError($error));
		}
		catch (Throwable $exception)
		{
			foreach ($pipes as $pipe)
				if (is_resource($pipe)) fclose($pipe);
			if (is_resource($process))
			{
				proc_terminate($process);
				proc_close($process);
			}
			throw $exception;
		}
		finally
		{
			if (is_resource($reader))
			{
				if ($compression === 'gzip') gzclose($reader); else fclose($reader);
			}
		}
	}

	private function writeProcessInput(mixed $stream, string $data): int|false
	{
		set_error_handler(static function (int $severity, string $message): never {
			throw new ErrorException($message, 0, $severity);
		});
		try
		{
			return fwrite($stream, $data);
		}
		finally
		{
			restore_error_handler();
		}
	}

	private function openReader(string $path, string $compression): mixed
	{
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
			throw new RuntimeException('Backup archive could not be opened for restore');
		return $reader;
	}

	private function restoredState(Connection $database, DatabaseCredentials $target): array
	{
		$schemaVersion = (string)$database->fetch1($database->select(
			'SchemaState', 'ssVersion', 'ssKey=?', array('current'), '', 1
		));
		if (!SchemaVersion::isValid($schemaVersion))
			throw new RuntimeException('Restored database has no valid schema version');
		return array(
			'target_database_sha256' => $target->databaseHash(),
			'schema_version' => $schemaVersion,
			'rows' => array(
				'Cfg' => $database->count('Cfg'),
				'Users' => $database->count('Users'),
			),
		);
	}

	private function findMysqlBinary(): ?string
	{
		$configured = trim((string)(getenv('BACKUP_MYSQL_PATH') ?: ''));
		$candidates = $configured !== '' ? array($configured) : array('/usr/bin/mysql', '/usr/local/bin/mysql', '/usr/bin/mariadb', '/usr/local/bin/mariadb');
		foreach ($candidates as $candidate)
			if (str_starts_with($candidate, '/') && is_file($candidate) && is_executable($candidate))
				return $candidate;
		return null;
	}

	private function safeError(string $error): string
	{
		$error = preg_replace('/\s+/', ' ', trim($error));
		return substr($error !== '' ? $error : 'unknown process error', 0, 500);
	}
}
