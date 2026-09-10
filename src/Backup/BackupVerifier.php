<?php

namespace HScript\Backup;

use ErrorException;
use RuntimeException;

final class BackupVerifier
{
	public function verify(
		string $path,
		string $compression,
		string $expectedChecksum,
		int $expectedRawSize,
		array $expectedTables,
		string $backupId
	): array {
		if (!is_file($path) || !is_readable($path) || filesize($path) < 1)
			throw new RuntimeException('Backup archive is not readable');
		$actualChecksum = hash_file('sha256', $path);
		if (!is_string($actualChecksum) || !hash_equals($expectedChecksum, $actualChecksum))
			throw new RuntimeException('Backup archive checksum does not match');

		$foundTables = array();
		$rawSize = 0;
		$tail = '';
		$gzipCrc = $compression === 'gzip' ? hash_init('crc32b') : null;
		$this->withWarningsAsExceptions(function () use (
			$path, $compression, &$foundTables, &$rawSize, &$tail, $gzipCrc
		): void {
			$stream = $compression === 'gzip' ? gzopen($path, 'rb') : fopen($path, 'rb');
			if ($stream === false)
				throw new RuntimeException('Backup archive could not be opened');
			try
			{
				while (true)
				{
					$chunk = $compression === 'gzip' ? gzread($stream, 65536) : fread($stream, 65536);
					if ($chunk === false)
						throw new RuntimeException('Backup archive could not be read');
					if ($chunk === '')
					{
						$ended = $compression === 'gzip' ? gzeof($stream) : feof($stream);
						if ($ended)
							break;
						continue;
					}
					$rawSize += strlen($chunk);
					if ($gzipCrc !== null)
						hash_update($gzipCrc, $chunk);
					$scan = $tail . $chunk;
					if (preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)? `([A-Za-z0-9_]+)`/i', $scan, $matches))
						foreach ($matches[1] as $table)
							$foundTables[$table] = true;
					$tail = substr($scan, -4096);
				}
			}
			finally
			{
				if ($compression === 'gzip')
					gzclose($stream);
				else
					fclose($stream);
			}
		});
		if ($gzipCrc !== null)
			$this->verifyGzipTrailer($path, $gzipCrc, $rawSize);

		if ($rawSize !== $expectedRawSize)
			throw new RuntimeException('Backup uncompressed size does not match');
		foreach ($expectedTables as $table)
			if (!isset($foundTables[$table]))
				throw new RuntimeException('Backup is missing table definition: ' . $table);
		foreach (array('Cfg', 'Users') as $coreTable)
			if (!isset($foundTables[$coreTable]))
				throw new RuntimeException('Backup is missing core table: ' . $coreTable);
		if (count($foundTables) !== count($expectedTables))
			throw new RuntimeException('Backup table count does not match');
		$footer = '-- H-Script backup complete:' . $backupId;
		if (!str_contains($tail, $footer))
			throw new RuntimeException('Backup completion marker is missing');

		return array(
			'archive_readable' => true,
			'checksum_match' => true,
			'core_tables_present' => true,
			'footer_present' => true,
			'table_count_match' => true,
		);
	}

	private function verifyGzipTrailer(string $path, mixed $crcContext, int $rawSize): void
	{
		$this->withWarningsAsExceptions(function () use ($path, $crcContext, $rawSize): void {
			$stream = fopen($path, 'rb');
			if ($stream === false || fseek($stream, -8, SEEK_END) !== 0)
				throw new RuntimeException('Backup gzip trailer is not readable');
			try
			{
				$trailer = fread($stream, 8);
			}
			finally
			{
				fclose($stream);
			}
			if (!is_string($trailer) || strlen($trailer) !== 8)
				throw new RuntimeException('Backup gzip trailer is incomplete');
			$values = unpack('Vcrc/Visize', $trailer);
			$actualCrc = (int)hexdec(hash_final($crcContext));
			$actualSize = $rawSize & 0xffffffff;
			if (!is_array($values) || $values['crc'] !== $actualCrc || $values['isize'] !== $actualSize)
				throw new RuntimeException('Backup gzip integrity check failed');
		});
	}

	private function withWarningsAsExceptions(callable $callback): void
	{
		set_error_handler(static function (int $severity, string $message): never {
			throw new ErrorException($message, 0, $severity);
		});
		try
		{
			$callback();
		}
		finally
		{
			restore_error_handler();
		}
	}
}
