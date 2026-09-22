<?php

namespace HScript\Recovery;

use HScript\Backup\BackupManifest;
use HScript\Backup\BackupService;
use HScript\Backup\BackupSettings;
use HScript\Backup\DatabaseCredentials;
use HScript\Backup\DatabaseRestoreService;
use HScript\Database\Connection;
use RuntimeException;
use Throwable;

final class RecoveryService
{
	private RecoveryBundleRepository $bundles;
	private RuntimeArchiveService $runtime;
	private RecoveryReportRepository $reports;

	public function __construct(
		private Connection $database,
		private DatabaseCredentials $source,
		private BackupService $backups,
		private RecoverySettings $settings
	) {
		$this->bundles = new RecoveryBundleRepository($settings);
		$this->runtime = new RuntimeArchiveService($settings);
		$this->reports = new RecoveryReportRepository($settings);
	}

	public function bundleFromBackupResult(string $resultPath): array
	{
		$startedAt = time();
		$bundleId = '';
		$components = array('database_backup');
		try
		{
			$data = json_decode((string)file_get_contents($resultPath), true, 64, JSON_THROW_ON_ERROR);
			$bundleId = is_array($data) && is_string($data['id'] ?? null) ? $data['id'] : '';
			if (!preg_match('/^[a-f0-9]{32}$/', $bundleId))
				throw new RecoveryException('database_backup_result_invalid', 'Database backup result is invalid');
			$databaseData = $this->backups->verify($bundleId);
			$databaseManifest = BackupManifest::fromArray($databaseData);
			$databaseArchive = $this->backups->archive($bundleId)['path'];
			$databaseManifestPath = $this->settings->backupDirectory() . '/' . $bundleId . '.manifest.json';

			$runtime = $this->runtime->create($bundleId);
			$components[] = 'runtime_archive';
			$bundle = RecoveryBundleManifest::create(
				$databaseManifest,
				$databaseArchive,
				$databaseManifestPath,
				$runtime,
				$this->settings->backupDirectory()
			);
			$this->bundles->publish($bundle);
			$components[] = 'bundle_manifest';
			$bundle->verifyFiles($this->settings->backupDirectory());
			$available = array_column($this->backups->list(), 'id');
			$deleted = $this->bundles->pruneLocal($available, $bundleId);
			$components[] = 'local_retention';
			$report = $this->reports->record('backup', $bundleId, $startedAt, $components, 'success');
			return array(
				'bundle' => $bundle->toArray(),
				'local_bundles_deleted' => $deleted,
				'report' => $report,
			);
		}
		catch (Throwable $exception)
		{
			$code = $exception instanceof RecoveryException ? $exception->errorCode() : 'recovery_bundle_failed';
			$this->reports->record('backup', $bundleId, $startedAt, $components, 'failed', $code);
			throw $exception;
		}
	}

	public function drill(string $requestedId = 'latest'): array
	{
		$startedAt = time();
		$bundleId = '';
		$components = array();
		$restoreRoot = '';
		$isolated = IsolatedDatabase::fromEnvironment();
		$targetCreated = false;
		try
		{
			$bundleId = $requestedId === 'latest' ? $this->bundles->latestId() : $requestedId;
			if (!preg_match('/^[a-f0-9]{32}$/', $bundleId))
				throw new RecoveryException('bundle_id_invalid', 'Recovery bundle ID is invalid');
			$restoreRoot = $this->settings->stateDirectory() . '/.drill-' . bin2hex(random_bytes(8));
			if (!mkdir($restoreRoot, 0700))
				throw new RuntimeException('Restore drill directory could not be created');
			$this->bundles->stage($bundleId, $restoreRoot);
			$components[] = 'local_bundle';
			$bundleRoot = $restoreRoot;
			$bundle = $this->bundles->find($bundleId, $bundleRoot);
			$bundle->verifyFiles($bundleRoot);
			$components[] = 'bundle_checksums';
			$runtimeArchive = $bundleRoot . '/' . $bundle->file('runtime_archive')['path'];
			$runtimeManifest = $bundleRoot . '/' . $bundle->file('runtime_manifest')['path'];
			$this->runtime->verify($runtimeArchive, $runtimeManifest);
			$components[] = 'runtime_checksums';

			$target = $isolated->create();
			$targetCreated = true;
			$restoredBackups = new BackupService(
				$this->database,
				$this->source,
				new BackupSettings($bundleRoot, 'external'),
				array()
			);
			(new DatabaseRestoreService($restoredBackups, $this->source))->restore(
				$bundleId, $target, $target->database()
			);
			$components[] = 'database_restore';
			$restored = new Connection();
			if (!$restored->open($target->connectionHost(), $target->database(), $target->username(), $target->password()))
				throw new RecoveryException('drill_database_unavailable', 'Restored drill database is unavailable');
			try
			{
				$checks = (new RecoveryDrillVerifier($this->settings->rowBoundsFile()))->verify($restored, $bundle);
				$components = array_merge($components, $checks);
			}
			finally
			{
				$restored->close();
			}
			$isolated->drop();
			$targetCreated = false;
			$components[] = 'isolated_cleanup';
			$report = $this->reports->record('drill', $bundleId, $startedAt, $components, 'success');
			return array('bundle_id' => $bundleId, 'report' => $report);
		}
		catch (Throwable $exception)
		{
			$code = $exception instanceof RecoveryException ? $exception->errorCode() : 'restore_drill_failed';
			$this->reports->record('drill', $bundleId, $startedAt, $components, 'failed', $code);
			throw $exception;
		}
		finally
		{
			if ($targetCreated)
			{
				try { $isolated->drop(); }
				catch (Throwable $cleanupError) { error_log('Restore drill cleanup failed: ' . $cleanupError->getMessage()); }
			}
			if ($restoreRoot !== '') RuntimeArchiveService::removeTree($restoreRoot);
		}
	}

	public function drillIfDue(): array
	{
		$latest = $this->reports->latest('drill');
		$completed = is_array($latest) && ($latest['status'] ?? '') === 'success'
			? strtotime((string)($latest['completed_at'] ?? '')) : false;
		if ($completed !== false && time() - $completed < $this->settings->drillMaximumAgeSeconds())
			return array('status' => 'not_due', 'last_completed_at' => $latest['completed_at']);
		return $this->drill('latest');
	}

	public function policy(): array
	{
		return (new RecoveryPolicyMonitor($this->settings, $this->reports))->evaluate();
	}

	public function recordLocalBackupFailure(string $errorCode = 'local_backup_failed'): array
	{
		return $this->reports->record('backup', '', time(), array('database_backup'), 'failed', $errorCode);
	}

	public function status(): array
	{
		return array(
			'backup' => $this->reports->latest('backup'),
			'drill' => $this->reports->latest('drill'),
			'health' => is_file($this->settings->stateDirectory() . '/reports/recovery-health.json')
				? json_decode((string)file_get_contents($this->settings->stateDirectory() . '/reports/recovery-health.json'), true)
				: null,
		);
	}

}
