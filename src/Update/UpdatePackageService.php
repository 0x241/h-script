<?php

namespace HScript\Update;

use HScript\Application;
use HScript\Database\Connection;
use PharData;
use RuntimeException;
use Throwable;

/** Prepares only the existing official GitHub shared-hosting release artifact. */
final class UpdatePackageService
{
	private Connection $database;
	private UpdateSettings $settings;
	private OfficialReleaseProvider $releases;
	private PreparedUpdateRepository $prepared;

	public function __construct(
		Connection $database,
		UpdateSettings $settings,
		?OfficialReleaseProvider $releases = null
	) {
		$this->database = $database;
		$this->settings = $settings;
		$this->releases = $releases ?? new GitHubReleaseProvider();
		$this->prepared = new PreparedUpdateRepository($settings);
	}

	public function prepareManual(string $uploadedPath): array
	{
		if (!is_file($uploadedPath) || is_link($uploadedPath) || !is_readable($uploadedPath))
			throw new RuntimeException('Uploaded release archive is not a readable regular file');
		return $this->prepare('manual', null, function (string $target) use ($uploadedPath): void {
			$this->copyBounded($uploadedPath, $target);
		});
	}

	public function prepareLatest(): array
	{
		$release = $this->releases->latest();
		$current = $this->readVersion($this->settings->projectRoot() . '/VERSION', 'current application');
		if (SchemaVersion::compare((string)($release['version'] ?? ''), $current) <= 0)
			throw new RuntimeException('The installed CMS already matches or exceeds the latest official GitHub Release');
		return $this->prepare('github', $release, function (string $target) use ($release): void {
			$this->releases->downloadArchive($release, $target, $this->settings->maximumPackageBytes());
		});
	}

	public function prepareBundled(): array
	{
		$id = bin2hex(random_bytes(16));
		$root = $this->settings->projectRoot();
		$targetSchema = Application::schemaVersion();
		$plan = $this->migrationPlan($root, $targetSchema);
		$classification = $this->classification($plan);
		$version = $this->readVersion($root . '/VERSION', 'application');
		$compatibility = UpdateCompatibility::fromRoot($root);
		$state = new SchemaStateRepository($this->database);
		$currentSchema = $state->currentVersion();
		if ($currentSchema === null) throw new RuntimeException('Explicit schema version must be initialized before update');
		$sourceApplication = $state->installedApplicationVersion();
		if ($sourceApplication === null)
			throw new RuntimeException('Installed application version must be recorded before a Docker database update');
		$compatibility->assertSource($sourceApplication, $currentSchema);
		$activationPlan = array();
		$activationPlanChecksum = $this->activationPlanChecksum($activationPlan);
		$manifest = ReleaseManifest::fromArray(array(
			'format' => 1,
			'source' => 'bundled',
			'activation_plan_sha256' => $activationPlanChecksum,
			'compatibility' => $compatibility->toArray(),
			'release' => array(
				'application_version' => $version,
				'schema_version' => $targetSchema,
				'released_at' => gmdate('Y-m-d\TH:i:s\Z'),
				'summary' => 'Docker-обновление H-Script ' . $version,
				'changes' => $plan
					? array('Создать проверенный SQL-бэкап и применить миграции базы из текущего образа')
					: array('Проверить новый образ и зафиксировать версию CMS без изменения базы'),
			),
			'classification' => $classification,
			'artifact' => array(
				'name' => 'docker-image-' . $version,
				'sha256' => hash('sha256', 'docker-image|' . $version . '|' . $targetSchema),
				'sigstore_url' => '',
			),
			'files' => array('managed' => array()),
		));
		$now = gmdate('Y-m-d\TH:i:s\Z');
		return $this->prepared->publish(array(
			'id' => $id,
			'source' => 'bundled',
			'activation_plan' => $activationPlan,
			'activation_plan_sha256' => $activationPlanChecksum,
			'archive' => '',
			'archive_sha256' => $manifest->artifact()['sha256'],
			'staging' => $root,
			'manifest_checksum' => $manifest->checksum(),
			'manifest' => $manifest->toArray(),
			'conflicts' => array(),
			'file_choices' => array(),
			'run_id' => '',
			'backup_id' => '',
			'created_at' => $now,
			'updated_at' => $now,
		));
	}

