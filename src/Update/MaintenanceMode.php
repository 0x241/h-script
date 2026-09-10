<?php

namespace HScript\Update;

use JsonException;
use RuntimeException;

final class MaintenanceMode
{
	private string $marker;

	public function __construct(string $projectRoot)
	{
		$this->marker = rtrim($projectRoot, '/') . '/.cfg/maintenance.json';
	}

	public function enable(string $runId, string $targetVersion): void
	{
		if (!preg_match('/^[a-f0-9]{32}$/', $runId))
			throw new RuntimeException('Update run ID is invalid');
		$this->write(array(
			'format' => 1,
			'run_id' => $runId,
			'target_version' => SchemaVersion::requireValid($targetVersion),
			'enabled_at' => gmdate('Y-m-d\TH:i:s\Z'),
		));
	}

	public function disable(string $runId): void
	{
		$current = $this->status();
		if ($current !== null && $current['run_id'] !== $runId)
			throw new RuntimeException('Maintenance mode belongs to another update run');
		if (is_file($this->marker) && !unlink($this->marker))
			throw new RuntimeException('Maintenance mode could not be disabled');
	}

	public function status(): ?array
	{
		if (!is_file($this->marker) || is_link($this->marker))
			return null;
		try
		{
			$data = json_decode((string)file_get_contents($this->marker), true, 8, JSON_THROW_ON_ERROR);
		}
		catch (JsonException $exception)
		{
			throw new RuntimeException('Maintenance marker is invalid', 0, $exception);
		}
		if (!is_array($data) || ($data['format'] ?? null) !== 1 || !preg_match('/^[a-f0-9]{32}$/', (string)($data['run_id'] ?? '')))
			throw new RuntimeException('Maintenance marker is invalid');
		return $data;
	}

	private function write(array $data): void
	{
		$directory = dirname($this->marker);
		if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory))
			throw new RuntimeException('Maintenance directory could not be created');
		$temporary = $this->marker . '.' . bin2hex(random_bytes(8)) . '.part';
		$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
		if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json))
			throw new RuntimeException('Maintenance marker could not be written');
		chmod($temporary, 0600);
		if (!rename($temporary, $this->marker))
		{
			unlink($temporary);
			throw new RuntimeException('Maintenance mode could not be enabled');
		}
	}
}
