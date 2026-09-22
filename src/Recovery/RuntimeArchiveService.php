<?php

namespace HScript\Recovery;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

final class RuntimeArchiveService
{
	private CommandRunner $runner;

	public function __construct(private RecoverySettings $settings, ?CommandRunner $runner = null)
	{
		$this->runner = $runner ?? new CommandRunner();
	}

	public function create(string $bundleId): array
	{
		$this->assertBundleId($bundleId);
		$bundleDirectory = $this->bundleDirectory($bundleId);
		if (file_exists($bundleDirectory))
			throw new RecoveryException('bundle_already_exists', 'Recovery bundle directory already exists');
		if (!mkdir($bundleDirectory, 0700, true))
			throw new RuntimeException('Recovery bundle directory could not be created');
		$staging = $bundleDirectory . '/.runtime-staging';
		if (!mkdir($staging . '/payload', 0700, true))
			throw new RuntimeException('Runtime staging directory could not be created');

		try
		{
			$files = $this->copyAllowlistedFiles($staging . '/payload');
			$internal = array(
				'format' => 1,
				'bundle_id' => $bundleId,
				'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
				'allowlist' => $this->settings->runtimePaths(),
				'protected_configuration_included' => $this->settings->includeProtectedConfig(),
				'files' => $files,
				'file_count' => count($files),
				'total_size' => array_sum(array_column($files, 'size')),
				'status' => 'verified',
			);
			$this->writeJson($staging . '/runtime-manifest.json', $internal);
			$archiveName = 'h-script-runtime-' . $bundleId . '.tar.gz';
			$archivePath = $bundleDirectory . '/' . $archiveName;
			$result = $this->runner->run(array(
				$this->settings->tarBinary(), '-czf', $archivePath, '-C', $staging, '.',
			));
			if ($result->exitCode() !== 0 || !is_file($archivePath) || filesize($archivePath) < 1)
				throw new RecoveryException('runtime_archive_failed', 'Runtime archive could not be created');
			chmod($archivePath, 0600);
			$checksum = hash_file('sha256', $archivePath);
			if (!is_string($checksum))
				throw new RuntimeException('Runtime archive checksum could not be calculated');
			$manifest = $internal + array(
				'archive' => $archiveName,
				'archive_sha256' => $checksum,
				'stored_size' => filesize($archivePath),
			);
			$manifestPath = $bundleDirectory . '/' . $bundleId . '.runtime.json';
			$this->writeJson($manifestPath, $manifest);
			self::removeTree($staging);
			$this->verify($archivePath, $manifestPath);
			return array(
				'archive_path' => $archivePath,
				'manifest_path' => $manifestPath,
				'manifest' => $manifest,
			);
		}
		catch (Throwable $exception)
		{
			self::removeTree($bundleDirectory);
			throw $exception;
		}
	}