	public function revalidate(array $record): ReleaseManifest
	{
		$manifest = ReleaseManifest::fromArray($record['manifest']);
		if (!hash_equals((string)$record['manifest_checksum'], $manifest->checksum()))
			throw new RuntimeException('Prepared release metadata changed');
		if ($record['source'] === 'bundled')
		{
			if ($record['staging'] !== $this->settings->projectRoot())
				throw new RuntimeException('Bundled update root changed');
			$this->validateTarget($manifest, $record['staging'], (string)$record['run_id']);
			return $manifest;
		}

		$archive = (string)$record['archive'];
		$staging = (string)$record['staging'];
		if (!is_file($archive) || is_link($archive) || !is_dir($staging) || is_link($staging))
			throw new RuntimeException('Prepared release files are missing');
		$archiveHash = hash_file('sha256', $archive);
		if (!is_string($archiveHash) || !hash_equals((string)$record['archive_sha256'], $archiveHash)
			|| !hash_equals($manifest->artifact()['sha256'], $archiveHash))
			throw new RuntimeException('Prepared release archive checksum changed');
		foreach ($manifest->managedFiles() as $file)
		{
			$path = $staging . '/' . $file['path'];
			$hash = is_file($path) && !is_link($path) ? hash_file('sha256', $path) : false;
			if (!is_string($hash) || !hash_equals($file['sha256'], $hash))
				throw new RuntimeException('Prepared release file changed: ' . $file['path']);
		}
		$this->validateTarget($manifest, $staging, (string)$record['run_id']);
		return $manifest;
	}

	public function prepared(string $id): array { return $this->prepared->get($id); }
	public function preparedForRun(string $runId): array { return $this->prepared->findByRun($runId); }
	public function attachRun(string $id, string $runId, string $backupId = ''): array { return $this->prepared->attachRun($id, $runId, $backupId); }
	public function saveFileChoices(string $id, array $choices): array { return $this->prepared->saveFileChoices($id, $choices); }
	public function verifiedReleaseBaseline(array $record, ReleaseManifest $manifest): array
	{
		return $this->verifiedTargetBaseline((string)($record['staging'] ?? ''), $manifest->applicationVersion());
	}

