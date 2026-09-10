<?php

namespace HScript\Update;

use JsonException;
use RuntimeException;

/** Machine-readable source-version contract shipped inside the existing release artifact. */
final class UpdateCompatibility
{
	private function __construct(private array $data) {}

	public static function fromRoot(string $root): self
	{
		$path = rtrim($root, '/') . '/resources/update-compatibility.json';
		if (!is_file($path) || is_link($path) || !is_readable($path))
			throw new RuntimeException('Release update compatibility contract is missing');
		try { $data = json_decode((string)file_get_contents($path), true, 16, JSON_THROW_ON_ERROR); }
		catch (JsonException $exception) { throw new RuntimeException('Release update compatibility contract is invalid', 0, $exception); }
		return self::fromArray($data);
	}

	public static function fromArray(array $data): self
	{
		if (array_keys($data) !== array('format', 'application', 'schema') || $data['format'] !== 1)
			throw new RuntimeException('Release update compatibility contract fields are invalid');
		foreach (array('application', 'schema') as $section)
		{
			if (!is_array($data[$section]) || array_keys($data[$section]) !== array('minimum', 'maximum'))
				throw new RuntimeException('Release update compatibility range is invalid');
			$data[$section]['minimum'] = SchemaVersion::requireValid($data[$section]['minimum'], $section . ' minimum source version');
			$data[$section]['maximum'] = SchemaVersion::requireValid($data[$section]['maximum'], $section . ' maximum source version');
			if (SchemaVersion::compare($data[$section]['minimum'], $data[$section]['maximum']) > 0)
				throw new RuntimeException('Release update compatibility range is reversed');
		}
		return new self($data);
	}

	public function assertSource(?string $applicationVersion, string $schemaVersion): void
	{
		$this->assertRange($schemaVersion, $this->data['schema'], 'database schema');
		if ($applicationVersion !== null)
			$this->assertRange($applicationVersion, $this->data['application'], 'application');
	}

	public function toArray(): array { return $this->data; }

	private function assertRange(string $version, array $range, string $name): void
	{
		$version = SchemaVersion::requireValid($version, 'source ' . $name . ' version');
		if (SchemaVersion::compare($version, $range['minimum']) < 0 || SchemaVersion::compare($version, $range['maximum']) > 0)
			throw new RuntimeException('The installed ' . $name . ' version is not supported by this release');
	}
}
