<?php

namespace HScript\Backup;

use HScript\Update\SchemaVersion;
use InvalidArgumentException;
use JsonException;

final class BackupManifest
{
	private array $data;

	private function __construct(array $data)
	{
		$this->data = $data;
	}

	public static function fromArray(array $data): self
	{
		self::validate($data);
		return new self($data);
	}

	public static function fromFile(string $path): self
	{
		if (!is_file($path) || !is_readable($path))
			throw new InvalidArgumentException('Backup manifest is not readable');
		try
		{
			$data = json_decode((string)file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
		}
		catch (JsonException $exception)
		{
			throw new InvalidArgumentException('Backup manifest is not valid JSON', 0, $exception);
		}
		if (!is_array($data))
			throw new InvalidArgumentException('Backup manifest root must be an object');
		return self::fromArray($data);
	}

	public function id(): string { return $this->data['id']; }
	public function archive(): string { return $this->data['archive']; }
	public function compression(): string { return $this->data['compression']; }
	public function checksum(): string { return $this->data['sha256']; }
	public function databaseHash(): string { return $this->data['database_sha256']; }
	public function schemaVersion(): string { return $this->data['schema_version']; }
	public function tables(): array { return $this->data['tables']; }
	public function toArray(): array { return $this->data; }

	private static function validate(array $data): void
	{
		$expected = array(
			'adapter', 'application_version', 'archive', 'compression', 'created_at',
			'database_sha256', 'format', 'id', 'location', 'schema_version', 'sha256',
			'status', 'stored_size', 'table_count', 'tables', 'uncompressed_size',
			'verification', 'verified_at',
		);
		$actual = array_keys($data);
		sort($expected);
		sort($actual);
		if ($actual !== $expected)
			throw new InvalidArgumentException('Backup manifest fields are invalid');
		if ($data['format'] !== 1)
			throw new InvalidArgumentException('Unsupported backup manifest format');
		if (!is_string($data['id']) || !preg_match('/^[a-f0-9]{32}$/', $data['id']))
			throw new InvalidArgumentException('Backup ID is invalid');
		if (!is_string($data['archive']) || !preg_match('/^h-script-[0-9]{8}-[0-9]{6}-[a-f0-9]{32}\.sql(?:\.gz)?$/', $data['archive']))
			throw new InvalidArgumentException('Backup archive name is invalid');
		if (!str_contains($data['archive'], $data['id']))
			throw new InvalidArgumentException('Backup archive does not match its ID');
		if (!in_array($data['compression'], array('gzip', 'plain'), true))
			throw new InvalidArgumentException('Backup compression is invalid');
		if (($data['compression'] === 'gzip') !== str_ends_with($data['archive'], '.gz'))
			throw new InvalidArgumentException('Backup archive extension is invalid');
		if (!in_array($data['adapter'], array('mysqldump', 'php-stream'), true))
			throw new InvalidArgumentException('Backup adapter is invalid');
		if (!in_array($data['location'], array('external', 'document-root-protected'), true))
			throw new InvalidArgumentException('Backup location is invalid');
		if ($data['status'] !== 'verified')
			throw new InvalidArgumentException('Only verified backups may be published');
		foreach (array('created_at', 'verified_at') as $field)
			if (!is_string($data[$field]) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $data[$field]))
				throw new InvalidArgumentException('Backup timestamp is invalid');
		foreach (array('database_sha256', 'sha256') as $field)
			if (!is_string($data[$field]) || !preg_match('/^[a-f0-9]{64}$/', $data[$field]))
				throw new InvalidArgumentException('Backup checksum is invalid');
		SchemaVersion::requireValid($data['application_version'], 'backup application version');
		SchemaVersion::requireValid($data['schema_version'], 'backup schema version');
		foreach (array('uncompressed_size', 'stored_size', 'table_count') as $field)
			if (!is_int($data[$field]) || $data[$field] < 1)
				throw new InvalidArgumentException('Backup size or table count is invalid');
		if (!is_array($data['tables']) || !array_is_list($data['tables']) || count($data['tables']) !== $data['table_count'])
			throw new InvalidArgumentException('Backup table list is invalid');
		$unique = array();
		foreach ($data['tables'] as $table)
		{
			if (!is_string($table) || !preg_match('/^[A-Za-z0-9_]+$/', $table) || isset($unique[$table]))
				throw new InvalidArgumentException('Backup table name is invalid or duplicated');
			$unique[$table] = true;
		}
		$verification = array(
			'archive_readable' => true,
			'checksum_match' => true,
			'core_tables_present' => true,
			'footer_present' => true,
			'table_count_match' => true,
		);
		if (!is_array($data['verification']) || count($data['verification']) !== count($verification))
			throw new InvalidArgumentException('Backup verification result is invalid');
		foreach ($verification as $field => $expectedValue)
			if (($data['verification'][$field] ?? null) !== $expectedValue)
				throw new InvalidArgumentException('Backup verification result is invalid');
	}
}
