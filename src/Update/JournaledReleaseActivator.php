<?php

namespace HScript\Update;

use JsonException;
use RuntimeException;

/** Journaled three-way activation for shared-hosting installations. */
final class JournaledReleaseActivator implements ReleaseActivator
{
	public function __construct(private UpdateSettings $settings) {}

	public function preflight(ReleaseManifest $manifest, array $activationPlan): array
	{
		$this->assertPlan($manifest, $activationPlan);
		$installBytes = 0;
		$recoveryBytes = 0;
		foreach ($activationPlan as $entry)
		{
			if (in_array($entry['action'], array('install', 'conflict'), true))
				$installBytes += (int)$entry['target_size'];
			$target = $this->target($entry['path']);
			if (!in_array($entry['action'], array('install', 'delete', 'conflict'), true)) continue;
			if (is_file($target) && !is_link($target))
				$recoveryBytes += (int)(filesize($target) ?: 0);
			$this->assertSafeTarget($entry['path']);
			$parent = dirname($target);
			while (!is_dir($parent) && $parent !== dirname($parent)) $parent = dirname($parent);
			if (!is_dir($parent) || !is_writable($parent))
				throw new RuntimeException('Managed target directory is not writable: ' . $entry['path']);
		}
		$liveFree = disk_free_space($this->settings->projectRoot());
		$recoveryFree = disk_free_space($this->settings->previousDirectory());
		if ($liveFree === false || $recoveryFree === false || $liveFree < $installBytes || $recoveryFree < $recoveryBytes)
			throw new RuntimeException('Not enough free space for release activation and previous code set');
		return array(
			'mode' => 'journaled-three-way-copy',
			'install_bytes' => $installBytes,
			'recovery_bytes' => $recoveryBytes,
			'writable' => true,
		);
	}

	public function activate(
		string $runId,
		string $preparedId,
		ReleaseManifest $manifest,
		string $stagingRoot,
		array $fileChoices,
		array $activationPlan
	): array {
		$this->assertPlan($manifest, $activationPlan);
		$this->assertChoices($activationPlan, $fileChoices);
		$journal = $this->loadOrCreate($runId, $preparedId, $manifest, $fileChoices);
		$this->assertInitialState($journal, $activationPlan, $fileChoices);

		foreach ($activationPlan as $planEntry)
		{
			$path = $planEntry['path'];
			$action = $this->effectiveAction($planEntry, $fileChoices);
			if ($action === 'preserve')
			{
				$this->assertObserved($path, $planEntry['local_sha256'], 'Preserved local file changed during activation: ' . $path);
				continue;
			}
			$targetHash = $action === 'delete' ? null : $planEntry['target_sha256'];
			$source = $stagingRoot . '/' . $path;
			if ($action === 'install')
				$this->assertChecksum($source, $targetHash, 'Staged release file changed: ' . $path);

			$entryIndex = $this->entryIndex($journal['entries'], $path);
			if ($entryIndex !== null && $journal['entries'][$entryIndex]['state'] === 'installed')
			{
				$this->assertTargetState($path, $targetHash, 'Installed release path changed during resume: ' . $path);
				continue;
			}
			if ($entryIndex === null)
			{
				$this->assertObserved($path, $planEntry['local_sha256'], 'Managed target changed after update preparation: ' . $path);
				$previousExists = $planEntry['local_sha256'] !== null;
				$journal['entries'][] = array(
					'path' => $path,
					'previous_exists' => $previousExists,
					'previous_sha256' => $planEntry['local_sha256'],
					'target_sha256' => $targetHash,
					'state' => 'pending',
				);
				$this->save($runId, $journal);
				$entryIndex = count($journal['entries']) - 1;
			}

			$entry = $journal['entries'][$entryIndex];
			if ($entry['target_sha256'] !== $targetHash)
				throw new RuntimeException('Activation journal target does not match the plan: ' . $path);
			if ($entry['previous_exists'])
				$this->ensureRecoveryCopy($runId, $entry);

			if ($this->matchesTargetState($path, $targetHash))
			{
				$journal['entries'][$entryIndex]['state'] = 'installed';
				$this->save($runId, $journal);
				continue;
			}
			$this->assertObserved($path, $entry['previous_sha256'], 'Managed target changed during interrupted activation: ' . $path);
			if ($action === 'delete')
			{
				if (!unlink($this->target($path)))
					throw new RuntimeException('Managed release file could not be removed: ' . $path);
			}
			else
			{
				$this->installAtomic($source, $this->target($path));
				$this->invalidateOpcode($this->target($path));
			}
			$this->assertTargetState($path, $targetHash, 'Activated release path checksum mismatch: ' . $path);
			$journal['entries'][$entryIndex]['state'] = 'installed';
			$this->save($runId, $journal);
		}

		$this->assertInitialState($journal, $activationPlan, $fileChoices);
		$journal['state'] = 'activated';
		$journal['activated_at'] = gmdate('Y-m-d\TH:i:s\Z');
		$this->save($runId, $journal);
		if (function_exists('opcache_reset')) opcache_reset();
		return $journal;
	}

