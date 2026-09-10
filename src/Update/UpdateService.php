<?php

namespace HScript\Update;

use HScript\Application;
use HScript\Backup\BackupService;
use HScript\Database\Connection;
use RuntimeException;
use Throwable;

/** Common guided update flow used by both CLI and the Configurator. */
final class UpdateService
{
	private Connection $database;
	private array $config;
	private string $domain;
	private UpdateSettings $settings;
	private UpdatePackageService $packages;
	private UpdateRunRepository $runs;
	private MaintenanceMode $maintenance;
	private ReleaseActivator $activator;
	private string $deploymentMode;

	public function __construct(
		Connection $database,
		array $config,
		string $domain,
		string $projectRoot,
		?UpdateSettings $settings = null,
		?OfficialReleaseProvider $releases = null
	) {
		$this->database = $database;
		$this->config = $config;
		$this->domain = $domain;
		$this->settings = $settings ?? UpdateSettings::fromEnvironment($projectRoot);
		$this->packages = new UpdatePackageService($database, $this->settings, $releases);
		$this->runs = new UpdateRunRepository($database);
		$this->maintenance = new MaintenanceMode($this->settings->projectRoot());
		$this->deploymentMode = trim((string)(getenv('APP_RELEASE_VERSION') ?: '')) !== '' ? 'docker' : 'shared-hosting';
		$this->activator = new JournaledReleaseActivator($this->settings);
	}

	public function prepareManual(string $path): array
	{
		$this->requireSharedHosting();
		return $this->packages->prepareManual($path);
	}

	public function prepareLatest(): array
	{
		$this->requireSharedHosting();
		return $this->packages->prepareLatest();
	}

	public function applyBundled(): array
	{
		if ($this->deploymentMode !== 'docker')
			throw new RuntimeException('Bundled database update is available only for a Docker image installation');
		$prepared = $this->packages->prepareBundled();
		return $this->apply($prepared['id'], array());
	}

	public function deploymentMode(): string { return $this->deploymentMode; }

	public function prepared(string $id): array
	{
		return $this->packages->prepared($id);
	}

	public function preparedForRun(string $runId): array
	{
		return $this->packages->preparedForRun($runId);
	}

	public function resume(string $runId, array $fileChoices): array
	{
		$record = $this->packages->preparedForRun($runId);
		return $this->apply($record['id'], $fileChoices);
	}

	public function apply(string $preparedId, array $fileChoices): array
	{
		$record = $this->packages->prepared($preparedId);
		if ($this->deploymentMode === 'docker' && $record['source'] !== 'bundled')
			throw new RuntimeException('Docker code is updated only by changing the official image tag');
		if ($this->deploymentMode !== 'docker' && $record['source'] === 'bundled')
			throw new RuntimeException('Bundled Docker database update cannot run on shared hosting');
		$manifest = $this->packages->revalidate($record);
		if ($record['source'] !== 'bundled' && $record['run_id'] === '')
			$this->activator->preflight($manifest, $record['activation_plan']);
		if ($record['run_id'] !== '')
		{
			if ($fileChoices && $fileChoices !== $record['file_choices'])
				throw new RuntimeException('File choices cannot be changed after the update run starts');
			$fileChoices = $record['file_choices'];
		}
		elseif (!$fileChoices && $record['file_choices'])
			$fileChoices = $record['file_choices'];
		$fileChoices = $this->validateFileChoices($record['conflicts'], $fileChoices);
		if ($record['file_choices'] !== $fileChoices)
			$record = $this->packages->saveFileChoices($preparedId, $fileChoices);
		$run = $record['run_id'] !== '' ? $this->requiredRun($record['run_id']) : null;
		if ($run === null)
		{
			$schemaVersion = (new SchemaStateRepository($this->database))->currentVersion();
			if ($schemaVersion === null)
				throw new RuntimeException('Explicit schema version must be initialized before update');
			$sourceVersion = $record['source'] === 'bundled'
				? $this->requiredInstalledApplicationVersion()
				: SchemaVersion::requireValid(trim((string)file_get_contents($this->settings->projectRoot() . '/VERSION')), 'source application version');
			$run = $this->runs->create($manifest, $sourceVersion, $schemaVersion);
			$record = $this->packages->attachRun($preparedId, $run['urID']);
		}

		$runId = (string)$run['urID'];
		try
		{
			$run = $this->completeBackupStep($run, $record, $manifest);
			$record = $this->packages->prepared($preparedId);
			$lock = new UpdateLock($this->database);
			$lock->acquire();
			try
			{
				$run = $this->completeLiveSteps($run, $record, $manifest, $fileChoices);
			}
			finally
			{
				$lock->release();
			}
			$preparedResult = $this->packages->prepared($preparedId);
			if ((string)$run['urState'] === UpdateRunState::COMPLETED)
			{
				try
				{
					(new UpdateRetention($this->database, $this->settings))->prune($preparedId);
					$this->activator->prunePrevious($this->settings->retentionCount());
				}
				catch (Throwable $retentionError) { error_log('Update retention failed: ' . $retentionError->getMessage()); }
			}
			return array(
				'run' => $run,
				'prepared' => $preparedResult,
				'maintenance' => $this->maintenance->status(),
			);
		}
		catch (Throwable $exception)
		{
			$this->recordInterruption($runId, $manifest, $exception);
			throw $exception;
		}
	}