	private function prepare(string $source, ?array $knownRelease, callable $receive): array
	{
		$id = bin2hex(random_bytes(16));
		$temporary = $this->settings->packagesDirectory() . '/.' . $id . '.part';
		$archive = $this->settings->packagesDirectory() . '/' . $id . '.tar.gz';
		$staging = $this->settings->stagingDirectory() . '/' . $id;
		try
		{
			$currentVersion = $this->readVersion($this->settings->projectRoot() . '/VERSION', 'current application');
			$currentSchema = (new SchemaStateRepository($this->database))->currentVersion();
			if ($currentSchema === null) throw new RuntimeException('Explicit schema version must be initialized before update');
			$sourceBaseline = $this->loadOrBuildBaseline($currentVersion, $id);
			$receive($temporary);
			if (!rename($temporary, $archive)) throw new RuntimeException('Release archive could not be published');
			chmod($archive, 0600);
			$this->extractArchive($archive, $staging);
			$version = $this->readVersion($staging . '/VERSION', 'application');
			$release = $knownRelease ?? $this->releases->byVersion($version);
			$this->validateOfficialRelease($release, $version, $archive);
			$this->verifiedTargetBaseline($staging, $version);
			$schema = $this->readVersion($staging . '/SCHEMA_VERSION', 'schema');
			$compatibility = UpdateCompatibility::fromRoot($staging);
			$compatibility->assertSource($currentVersion, $currentSchema);
			$plan = $this->migrationPlan($staging, $schema);
			$class = $this->classification($plan);
			$managedFiles = ReleaseInventory::managed($staging, $sourceBaseline);
			$changeSet = (new ReleaseChangePlanner($this->settings))->build($id, $sourceBaseline, $managedFiles, $staging);
			$activationPlanChecksum = $this->activationPlanChecksum($changeSet['plan']);
			$manifest = ReleaseManifest::fromArray(array(
				'format' => 1,
				'source' => $source,
				'activation_plan_sha256' => $activationPlanChecksum,
				'compatibility' => $compatibility->toArray(),
				'release' => array(
					'application_version' => $version,
					'schema_version' => $schema,
					'released_at' => (string)$release['released_at'],
					'summary' => (string)$release['summary'],
					'changes' => $release['changes'],
				),
				'classification' => $class,
				'artifact' => array(
					'name' => (string)$release['archive_name'],
					'sha256' => (string)$release['archive_sha256'],
					'sigstore_url' => (string)($release['sigstore_url'] ?? ''),
				),
				'files' => array('managed' => $managedFiles),
			));
			$this->validateTarget($manifest, $staging, '');
			$now = gmdate('Y-m-d\TH:i:s\Z');
			return $this->prepared->publish(array(
				'id' => $id,
				'source' => $source,
				'activation_plan' => $changeSet['plan'],
				'activation_plan_sha256' => $activationPlanChecksum,
				'archive' => $archive,
				'archive_sha256' => (string)$release['archive_sha256'],
				'staging' => $staging,
				'manifest_checksum' => $manifest->checksum(),
				'manifest' => $manifest->toArray(),
				'conflicts' => $changeSet['conflicts'],
				'file_choices' => array(),
				'run_id' => '',
				'backup_id' => '',
				'created_at' => $now,
				'updated_at' => $now,
			));
		}
		catch (Throwable $exception)
		{
			if (is_file($temporary)) unlink($temporary);
			if (is_file($archive)) unlink($archive);
			$this->removeTree($staging, $this->settings->stagingDirectory());
			$this->removeTree($this->settings->conflictsDirectory() . '/' . $id, $this->settings->conflictsDirectory());
			throw $exception;
		}
	}

	private function validateOfficialRelease(array $release, string $version, string $archive): void
	{
		if (($release['version'] ?? '') !== $version || ($release['archive_name'] ?? '') !== 'h-script-' . $version . '-shared-hosting.tar.gz')
			throw new RuntimeException('Archive does not match the official GitHub release');
		$expected = (string)($release['archive_sha256'] ?? '');
		$actual = hash_file('sha256', $archive);
		if (!preg_match('/^[a-f0-9]{64}$/', $expected) || !is_string($actual) || !hash_equals($expected, $actual))
			throw new RuntimeException('Archive checksum does not match GitHub Release SHA256SUMS');
	}

	/** @return array<string,array{sha256:string,size:int,class:string}> */
	private function verifiedTargetBaseline(string $staging, string $version): array
	{
		$entries = ReleaseBaselineRepository::read($staging . '/resources/release-baseline.json', $version);
		if ($entries === null) throw new RuntimeException('Official release integrity baseline is missing');
		$actual = array();
		foreach (ReleaseInventory::integrityBaseline($staging) as $file)
			$actual[$file['path']] = array('sha256' => $file['sha256'], 'size' => $file['size'], 'class' => $file['class']);
		if ($actual !== $entries) throw new RuntimeException('Official release integrity baseline does not match the archive');
		return $entries;
	}

