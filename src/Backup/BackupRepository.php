<?php

namespace HScript\Backup;

use RuntimeException;
use Throwable;

final class BackupRepository
{
	private BackupSettings $settings;

	public function __construct(BackupSettings $settings)
	{
		$this->settings = $settings;
	}

	public function publish(BackupManifest $manifest, string $temporaryArchive): void
	{
		$archivePath = $this->settings->directory() . '/' . $manifest->archive();
		$manifestPath = $this->manifestPath($manifest->id());
		if (file_exists($manifestPath) || is_link($manifestPath))
			throw new RuntimeException('Backup manifest already exists');
		if (!is_file($temporaryArchive) || is_link($temporaryArchive)
			|| dirname((string)realpath($temporaryArchive)) !== $this->settings->directory())
			throw new RuntimeException('Temporary backup archive is invalid');
		if (file_exists($archivePath) || is_link($archivePath) || !rename($temporaryArchive, $archivePath))
			throw new RuntimeException('Backup archive could not be published');
		chmod($archivePath, 0600);

		$temporaryManifest = $manifestPath . '.part';
		$json = json_encode($manifest->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
		$mask = umask(0077);
		try { $stream = fopen($temporaryManifest, 'xb'); }
		finally { umask($mask); }
		if ($stream === false)
		{
			unlink($archivePath);
			throw new RuntimeException('Backup manifest could not be created');
		}
		try
		{
			$offset = 0;
			$length = strlen($json);
			while ($offset < $length)
			{
				$written = fwrite($stream, substr($json, $offset));
				if ($written === false || $written === 0)
					throw new RuntimeException('Backup manifest could not be written');
				$offset += $written;
			}
			if (!fflush($stream))
				throw new RuntimeException('Backup manifest could not be written');
		}
		catch (Throwable $exception)
		{
			fclose($stream);
			unlink($temporaryManifest);
			unlink($archivePath);
			throw $exception;
		}
		fclose($stream);
		chmod($temporaryManifest, 0600);
		if (!rename($temporaryManifest, $manifestPath))
		{
			unlink($temporaryManifest);
			unlink($archivePath);
			throw new RuntimeException('Backup manifest could not be published');
		}
	}

	public function find(string $id): BackupManifest
	{
		$this->assertId($id);
		$manifest = BackupManifest::fromFile($this->manifestPath($id));
		if ($manifest->id() !== $id) throw new RuntimeException('Backup manifest ID does not match its filename');
		return $manifest;
	}

	public function list(int $limit = 50): array
	{
		return array_slice($this->manifests(), 0, max(1, min($limit, 50)));
	}

	public function archivePath(BackupManifest $manifest): string
	{
		$path = $this->settings->directory() . '/' . $manifest->archive();
		if (!is_file($path) || is_link($path))
			throw new RuntimeException('Backup archive is not a regular file');
		$resolved = realpath($path);
		if ($resolved === false || dirname($resolved) !== $this->settings->directory())
			throw new RuntimeException('Backup archive is outside backup storage');
		return $resolved;
	}

	public function delete(string $id): void
	{
		$manifest = $this->find($id);
		$archivePath = $this->archivePath($manifest);
		if (is_link($archivePath) || (is_file($archivePath) && !unlink($archivePath)))
			throw new RuntimeException('Backup archive could not be deleted');
		$manifestPath = $this->manifestPath($id);
		if (is_file($manifestPath) && !unlink($manifestPath))
			throw new RuntimeException('Backup manifest could not be deleted');
	}

	public function prune(bool $updateActive, string $keepId): array
	{
		if ($updateActive)
			return array();
		$items = $this->manifests();
		usort($items, static function (array $left, array $right) use ($keepId): int {
			if ($left['id'] === $keepId) return -1;
			if ($right['id'] === $keepId) return 1;
			return strcmp($right['created_at'], $left['created_at']);
		});
		$cutoff = time() - ($this->settings->retentionDays() * 86400);
		$deleted = array();
		$kept = 0;
		foreach ($items as $item)
		{
			if ($item['id'] === $keepId)
			{
				$kept++;
				continue;
			}
			$expired = (int)strtotime($item['created_at']) < $cutoff;
			$overLimit = $kept >= $this->settings->retentionCount();
			if ($expired || $overLimit)
			{
				$this->delete($item['id']);
				$deleted[] = $item['id'];
			}
			else
				$kept++;
		}
		return $deleted;
	}

	private function manifests(): array
	{
		$paths = glob($this->settings->directory() . '/*.manifest.json');
		if ($paths === false)
			throw new RuntimeException('Backup directory could not be listed');
		$items = array();
		foreach ($paths as $path)
		{
			try
			{
				$manifest = $this->find(basename($path, '.manifest.json'));
				$this->archivePath($manifest);
				$items[] = $manifest->toArray();
			}
			catch (Throwable $exception)
			{
				error_log('Ignored invalid backup manifest ' . basename($path) . ': ' . $exception->getMessage());
			}
		}
		usort($items, static fn(array $left, array $right): int => strcmp($right['created_at'], $left['created_at']));
		return $items;
	}

	private function manifestPath(string $id): string
	{
		return $this->settings->directory() . '/' . $id . '.manifest.json';
	}

	private function assertId(string $id): void
	{
		if (!preg_match('/^[a-f0-9]{32}$/', $id))
			throw new RuntimeException('Backup ID is invalid');
	}
}
