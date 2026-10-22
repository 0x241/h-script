<?php

namespace HScript\Observability;

use RuntimeException;
use Throwable;

/** Captures descriptors and byte boundaries under the writer lock, then reads without blocking writers. */
final class NdjsonHistory
{
	/** Streams generations oldest first, each captured record exactly once. No paths are returned. */
	public static function read(string $path, callable $consumer, ?int $now = null): array
	{
		$files = self::snapshot($path);
		$result = self::emptyResult();
		$daily = array();
		try
		{
			foreach ($files as [$stream, $size, $physicalSize])
			{
				$result['files']++;
				$result['bytes'] += $physicalSize;
				$result['incomplete_tail_bytes'] += $physicalSize - $size;
				$remaining = $size;
				while ($remaining > 0)
				{
					// Application records are bounded; diagnostic input cannot allocate an entire log.
					$line = fgets($stream, min($remaining + 1, 1048577));
					if ($line === false) throw new RuntimeException('NDJSON history is unavailable');
					$remaining -= strlen($line);
					if (!str_ends_with($line, "\n"))
					{
						$result['invalid_lines']++;
						while ($remaining > 0 && !str_ends_with($line, "\n"))
						{
							$line = fgets($stream, min($remaining + 1, 1048577));
							if ($line === false) throw new RuntimeException('NDJSON history is unavailable');
							$remaining -= strlen($line);
						}
						continue;
					}
					$record = json_decode($line, true, 64);
					if (!is_array($record)) { $result['invalid_lines']++; continue; }
					$result['records']++;
					$timestamp = self::timestamp($record['timestamp'] ?? null);
					if ($timestamp !== null)
					{
						self::observeTime($result, $timestamp);
						$day = gmdate('Y-m-d', $timestamp);
						$daily[$day] = ($daily[$day] ?? 0) + strlen($line);
					}
					$consumer($record);
				}
			}
		}
		finally { foreach ($files as [$stream]) fclose($stream); }
		$result['peak_daily_bytes'] = $daily ? max($daily) : 0;
		$result['observed_days'] = count($daily);
		return self::finish($result, $now ?? time());
	}

	/** Cheap monitor: at most 64 KiB from each end, without scanning a 160-MiB stream. */
	public static function inspect(string $path, ?int $now = null): array
	{
		$files = self::snapshot($path);
		$result = self::emptyResult();
		try
		{
			foreach ($files as [$stream, $size, $physicalSize])
			{
				$result['files']++;
				$result['bytes'] += $physicalSize;
				$result['incomplete_tail_bytes'] += $physicalSize - $size;
				$starts = array(0, max(0, $size - 65536));
				foreach (array_unique($starts) as $offset)
				{
					if (fseek($stream, $offset) !== 0) throw new RuntimeException('NDJSON history is unavailable');
					$chunk = fread($stream, min(65536, $size - $offset));
					if (!is_string($chunk)) throw new RuntimeException('NDJSON history is unavailable');
					$lines = explode("\n", $chunk);
					if ($offset > 0) array_shift($lines);
					array_pop($lines); // Never inspect a partial boundary record.
					foreach ($lines as $line)
					{
						$record = json_decode($line, true, 64);
						$timestamp = is_array($record) ? self::timestamp($record['timestamp'] ?? null) : null;
						if ($timestamp !== null) self::observeTime($result, $timestamp);
					}
				}
			}
		}
		finally { foreach ($files as [$stream]) fclose($stream); }
		unset($result['records'], $result['invalid_lines']); // This bounded probe does not count records.
		return self::finish($result, $now ?? time());
	}

	public static function retentionDays(): int
	{
		$value = filter_var(getenv('NDJSON_RETENTION_DAYS'), FILTER_VALIDATE_INT);
		return $value === false ? 7 : max(1, min((int)$value, 90));
	}

