<?php

namespace HScript\Backup;

use RuntimeException;

/** Private atomic metadata publication without following predictable temp links. */
final class PrivateJsonFile
{
	public static function write(string $path, array $data): void
	{
		if (is_link($path) || is_link(dirname($path)))
			throw new RuntimeException('Private metadata path must not be a symlink');
		$content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
		$temporary = $path . '.' . bin2hex(random_bytes(16)) . '.part';
		$mask = umask(0077);
		try { $stream = fopen($temporary, 'xb'); }
		finally { umask($mask); }
		if ($stream === false) throw new RuntimeException('Private metadata could not be created');
		try
		{
			$offset = 0;
			while ($offset < strlen($content))
			{
				$written = fwrite($stream, substr($content, $offset));
				if ($written === false || $written === 0) throw new RuntimeException('Private metadata could not be written');
				$offset += $written;
			}
			if (!fflush($stream) || !fsync($stream)) throw new RuntimeException('Private metadata could not be flushed');
			fclose($stream);
			if (is_link($path) || !rename($temporary, $path)) throw new RuntimeException('Private metadata could not be published');
		}
		finally
		{
			if (is_resource($stream)) fclose($stream);
			if (is_file($temporary)) unlink($temporary);
		}
	}
}
