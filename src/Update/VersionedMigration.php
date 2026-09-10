<?php

namespace HScript\Update;

use Closure;
use HScript\Database\Connection;
use InvalidArgumentException;

final class VersionedMigration
{
	private string $id;
	private string $fromVersion;
	private string $toVersion;
	private string $classification;
	private string $checksum;
	private Closure $up;

	private function __construct(
		string $id,
		string $fromVersion,
		string $toVersion,
		string $classification,
		string $checksum,
		Closure $up
	) {
		$this->id = $id;
		$this->fromVersion = $fromVersion;
		$this->toVersion = $toVersion;
		$this->classification = $classification;
		$this->checksum = $checksum;
		$this->up = $up;
	}

	public static function fromFile(string $path): self
	{
		if (!is_file($path) || !is_readable($path))
			throw new InvalidArgumentException('Migration file is not readable');
		$source = file_get_contents($path);
		if (!is_string($source))
			throw new InvalidArgumentException('Migration file could not be read');
		$definition = require $path;
		if (!is_array($definition))
			throw new InvalidArgumentException('Migration must return an array');
		$expected = array('classification', 'from', 'id', 'to', 'up');
		$actual = array_keys($definition);
		sort($expected);
		sort($actual);
		if ($actual !== $expected)
			throw new InvalidArgumentException('Migration fields are invalid');

		$id = is_string($definition['id']) ? $definition['id'] : '';
		if (!preg_match('/^[0-9]{12}_[a-z0-9_]{1,64}$/', $id))
			throw new InvalidArgumentException('Migration ID is invalid');
		if (basename($path) !== $id . '.php')
			throw new InvalidArgumentException('Migration filename must match its ID');
		$from = SchemaVersion::requireValid($definition['from'], 'migration source version');
		$to = SchemaVersion::requireValid($definition['to'], 'migration target version');
		if (SchemaVersion::compare($from, $to) >= 0)
			throw new InvalidArgumentException('Migration target version must be newer than source version');
		$classification = UpdateClassification::requireValid($definition['classification']);
		if ($classification === UpdateClassification::CODE_ONLY)
			throw new InvalidArgumentException('Database migration cannot be code-only');
		if (!$definition['up'] instanceof Closure)
			throw new InvalidArgumentException('Migration up handler must be a closure');

		return new self($id, $from, $to, $classification, hash('sha256', $source), $definition['up']);
	}

	public function id(): string { return $this->id; }
	public function fromVersion(): string { return $this->fromVersion; }
	public function toVersion(): string { return $this->toVersion; }
	public function classification(): string { return $this->classification; }
	public function checksum(): string { return $this->checksum; }

	public function apply(Connection $database): void
	{
		($this->up)($database);
	}
}
