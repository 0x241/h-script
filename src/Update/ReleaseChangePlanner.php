<?php

namespace HScript\Update;

use RuntimeException;

/** Creates a bounded three-way source/local/target activation plan. */
final class ReleaseChangePlanner
{
	public function __construct(private UpdateSettings $settings) {}

	/**
	 * @param array<string,string> $sourceBaseline
	 * @param array<int,array<string,mixed>> $targetFiles
	 * @return array{plan:array<int,array<string,mixed>>,conflicts:array<int,array<string,mixed>>}
	 */
	public function build(string $preparedId, array $sourceBaseline, array $targetFiles, string $stagingRoot): array
	{
		$targets = array();
		foreach ($targetFiles as $file) $targets[$file['path']] = $file;
		$paths = array_values(array_unique(array_merge(array_keys($sourceBaseline), array_keys($targets))));
		sort($paths, SORT_STRING);
		$plan = array();
		$conflicts = array();
		foreach ($paths as $path)
		{
			ReleaseInventory::requireSafePath($path);
			if (ReleaseInventory::preserved($path)) continue;
			$sourceHash = $sourceBaseline[$path] ?? null;
			$target = $targets[$path] ?? null;
			$targetHash = $target['sha256'] ?? null;
			$localPath = $this->settings->projectRoot() . '/' . $path;
			if (file_exists($localPath) && (!is_file($localPath) || is_link($localPath)))
				throw new RuntimeException('Managed local path is not a regular file: ' . $path);
			$localHash = is_file($localPath) ? hash_file('sha256', $localPath) : null;
			if ($localHash === false) throw new RuntimeException('Managed local file could not be read: ' . $path);

			[$action, $reason] = $this->classify($sourceHash, $localHash, $targetHash);
			$class = (string)($target['class'] ?? (str_ends_with($path, '.twig') ? 'customizable' : 'core_strict'));
			$entry = array(
				'path' => $path,
				'action' => $action,
				'reason' => $reason,
				'class' => $class,
				'source_sha256' => $sourceHash,
				'local_sha256' => $localHash,
				'target_sha256' => $targetHash,
				'target_size' => (int)($target['size'] ?? 0),
			);
			$plan[] = $entry;
			if ($action !== 'conflict') continue;

			if ($localHash !== null) $this->copy($localPath, $this->settings->conflictsDirectory() . '/' . $preparedId . '/local/' . $path);
			if ($targetHash !== null) $this->copy($stagingRoot . '/' . $path, $this->settings->conflictsDirectory() . '/' . $preparedId . '/release/' . $path);
			$conflicts[] = array(
				'path' => $path,
				'reason' => $reason,
				'class' => $class,
				'local_sha256' => $localHash,
				'source_sha256' => $sourceHash,
				'release_sha256' => $targetHash,
				'local_mtime' => is_file($localPath) ? (int)filemtime($localPath) : null,
			);
		}
		return array('plan' => $plan, 'conflicts' => $conflicts);
	}

	private function classify(?string $source, ?string $local, ?string $target): array
	{
		if ($source === null)
		{
			if ($target === null) return array('preserve', 'unmanaged_local');
			if ($local === null) return array('install', 'new_official_file');
			if (hash_equals($target, $local)) return array('preserve', 'already_target');
			return array('conflict', 'new_path_collision');
		}
		if ($target === null)
		{
			if ($local === null) return array('preserve', 'already_removed');
			if (hash_equals($source, $local)) return array('delete', 'removed_from_release');
			return array('conflict', 'removed_but_locally_changed');
		}
		if ($local === null)
		{
			if (hash_equals($source, $target)) return array('preserve', 'locally_removed');
			return array('conflict', 'locally_removed_and_release_changed');
		}
		if (hash_equals($local, $target)) return array('preserve', 'already_target');
		if (hash_equals($local, $source)) return array('install', 'official_update');
		if (hash_equals($target, $source)) return array('preserve', 'local_only_change');
		return array('conflict', 'local_and_release_changed');
	}

	private function copy(string $source, string $target): void
	{
		$directory = dirname($target);
		if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory))
			throw new RuntimeException('Release conflict directory could not be created');
		$input = fopen($source, 'rb');
		$output = fopen($target, 'xb');
		if ($input === false || $output === false)
		{
			if (is_resource($input)) fclose($input);
			if (is_resource($output)) fclose($output);
			throw new RuntimeException('Release conflict copy could not be created');
		}
		try
		{
			if (stream_copy_to_stream($input, $output) === false || !fflush($output))
				throw new RuntimeException('Release conflict copy could not be written');
		}
		finally { fclose($input); fclose($output); }
		chmod($target, 0600);
	}
}