	public function rollbackCode(string $runId): array
	{
		$run = $this->requiredRun($runId);
		$record = $this->packages->preparedForRun($runId);
		if ($record['source'] === 'bundled')
			throw new RuntimeException('Docker code rollback is performed by restoring the previous image tag');
		if (UpdateRunState::terminal((string)$run['urState']))
			throw new RuntimeException('Terminal update run cannot be rolled back');
		$currentSchema = (new SchemaStateRepository($this->database))->currentVersion();
		if ($currentSchema !== (string)$run['urSourceSchemaVersion'])
			throw new RuntimeException('Database schema changed; restore the verified SQL backup instead of code-only rollback');
		$lock = new UpdateLock($this->database);
		$lock->acquire();
		try
		{
			$journal = $this->activator->rollback($runId);
			(new SchemaStateRepository($this->database))->setInstalledApplicationVersion((string)$run['urSourceVersion']);
			$this->maintenance->disable($runId);
			$run = $this->runs->fail($runId, 'code_rolled_back', 'Previous code restored; database was not changed');
			return array('run' => $run, 'journal' => $journal);
		}
		finally
		{
			$lock->release();
		}
	}

	public function cancel(string $runId): array
	{
		$lock = new UpdateLock($this->database);
		$lock->acquire();
		try
		{
			$run = $this->requiredRun($runId);
			$record = $this->packages->preparedForRun($runId);
			if (UpdateRunState::terminal((string)$run['urState']))
				throw new RuntimeException('Terminal update run cannot be cancelled');
			$state = new SchemaStateRepository($this->database);
			$bundledImageRolledBack = $record['source'] === 'bundled'
				&& (string)$run['urState'] === UpdateRunState::HEALTH
				&& Application::version() === (string)$run['urSourceVersion']
				&& $state->installedApplicationVersion() === (string)$run['urSourceVersion']
				&& $state->currentVersion() === (string)$run['urSourceSchemaVersion'];
			if (!in_array((string)$run['urState'], array(UpdateRunState::PREFLIGHT, UpdateRunState::BACKUP, UpdateRunState::PACKAGE), true)
				&& !$bundledImageRolledBack)
				throw new RuntimeException('Update can be cancelled only before code activation or database migration');
			if ($record['source'] !== 'bundled' && $this->activator->status($runId) !== null)
				throw new RuntimeException('Code activation started; use the safe recovery action shown for this run');
			$status = $this->maintenance->status();
			if ($status !== null && $status['run_id'] === $runId) $this->maintenance->disable($runId);
			return $bundledImageRolledBack
				? $this->runs->fail($runId, 'docker_image_rolled_back', 'Previous Docker image restored; database remained at the source schema')
				: $this->runs->fail($runId, 'update_cancelled', 'Update cancelled before live code or database changes');
		}
		finally { $lock->release(); }
	}

