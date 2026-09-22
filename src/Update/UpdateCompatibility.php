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
		if (!is_array($data)) throw new RuntimeException('Release update compatibility contract must be an object');
		return self::fromArray($data);
	}

	public static function fromArray(array $data): self
	{
		$keys = array_keys($data); sort($keys);
		if ($keys !== array('application', 'environment', 'format', 'rollback', 'schema') || $data['format'] !== 2)
			throw new RuntimeException('Release update compatibility contract fields are invalid');
		foreach (array('application', 'schema') as $section)
		{
			self::keys($data[$section], array('minimum', 'maximum'));
			$data[$section]['minimum'] = SchemaVersion::requireValid($data[$section]['minimum'], $section . ' minimum source version');
			$data[$section]['maximum'] = SchemaVersion::requireValid($data[$section]['maximum'], $section . ' maximum source version');
			if (SchemaVersion::compare($data[$section]['minimum'], $data[$section]['maximum']) > 0)
				throw new RuntimeException('Release update compatibility range is reversed');
		}
		self::validateEnvironment($data['environment']);
		self::keys($data['rollback'], array('code', 'schema', 'automatic_downgrade'));
		if ($data['rollback']['code'] !== 'only-before-schema-change' || $data['rollback']['schema'] !== 'verified-full-backup-restore'
			|| $data['rollback']['automatic_downgrade'] !== false)
			throw new RuntimeException('Release rollback contract is invalid');
		return new self($data);
	}

	public function assertSource(?string $applicationVersion, string $schemaVersion): void
	{
		$this->assertRange($schemaVersion, $this->data['schema'], 'database schema');
		if ($applicationVersion !== null)
			$this->assertRange($applicationVersion, $this->data['application'], 'application');
	}

	public function toArray(): array { return $this->data; }
	public function environment(): array { return $this->data['environment']; }
	public function rollback(): array { return $this->data['rollback']; }

	/** Read-only measurements are supplied by the existing update preflight. */
	public function assertRuntime(array $runtime): void
	{
		$environment = $this->environment();
		if (!self::inRange($runtime['php'] ?? '', $environment['php']))
			throw new RuntimeException('Release does not support this PHP version');
		foreach ($environment['extensions'] as $extension)
			if (!in_array($extension, $runtime['extensions'] ?? array(), true))
				throw new RuntimeException('Release requires PHP extension: ' . $extension);
		$engine = $runtime['database_engine'] ?? '';
		$supported = false;
		foreach ($environment['database'][$engine] ?? array() as $range)
			$supported = $supported || self::inRange($runtime['database_version'] ?? '', $range);
		if (!$supported) throw new RuntimeException('Release does not support this database version');
		if (!is_bool($runtime['redis_enabled'] ?? null)) throw new RuntimeException('Redis runtime state is unknown');
		if ($environment['redis']['required'] && !$runtime['redis_enabled'])
			throw new RuntimeException('Release requires Redis');
		if ($runtime['redis_enabled'] && !self::inRange($runtime['redis_version'] ?? '', $environment['redis']))
			throw new RuntimeException('Redis is unavailable or unsupported; restore it or explicitly disable optional caching');
	}

	private static function validateEnvironment(mixed $environment): void
	{
		self::keys($environment, array('php', 'extensions', 'database', 'redis', 'browsers'));
		self::range($environment['php']);
		if (!is_array($environment['extensions']) || !array_is_list($environment['extensions']) || !$environment['extensions'])
			throw new RuntimeException('Release PHP extensions are invalid');
		foreach ($environment['extensions'] as $extension)
			if (!is_string($extension) || !preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $extension))
				throw new RuntimeException('Release PHP extension is invalid');
		self::keys($environment['database'], array('mysql', 'mariadb'));
		foreach ($environment['database'] as $ranges)
		{
			if (!is_array($ranges) || !array_is_list($ranges) || count($ranges) > 8)
				throw new RuntimeException('Release database ranges are invalid');
			foreach ($ranges as $range) self::range($range);
		}
		self::keys($environment['redis'], array('required', 'minimum', 'maximum_exclusive'));
		if (!is_bool($environment['redis']['required'])) throw new RuntimeException('Release Redis requirement is invalid');
		self::range(array_diff_key($environment['redis'], array('required' => true)));
		self::keys($environment['browsers'], array('chrome', 'firefox', 'safari'));
		foreach ($environment['browsers'] as $version)
			if (!is_string($version) || !preg_match('/^[1-9][0-9]*(?:\.[0-9]+)?$/D', $version))
				throw new RuntimeException('Release browser baseline is invalid');
	}

	private static function keys(mixed $value, array $expected): void
	{
		if (!is_array($value)) throw new RuntimeException('Release environment contract is invalid');
		$keys = array_keys($value); sort($keys); sort($expected);
		if ($keys !== $expected) throw new RuntimeException('Release environment fields are invalid');
	}

	private static function range(mixed $range): void
	{
		self::keys($range, array('minimum', 'maximum_exclusive'));
		foreach ($range as $version)
			if (!is_string($version) || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $version))
				throw new RuntimeException('Release runtime version is invalid');
		if (version_compare($range['minimum'], $range['maximum_exclusive'], '>='))
			throw new RuntimeException('Release runtime range is reversed');
	}

	private static function inRange(mixed $version, array $range): bool
	{
		return is_string($version) && (bool)preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $version)
			&& version_compare($version, $range['minimum'], '>=') && version_compare($version, $range['maximum_exclusive'], '<');
	}

	/** Validate direction independently of the declared supported source range. */
	public function assertUpgrade(string $sourceApplication, string $sourceSchema, string $targetApplication, string $targetSchema): void
	{
		$this->assertSource($sourceApplication, $sourceSchema);
		if (SchemaVersion::compare($targetApplication, $sourceApplication) < 0)
			throw new RuntimeException('Application downgrade is not supported by the update workflow');
		if (SchemaVersion::compare($targetSchema, $sourceSchema) < 0)
			throw new RuntimeException('Schema downgrade is not supported');
	}

	private function assertRange(string $version, array $range, string $name): void
	{
		$version = SchemaVersion::requireValid($version, 'source ' . $name . ' version');
		if (SchemaVersion::compare($version, $range['minimum']) < 0 || SchemaVersion::compare($version, $range['maximum']) > 0)
			throw new RuntimeException('The installed ' . $name . ' version is not supported by this release');
	}
}