	/** @return list<array{resource, int, int}> */
	private static function snapshot(string $path): array
	{
		$lock = null;
		$files = array();
		set_error_handler(static fn(): bool => true);
		try
		{
			if (!NdjsonWriter::safePaths($path) || !is_file($path . '.lock')) throw new RuntimeException('NDJSON history is unavailable');
			$lock = fopen($path . '.lock', 'rb');
			if ($lock === false || !flock($lock, LOCK_SH | LOCK_NB)) throw new RuntimeException('NDJSON history is busy');
			if (!NdjsonWriter::safePaths($path) || is_file($path . '.rotation')) throw new RuntimeException('NDJSON history needs writer recovery');
			// Include every retained generation even if the count was lowered before the next append.
			for ($index = NdjsonWriter::MAX_ROTATIONS; $index >= 0; $index--)
			{
				$candidate = $index === 0 ? $path : $path . '.' . $index;
				if (!is_file($candidate)) continue;
				$stream = fopen($candidate, 'rb');
				if ($stream === false) throw new RuntimeException('NDJSON history is unavailable');
				$physicalSize = (int)fstat($stream)['size'];
				$files[] = array($stream, $physicalSize, $physicalSize);
				// Tail repair may truncate/rewrite bytes after the last newline after we unlock.
				$files[array_key_last($files)][1] = self::completeSize($stream, $physicalSize);
				if (fseek($stream, 0) !== 0) throw new RuntimeException('NDJSON history is unavailable');
			}
			return $files;
		}
		catch (Throwable)
		{
			foreach ($files as [$stream]) fclose($stream);
			throw new RuntimeException('NDJSON history is unavailable');
		}
		finally
		{
			if (is_resource($lock)) fclose($lock);
			restore_error_handler();
		}
	}

	/** The captured boundary excludes an unfinished tail that the writer is allowed to replace. */
	private static function completeSize($stream, int $size): int
	{
		$offset = $size;
		while ($offset > 0)
		{
			$length = min(4096, $offset);
			$offset -= $length;
			if (fseek($stream, $offset) !== 0) throw new RuntimeException('NDJSON history is unavailable');
			$chunk = fread($stream, $length);
			if (!is_string($chunk) || strlen($chunk) !== $length) throw new RuntimeException('NDJSON history is unavailable');
			$position = strrpos($chunk, "\n");
			if ($position !== false) return $offset + $position + 1;
		}
		return 0;
	}

	private static function timestamp(mixed $value): ?int
	{
		if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value)) return null;
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
		return $date !== false && $date->format('Y-m-d\TH:i:s\Z') === $value ? $date->getTimestamp() : null;
	}

	private static function emptyResult(): array
	{
		return array('files' => 0, 'bytes' => 0, 'records' => 0, 'invalid_lines' => 0, 'incomplete_tail_bytes' => 0, 'oldest_timestamp' => null, 'newest_timestamp' => null);
	}

	private static function observeTime(array &$result, int $timestamp): void
	{
		$result['oldest_timestamp'] = $result['oldest_timestamp'] === null ? $timestamp : min($result['oldest_timestamp'], $timestamp);
		$result['newest_timestamp'] = $result['newest_timestamp'] === null ? $timestamp : max($result['newest_timestamp'], $timestamp);
	}

	private static function finish(array $result, int $now): array
	{
		$oldest = $result['oldest_timestamp'];
		$newest = $result['newest_timestamp'];
		$result['required_seconds'] = self::retentionDays() * 86400;
		$result['retained_seconds'] = $oldest !== null && $oldest <= $now && $newest <= $now ? $now - $oldest : 0;
		$result['record_span_seconds'] = $oldest !== null ? max(0, $newest - $oldest) : 0;
		$result['status'] = $result['retained_seconds'] >= $result['required_seconds'] ? 'ok' : 'shortfall';
		$result['oldest_timestamp'] = $oldest !== null ? gmdate('Y-m-d\TH:i:s\Z', $oldest) : null;
		$result['newest_timestamp'] = $newest !== null ? gmdate('Y-m-d\TH:i:s\Z', $newest) : null;
		return $result;
	}
}
