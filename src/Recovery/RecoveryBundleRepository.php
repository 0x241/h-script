<?php

namespace HScript\Recovery;

use RuntimeException;

final class RecoveryBundleRepository
{
	public function __construct(private RecoverySettings $settings) {}

	public function publish(RecoveryBundleManifest $manifest): string
	{
		$directory = $this->bundleDirectory($manifest->id());
		if (!is_dir($directory))
			throw new RuntimeException('Recovery bundle directory is missing');
		$path = $directory . '/' . $manifest->id() . '.bundle.json';
		$this->writeJson($path, $manifest->toArray());
		return $path;
	}

	public function find(string $id, ?string $root = null): RecoveryBundleManifest
	{
		$this->assertId($id);
		$base = $root === null ? $this->settings->stateDirectory() : rtrim($root, '/') . '/recovery';
		$bundle = RecoveryBundleManifest::fromFile($base . '/bundles/' . $id . '/' . $id . '.bundle.json');
		if ($bundle->id() !== $id)
			throw new RecoveryException('bundle_manifest_invalid', 'Recovery bundle ID does not match its directory');
		return $bundle;
	}

	public function relativeManifestPath(string $id): string
	{
		$this->assertId($id);
		return 'recovery/bundles/' . $id . '/' . $id . '.bundle.json';
	}

	public function latestId(): string
	{
		$paths = glob($this->settings->stateDirectory() . '/bundles/*/*.bundle.json') ?: array();
		usort($paths, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a) ?: strcmp($b, $a));
		if (!$paths) throw new RecoveryException('local_bundle_missing', 'No local recovery bundle is available');
		// Never silently fall back to an older bundle when the latest one is corrupt.
		$id = basename(dirname($paths[0]));
		if (basename($paths[0]) !== $id . '.bundle.json')
			throw new RecoveryException('bundle_manifest_invalid', 'Recovery bundle filename is invalid');
		return $this->find($id)->id();
	}

	/** Copy a verified local bundle so the drill cannot alter the source backup. */
	public function stage(string $id, string $target): void
	{
		$bundle = $this->find($id);
		$bundle->verifyFiles($this->settings->backupDirectory());
		foreach ($bundle->toArray()['files'] as $file)
		{
			$destination = $target . '/' . $file['path'];
			if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0700, true))
				throw new RecoveryException('local_bundle_unavailable', 'Cannot stage local recovery bundle');
			if (!copy($this->settings->backupDirectory() . '/' . $file['path'], $destination))
				throw new RecoveryException('local_bundle_unavailable', 'Cannot read local recovery bundle');
			chmod($destination, 0600);
		}
		$this->writeJson($target . '/' . $this->relativeManifestPath($id), $bundle->toArray());
		$bundle->verifyFiles($target);
	}

	public function pruneLocal(array $availableBackupIds, string $keepId): array
	{
		$available = array_fill_keys($availableBackupIds, true);
		$directories = glob($this->settings->stateDirectory() . '/bundles/*', GLOB_ONLYDIR) ?: array();
		$items = array();
		foreach ($directories as $directory)
		{
			$id = basename($directory);
			if (!preg_match('/^[a-f0-9]{32}$/', $id)) continue;
			$manifest = $directory . '/' . $id . '.bundle.json';
			$items[] = array('id' => $id, 'directory' => $directory, 'mtime' => is_file($manifest) ? filemtime($manifest) : 0);
		}
		usort($items, static fn(array $left, array $right): int => $right['mtime'] <=> $left['mtime']);
		$cutoff = time() - ($this->settings->localRetentionDays() * 86400);
		$kept = 0;
		$deleted = array();
		foreach ($items as $item)
		{
			if ($item['id'] === $keepId)
			{
				$kept++;
				continue;
			}
			$delete = !isset($available[$item['id']]) || $item['mtime'] < $cutoff || $kept >= $this->settings->localRetentionCount();
			if ($delete)
			{
				RuntimeArchiveService::removeTree($item['directory']);
				if (is_dir($item['directory']))
					throw new RecoveryException('local_retention_failed', 'Expired local recovery bundle was not removed');
				$deleted[] = $item['id'];
			}
			else
				$kept++;
		}
		return $deleted;
	}

	private function bundleDirectory(string $id): string
	{
		$this->assertId($id);
		return $this->settings->stateDirectory() . '/bundles/' . $id;
	}

	private function writeJson(string $path, array $data): void
	{
		\HScript\Backup\PrivateJsonFile::write($path, $data);
	}

	private function assertId(string $id): void
	{
		if (!preg_match('/^[a-f0-9]{32}$/', $id))
			throw new RuntimeException('Recovery bundle ID is invalid');
	}
}