	private function completeBackupStep(array $run, array $record, ReleaseManifest $manifest): array
	{
		$state = (string)$run['urState'];
		$requiresBackup = UpdateClassification::requiresBackup($manifest->classification());
		if ($state === UpdateRunState::PREFLIGHT)
		{
			$run = $this->runs->transition(
				(string)$run['urID'],
				$requiresBackup ? UpdateRunState::BACKUP : UpdateRunState::PACKAGE,
				$requiresBackup ? 'backup_required' : 'code_only',
				$requiresBackup ? 'Creating a verified SQL backup' : 'Database backup explicitly skipped for a code-only release'
			);
			$state = (string)$run['urState'];
		}
		if ($state !== UpdateRunState::BACKUP)
			return $run;

		$backup = BackupService::fromConfig(
			$this->database,
			$this->config,
			$this->domain,
			$this->settings->projectRoot()
		);
		$backupId = (string)$record['backup_id'];
		if ($backupId === '')
		{
			$created = $backup->create('plain');
			$backupId = $created['id'];
			$this->packages->attachRun($record['id'], (string)$run['urID'], $backupId);
		}
		$backup->verifyForUpdate($backupId, (string)$run['urSourceSchemaVersion']);
		return $this->runs->transition(
			(string)$run['urID'],
			UpdateRunState::PACKAGE,
			'backup_verified',
			'Verified SQL backup ' . $backupId . ' is attached to this update'
		);
	}

	private function completeLiveSteps(
		array $run,
		array $record,
		ReleaseManifest $manifest,
		array $fileChoices
	): array {
		$runId = (string)$run['urID'];
		$state = (string)$run['urState'];
		if ($state === UpdateRunState::PACKAGE)
		{
			$this->packages->revalidate($record);
			if (UpdateClassification::requiresBackup($manifest->classification()))
			{
				$backupId = (string)$record['backup_id'];
				if ($backupId === '')
					throw new RuntimeException('Verified update backup reference is missing');
				BackupService::fromConfig(
					$this->database,
					$this->config,
					$this->domain,
					$this->settings->projectRoot()
				)->verifyForUpdate($backupId, (string)$run['urSourceSchemaVersion']);
			}
			$this->maintenance->enable($runId, $manifest->applicationVersion());
			if ($record['source'] === 'bundled')
			{
				$message = 'Using database migrations bundled in the active Docker image';
			}
			else
			{
				$this->activator->activate($runId, $record['id'], $manifest, $record['staging'], $fileChoices, $record['activation_plan']);
				$message = 'Release files activated with journaled atomic file replacement';
			}
			$currentSchema = (new SchemaStateRepository($this->database))->currentVersion();
			$next = $currentSchema !== $manifest->schemaVersion() ? UpdateRunState::MIGRATION : UpdateRunState::HEALTH;
			$run = $this->runs->transition($runId, $next, 'code_activated', $message);
			$state = $next;
		}

		if ($state === UpdateRunState::MIGRATION)
		{
			$this->ensureMaintenance($runId, $manifest);
			$runner = new MigrationRunner(
				$this->database,
				new SchemaStateRepository($this->database),
				new MigrationLoader(($record['source'] === 'bundled' ? $this->settings->projectRoot() : $this->activator->activeRoot()) . '/migrations/versioned')
			);
			$runner->runLocked(
				$manifest->schemaVersion(),
				true,
				$manifest->applicationVersion(),
				fn(string $id): array => $this->runs->note($runId, 'current_migration', 'Applying migration ' . $id)
			);
			$run = $this->runs->transition($runId, UpdateRunState::HEALTH, 'migrations_applied', 'Release database migrations completed');
			$state = UpdateRunState::HEALTH;
		}

		if ($state === UpdateRunState::HEALTH)
		{
			$this->ensureMaintenance($runId, $manifest);
			(new UpdateHealthChecker(
				$this->database,
				$record['source'] === 'bundled' ? $this->settings->projectRoot() : $this->activator->activeRoot(),
				$this->config,
				$this->domain
			))->check($manifest);
			if ($record['source'] !== 'bundled')
			{
				$files = array();
				foreach ($this->packages->verifiedReleaseBaseline($record, $manifest) as $path => $entry)
					$files[] = array('path' => $path, 'sha256' => $entry['sha256'], 'size' => $entry['size'], 'class' => $entry['class']);
				(new ReleaseBaselineRepository($this->settings))->publish($manifest->applicationVersion(), $files);
			}
			(new SchemaStateRepository($this->database))->setInstalledApplicationVersion($manifest->applicationVersion());
			if ($record['source'] === 'bundled')
				(new SchemaUpdateGate($this->database, $this->settings->projectRoot()))->refresh();
			$this->maintenance->disable($runId);
			$run = $this->runs->transition($runId, UpdateRunState::COMPLETED, 'update_completed', 'CMS and database update completed');
		}
		return $run;
	}

