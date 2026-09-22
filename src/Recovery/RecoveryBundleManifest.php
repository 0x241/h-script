<?php

namespace HScript\Recovery;

use HScript\Backup\BackupManifest;
use HScript\Update\SchemaVersion;
use RuntimeException;
use Throwable;

final class RecoveryBundleManifest
{
	private function __construct(private array $data) {}

	public static function create(
		BackupManifest $database,
		string $databaseArchivePath,
		string $databaseManifestPath,
		array $runtime,
		string $backupRoot
	): self {
		$id = $database->id();
		$files = array(
			'database_archive' => self::fileEntry($databaseArchivePath, $backupRoot),
			'database_manifest' => self::fileEntry($databaseManifestPath, $backupRoot),
			'runtime_archive' => self::fileEntry($runtime['archive_path'], $backupRoot),
			'runtime_manifest' => self::fileEntry($runtime['manifest_path'], $backupRoot),
		);
		return self::fromArray(array(
			'format' => 1,
			'id' => $id,
			'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
			'application_version' => $database->toArray()['application_version'],
			'schema_version' => $database->schemaVersion(),
			'database_backup_id' => $id,
			'files' => $files,
			'components' => array('mysql', 'runtime_files'),
			'status' => 'verified',
		));
	}

	public static function fromFile(string $path): self
	{
		if (!is_file($path) || is_link($path) || filesize($path) > 1048576)
			throw new RecoveryException('bundle_manifest_invalid', 'Recovery bundle manifest is not a regular bounded file');
		try
		{
			$data = json_decode((string)file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
		}
		catch (Throwable $exception)
		{
			throw new RecoveryException('bundle_manifest_invalid', 'Recovery bundle manifest is invalid', $exception);
		}
		return self::fromArray(is_array($data) ? $data : array());
	}

	public static function fromArray(array $data): self
	{
		$expected = array(
			'application_version', 'components', 'created_at', 'database_backup_id',
			'files', 'format', 'id', 'schema_version', 'status',
		);
		$actual = array_keys($data);
		sort($expected);
		sort($actual);
		if ($actual !== $expected || ($data['format'] ?? null) !== 1 || ($data['status'] ?? null) !== 'verified')
			throw new RecoveryException('bundle_manifest_invalid', 'Recovery bundle manifest fields are invalid');
		if (!is_string($data['id']) || !preg_match('/^[a-f0-9]{32}$/', $data['id']) || $data['database_backup_id'] !== $data['id'])
			throw new RecoveryException('bundle_manifest_invalid', 'Recovery bundle ID is invalid');
		if ($data['components'] !== array('mysql', 'runtime_files'))
			throw new RecoveryException('bundle_manifest_invalid', 'Recovery bundle components are invalid');
		try
		{
			SchemaVersion::requireValid($data['application_version'], 'recovery application version');
			SchemaVersion::requireValid($data['schema_version'], 'recovery schema version');
		}
		catch (Throwable $exception)
		{
			throw new RecoveryException('bundle_manifest_invalid', 'Recovery bundle versions are invalid', $exception);
		}
		if (!is_array($data['files']) || array_keys($data['files']) !== array(
			'database_archive', 'database_manifest', 'runtime_archive', 'runtime_manifest',
		))
			throw new RecoveryException('bundle_incomplete', 'Recovery bundle is incomplete');
		foreach ($data['files'] as $file)
			if (!is_array($file) || count($file) !== 3
				|| !array_key_exists('path', $file) || !array_key_exists('sha256', $file) || !array_key_exists('size', $file)
				|| !is_string($file['path']) || str_starts_with($file['path'], '/')
				|| preg_match('#(?:^|/)\.\.(?:/|$)#', $file['path'])
				|| !is_string($file['sha256']) || !preg_match('/^[a-f0-9]{64}$/', $file['sha256'])
				|| !is_int($file['size']) || $file['size'] < 1)
				throw new RecoveryException('bundle_manifest_invalid', 'Recovery bundle file metadata is invalid');
		return new self($data);
	}

	public function verifyFiles(string $root): void
	{
		$root = rtrim((string)realpath($root), '/');
		if ($root === '')
			throw new RecoveryException('bundle_restore_missing', 'Restored bundle root is missing');
		foreach ($this->data['files'] as $file)
		{
			$path = $root . '/' . $file['path'];
			$resolved = realpath($path);
			if ($resolved === false || !is_file($resolved) || is_link($path)
				|| !str_starts_with($resolved . '/', $root . '/'))
				throw new RecoveryException('bundle_incomplete', 'Recovery bundle file is missing');
			$checksum = hash_file('sha256', $resolved);
			if (filesize($resolved) !== $file['size'] || !is_string($checksum) || !hash_equals($file['sha256'], $checksum))
				throw new RecoveryException('bundle_corrupt', 'Recovery bundle file checksum does not match');
		}
	}

	private static function fileEntry(string $path, string $root): array
	{
		$root = rtrim((string)realpath($root), '/');
		$resolved = realpath($path);
		if ($root === '' || $resolved === false || !is_file($resolved) || is_link($path)
			|| !str_starts_with($resolved . '/', $root . '/'))
			throw new RuntimeException('Recovery bundle file is outside backup storage');
		$checksum = hash_file('sha256', $resolved);
		if (!is_string($checksum))
			throw new RuntimeException('Recovery bundle checksum could not be calculated');
		return array(
			'path' => substr($resolved, strlen($root) + 1),
			'sha256' => $checksum,
			'size' => filesize($resolved),
		);
	}

	public function id(): string { return $this->data['id']; }
	public function file(string $component): array { return $this->data['files'][$component]; }
	public function toArray(): array { return $this->data; }
}
