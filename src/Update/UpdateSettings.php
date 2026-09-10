<?php

namespace HScript\Update;

use RuntimeException;

final class UpdateSettings
{
	private string $projectRoot;
	private string $workDirectory;
	private int $maximumPackageBytes;
	private int $maximumUnpackedBytes;
	private int $maximumFiles;
	private int $minimumFreeBytes;
	private int $retentionCount;

	public function __construct(
		string $projectRoot,
		string $workDirectory,
		int $maximumPackageBytes = 268435456,
		int $maximumUnpackedBytes = 536870912,
		int $maximumFiles = 10000,
		int $minimumFreeBytes = 134217728,
		int $retentionCount = 3
	) {
		$root = realpath($projectRoot);
		if ($root === false || !is_dir($root))
			throw new RuntimeException('Project root could not be resolved');
		if (!str_starts_with($workDirectory, '/'))
			throw new RuntimeException('Update work directory must be absolute');
		$this->projectRoot = rtrim($root, '/');
		$this->workDirectory = rtrim($workDirectory, '/');
		$this->maximumPackageBytes = max(1048576, min($maximumPackageBytes, 2147483648));
		$this->maximumUnpackedBytes = max(1048576, min($maximumUnpackedBytes, 4294967296));
		$this->maximumFiles = max(10, min($maximumFiles, 100000));
		$this->minimumFreeBytes = max(10485760, $minimumFreeBytes);
		$this->retentionCount = max(1, min($retentionCount, 20));
		$this->initializeDirectories();
	}

	public static function fromEnvironment(string $projectRoot): self
	{
		$root = realpath($projectRoot);
		if ($root === false)
			throw new RuntimeException('Project root could not be resolved');
		$work = trim((string)(getenv('UPDATE_WORK_PATH') ?: ''));
		if ($work === '')
			$work = $root . '/.cfg/update';
		return new self(
			$root,
			$work,
			self::integerEnvironment('UPDATE_MAX_PACKAGE_BYTES', 268435456),
			self::integerEnvironment('UPDATE_MAX_UNPACKED_BYTES', 536870912),
			self::integerEnvironment('UPDATE_MAX_FILES', 10000),
			self::integerEnvironment('UPDATE_MIN_FREE_BYTES', 134217728),
			self::integerEnvironment('UPDATE_RETENTION_COUNT', 3)
		);
	}

	private function initializeDirectories(): void
	{
		foreach (array('', 'packages', 'staging', 'prepared', 'runs', 'previous', 'conflicts', 'baselines') as $suffix)
		{
			$path = $this->workDirectory . ($suffix === '' ? '' : '/' . $suffix);
			if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path))
				throw new RuntimeException('Update work directory could not be created');
			if (!is_writable($path))
				throw new RuntimeException('Update work directory is not writable');
			chmod($path, 0700);
		}
	}

	private static function integerEnvironment(string $name, int $default): int
	{
		$value = getenv($name);
		return is_string($value) && ctype_digit($value) ? (int)$value : $default;
	}

	public function projectRoot(): string { return $this->projectRoot; }
	public function workDirectory(): string { return $this->workDirectory; }
	public function maximumPackageBytes(): int { return $this->maximumPackageBytes; }
	public function maximumUnpackedBytes(): int { return $this->maximumUnpackedBytes; }
	public function maximumFiles(): int { return $this->maximumFiles; }
	public function minimumFreeBytes(): int { return $this->minimumFreeBytes; }
	public function retentionCount(): int { return $this->retentionCount; }
	public function packagesDirectory(): string { return $this->workDirectory . '/packages'; }
	public function stagingDirectory(): string { return $this->workDirectory . '/staging'; }
	public function preparedDirectory(): string { return $this->workDirectory . '/prepared'; }
	public function runsDirectory(): string { return $this->workDirectory . '/runs'; }
	public function previousDirectory(): string { return $this->workDirectory . '/previous'; }
	public function conflictsDirectory(): string { return $this->workDirectory . '/conflicts'; }
	public function baselinesDirectory(): string { return $this->workDirectory . '/baselines'; }
	public function baselinePath(): string { return $this->baselinesDirectory() . '/installed.json'; }
}