	public function rollback(string $runId): array
	{
		$journal = $this->load($runId);
		if ($journal['state'] === 'rolled_back') return $journal;
		for ($index = count($journal['entries']) - 1; $index >= 0; $index--)
		{
			$entry = $journal['entries'][$index];
			if ($entry['state'] === 'pending')
			{
				if ($this->matchesTargetState($entry['path'], $entry['target_sha256']))
					$entry['state'] = 'installed';
				else
				{
					$this->assertObserved($entry['path'], $entry['previous_sha256'], 'Managed target changed during interrupted activation: ' . $entry['path']);
					$journal['entries'][$index]['state'] = 'rolled_back';
					$this->save($runId, $journal);
					continue;
				}
			}
			if ($entry['state'] !== 'installed') continue;
			$this->assertTargetState($entry['path'], $entry['target_sha256'], 'Installed path changed; automatic code rollback stopped: ' . $entry['path']);
			$target = $this->target($entry['path']);
			if ($entry['previous_exists'])
			{
				$previous = $this->recoveryPath($runId, $entry['path']);
				$this->assertChecksum($previous, $entry['previous_sha256'], 'Previous release copy is invalid: ' . $entry['path']);
				$this->installAtomic($previous, $target);
				$this->invalidateOpcode($target);
			}
			elseif (file_exists($target) && !unlink($target))
				throw new RuntimeException('New release file could not be removed during rollback: ' . $entry['path']);
			$journal['entries'][$index]['state'] = 'rolled_back';
			$this->save($runId, $journal);
		}
		$journal['state'] = 'rolled_back';
		$journal['rolled_back_at'] = gmdate('Y-m-d\TH:i:s\Z');
		$this->save($runId, $journal);
		if (function_exists('opcache_reset')) opcache_reset();
		return $journal;
	}

	public function prunePrevious(int $keep): array { return array(); }
	public function status(string $runId): ?array { return is_file($this->journalPath($runId)) ? $this->load($runId) : null; }
	public function activeRoot(): string { return $this->settings->projectRoot(); }

