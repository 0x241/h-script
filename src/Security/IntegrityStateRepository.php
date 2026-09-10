<?php

namespace HScript\Security;

use JsonException;
use RuntimeException;

final class IntegrityStateRepository
{
	private string $directory;
	private string $path;

	public function __construct(string $projectRoot)
	{
		$root = realpath($projectRoot);
		if ($root === false || !is_dir($root)) throw new RuntimeException('Project root could not be resolved');
		$this->directory = rtrim($root, '/') . '/.cfg/security';
		$this->path = $this->directory . '/integrity-state.json';
	}

	public function get(): ?array
	{
		if (!is_file($this->path)) return null;
		if (is_link($this->path) || !is_readable($this->path)) throw new RuntimeException('Integrity state is unsafe or unreadable');
		try { $state = json_decode((string)file_get_contents($this->path), true, 64, JSON_THROW_ON_ERROR); }
		catch (JsonException $exception) { throw new RuntimeException('Integrity state is invalid', 0, $exception); }
		if (!is_array($state) || ($state['format'] ?? null) !== 1 || !in_array($state['status'] ?? null, array('running', 'completed', 'failed'), true))
			throw new RuntimeException('Integrity state is invalid');
		return $state;
	}

	public function save(array $state): void
	{
		$this->ensureDirectory();
		$state['format'] = 1;
		$json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
		$temporary = $this->path . '.' . bin2hex(random_bytes(8)) . '.part';
		if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json))
			throw new RuntimeException('Integrity state could not be written');
		chmod($temporary, 0600);
		if (!rename($temporary, $this->path))
		{
			unlink($temporary);
			throw new RuntimeException('Integrity state could not be published');
		}
	}

	public function acknowledge(): array
	{
		$state = $this->get();
		if ($state === null || ($state['status'] ?? '') !== 'completed' || ($state['alert_signature'] ?? '') === '')
			throw new RuntimeException('There is no completed integrity alert to acknowledge');
		$state['acknowledged_signature'] = $state['alert_signature'];
		$state['acknowledged_at'] = time();
		$this->save($state);
		return $state;
	}

	public function markNotified(string $signature): void
	{
		$state = $this->get();
		if ($state === null || !hash_equals((string)($state['alert_signature'] ?? ''), $signature)) return;
		$state['notified_signature'] = $signature;
		$state['notified_at'] = time();
		$this->save($state);
	}

	private function ensureDirectory(): void
	{
		if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory))
			throw new RuntimeException('Integrity directory could not be created');
		if (is_link($this->directory) || !is_writable($this->directory))
			throw new RuntimeException('Integrity directory is unsafe or not writable');
		chmod($this->directory, 0700);
	}
}
