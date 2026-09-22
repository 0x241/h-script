<?php

namespace HScript\Recovery;

use RuntimeException;

final class RecoverySettings
{
	private const FORBIDDEN_RUNTIME_PATHS = array(
		'.env', '.git', '.agents', 'backup', 'cache', 'compile', 'docker', 'logs',
		'node_modules', 'runtime', 'tmp', 'tpl_c', 'vendor', '.cfg/update',
		'.cfg/security/audit.ndjson', '.cfg/security/rate-limits.json',
	);
	private const PROTECTED_RUNTIME_PATHS = array(
		'_config.php', '_config.local.php', 'module/_config/pass',
		'config/_config.php', 'config/_config.local.php', 'config/configurator-pass',
	);
	private const ALLOWED_RUNTIME_PATHS = array(
		'upload', 'themes', 'tpl/themes', '.cfg/security/integrity-state.json',
		'_config.php', '_config.local.php', 'module/_config/pass',
		'config/_config.php', 'config/_config.local.php', 'config/configurator-pass',
	);

	public function __construct(
		private string $projectRoot,
		private string $backupDirectory,
		private string $stateDirectory,
		private string $runtimeRoot,
		private array $runtimePaths,
		private bool $includeProtectedConfig,
		private int $maximumRuntimeFiles,
		private int $maximumRuntimeBytes,
		private string $tarBinary,
		private int $localRetentionCount,
		private int $localRetentionDays,
		private string $rowBoundsFile,
		private string $alertCommand
	) {
		$this->projectRoot = $this->directory($projectRoot, false, 'Project root');
		$this->backupDirectory = $this->directory($backupDirectory, true, 'Backup directory');
		$this->runtimeRoot = $this->directory($runtimeRoot, false, 'Runtime root');
		$this->stateDirectory = $this->prepareStateDirectory($stateDirectory);
		$this->runtimePaths = $this->validateRuntimePaths($runtimePaths);
		$this->tarBinary = $this->executable($tarBinary, 'tar');
		if ($alertCommand !== '')
			$this->alertCommand = $this->executable($alertCommand, 'alert');
	}

	public static function fromEnvironment(string $projectRoot, string $backupDirectory): self
	{
		$projectRoot = rtrim((string)realpath($projectRoot), '/');
		$backupDirectory = rtrim((string)realpath($backupDirectory), '/');
		$state = self::environment('RECOVERY_STATE_PATH', $backupDirectory . '/recovery');
		$runtimeRoot = self::environment('RECOVERY_RUNTIME_ROOT', $projectRoot);
		$paths = self::environment('RECOVERY_RUNTIME_PATHS', '');
		if ($paths === '')
			$paths = is_dir($runtimeRoot . '/themes') ? 'upload,themes' : 'upload,tpl/themes';

		return new self(
			$projectRoot,
			$backupDirectory,
			$state,
			$runtimeRoot,
			array_map('trim', explode(',', $paths)),
			self::booleanEnvironment('RECOVERY_INCLUDE_PROTECTED_CONFIG', false),
			self::integerEnvironment('RECOVERY_RUNTIME_MAX_FILES', 100000, 1, 1000000),
			self::integerEnvironment('RECOVERY_RUNTIME_MAX_BYTES', 10737418240, 1, 1099511627776),
			self::environment('RECOVERY_TAR_PATH', self::findExecutable(array('/bin/tar', '/usr/bin/tar'))),
			self::integerEnvironment('BACKUP_RETENTION_COUNT', 10, 1, 100),
			self::integerEnvironment('BACKUP_RETENTION_DAYS', 30, 1, 3650),
			self::environment('RECOVERY_ROW_BOUNDS_FILE', ''),
			self::environment('RECOVERY_ALERT_COMMAND', '')
		);
	}

	public function policySeconds(string $target, string $objective): int
	{
		$name = 'RECOVERY_' . strtoupper($target) . '_' . strtoupper($objective) . '_SECONDS';
		$value = getenv($name);
		if (!is_string($value) || !ctype_digit($value) || (int)$value < 1)
			throw new RecoveryException('policy_not_configured', $name . ' must be a positive integer');
		return (int)$value;
	}

	public function drillMaximumAgeSeconds(): int
	{
		$value = getenv('RECOVERY_DRILL_MAX_AGE_SECONDS');
		if (!is_string($value) || !ctype_digit($value) || (int)$value < 1)
			throw new RecoveryException('policy_not_configured', 'RECOVERY_DRILL_MAX_AGE_SECONDS must be a positive integer');
		return (int)$value;
	}

