<?php

namespace HScript\Observability;

use Throwable;

/** Serializes append and rotation without blocking business requests on a busy sink. */
final class NdjsonWriter
{
	public static function append(string $path, string $lines, int $maximumBytes): bool
	{
		$lock = null;
		$stream = null;
		$mask = umask(0077);
		set_error_handler(static fn(): bool => true);
		try
		{
			$directory = dirname($path);
			if (!is_dir($directory) || !is_writable($directory) || strlen($lines) > $maximumBytes) return false;
			foreach (array($path, $path . '.1', $path . '.lock') as $candidate)
				if (is_link($candidate) || (file_exists($candidate) && !is_file($candidate))) return false;
			$lock = fopen($path . '.lock', 'c+b');
			if ($lock === false || !PrivateStorage::secure($path . '.lock', $directory, 0600)) return false;
			if (!flock($lock, LOCK_EX | LOCK_NB)) return false;
			clearstatcache(true, $path);
			if (is_file($path))
			{
				if (!PrivateStorage::secure($path, $directory, 0600)) return false;
				if (filesize($path) + strlen($lines) > $maximumBytes)
				{
					if (!rename($path, $path . '.1')) return false;
				}
			}
			$stream = fopen($path, 'ab');
			if ($stream === false || !PrivateStorage::secure($path, $directory, 0600)) return false;
			$size = fstat($stream)['size'];
			if (fwrite($stream, $lines) !== strlen($lines) || !fflush($stream))
			{
				// Do not leave a partial JSON record after a short write (e.g. disk full).
				ftruncate($stream, $size);
				return false;
			}
			return true;
		}
		catch (Throwable) { return false; }
		finally
		{
			if (is_resource($stream)) fclose($stream);
			if (is_resource($lock)) fclose($lock);
			restore_error_handler();
			umask($mask);
		}
	}
}