	private function validateTarget(ReleaseManifest $manifest, string $root, string $runId): array
	{
		$currentVersion = $this->readVersion($this->settings->projectRoot() . '/VERSION', 'current application');
		$currentSchema = (new SchemaStateRepository($this->database))->currentVersion();
		if ($currentSchema === null) throw new RuntimeException('Explicit schema version must be initialized before update');
		$sourceApplication = $manifest->source() === 'bundled' ? null : $currentVersion;
		$sourceSchema = $currentSchema;
		if ($runId === '')
		{
			if ($manifest->source() !== 'bundled' && SchemaVersion::compare($manifest->applicationVersion(), $currentVersion) <= 0)
				throw new RuntimeException('Only an official release newer than the installed CMS can be prepared');
		}
		else
		{
			$run = (new UpdateRunRepository($this->database))->get($runId);
			if (!$run || !hash_equals((string)$run['urManifestChecksum'], $manifest->checksum())
				|| (string)$run['urTargetVersion'] !== $manifest->applicationVersion())
				throw new RuntimeException('Prepared release does not match its update run');
			$sourceSchema = (string)$run['urSourceSchemaVersion'];
			if ($manifest->source() === 'bundled') $sourceApplication = (string)$run['urSourceVersion'];
			if ($manifest->source() !== 'bundled')
			{
				$sourceApplication = (string)$run['urSourceVersion'];
				if (!in_array($currentVersion, array($sourceApplication, $manifest->applicationVersion()), true))
					throw new RuntimeException('Active CMS version does not match the resumable update run');
			}
		}
		UpdateCompatibility::fromArray($manifest->compatibility())->assertSource($sourceApplication, $sourceSchema);
		if (SchemaVersion::compare($manifest->schemaVersion(), $currentSchema) < 0)
			throw new RuntimeException('Schema downgrade is not supported');
		return $this->migrationPlan($root, $manifest->schemaVersion());
	}

	/** @return array<string,string> */
	private function loadOrBuildBaseline(string $currentVersion, string $preparedId): array
	{
		$repository = new ReleaseBaselineRepository($this->settings);
		$existing = $repository->get($currentVersion);
		if ($existing !== null) return $existing;

		$archive = $this->settings->packagesDirectory() . '/.source-' . $preparedId . '.tar.gz';
		$staging = $this->settings->stagingDirectory() . '/.source-' . $preparedId;
		try
		{
			$release = $this->releases->byVersion($currentVersion);
			$this->releases->downloadArchive($release, $archive, $this->settings->maximumPackageBytes());
			$this->validateOfficialRelease($release, $currentVersion, $archive);
			$this->extractArchive($archive, $staging);
			if ($this->readVersion($staging . '/VERSION', 'baseline application') !== $currentVersion)
				throw new RuntimeException('Official source baseline version does not match the installed CMS');
			$baselinePath = $staging . '/resources/release-baseline.json';
			$managed = is_file($baselinePath)
				? $this->verifiedTargetBaseline($staging, $currentVersion)
				: ReleaseInventory::managed($staging);
			if (!array_is_list($managed))
			{
				$files = array();
				foreach ($managed as $path => $entry)
					$files[] = array('path' => $path, 'sha256' => $entry['sha256'], 'size' => $entry['size'], 'class' => $entry['class']);
				$managed = $files;
			}
			$repository->publish($currentVersion, $managed);
			return $repository->get($currentVersion) ?? throw new RuntimeException('Installed release baseline could not be read');
		}
		finally
		{
			if (is_file($archive)) unlink($archive);
			$this->removeTree($staging, $this->settings->stagingDirectory());
		}
	}

