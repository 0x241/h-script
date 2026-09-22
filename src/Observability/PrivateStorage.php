<?php

namespace HScript\Observability;

use Throwable;

/** Private files inherit the runtime directory owner, including when CLI runs as root. */
final class PrivateStorage
{
	public static function secure(string $path, string $ownerPath, int $mode): bool
	{
		clearstatcache(true, $path);
		if (is_link($path)) return false;
		$owner = fileowner($ownerPath);
		if ($owner === false) return false;
		if (fileowner($path) !== $owner && !chown($path, $owner)) return false;
		return chmod($path, $mode);
	}

	public static function directory(string $path): bool
	{
		if (is_link($path) || !is_dir(dirname($path))) return false;
		if (!is_dir($path) && !mkdir($path, 0700) && !is_dir($path)) return false;
		return self::secure($path, dirname($path), 0700);
	}

	public static function writeJson(string $path, array $data): bool
	{
		$temporary = null;
		$mask = umask(0077);
		set_error_handler(static fn(): bool => true);
		try
		{
			if (is_link($path)) return false;
			$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
			$temporary = $path . '.' . bin2hex(random_bytes(12)) . '.part';
			$handle = fopen($temporary, 'xb');
			if ($handle === false) return false;
			try
			{
				if (!self::secure($temporary, dirname($path), 0600)) return false;
				if (fwrite($handle, $json) !== strlen($json) || !fflush($handle)) return false;
			}
			finally { fclose($handle); }
			return rename($temporary, $path);
		}
		catch (Throwable) { return false; }
		finally
		{
			if ($temporary !== null && is_file($temporary)) unlink($temporary);
			restore_error_handler();
			umask($mask);
		}
	}
}