	private function validateFileChoices(array $conflicts, array $choices): array
	{
		$result = array();
		$known = array();
		foreach ($conflicts as $conflict)
		{
			$path = (string)($conflict['path'] ?? '');
			$known[$path] = true;
			$choice = $choices[$path] ?? null;
			if (!in_array($choice, array('local', 'release'), true))
				throw new RuntimeException('Choose local or release version for file conflict: ' . $path);
			$result[$path] = $choice;
		}
		foreach ($choices as $path => $choice)
			if (!isset($known[$path]))
				throw new RuntimeException('Unknown file conflict choice: ' . $path);
		return $result;
	}

	private function ensureMaintenance(string $runId, ReleaseManifest $manifest): void
	{
		$status = $this->maintenance->status();
		if ($status === null)
			$this->maintenance->enable($runId, $manifest->applicationVersion());
		elseif ($status['run_id'] !== $runId)
			throw new RuntimeException('Maintenance mode belongs to another update run');
	}

	private function recordInterruption(string $runId, ReleaseManifest $manifest, Throwable $exception): void
	{
		try
		{
			$run = $this->runs->get($runId);
			if (!$run || UpdateRunState::terminal((string)$run['urState']))
				return;
			$state = (string)$run['urState'];
			$journal = $manifest->source() === 'bundled' ? null : $this->activator->status($runId);
			if ($manifest->source() === 'bundled' && $state === UpdateRunState::MIGRATION)
			{
				$code = $manifest->classification() === UpdateClassification::IRREVERSIBLE ? 'restore_backup' : 'retry_migration';
				$summary = $code === 'restore_backup'
					? 'Safe next action: restore the attached SQL backup using the documented CLI procedure'
					: 'Safe next action: retry the idempotent bundled migration';
			}
			elseif ($manifest->source() === 'bundled' && $state === UpdateRunState::HEALTH)
			{
				$code = 'retry_health';
				$summary = 'Safe next action: retry the bounded health check';
			}
			elseif ($journal === null)
			{
				$code = 'retry_update';
				$summary = 'Safe next action: retry; live release was not activated';
			}
			elseif ($state === UpdateRunState::MIGRATION && $manifest->classification() === UpdateClassification::IRREVERSIBLE)
			{
				$code = 'restore_backup';
				$summary = 'Safe next action: restore the attached SQL backup using the documented CLI procedure';
			}
			elseif ($state === UpdateRunState::MIGRATION)
			{
				$code = 'retry_migration';
				$summary = 'Safe next action: retry the idempotent current migration';
			}
			elseif ($state === UpdateRunState::HEALTH)
			{
				$code = 'retry_health';
				$summary = 'Safe next action: retry the bounded health check';
			}
			else
			{
				$code = 'rollback_code';
				$summary = 'Safe next action: restore the previous code set';
			}
			error_log('Update run ' . $runId . ' interrupted: ' . $exception->getMessage());
			$this->runs->note($runId, $code, $summary);
		}
		catch (Throwable $noteException)
		{
			error_log('Update interruption state could not be recorded: ' . $noteException->getMessage());
		}
	}

	private function requiredRun(string $id): array
	{
		$run = $this->runs->get($id);
		if (!$run)
			throw new RuntimeException('Update run was not found');
		return $run;
	}

	private function requireSharedHosting(): void
	{
		if ($this->deploymentMode === 'docker')
			throw new RuntimeException('Docker code is updated outside the Configurator by changing the official image tag');
	}

	private function requiredInstalledApplicationVersion(): string
	{
		$version = (new SchemaStateRepository($this->database))->installedApplicationVersion();
		if ($version === null)
			throw new RuntimeException('Installed application version is missing; initialize it before replacing a Docker image');
		return $version;
	}

}