	private function validateRuntimePaths(array $paths): array
	{
		$result = array();
		foreach ($paths as $path)
		{
			$path = trim(str_replace('\\', '/', (string)$path), '/');
			if ($path === '' || $path === '.' || str_contains($path, "\0") || preg_match('#(?:^|/)\.\.(?:/|$)#', $path))
				throw new RuntimeException('Runtime allowlist path is invalid');
			if (!preg_match('#^[A-Za-z0-9._/-]+$#', $path))
				throw new RuntimeException('Runtime allowlist path contains unsupported characters');
			foreach (self::FORBIDDEN_RUNTIME_PATHS as $forbidden)
				if ($path === $forbidden || str_starts_with($path . '/', $forbidden . '/'))
					throw new RuntimeException('Runtime allowlist contains forbidden path: ' . $path);
			if (!in_array($path, self::ALLOWED_RUNTIME_PATHS, true))
				throw new RuntimeException('Runtime allowlist path is not supported: ' . $path);
			foreach (self::PROTECTED_RUNTIME_PATHS as $protected)
				if (($path === $protected || str_starts_with($path . '/', $protected . '/')) && !$this->includeProtectedConfig)
					throw new RuntimeException('Protected configuration requires RECOVERY_INCLUDE_PROTECTED_CONFIG=1');
			$result[$path] = true;
		}
		if (!$result)
			throw new RuntimeException('Runtime allowlist must not be empty');
		$paths = array_keys($result);
		sort($paths, SORT_STRING);
		return $paths;
	}

	private function directory(string $path, bool $writable, string $label): string
	{
		$resolved = realpath($path);
		if ($resolved === false || !is_dir($resolved) || ($writable && !is_writable($resolved)))
			throw new RuntimeException($label . ' is invalid');
		return rtrim($resolved, '/');
	}

	private function prepareStateDirectory(string $path): string
	{
		if (!str_starts_with($path, '/'))
			throw new RuntimeException('RECOVERY_STATE_PATH must be absolute');
		if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path))
			throw new RuntimeException('Recovery state directory could not be created');
		chmod($path, 0700);
		return $this->directory($path, true, 'Recovery state directory');
	}

	private function executable(string $path, string $label): string
	{
		if (!str_starts_with($path, '/') || !is_file($path) || !is_executable($path))
			throw new RuntimeException($label . ' executable is invalid');
		return $path;
	}

	private static function environment(string $name, string $default): string
	{
		$value = getenv($name);
		return is_string($value) && trim($value) !== '' ? trim($value) : $default;
	}

	private static function booleanEnvironment(string $name, bool $default): bool
	{
		$value = getenv($name);
		if (!is_string($value) || trim($value) === '') return $default;
		return in_array(strtolower(trim($value)), array('1', 'true', 'yes', 'on'), true);
	}

	private static function integerEnvironment(string $name, int $default, int $minimum, int $maximum): int
	{
		$value = getenv($name);
		$value = is_string($value) && ctype_digit($value) ? (int)$value : $default;
		return max($minimum, min($value, $maximum));
	}

	private static function findExecutable(array $candidates): string
	{
		foreach ($candidates as $candidate)
			if (is_file($candidate) && is_executable($candidate)) return $candidate;
		return '';
	}

	public function projectRoot(): string { return $this->projectRoot; }
	public function backupDirectory(): string { return $this->backupDirectory; }
	public function stateDirectory(): string { return $this->stateDirectory; }
	public function runtimeRoot(): string { return $this->runtimeRoot; }
	public function runtimePaths(): array { return $this->runtimePaths; }
	public function includeProtectedConfig(): bool { return $this->includeProtectedConfig; }
	public function maximumRuntimeFiles(): int { return $this->maximumRuntimeFiles; }
	public function maximumRuntimeBytes(): int { return $this->maximumRuntimeBytes; }
	public function tarBinary(): string { return $this->tarBinary; }
	public function localRetentionCount(): int { return $this->localRetentionCount; }
	public function localRetentionDays(): int { return $this->localRetentionDays; }
	public function rowBoundsFile(): string { return $this->rowBoundsFile; }
	public function alertCommand(): string { return $this->alertCommand; }
}
