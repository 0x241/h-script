<?php

namespace HScript\Backup;

use RuntimeException;
use Throwable;

final class MysqldumpBackupAdapter implements DatabaseBackupAdapter
{
	private ?string $binary;

	public function __construct(?string $binary = null)
	{
		$this->binary = $binary ?: $this->findBinary();
	}

	public function name(): string
	{
		return 'mysqldump';
	}

	public function available(): bool
	{
		return $this->binary !== null;
	}

	public function dump(DatabaseCredentials $credentials, array $tables, callable $write, string $footerMarker): void
	{
		if (!$this->binary)
			throw new RuntimeException('mysqldump is not available');
		$command = array(
			$this->binary,
			'--single-transaction',
			'--quick',
			'--skip-lock-tables',
			'--skip-extended-insert',
			'--routines',
			'--events',
			'--triggers',
			'--hex-blob',
			'--default-character-set=utf8mb4',
			'--user=' . $credentials->username(),
		);
		if ($credentials->socket() !== '')
			$command[] = '--socket=' . $credentials->socket();
		else
		{
			$command[] = '--protocol=TCP';
			$command[] = '--host=' . $credentials->host();
			$command[] = '--port=' . $credentials->port();
		}
		$command[] = $credentials->database();
		foreach ($tables as $table)
		{
			if (!is_string($table) || !preg_match('/^[A-Za-z0-9_]+$/', $table))
				throw new RuntimeException('Unsafe database table name');
			$command[] = $table;
		}

		$environment = getenv();
		if (!is_array($environment))
			$environment = array();
		$environment['MYSQL_PWD'] = $credentials->password();
		$descriptor = array(
			0 => array('pipe', 'r'),
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w'),
		);
		$pipes = array();
		$process = proc_open($command, $descriptor, $pipes, null, $environment, array('bypass_shell' => true));
		if (!is_resource($process))
			throw new RuntimeException('mysqldump process could not be started');
		fclose($pipes[0]);
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);
		$error = '';

		try
		{
			$write("-- H-Script verified database backup\nSET NAMES utf8mb4;\n");
			$open = array(1 => true, 2 => true);
			while ($open)
			{
				$read = array();
				foreach (array_keys($open) as $index)
					$read[] = $pipes[$index];
				$writeSet = null;
				$except = null;
				$selected = stream_select($read, $writeSet, $except, 5);
				if ($selected === false)
					throw new RuntimeException('Could not read mysqldump output');
				foreach ($read as $stream)
				{
					$index = $stream === $pipes[1] ? 1 : 2;
					$chunk = fread($stream, 65536);
					if ($chunk === false)
						throw new RuntimeException('Could not read mysqldump stream');
					if ($chunk !== '')
					{
						if ($index === 1)
							$write($chunk);
						elseif (strlen($error) < 16384)
							$error .= substr($chunk, 0, 16384 - strlen($error));
					}
					if (feof($stream))
					{
						fclose($stream);
						unset($open[$index]);
					}
				}
			}
			$exitCode = proc_close($process);
			$process = null;
			if ($exitCode !== 0)
				throw new RuntimeException('mysqldump failed: ' . $this->safeError($error));
			$write("\n" . $footerMarker . "\n");
		}
		catch (Throwable $exception)
		{
			foreach ($pipes as $pipe)
				if (is_resource($pipe))
					fclose($pipe);
			if (is_resource($process))
			{
				proc_terminate($process);
				proc_close($process);
			}
			throw $exception;
		}
	}

	private function findBinary(): ?string
	{
		$configured = trim((string)(getenv('BACKUP_MYSQLDUMP_PATH') ?: ''));
		$candidates = $configured !== ''
			? array($configured)
			: array('/usr/bin/mysqldump', '/usr/local/bin/mysqldump', '/usr/bin/mariadb-dump', '/usr/local/bin/mariadb-dump');
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