	private function assertPlan(ReleaseManifest $manifest, array $plan): void
	{
		$checksum = hash('sha256', json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
		if (!hash_equals($manifest->activationPlanChecksum(), $checksum))
			throw new RuntimeException('Activation plan does not match release metadata');
		$seen = array();
		foreach ($plan as $entry)
		{
			if (!is_array($entry) || array_keys($entry) !== array('path', 'action', 'reason', 'class', 'source_sha256', 'local_sha256', 'target_sha256', 'target_size'))
				throw new RuntimeException('Activation plan entry is invalid');
			$path = ReleaseInventory::requireSafePath($entry['path']);
			if (isset($seen[strtolower($path)]) || ReleaseInventory::preserved($path))
				throw new RuntimeException('Activation plan path is duplicated or preserved: ' . $path);
			$seen[strtolower($path)] = true;
			if (!in_array($entry['action'], array('install', 'preserve', 'delete', 'conflict'), true)
				|| !in_array($entry['class'], array('core_strict', 'customizable'), true)
				|| !is_string($entry['reason']) || $entry['reason'] === '' || !is_int($entry['target_size']) || $entry['target_size'] < 0)
				throw new RuntimeException('Activation plan action is invalid: ' . $path);
			foreach (array('source_sha256', 'local_sha256', 'target_sha256') as $field)
				if ($entry[$field] !== null && (!is_string($entry[$field]) || !preg_match('/^[a-f0-9]{64}$/', $entry[$field])))
					throw new RuntimeException('Activation plan checksum is invalid: ' . $path);
		}
	}

	private function assertChoices(array $plan, array $choices): void
	{
		$conflicts = array();
		foreach ($plan as $entry) if ($entry['action'] === 'conflict') $conflicts[$entry['path']] = true;
		foreach ($conflicts as $path => $_)
			if (!in_array($choices[$path] ?? null, array('local', 'release'), true))
				throw new RuntimeException('Choose local or release version for file conflict: ' . $path);
		foreach ($choices as $path => $choice)
			if (!isset($conflicts[$path]) || !in_array($choice, array('local', 'release'), true))
				throw new RuntimeException('Unknown file conflict choice: ' . $path);
	}

	private function assertInitialState(array $journal, array $plan, array $choices): void
	{
		foreach ($plan as $entry)
		{
			$action = $this->effectiveAction($entry, $choices);
			$index = $this->entryIndex($journal['entries'], $entry['path']);
			if ($action === 'preserve')
			{
				$this->assertObserved($entry['path'], $entry['local_sha256'], 'Preserved local file changed after update preparation: ' . $entry['path']);
				continue;
			}
			if ($index === null)
			{
				$this->assertObserved($entry['path'], $entry['local_sha256'], 'Managed target changed after update preparation: ' . $entry['path']);
				continue;
			}
			$journalEntry = $journal['entries'][$index];
			if ($journalEntry['state'] === 'installed')
				$this->assertTargetState($entry['path'], $journalEntry['target_sha256'], 'Installed release path changed during activation: ' . $entry['path']);
			else if (!$this->matchesTargetState($entry['path'], $journalEntry['target_sha256']))
				$this->assertObserved($entry['path'], $journalEntry['previous_sha256'], 'Managed target changed during interrupted activation: ' . $entry['path']);
		}
	}

	private function effectiveAction(array $entry, array $choices): string
	{
		if ($entry['action'] !== 'conflict') return $entry['action'];
		if (($choices[$entry['path']] ?? null) === 'local') return 'preserve';
		return $entry['target_sha256'] === null ? 'delete' : 'install';
	}

	private function loadOrCreate(string $runId, string $preparedId, ReleaseManifest $manifest, array $choices): array
	{
		$path = $this->journalPath($runId);
		if (is_file($path))
		{
			$journal = $this->load($runId);
			if ($journal['prepared_id'] !== $preparedId || !hash_equals($journal['manifest_checksum'], $manifest->checksum())
				|| !hash_equals($journal['activation_plan_sha256'], $manifest->activationPlanChecksum()) || $journal['choices'] !== $choices)
				throw new RuntimeException('Activation journal does not match prepared release');
			return $journal;
		}
		$journal = array(
			'format' => 1,
			'mode' => 'journaled-three-way-copy',
			'run_id' => $runId,
			'prepared_id' => $preparedId,
			'manifest_checksum' => $manifest->checksum(),
			'activation_plan_sha256' => $manifest->activationPlanChecksum(),
			'choices' => $choices,
			'state' => 'activating',
			'entries' => array(),
			'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
			'activated_at' => null,
			'rolled_back_at' => null,
		);
		$this->save($runId, $journal);
		return $journal;
	}

	private function ensureRecoveryCopy(string $runId, array $entry): void
	{
		$previous = $this->recoveryPath($runId, $entry['path']);
		if (is_file($previous) && !is_link($previous))
		{
			$this->assertChecksum($previous, $entry['previous_sha256'], 'Previous release copy is invalid: ' . $entry['path']);
			return;
		}
		$this->assertObserved($entry['path'], $entry['previous_sha256'], 'Managed target changed before recovery copy: ' . $entry['path']);
		$this->copyNew($this->target($entry['path']), $previous, 0600);
	}

	private function load(string $runId): array
	{
		$path = $this->journalPath($runId);
		if (!is_file($path) || is_link($path)) throw new RuntimeException('Activation journal was not found');
		try { $journal = json_decode((string)file_get_contents($path), true, 64, JSON_THROW_ON_ERROR); }
		catch (JsonException $exception) { throw new RuntimeException('Activation journal is invalid', 0, $exception); }
		if (!is_array($journal) || ($journal['format'] ?? null) !== 1 || ($journal['run_id'] ?? '') !== $runId
			|| !is_array($journal['entries'] ?? null) || !is_array($journal['choices'] ?? null))
			throw new RuntimeException('Activation journal is invalid');
		return $journal;
	}

	private function save(string $runId, array $journal): void
	{
		$path = $this->journalPath($runId);
		$temporary = $path . '.' . bin2hex(random_bytes(8)) . '.part';
		$json = json_encode($journal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
		if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)) throw new RuntimeException('Activation journal could not be written');
		chmod($temporary, 0600);
		if (!rename($temporary, $path))
		{
			unlink($temporary);
			throw new RuntimeException('Activation journal could not be published');
		}
	}

