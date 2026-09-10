<?php

namespace HScript\Backup;

use RuntimeException;

final class BackupStreamWriter
{
	private string $path;
	private string $compression;
	private int $maximumBytes;
	private mixed $stream;
	private int $rawBytes = 0;
	private bool $closed = false;

	public function __construct(string $path, string $compression, int $maximumBytes)
	{
		if (!in_array($compression, array('gzip', 'plain'), true))
			throw new RuntimeException('Backup compression must be gzip or plain');
		$this->path = $path;
		$this->compression = $compression;
		$this->maximumBytes = $maximumBytes;
		$this->stream = $compression === 'gzip' ? gzopen($path, 'wb6') : fopen($path, 'xb');
		if ($this->stream === false)
			throw new RuntimeException('Temporary backup file could not be opened');
		chmod($path, 0640);
	}

	public function write(string $chunk): void
	{
		if ($this->closed)
			throw new RuntimeException('Backup writer is already closed');
		$length = strlen($chunk);
		if ($this->rawBytes + $length > $this->maximumBytes)
			throw new RuntimeException('Backup exceeds BACKUP_MAX_BYTES');
		$offset = 0;
		while ($offset < $length)
		{
			$written = $this->compression === 'gzip'
				? gzwrite($this->stream, substr($chunk, $offset))
				: fwrite($this->stream, substr($chunk, $offset));
			if ($written === false || $written === 0)
				throw new RuntimeException('Backup stream could not be written');
			$offset += $written;
		}
		$this->rawBytes += $length;
	}

	public function close(): array
	{
		if (!$this->closed)
		{
			$closed = $this->compression === 'gzip' ? gzclose($this->stream) : fclose($this->stream);
			$this->closed = true;
			if (!$closed)
				throw new RuntimeException('Backup stream could not be finalized');
		}
		clearstatcache(true, $this->path);
		$storedBytes = filesize($this->path);
		$checksum = hash_file('sha256', $this->path);
		if (!is_int($storedBytes) || $storedBytes < 1 || !is_string($checksum))
			throw new RuntimeException('Finalized backup file is invalid');
		return array(
			'uncompressed_size' => $this->rawBytes,
			'stored_size' => $storedBytes,
			'sha256' => $checksum,
		);
	}

	public function __destruct()
	{
		if (!$this->closed && is_resource($this->stream))
		{
			if ($this->compression === 'gzip')
				gzclose($this->stream);
			else
				fclose($this->stream);
		}
	}
}