	private function activationPlanChecksum(array $plan): string
	{
		return hash('sha256', json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
	}

	/** @return VersionedMigration[] */
	private function migrationPlan(string $root, string $targetSchema): array
	{
		return (new MigrationRunner(
			$this->database,
			new SchemaStateRepository($this->database),
			new MigrationLoader(rtrim($root, '/') . '/migrations/versioned')
		))->preflight($targetSchema);
	}

	/** @param VersionedMigration[] $plan */
	private function classification(array $plan): string
	{
		if (!$plan) return UpdateClassification::CODE_ONLY;
		foreach ($plan as $migration)
			if ($migration->classification() === UpdateClassification::IRREVERSIBLE) return UpdateClassification::IRREVERSIBLE;
		return UpdateClassification::BACKUP_REQUIRED;
	}

	private function extractArchive(string $archive, string $staging): void
	{
		$size = filesize($archive);
		if (!is_int($size) || $size < 1 || $size > $this->settings->maximumPackageBytes())
			throw new RuntimeException('Release archive exceeds UPDATE_MAX_PACKAGE_BYTES');
		$this->inspectTarHeaders($archive);
		try { $phar = new PharData($archive); }
		catch (Throwable $exception) { throw new RuntimeException('Release package is not a readable tar.gz archive', 0, $exception); }
		$prefix = 'phar://' . $archive . '/';
		$entries = array();
		$total = 0;
		try
		{
			$iterator = new \RecursiveIteratorIterator($phar, \RecursiveIteratorIterator::SELF_FIRST);
			foreach ($iterator as $item)
			{
				$inside = str_starts_with($item->getPathname(), $prefix) ? substr($item->getPathname(), strlen($prefix)) : '';
				$inside = $this->safeArchivePath($inside);
				if ($inside === 'h-script') continue;
				if (!str_starts_with($inside, 'h-script/')) throw new RuntimeException('Release archive must contain one h-script directory');
				$path = substr($inside, 9);
				if ($path === '') continue;
				$folded = strtolower($path);
				if (isset($entries[$folded])) throw new RuntimeException('Release archive contains a duplicated path: ' . $path);
				$directory = $item->isDir();
				if ($item->isLink() || (!$directory && !$item->isFile())) throw new RuntimeException('Release archive links and special files are forbidden: ' . $path);
				if (!$directory && (($item->getPerms() & 0111) !== 0)) throw new RuntimeException('Release archive contains an unexpected executable file: ' . $path);
				$entrySize = $directory ? 0 : $item->getSize();
				$total += $entrySize;
				if (count($entries) + 1 > $this->settings->maximumFiles() || $total > $this->settings->maximumUnpackedBytes())
					throw new RuntimeException('Release archive exceeds configured extraction limits');
				$entries[$folded] = array('path' => $path, 'directory' => $directory, 'size' => $entrySize, 'source' => $item->getPathname());
			}
		}
		catch (RuntimeException $exception) { throw $exception; }
		catch (Throwable $exception) { throw new RuntimeException('Release archive entries could not be read', 0, $exception); }
		$free = disk_free_space($this->settings->stagingDirectory());
		if ($free === false || $free < $total + $this->settings->minimumFreeBytes()) throw new RuntimeException('Not enough free space to stage release archive');
		if (!mkdir($staging, 0700) && !is_dir($staging)) throw new RuntimeException('Unique release staging directory could not be created');
		foreach ($entries as $entry)
		{
			$target = $staging . '/' . $entry['path'];
			if ($entry['directory'])
			{
				if (!is_dir($target) && !mkdir($target, 0700, true) && !is_dir($target)) throw new RuntimeException('Release directory could not be extracted');
				continue;
			}
			$this->extractEntry($entry['source'], $target, $entry['size']);
		}
	}

	/** Rejects unsafe tar metadata before PharData has a chance to normalize it. */
	private function inspectTarHeaders(string $archive): void
	{
		$stream = gzopen($archive, 'rb');
		if ($stream === false) throw new RuntimeException('Release gzip stream could not be opened');
		$seen = array();
		$count = 0;
		$total = 0;
		$pendingPath = null;
		$ended = false;
		try
		{
			while (!gzeof($stream))
			{
				$header = $this->gzReadExact($stream, 512, true);
				if ($header === '') break;
				if ($header === str_repeat("\0", 512)) { $ended = true; break; }
				$this->validateTarChecksum($header);
				$name = rtrim(substr($header, 0, 100), "\0");
				$prefix = rtrim(substr($header, 345, 155), "\0");
				if ($prefix !== '') $name = $prefix . '/' . $name;
				$mode = $this->tarOctal(substr($header, 100, 8), 'mode');
				$entrySize = $this->tarOctal(substr($header, 124, 12), 'size');
				$type = substr($header, 156, 1);
				$headerPath = in_array($type, array('x', 'g'), true) && str_starts_with($name, './') ? substr($name, 2) : $name;
				$this->safeArchivePath($headerPath);

				if ($type === 'x' || $type === 'g')
				{
					if ($pendingPath !== null) throw new RuntimeException('Release PAX path metadata is ambiguous');
					if ($entrySize > 1048576) throw new RuntimeException('Release PAX metadata is too large');
					$contents = $this->gzReadExact($stream, $entrySize);
					$this->skipGzip($stream, (512 - $entrySize % 512) % 512);
					$pax = $this->parsePax($contents);
					if (isset($pax['linkpath'])) throw new RuntimeException('Release archive links are forbidden');
					if (isset($pax['path']))
					{
						if ($type === 'g') throw new RuntimeException('Global PAX path is forbidden');
						$pendingPath = $this->safeArchivePath($pax['path']);
					}
					continue;
				}

				if (!in_array($type, array("\0", '0', '5'), true))
					throw new RuntimeException('Release archive links and special entries are forbidden');
				$path = $pendingPath ?? $this->safeArchivePath($name);
				$pendingPath = null;
				if ($path !== 'h-script' && !str_starts_with($path, 'h-script/'))
					throw new RuntimeException('Release archive must contain one h-script directory');
				$relative = $path === 'h-script' ? '' : substr($path, 9);
				if ($relative !== '')
				{
					$folded = strtolower($relative);
					if (isset($seen[$folded])) throw new RuntimeException('Release archive contains a duplicated path: ' . $relative);
					$seen[$folded] = true;
					$count++;
					if ($count > $this->settings->maximumFiles()) throw new RuntimeException('Release archive contains too many entries');
				}
				if ($type !== '5')
				{
					if (($mode & 0111) !== 0) throw new RuntimeException('Release archive contains an unexpected executable file: ' . $relative);
					$total += $entrySize;
					if ($total > $this->settings->maximumUnpackedBytes()) throw new RuntimeException('Release archive exceeds UPDATE_MAX_UNPACKED_BYTES');
				}
				$this->skipGzip($stream, $entrySize + (512 - $entrySize % 512) % 512);
			}
		}
		finally { gzclose($stream); }
		if (!$ended || !$seen || $pendingPath !== null) throw new RuntimeException('Release tar archive is incomplete or empty');
	}

	/** @param resource $stream */
	private function gzReadExact($stream, int $length, bool $allowEnd = false): string
	{
		$result = '';
		while (strlen($result) < $length && !gzeof($stream))
		{
			$chunk = gzread($stream, $length - strlen($result));
			if ($chunk === false) throw new RuntimeException('Release gzip stream could not be read');
			if ($chunk === '') break;
			$result .= $chunk;
		}
		if (strlen($result) !== $length && !($allowEnd && $result === ''))
			throw new RuntimeException('Release tar archive is truncated');
		return $result;
	}

	/** @param resource $stream */
	private function skipGzip($stream, int $length): void
	{
		while ($length > 0)
		{
			$size = min(65536, $length);
			$this->gzReadExact($stream, $size);
			$length -= $size;
		}
	}

	private function tarOctal(string $value, string $field): int
	{
		$value = trim($value, " \0");
		if ($value === '' || !preg_match('/^[0-7]+$/', $value)) throw new RuntimeException('Release tar ' . $field . ' is invalid');
		$result = octdec($value);
		if (!is_int($result) || $result < 0) throw new RuntimeException('Release tar ' . $field . ' is invalid');
		return $result;
	}

	private function validateTarChecksum(string $header): void
	{
		$expected = $this->tarOctal(substr($header, 148, 8), 'checksum');
		$checkable = substr_replace($header, '        ', 148, 8);
		$actual = array_sum(unpack('C*', $checkable));
		if ($actual !== $expected) throw new RuntimeException('Release tar header checksum is invalid');
	}

	/** @return array<string,string> */
	private function parsePax(string $contents): array
	{
		$result = array();
		$offset = 0;
		$length = strlen($contents);
		while ($offset < $length)
		{
			$space = strpos($contents, ' ', $offset);
			if ($space === false || $space === $offset) throw new RuntimeException('Release PAX record is invalid');
			$recordLengthText = substr($contents, $offset, $space - $offset);
			if (!ctype_digit($recordLengthText)) throw new RuntimeException('Release PAX record length is invalid');
			$recordLength = (int)$recordLengthText;
			$record = substr($contents, $offset, $recordLength);
			if ($recordLength < 4 || strlen($record) !== $recordLength || !str_ends_with($record, "\n")) throw new RuntimeException('Release PAX record is truncated');
			$pair = substr($record, ($space - $offset) + 1, -1);
			$equals = strpos($pair, '=');
			if ($equals === false) throw new RuntimeException('Release PAX field is invalid');
			$key = substr($pair, 0, $equals);
			$value = substr($pair, $equals + 1);
			if (in_array($key, array('path', 'linkpath'), true)) $result[$key] = $value;
			$offset += $recordLength;
		}
		return $result;
	}

	private function extractEntry(string $source, string $target, int $expectedSize): void
	{
		$directory = dirname($target);
		if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Release staging directory could not be created');
		$input = fopen($source, 'rb');
		$output = fopen($target, 'xb');
		if ($input === false || $output === false)
		{
			if (is_resource($input)) fclose($input);
			if (is_resource($output)) fclose($output);
			throw new RuntimeException('Release archive entry could not be opened');
		}
		try
		{
			$written = stream_copy_to_stream($input, $output, $expectedSize + 1);
			if ($written !== $expectedSize || !fflush($output)) throw new RuntimeException('Release archive entry size changed while extracting');
		}
		finally { fclose($input); fclose($output); }
		chmod($target, 0644);
	}

	private function copyBounded(string $source, string $target): void
	{
		$size = filesize($source);
		if (!is_int($size) || $size < 1 || $size > $this->settings->maximumPackageBytes()) throw new RuntimeException('Release archive exceeds UPDATE_MAX_PACKAGE_BYTES');
		$input = fopen($source, 'rb');
		$output = fopen($target, 'xb');
		if ($input === false || $output === false)
		{
			if (is_resource($input)) fclose($input);
			if (is_resource($output)) fclose($output);
			throw new RuntimeException('Release archive could not be copied');
		}
		try
		{
			$written = stream_copy_to_stream($input, $output, $this->settings->maximumPackageBytes() + 1);
			if ($written !== $size || !fflush($output)) throw new RuntimeException('Release archive copy was interrupted');
		}
		finally { fclose($input); fclose($output); }
	}

	private function readVersion(string $path, string $name): string
	{
		if (!is_file($path) || is_link($path) || !is_readable($path)) throw new RuntimeException(ucfirst($name) . ' version file is missing');
		return SchemaVersion::requireValid(trim((string)file_get_contents($path)), $name . ' version');
	}

	private function safeArchivePath(string $path): string
	{
		$path = rtrim($path, '/');
		$segments = explode('/', $path);
		if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\') || str_contains($path, "\0")
			|| in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)
			|| !preg_match('/^[A-Za-z0-9._\/-]+$/', $path))
			throw new RuntimeException('Release archive contains an unsafe path');
		return $path;
	}

	private function removeTree(string $path, string $allowedRoot): void
	{
		if ($path === '' || !str_starts_with($path, rtrim($allowedRoot, '/') . '/')) return;
		if (is_link($path) || is_file($path)) { unlink($path); return; }
		if (!is_dir($path)) return;
		foreach ((array)scandir($path) as $item)
			if ($item !== '.' && $item !== '..') $this->removeTree($path . '/' . $item, $allowedRoot);
		rmdir($path);
	}
}