	public function verify(string $archivePath, string $manifestPath): array
	{
		$manifest = $this->readManifest($manifestPath);
		if (!is_file($archivePath) || is_link($archivePath) || !is_readable($archivePath))
			throw new RecoveryException('runtime_archive_missing', 'Runtime archive is missing');
		$checksum = hash_file('sha256', $archivePath);
		if (!is_string($checksum) || !hash_equals($manifest['archive_sha256'], $checksum))
			throw new RecoveryException('runtime_archive_corrupt', 'Runtime archive checksum does not match');

		$list = $this->runner->run(array($this->settings->tarBinary(), '-tzf', $archivePath));
		if ($list->exitCode() !== 0)
			throw new RecoveryException('runtime_archive_corrupt', 'Runtime archive cannot be listed');
		foreach (preg_split('/\r?\n/', trim($list->stdout())) ?: array() as $entry)
		{
			$entry = preg_replace('#^\./#', '', trim($entry));
			if ($entry === '' || $entry === '.') continue;
			if (str_starts_with($entry, '/') || preg_match('#(?:^|/)\.\.(?:/|$)#', $entry))
				throw new RecoveryException('runtime_archive_unsafe', 'Runtime archive contains an unsafe path');
		}

		$extract = $this->settings->stateDirectory() . '/.verify-' . bin2hex(random_bytes(8));
		if (!mkdir($extract, 0700))
			throw new RuntimeException('Runtime verification directory could not be created');
		try
		{
			$result = $this->runner->run(array($this->settings->tarBinary(), '-xzf', $archivePath, '-C', $extract));
			if ($result->exitCode() !== 0)
				throw new RecoveryException('runtime_archive_corrupt', 'Runtime archive cannot be extracted');
			$internal = json_decode((string)file_get_contents($extract . '/runtime-manifest.json'), true, 64, JSON_THROW_ON_ERROR);
			foreach (array('format', 'bundle_id', 'allowlist', 'files', 'file_count', 'total_size', 'status') as $field)
				if (($internal[$field] ?? null) !== $manifest[$field])
					throw new RecoveryException('runtime_manifest_mismatch', 'Runtime manifest inside archive does not match');
			$actualPaths = array();
			foreach ($manifest['files'] as $file)
			{
				$path = $extract . '/payload/' . $file['path'];
				if (!is_file($path) || is_link($path) || filesize($path) !== $file['size'])
					throw new RecoveryException('runtime_restore_partial', 'Runtime file is missing or incomplete');
				$actual = hash_file('sha256', $path);
				if (!is_string($actual) || !hash_equals($file['sha256'], $actual))
					throw new RecoveryException('runtime_restore_partial', 'Runtime file checksum does not match');
				$actualPaths[$file['path']] = true;
			}
			$payload = $extract . '/payload';
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($payload, FilesystemIterator::SKIP_DOTS),
				RecursiveIteratorIterator::LEAVES_ONLY
			);
			foreach ($iterator as $item)
			{
				$path = substr($item->getPathname(), strlen($payload) + 1);
				if ($item->isLink() || !$item->isFile() || !isset($actualPaths[$path]))
					throw new RecoveryException('runtime_restore_partial', 'Runtime restore contains an unexpected entry');
			}
		}
		catch (RecoveryException $exception)
		{
			throw $exception;
		}
		catch (Throwable $exception)
		{
			throw new RecoveryException('runtime_archive_corrupt', 'Runtime archive verification failed', $exception);
		}
		finally
		{
			self::removeTree($extract);
		}