	private function installAtomic(string $source, string $target): void
	{
		$directory = dirname($target);
		if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) throw new RuntimeException('Managed target directory could not be created');
		$temporary = $directory . '/.' . basename($target) . '.' . bin2hex(random_bytes(8)) . '.update';
		$this->copyNew($source, $temporary, 0644);
		if (!rename($temporary, $target))
		{
			unlink($temporary);
			throw new RuntimeException('Managed release file could not be activated');
		}
	}

	private function copyNew(string $source, string $target, int $mode): void
	{
		$directory = dirname($target);
		if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Update recovery directory could not be created');
		$input = fopen($source, 'rb');
		$output = fopen($target, 'xb');
		if ($input === false || $output === false)
		{
			if (is_resource($input)) fclose($input);
			if (is_resource($output)) fclose($output);
			throw new RuntimeException('Release file copy could not be created');
		}
		try
		{
			if (stream_copy_to_stream($input, $output) === false || !fflush($output)) throw new RuntimeException('Release file copy was interrupted');
		}
		finally { fclose($input); fclose($output); }
		chmod($target, $mode);
	}

	private function assertSafeTarget(string $path): void
	{
		$current = $this->settings->projectRoot();
		$parts = explode('/', dirname($path));
		if ($parts === array('.')) return;
		foreach ($parts as $part)
		{
			$current .= '/' . $part;
			if (is_link($current)) throw new RuntimeException('Managed target directory is a symbolic link: ' . $path);
			if (file_exists($current) && !is_dir($current)) throw new RuntimeException('Managed target parent is not a directory: ' . $path);
		}
	}

	private function assertObserved(string $path, ?string $checksum, string $message): void
	{
		$target = $this->target($path);
		if ($checksum === null)
		{
			if (file_exists($target) || is_link($target)) throw new RuntimeException($message);
			return;
		}
		$this->assertChecksum($target, $checksum, $message);
	}

	private function matchesTargetState(string $path, ?string $checksum): bool
	{
		$target = $this->target($path);
		if ($checksum === null) return !file_exists($target) && !is_link($target);
		if (!is_file($target) || is_link($target)) return false;
		$actual = hash_file('sha256', $target);
		return is_string($actual) && hash_equals($checksum, $actual);
	}

	private function assertTargetState(string $path, ?string $checksum, string $message): void
	{
		if (!$this->matchesTargetState($path, $checksum)) throw new RuntimeException($message);
	}

	private function assertChecksum(string $path, ?string $checksum, string $message): void
	{
		if (!is_string($checksum) || !is_file($path) || is_link($path)) throw new RuntimeException($message);
		$actual = hash_file('sha256', $path);
		if (!is_string($actual) || !hash_equals($checksum, $actual)) throw new RuntimeException($message);
	}

	private function invalidateOpcode(string $path): void
	{
		if (str_ends_with($path, '.php') && function_exists('opcache_invalidate')) opcache_invalidate($path, true);
	}

	private function target(string $path): string { return $this->settings->projectRoot() . '/' . $path; }
	private function recoveryPath(string $runId, string $path): string { return $this->settings->previousDirectory() . '/' . $runId . '/' . $path; }

	private function entryIndex(array $entries, string $path): ?int
	{
		foreach ($entries as $index => $entry) if (($entry['path'] ?? null) === $path) return $index;
		return null;
	}

	private function journalPath(string $runId): string
	{
		if (!preg_match('/^[a-f0-9]{32}$/', $runId)) throw new RuntimeException('Update run ID is invalid');
		return $this->settings->runsDirectory() . '/' . $runId . '.journal.json';
	}
}
