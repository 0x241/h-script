<?php

namespace HScript\Update;

use InvalidArgumentException;
use JsonException;

/**
 * Internal description of an already verified official release.
 *
 * It is generated from the contents of the GitHub shared-hosting archive and is
 * never accepted from inside the archive as a separate trust source.
 */
final class ReleaseManifest
{
	private const FILE_CLASSES = array('core_strict', 'customizable');

	private array $data;
	private string $canonicalJson;

	private function __construct(array $data)
	{
		$this->data = $data;
		$this->canonicalJson = json_encode(
			$data,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
		);
	}

	public static function fromArray(array $data): self
	{
		self::validate($data);
		return new self($data);
	}

	public static function fromJson(string $json): self
	{
		try { $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR); }
		catch (JsonException $exception) { throw new InvalidArgumentException('Release metadata is not valid JSON', 0, $exception); }
		if (!is_array($data) || array_is_list($data))
			throw new InvalidArgumentException('Release metadata root must be an object');
		return self::fromArray($data);
	}

	public function applicationVersion(): string { return $this->data['release']['application_version']; }
	public function schemaVersion(): string { return $this->data['release']['schema_version']; }
	public function releasedAt(): string { return $this->data['release']['released_at']; }
	public function summary(): string { return $this->data['release']['summary']; }
	public function changes(): array { return $this->data['release']['changes']; }
	public function classification(): string { return $this->data['classification']; }
	public function managedFiles(): array { return $this->data['files']['managed']; }
	public function migrations(): array { return array(); }
	public function source(): string { return $this->data['source']; }
	public function artifact(): array { return $this->data['artifact']; }
	public function compatibility(): array { return $this->data['compatibility']; }
	public function activationPlanChecksum(): string { return $this->data['activation_plan_sha256']; }
	public function toArray(): array { return $this->data; }
	public function checksum(): string { return hash('sha256', $this->canonicalJson); }

	private static function validate(array $data): void
	{
		self::exact($data, array(
			'activation_plan_sha256', 'artifact', 'classification', 'compatibility', 'files', 'format', 'release', 'source',
		), 'release metadata');
		if ($data['format'] !== 1)
			throw new InvalidArgumentException('Unsupported release metadata format');
		if (!in_array($data['source'], array('github', 'manual', 'bundled'), true))
			throw new InvalidArgumentException('Release source is invalid');
		UpdateClassification::requireValid($data['classification']);
		self::sha256($data['activation_plan_sha256'], 'activation plan checksum');
		UpdateCompatibility::fromArray($data['compatibility']);

		self::exact($data['release'], array('application_version', 'changes', 'released_at', 'schema_version', 'summary'), 'release');
		SchemaVersion::requireValid($data['release']['application_version'], 'release application version');
		SchemaVersion::requireValid($data['release']['schema_version'], 'release schema version');
		if (!is_string($data['release']['released_at']) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $data['release']['released_at']))
			throw new InvalidArgumentException('Release timestamp must use UTC RFC3339 format');
		if (!is_string($data['release']['summary']) || trim($data['release']['summary']) === '' || strlen($data['release']['summary']) > 300)
			throw new InvalidArgumentException('Release summary is invalid');
		if (!is_array($data['release']['changes']) || !array_is_list($data['release']['changes']) || !$data['release']['changes'] || count($data['release']['changes']) > 50)
			throw new InvalidArgumentException('Release changes must be a non-empty bounded list');
		foreach ($data['release']['changes'] as $change)
			if (!is_string($change) || trim($change) === '' || strlen($change) > 300)
				throw new InvalidArgumentException('Release change is invalid');

		self::exact($data['artifact'], array('name', 'sha256', 'sigstore_url'), 'artifact');
		if (!is_string($data['artifact']['name']) || !preg_match('/^[A-Za-z0-9._-]{1,200}$/', $data['artifact']['name']))
			throw new InvalidArgumentException('Release artifact name is invalid');
		self::sha256($data['artifact']['sha256'], 'release artifact checksum');
		if (!is_string($data['artifact']['sigstore_url']) || ($data['artifact']['sigstore_url'] !== '' && !str_starts_with($data['artifact']['sigstore_url'], 'https://github.com/0x241/h-script/releases/download/')))
			throw new InvalidArgumentException('Release Sigstore URL is invalid');

		self::exact($data['files'], array('managed'), 'files');
		if (!is_array($data['files']['managed']) || !array_is_list($data['files']['managed']))
			throw new InvalidArgumentException('Managed release files must be a list');
		$seen = array();
		foreach ($data['files']['managed'] as $file)
		{
			self::exact($file, array('class', 'path', 'sha256', 'size', 'source_sha256'), 'managed file');
			$path = self::safePath($file['path']);
			if (isset($seen[strtolower($path)]))
				throw new InvalidArgumentException('Managed release path is duplicated: ' . $path);
			$seen[strtolower($path)] = true;
			self::sha256($file['sha256'], 'managed file checksum');
			if ($file['source_sha256'] !== null) self::sha256($file['source_sha256'], 'managed source checksum');
			if (!is_int($file['size']) || $file['size'] < 0)
				throw new InvalidArgumentException('Managed file size is invalid');
			if (!in_array($file['class'], self::FILE_CLASSES, true))
				throw new InvalidArgumentException('Managed file class is invalid');
			if (str_ends_with($path, '.twig') && $file['class'] !== 'customizable')
				throw new InvalidArgumentException('Twig templates must be customizable');
			if (ReleaseInventory::preserved($path))
				throw new InvalidArgumentException('Runtime path cannot be managed by a release: ' . $path);
		}
		if ($data['source'] !== 'bundled')
			foreach (array('VERSION', 'SCHEMA_VERSION') as $required)
				if (!isset($seen[strtolower($required)]))
					throw new InvalidArgumentException('Release archive does not contain ' . $required);
		if ($data['source'] === 'bundled' && $data['files']['managed'])
			throw new InvalidArgumentException('Bundled database update cannot manage code files');
	}

	private static function exact(mixed $value, array $expected, string $section): void
	{
		if (!is_array($value) || array_is_list($value))
			throw new InvalidArgumentException(ucfirst($section) . ' must be an object');
		$actual = array_keys($value);
		sort($actual);
		sort($expected);
		if ($actual !== $expected)
			throw new InvalidArgumentException(ucfirst($section) . ' fields are invalid');
	}

	private static function safePath(mixed $path): string
	{
		$path = is_string($path) ? trim($path) : '';
		$segments = explode('/', $path);
		if ($path === '' || str_starts_with($path, '/') || str_ends_with($path, '/') || str_contains($path, '\\')
			|| str_contains($path, "\0") || in_array('', $segments, true) || in_array('.', $segments, true)
			|| in_array('..', $segments, true) || !preg_match('/^[A-Za-z0-9._\/-]+$/', $path))
			throw new InvalidArgumentException('Managed release path is unsafe');
		return $path;
	}

	private static function sha256(mixed $checksum, string $field): void
	{
		if (!is_string($checksum) || !preg_match('/^[a-f0-9]{64}$/', $checksum))
			throw new InvalidArgumentException(ucfirst($field) . ' is invalid');
	}
}
