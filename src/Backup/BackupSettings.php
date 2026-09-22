<?php

namespace HScript\Backup;

use RuntimeException;

final class BackupSettings
{
	private string $directory;
	private string $location;
	private int $maximumBytes;
	private int $minimumFreeBytes;
	private int $retentionCount;
	private int $retentionDays;

	public function __construct(
		string $directory,
		string $location,
		int $maximumBytes = 2147483648,
		int $minimumFreeBytes = 134217728,
		int $retentionCount = 10,
		int $retentionDays = 30
	) {
		$resolvedDirectory = realpath($directory);
		if ($resolvedDirectory === false || !is_dir($resolvedDirectory) || !is_writable($resolvedDirectory))
			throw new RuntimeException('Backup directory must exist and be writable');
		if (!in_array($location, array('external', 'document-root-protected'), true))
			throw new RuntimeException('Backup storage location is invalid');
		$this->directory = rtrim($resolvedDirectory, '/');
		$this->location = $location;
		$this->maximumBytes = max(10485760, min($maximumBytes, 107374182400));
		$this->minimumFreeBytes = max(10485760, $minimumFreeBytes);
		$this->retentionCount = max(1, min($retentionCount, 100));
		$this->retentionDays = max(1, min($retentionDays, 3650));
	}

	public static function fromEnvironment(string $projectRoot): self
	{
		$projectRoot = rtrim((string)realpath($projectRoot), '/');
		if ($projectRoot === '')
			throw new RuntimeException('Project root could not be resolved');
		$configured = trim((string)(getenv('BACKUP_STORAGE_PATH') ?: ''));
		if ($configured !== '' && !str_starts_with($configured, '/'))
			throw new RuntimeException('BACKUP_STORAGE_PATH must be absolute');

		if ($configured !== '')
			$directory = $configured;
		else
		{
			$external = dirname($projectRoot) . '/hscript-backups';
			if (is_dir($external) && is_writable($external))
				$directory = $external;
			else
			{
				$directory = $projectRoot . '/backup';
			}
		}
		if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory))
			throw new RuntimeException('Backup directory could not be created');
		if (!is_writable($directory))
			throw new RuntimeException('Backup directory is not writable');
		$resolvedDirectory = realpath($directory);
		if ($resolvedDirectory === false)
			throw new RuntimeException('Backup directory could not be resolved');
		$directory = rtrim($resolvedDirectory, '/');
		if ($directory === '' || $directory === $projectRoot || $directory === dirname($projectRoot))
			throw new RuntimeException('Backup storage must use a dedicated directory');
		$insideProject = $directory === $projectRoot || str_starts_with($directory . '/', $projectRoot . '/');
		$location = $insideProject ? 'document-root-protected' : 'external';
		if ($location === 'document-root-protected')
		{
			$denyFile = $directory . '/.htaccess';
			if (!is_file($denyFile) || is_link($denyFile) || filesize($denyFile) > 16384
				|| !preg_match('/^\s*Require\s+all\s+denied\s*$/mi', (string)file_get_contents($denyFile)))
				throw new RuntimeException('Document-root backup directory is not protected');
		}
		if (!chmod($directory, 0700)) throw new RuntimeException('Backup directory permissions could not be restricted');

		return new self(
			$directory,
			$location,
			self::integerEnvironment('BACKUP_MAX_BYTES', 2147483648),
			self::integerEnvironment('BACKUP_MIN_FREE_BYTES', 134217728),
			self::integerEnvironment('BACKUP_RETENTION_COUNT', 10),
			self::integerEnvironment('BACKUP_RETENTION_DAYS', 30)
		);
	}

	private static function integerEnvironment(string $name, int $default): int
	{
		$value = getenv($name);
		return is_string($value) && ctype_digit($value) ? (int)$value : $default;
	}

	public function directory(): string { return $this->directory; }
	public function location(): string { return $this->location; }
	public function maximumBytes(): int { return $this->maximumBytes; }
	public function minimumFreeBytes(): int { return $this->minimumFreeBytes; }
	public function retentionCount(): int { return $this->retentionCount; }
	public function retentionDays(): int { return $this->retentionDays; }
}
