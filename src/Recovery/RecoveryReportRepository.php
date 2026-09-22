<?php

namespace HScript\Recovery;

use RuntimeException;
use Throwable;

final class RecoveryReportRepository
{
	private string $directory;

	public function __construct(private RecoverySettings $settings)
	{
		$this->directory = $settings->stateDirectory() . '/reports';
		if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory))
			throw new RuntimeException('Recovery report directory could not be created');
		chmod($this->directory, 0700);
	}

	public function record(
		string $type,
		string $bundleId,
		int $startedAt,
		array $components,
		string $status,
		string $errorCode = ''
	): array {
		if (!in_array($type, array('backup', 'drill'), true)
			|| !in_array($status, array('success', 'failed'), true)
			|| ($bundleId !== '' && !preg_match('/^[a-f0-9]{32}$/', $bundleId)))
			throw new RuntimeException('Recovery report identity is invalid');
		$finishedAt = time();
		$report = array(
			'format' => 1,
			'type' => $type,
			'bundle_id' => $bundleId,
			'started_at' => gmdate('Y-m-d\TH:i:s\Z', $startedAt),
			'completed_at' => gmdate('Y-m-d\TH:i:s\Z', $finishedAt),
			'duration_ms' => max(0, (int)round((microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? (float)$startedAt)) * 1000)),
			'components' => array_values(array_unique(array_filter($components, static fn(mixed $value): bool => is_string($value) && preg_match('/^[a-z][a-z0-9_]{0,63}$/', $value)))),
			'status' => $status,
			'error_code' => $status === 'failed' ? $this->safeErrorCode($errorCode) : '',
		);
		$suffix = gmdate('Ymd-His', $finishedAt) . '-' . ($bundleId !== '' ? $bundleId : bin2hex(random_bytes(8)));
		$this->writeJson($this->directory . '/' . $type . '-' . $suffix . '.json', $report);
		$this->writeJson($this->directory . '/latest-' . $type . '.json', $report);
		return $report;
	}

	public function latest(string $type): ?array
	{
		if (!in_array($type, array('backup', 'drill'), true))
			throw new RuntimeException('Recovery report type is invalid');
		$path = $this->directory . '/latest-' . $type . '.json';
		if (!is_file($path) || is_link($path) || filesize($path) > 1048576) return null;
		try
		{
			$data = json_decode((string)file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
			return is_array($data) ? $data : null;
		}
		catch (Throwable)
		{
			return null;
		}
	}

	public function recordHealth(array $health): string
	{
		$path = $this->directory . '/recovery-health.json';
		$this->writeJson($path, $health);
		return $path;
	}

	public function recordAlert(array $alert): string
	{
		$directory = $this->directory . '/alerts';
		if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory))
			throw new RuntimeException('Recovery alert directory could not be created');
		$path = $directory . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.json';
		$this->writeJson($path, $alert);
		return $path;
	}

	private function safeErrorCode(string $code): string
	{
		$code = strtolower(trim($code));
		return preg_match('/^[a-z][a-z0-9_]{0,63}$/', $code) ? $code : 'recovery_failed';
	}

	private function writeJson(string $path, array $data): void
	{
		\HScript\Backup\PrivateJsonFile::write($path, $data);
	}
}
