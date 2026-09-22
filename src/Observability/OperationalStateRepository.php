<?php

namespace HScript\Observability;

use Throwable;

/** Persists bounded operational heartbeats outside public HTTP paths. */
final class OperationalStateRepository
{
	private string $directory;

	public function __construct(string $projectRoot)
	{
		$this->directory = rtrim($projectRoot, '/') . '/.cfg/observability';
	}

	public function recordCron(string $status): bool
	{
		$status = in_array($status, array('started', 'success', 'disabled'), true) ? $status : 'failed';
		$record = array(
			'format' => 1,
			'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
			'status' => $status,
			'correlation_id' => CorrelationContext::current(),
		);
		// A new start (including a concurrent/crashed run) must never refresh success.
		$success = $status !== 'success' || $this->write('cron-success.json', $record);
		return $this->write('cron-heartbeat.json', $record) && $success;
	}

	public function recordReadiness(array $result): bool
	{
		$allowed = array_intersect_key($result, array_flip(array('format', 'checked_at', 'status', 'checks')));
		return $this->write('readiness.json', $allowed);
	}

	public function cron(): ?array
	{
		return $this->read('cron-heartbeat.json');
	}

	public function cronSuccess(): ?array
	{
		return $this->read('cron-success.json');
	}

	public function readiness(): ?array
	{
		return $this->read('readiness.json');
	}

	private function write(string $name, array $data): bool
	{
		set_error_handler(static fn(): bool => true);
		try
		{
			return PrivateStorage::directory($this->directory)
				&& PrivateStorage::writeJson($this->directory . '/' . $name, $data);
		}
		catch (Throwable)
		{
			return false;
		}
		finally { restore_error_handler(); }
	}

	private function read(string $name): ?array
	{
		set_error_handler(static fn(): bool => true);
		try
		{
			$path = $this->directory . '/' . $name;
			if (!is_file($path) || is_link($path) || !is_readable($path)) return null;
			$data = json_decode((string)file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
			return is_array($data) ? $data : null;
		}
		catch (Throwable)
		{
			return null;
		}
		finally { restore_error_handler(); }
	}
}