		return array(
			'archive_checksum' => true,
			'manifest_match' => true,
			'file_checksums' => true,
			'file_count' => count($manifest['files']),
		);
	}

	private function copyAllowlistedFiles(string $destination): array
	{
		$files = array();
		$totalBytes = 0;
		$seen = array();
		foreach ($this->settings->runtimePaths() as $relative)
		{
			$source = $this->settings->runtimeRoot() . '/' . $relative;
			if (!file_exists($source) && !is_link($source))
				throw new RecoveryException('runtime_path_missing', 'Allowlisted runtime path is missing: ' . $relative);
			$this->copyEntry($source, $relative, $destination, $files, $seen, $totalBytes);
		}
		usort($files, static fn(array $left, array $right): int => strcmp($left['path'], $right['path']));
		return $files;
	}

	private function copyEntry(
		string $source,
		string $relative,
		string $destination,
		array &$files,
		array &$seen,
		int &$totalBytes
	): void {
		if (isset($seen[$relative])) return;
		$seen[$relative] = true;
		if (is_link($source))
			throw new RecoveryException('runtime_symlink_rejected', 'Runtime allowlist contains a symbolic link');
		if (is_dir($source))
		{
			$target = $destination . '/' . $relative;
			if (!is_dir($target) && !mkdir($target, 0700, true) && !is_dir($target))
				throw new RuntimeException('Runtime staging directory could not be created');
			$items = scandir($source);
			if ($items === false)
				throw new RuntimeException('Runtime directory could not be listed');
			foreach ($items as $item)
			{
				if ($item === '.' || $item === '..') continue;
				$this->copyEntry($source . '/' . $item, $relative . '/' . $item, $destination, $files, $seen, $totalBytes);
			}
			return;
		}
		if (!is_file($source))
			throw new RecoveryException('runtime_special_file_rejected', 'Runtime allowlist contains a special file');
		if (count($files) >= $this->settings->maximumRuntimeFiles())
			throw new RecoveryException('runtime_file_limit', 'Runtime file-count limit was exceeded');
		$size = filesize($source);
		if (!is_int($size) || $size < 0 || $totalBytes + $size > $this->settings->maximumRuntimeBytes())
			throw new RecoveryException('runtime_size_limit', 'Runtime byte limit was exceeded');
		$target = $destination . '/' . $relative;
		if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true) && !is_dir(dirname($target)))
			throw new RuntimeException('Runtime staging parent could not be created');
		$this->copyFile($source, $target);
		$checksum = hash_file('sha256', $target);
		if (!is_string($checksum))
			throw new RuntimeException('Runtime file checksum could not be calculated');
		chmod($target, 0600);
		$files[] = array('path' => $relative, 'size' => $size, 'sha256' => $checksum);
		$totalBytes += $size;
	}

	private function copyFile(string $source, string $target): void
	{
		$input = fopen($source, 'rb');
		$output = fopen($target, 'xb');
		if ($input === false || $output === false)
			throw new RuntimeException('Runtime file could not be staged');
		try
		{
			while (!feof($input))
			{
				$chunk = fread($input, 65536);
				if ($chunk === false)
					throw new RuntimeException('Runtime file could not be copied');
				$offset = 0;
				while ($offset < strlen($chunk))
				{
					$written = fwrite($output, substr($chunk, $offset));
					if ($written === false || $written === 0)
						throw new RuntimeException('Runtime file could not be copied');
					$offset += $written;
				}
			}
			if (!fflush($output))
				throw new RuntimeException('Runtime file could not be flushed');
		}
		finally
		{
			fclose($input);
			fclose($output);
		}
	}

	private function readManifest(string $path): array
	{
		if (!is_file($path) || is_link($path))
			throw new RecoveryException('runtime_manifest_invalid', 'Runtime manifest is not a regular file');
		try
		{
			$data = json_decode((string)file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);
		}
		catch (Throwable $exception)
		{
			throw new RecoveryException('runtime_manifest_invalid', 'Runtime manifest is invalid', $exception);
		}
		$expected = array(
			'allowlist', 'archive', 'archive_sha256', 'bundle_id', 'created_at', 'file_count',
			'files', 'format', 'protected_configuration_included', 'status', 'stored_size', 'total_size',
		);
		$fields = is_array($data) ? array_keys($data) : array();
		sort($fields);
		sort($expected);
		if ($fields !== $expected || $data['format'] !== 1 || $data['status'] !== 'verified')
			throw new RecoveryException('runtime_manifest_invalid', 'Runtime manifest fields are invalid');
		$this->assertBundleId((string)$data['bundle_id']);
		if (!preg_match('/^h-script-runtime-[a-f0-9]{32}\.tar\.gz$/', (string)$data['archive'])
			|| !preg_match('/^[a-f0-9]{64}$/', (string)$data['archive_sha256']))
			throw new RecoveryException('runtime_manifest_invalid', 'Runtime archive metadata is invalid');
		if (!is_array($data['files']) || !array_is_list($data['files']) || count($data['files']) !== $data['file_count'])
			throw new RecoveryException('runtime_manifest_invalid', 'Runtime file inventory is invalid');
		$seen = array();
		$total = 0;
		foreach ($data['files'] as $file)
		{
			if (!is_array($file) || count($file) !== 3
				|| !array_key_exists('path', $file) || !array_key_exists('size', $file) || !array_key_exists('sha256', $file)
				|| !is_string($file['path']) || isset($seen[$file['path']])
				|| !is_int($file['size']) || $file['size'] < 0
				|| !is_string($file['sha256']) || !preg_match('/^[a-f0-9]{64}$/', $file['sha256']))
				throw new RecoveryException('runtime_manifest_invalid', 'Runtime file metadata is invalid');
			$seen[$file['path']] = true;
			$total += $file['size'];
		}
		if ($total !== $data['total_size'])
			throw new RecoveryException('runtime_manifest_invalid', 'Runtime total size is invalid');
		return $data;
	}

	private function writeJson(string $path, array $data): void
	{
		\HScript\Backup\PrivateJsonFile::write($path, $data);
	}

	private function bundleDirectory(string $bundleId): string
	{
		return $this->settings->stateDirectory() . '/bundles/' . $bundleId;
	}

	private function assertBundleId(string $bundleId): void
	{
		if (!preg_match('/^[a-f0-9]{32}$/', $bundleId))
			throw new RuntimeException('Recovery bundle ID is invalid');
	}

	public static function removeTree(string $directory): void
	{
		if (!is_dir($directory) || is_link($directory)) return;
		$items = scandir($directory);
		if ($items === false) return;
		foreach ($items as $item)
		{
			if ($item === '.' || $item === '..') continue;
			$path = $directory . '/' . $item;
			if (is_dir($path) && !is_link($path)) self::removeTree($path); else unlink($path);
		}
		rmdir($directory);
	}
}
