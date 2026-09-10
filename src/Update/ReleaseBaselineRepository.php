<?php

namespace HScript\Update;

use JsonException;
use RuntimeException;

/** Local checksum baseline of the official release currently installed. */
final class ReleaseBaselineRepository
{
	public function __construct(private UpdateSettings $settings) {}

	/** @return array<string,string>|null */
	public function get(string $expectedVersion): ?array
	{
		$files = array();
		$entries = $this->entries($expectedVersion);
		if ($entries === null) return null;
		foreach ($entries as $path => $entry) $files[$path] = $entry['sha256'];
		return $files;
	}

	/** @return array<string,array{sha256:string,size:int,class:string}>|null */
	public function entries(string $expectedVersion): ?array
	{
		return self::read($this->settings->baselinePath(), $expectedVersion);
	}

	/** @return array<string,array{sha256:string,size:int,class:string}>|null */
	public static function read(string $path, string $expectedVersion): ?array
	{
		if (!is_file($path) || is_link($path)) return null;
		try { $data = json_decode((string)file_get_contents($path), true, 64, JSON_THROW_ON_ERROR); }
		catch (JsonException $exception) { throw new RuntimeException('Installed release baseline is invalid', 0, $exception); }
		if (!is_array($data) || array_keys($data) !== array('format', 'version', 'files') || $data['format'] !== 2
			|| $data['version'] !== SchemaVersion::requireValid($expectedVersion, 'baseline version') || !is_array($data['files']))
			throw new RuntimeException('Installed release baseline does not match the current CMS version');
		$files = array();
		$seen = array();
		foreach ($data['files'] as $filePath => $entry)
		{
			$filePath = ReleaseInventory::requireSafePath($filePath);
			$key = strtolower($filePath);
			if (!is_array($entry) || array_keys($entry) !== array('sha256', 'size', 'class') || isset($seen[$key])
				|| !is_string($entry['sha256']) || !preg_match('/^[a-f0-9]{64}$/', $entry['sha256'])
				|| !is_int($entry['size']) || $entry['size'] < 0
				|| !in_array($entry['class'], array('core_strict', 'customizable'), true))
				throw new RuntimeException('Installed release baseline contains an invalid file');
			if (self::customizablePath($filePath) && $entry['class'] !== 'customizable')
				throw new RuntimeException('Template baseline entry must be customizable');
			$seen[$key] = true;
			$files[$filePath] = $entry;
		}
		ksort($files, SORT_STRING);
		return $files;
	}

	/** @param array<int,array<string,mixed>> $managedFiles */
	public function publish(string $version, array $managedFiles): void
	{
		$files = array();
		$seen = array();
		foreach ($managedFiles as $file)
		{
			$path = ReleaseInventory::requireSafePath($file['path'] ?? null);
			$checksum = $file['sha256'] ?? null;
			$size = $file['size'] ?? null;
			$class = $file['class'] ?? null;
			$key = strtolower($path);
			if (!is_string($checksum) || !preg_match('/^[a-f0-9]{64}$/', $checksum) || !is_int($size) || $size < 0
				|| !in_array($class, array('core_strict', 'customizable'), true) || isset($seen[$key]))
				throw new RuntimeException('Release baseline file list is invalid');
			if (self::customizablePath($path) && $class !== 'customizable')
				throw new RuntimeException('Template baseline entry must be customizable');
			$seen[$key] = true;
			$files[$path] = array('sha256' => $checksum, 'size' => $size, 'class' => $class);
		}
		ksort($files, SORT_STRING);
		$data = array(
			'format' => 2,
			'version' => SchemaVersion::requireValid($version, 'baseline version'),
			'files' => $files,
		);
		$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
		$target = $this->settings->baselinePath();
		$temporary = $target . '.' . bin2hex(random_bytes(8)) . '.part';
		if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json))
			throw new RuntimeException('Release baseline could not be written');
		chmod($temporary, 0600);
		if (!rename($temporary, $target))
		{
			unlink($temporary);
			throw new RuntimeException('Release baseline could not be published');
		}
	}

	private static function customizablePath(string $path): bool
	{
		return str_starts_with($path, 'tpl/themes/')
			|| (str_starts_with($path, 'tpl/') && str_ends_with(strtolower($path), '.twig'));
	}
}
