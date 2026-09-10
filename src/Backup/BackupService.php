<?php

namespace HScript\Backup;

use HScript\Application;
use HScript\Database\Connection;
use HScript\Update\SchemaStateRepository;
use HScript\Update\UpdateLock;
use HScript\Update\UpdateRunRepository;
use HScript\Update\UpdateRunState;
use RuntimeException;
use Throwable;

final class BackupService
{
	private Connection $database;
	private DatabaseCredentials $credentials;
	private BackupSettings $settings;
	private BackupRepository $repository;
	private BackupVerifier $verifier;
	/** @var DatabaseBackupAdapter[] */
	private array $adapters;

	public function __construct(
		Connection $database,
		DatabaseCredentials $credentials,
		BackupSettings $settings,
		?array $adapters = null
	) {
		$this->database = $database;
		$this->credentials = $credentials;
		$this->settings = $settings;
		$this->repository = new BackupRepository($settings);
		$this->verifier = new BackupVerifier();
		$this->adapters = $adapters ?? array(
			new MysqldumpBackupAdapter(),
			new PhpStreamingBackupAdapter(),
		);
	}

	public static function fromConfig(Connection $database, array $config, string $domain, string $projectRoot): self
	{
		return new self(
			$database,
			DatabaseCredentials::fromConfig($config, $domain),
			BackupSettings::fromEnvironment($projectRoot)
		);
	}

	public function create(string $compression = 'plain'): array
	{
		if (!in_array($compression, array('gzip', 'plain'), true))
			throw new RuntimeException('Backup compression must be gzip or plain');
		$state = new SchemaStateRepository($this->database);
		$schemaVersion = $state->currentVersion();
		if ($schemaVersion === null)
			throw new RuntimeException('Explicit schema version must be initialized before backup');

		$lock = new UpdateLock($this->database);
		$lock->acquire();
		try
		{
			$inspector = new DatabaseInspector($this->database);
			$unsupported = $inspector->unsupportedObjects();
			if ($unsupported)
			{
				$summary = implode(', ', array_map(
					static fn(string $type, int $count): string => $type . '=' . $count,
					array_keys($unsupported),
					array_values($unsupported)
				));
				throw new RuntimeException('Verified SQL backup supports base tables only; unsupported database objects found: ' . $summary);
			}
			$tables = $inspector->tables();
			$estimatedBytes = $inspector->estimatedBytes();
			$this->assertCapacity($estimatedBytes);
			$id = bin2hex(random_bytes(16));
			$createdAt = gmdate('Y-m-d\TH:i:s\Z');
			$extension = $compression === 'gzip' ? '.sql.gz' : '.sql';
			$archive = 'h-script-' . gmdate('Ymd-His') . '-' . $id . $extension;
			$temporary = $this->settings->directory() . '/.' . $id . '.part';
			$footer = '-- H-Script backup complete:' . $id;
			$lastError = null;

			foreach ($this->adapters as $adapter)
			{
				if (!$adapter instanceof DatabaseBackupAdapter || !$adapter->available())
					continue;
				$writer = null;
				try
				{
					$writer = new BackupStreamWriter($temporary, $compression, $this->settings->maximumBytes());
					$adapter->dump(
						$this->credentials,
						$tables,
						static fn(string $chunk): mixed => $writer->write($chunk),
						$footer
					);
					$sizes = $writer->close();
					$verification = $this->verifier->verify(
						$temporary,
						$compression,
						$sizes['sha256'],
						$sizes['uncompressed_size'],
						$tables,
						$id
					);
					$manifest = BackupManifest::fromArray(array(
						'format' => 1,
						'id' => $id,
						'created_at' => $createdAt,
						'verified_at' => gmdate('Y-m-d\TH:i:s\Z'),
						'database_sha256' => $this->credentials->databaseHash(),
						'application_version' => Application::version(),
						'schema_version' => $schemaVersion,
						'archive' => $archive,
						'compression' => $compression,
						'adapter' => $adapter->name(),
						'location' => $this->settings->location(),
						'uncompressed_size' => $sizes['uncompressed_size'],
						'stored_size' => $sizes['stored_size'],
						'sha256' => $sizes['sha256'],
						'table_count' => count($tables),
						'tables' => $tables,
						'status' => 'verified',
						'verification' => $verification,
					));
					$this->repository->publish($manifest, $temporary);
					$this->repository->prune($this->hasActiveUpdate(), $id);
					return $manifest->toArray();
				}
				catch (Throwable $exception)
				{
					$lastError = $exception;
					unset($writer);
					if (is_file($temporary))
						unlink($temporary);
					if ($adapter->name() !== 'mysqldump')
						throw $exception;
					error_log('mysqldump backup adapter failed; using PHP stream fallback: ' . $exception->getMessage());
				}
			}
			throw new RuntimeException('No database backup adapter completed successfully', 0, $lastError);
		}
		finally
		{
			$lock->release();
		}
	}

	public function list(int $limit = 50): array
	{
		return $this->repository->list($limit);
	}

	public function storageLocation(): string
	{
		return $this->settings->location();
	}

	public function verify(string $id): array
	{
		$manifest = $this->repository->find($id);
		$data = $manifest->toArray();
		$this->verifier->verify(
			$this->repository->archivePath($manifest),
			$manifest->compression(),
			$manifest->checksum(),
			$data['uncompressed_size'],
			$manifest->tables(),
			$id
		);
		return $data;
	}

	public function archive(string $id): array
	{
		$manifest = $this->repository->find($id);
		$this->verify($id);
		return array('manifest' => $manifest->toArray(), 'path' => $this->repository->archivePath($manifest));
	}

	public function verifyForUpdate(string $id, string $expectedSchemaVersion): array
	{
		$manifest = $this->repository->find($id);
		if (!hash_equals($this->credentials->databaseHash(), $manifest->databaseHash()))
			throw new RuntimeException('Verified backup belongs to another database');
		if ($manifest->schemaVersion() !== $expectedSchemaVersion)
			throw new RuntimeException('Verified backup does not match the source schema version');
		return $this->verify($id);
	}

	public function delete(string $id): void
	{
		if ($this->hasActiveUpdate())
			throw new RuntimeException('Backups cannot be deleted while an update run is unfinished');
		$this->repository->delete($id);
	}

	private function assertCapacity(int $estimatedBytes): void
	{
		if ($estimatedBytes > $this->settings->maximumBytes())
			throw new RuntimeException('Estimated database size exceeds BACKUP_MAX_BYTES');
		$free = disk_free_space($this->settings->directory());
		if ($free === false)
			throw new RuntimeException('Free backup storage space could not be determined');
		$minimumFree = $this->settings->minimumFreeBytes();
		if ($free < $minimumFree || $estimatedBytes > $free - $minimumFree)
			throw new RuntimeException('Not enough free space for a verified database backup');
	}

	private function hasActiveUpdate(): bool
	{
		try
		{
			$latest = (new UpdateRunRepository($this->database))->latest();
			return $latest !== null && !UpdateRunState::terminal((string)$latest['urState']);
		}
		catch (Throwable)
		{
			return true;
		}
	}
}
