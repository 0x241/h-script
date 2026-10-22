<?php

namespace HScript\Observability;

use Throwable;

/** Serializes bounded complete-record append/rotation; busy or failing sinks are fail-open. */
final class NdjsonWriter
{
	public const MAX_ROTATIONS = 100;

	public static function rotationCount(): int
	{
		$value = filter_var(getenv('NDJSON_ROTATED_FILES'), FILTER_VALIDATE_INT);
		return $value === false ? 7 : max(1, min((int)$value, self::MAX_ROTATIONS));
	}

	public static function append(string $path, string $lines, int $maximumBytes, ?int $rotations = null): bool
	{
		$lock = null;
		$stream = null;
		$mask = umask(0077);
		set_error_handler(static fn(): bool => true);
		try
		{
			$count = $rotations ?? self::rotationCount();
			$directory = dirname($path);
			if ($maximumBytes < 1 || $count < 1 || $count > self::MAX_ROTATIONS
				|| !is_dir($directory) || !is_writable($directory)
				|| $lines === '' || strlen($lines) > $maximumBytes || !str_ends_with($lines, "\n")) return false;
			foreach (explode("\n", substr($lines, 0, -1)) as $line)
				if (!is_array(json_decode($line, true, 64, JSON_THROW_ON_ERROR))) return false;
			if (!self::safePaths($path)) return false;
			$lock = fopen($path . '.lock', 'c+b');
			if ($lock === false || !PrivateStorage::secure($path . '.lock', $directory, 0600)) return false;
			if (!flock($lock, LOCK_EX | LOCK_NB)) return false;
			if (!self::safePaths($path) || !self::recoverRotation($path)) return false;
			for ($index = 1; $index <= self::MAX_ROTATIONS; $index++)
				if (is_file($path . '.' . $index) && !PrivateStorage::secure($path . '.' . $index, $directory, 0600)) return false;
			// Reducing the configured count also removes obsolete generations under this lock.
			for ($index = self::MAX_ROTATIONS; $index > $count; $index--)
				if (is_file($path . '.' . $index) && !unlink($path . '.' . $index)) return false;
			$stream = fopen($path, 'c+b');
			if ($stream === false || !PrivateStorage::secure($path, $directory, 0600)) return false;
			$size = self::repairTail($stream);
			if ($size === false) return false;
			if ($size + strlen($lines) > $maximumBytes)
			{
				fclose($stream);
				$stream = null;
				if (!self::journal($path, array('format' => 1, 'count' => $count, 'next' => $count))
					|| !self::recoverRotation($path)) return false;
				$stream = fopen($path, 'c+b');
				if ($stream === false || !PrivateStorage::secure($path, $directory, 0600)) return false;
				$size = 0;
			}
			if (fseek($stream, $size) !== 0) return false;
			if (fwrite($stream, $lines) !== strlen($lines) || !fflush($stream))
			{
				// The previous complete records survive a short write or exhausted filesystem.
				ftruncate($stream, $size);
				fflush($stream);
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

	/** Validate every supported generation, including an old higher-count configuration. */
	public static function safePaths(string $path): bool
	{
		foreach (array('', '.lock', '.rotation', '.rotation.next') as $suffix)
			if (!self::regular($path . $suffix)) return false;
		for ($index = 1; $index <= self::MAX_ROTATIONS; $index++)
			if (!self::regular($path . '.' . $index)) return false;
		return true;
	}

	private static function regular(string $path): bool
	{
		clearstatcache(true, $path);
		return !is_link($path) && (!file_exists($path) || is_file($path));
	}

	/** Remove only an unfinished trailing record left by a killed writer. */
	private static function repairTail($stream): int|false
	{
		$size = fstat($stream)['size'];
		if ($size === 0) return 0;
		$offset = $size;
		while ($offset > 0)
		{
			$length = min(4096, $offset);
			$offset -= $length;
			if (fseek($stream, $offset) !== 0) return false;
			$chunk = fread($stream, $length);
			if (!is_string($chunk) || strlen($chunk) !== $length) return false;
			$position = strrpos($chunk, "\n");
			if ($position === false) continue;
			$complete = $offset + $position + 1;
			return $complete === $size || (ftruncate($stream, $complete) && fflush($stream)) ? $complete : false;
		}
		return ftruncate($stream, 0) && fflush($stream) ? 0 : false;
	}

	/** A persisted step makes each rename restartable after a process crash. */
	private static function recoverRotation(string $path): bool
	{
		if (!is_file($path . '.rotation')) return true;
		$state = json_decode((string)file_get_contents($path . '.rotation'), true, 4, JSON_THROW_ON_ERROR);
		$count = $state['count'] ?? null;
		$next = $state['next'] ?? null;
		if (($state['format'] ?? null) !== 1 || !is_int($count) || $count < 1 || $count > self::MAX_ROTATIONS
			|| !is_int($next) || $next < -1 || $next > $count) return false;
		for ($index = $next; $index >= 0; $index--)
		{
			$source = $index === 0 ? $path : $path . '.' . $index;
			if (is_file($source))
			{
				if (!PrivateStorage::secure($source, dirname($path), 0600)) return false;
				if ($index === $count)
				{
					if (!unlink($source)) return false;
				}
				elseif (file_exists($path . '.' . ($index + 1)) || !rename($source, $path . '.' . ($index + 1))) return false;
			}
			// If rename completed before a crash, its source is absent and is not repeated.
			$state['next'] = $index - 1;
			if (!self::journal($path, $state)) return false;
		}
		if (is_file($path . '.rotation.next') && !unlink($path . '.rotation.next')) return false;
		return unlink($path . '.rotation');
	}

	private static function journal(string $path, array $state): bool
	{
		$temporary = $path . '.rotation.next';
		if (!self::regular($temporary) || !self::regular($path . '.rotation')) return false;
		$json = json_encode($state, JSON_THROW_ON_ERROR) . "\n";
		$stream = fopen($temporary, 'wb');
		if ($stream === false) return false;
		try
		{
			if (!PrivateStorage::secure($temporary, dirname($path), 0600)
				|| fwrite($stream, $json) !== strlen($json) || !fflush($stream) || !fsync($stream)) return false;
		}
		finally { fclose($stream); }
		return rename($temporary, $path . '.rotation');
	}
}
