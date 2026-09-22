<?php

namespace HScript\Update;

use HScript\Backup\BackupService;
use HScript\Recovery\RecoveryReportRepository;
use HScript\Recovery\RecoverySettings;
use HScript\Recovery\RecoveryBundleRepository;
use RuntimeException;

/** Uses the existing local backup/drill records, not another backup flow. */
final class RecoveryPreflight
{
	public static function assertReady(RecoverySettings $settings, BackupService $backups, string $schema, array $targets): void
	{
		$reports = new RecoveryReportRepository($settings);
		$backup = $reports->latest('backup');
		$drill = $reports->latest('drill');
		$rpo = min(array_map(static fn(string $target): int => $settings->policySeconds($target, 'rpo'), $targets));
		$rto = min(array_map(static fn(string $target): int => $settings->policySeconds($target, 'rto'), $targets));
		self::assertReport($backup, 'backup', $rpo, array('database_backup', 'runtime_archive', 'bundle_manifest', 'local_retention'));
		self::assertReport($drill, 'drill', $settings->drillMaximumAgeSeconds(), array('local_bundle', 'bundle_checksums', 'runtime_checksums', 'versions', 'financial_invariants', 'queue', 'installation_telemetry', 'service_token_metadata', 'isolated_cleanup'));
		if (!is_int($drill['duration_ms'] ?? null) || $drill['duration_ms'] < 0 || $drill['duration_ms'] > $rto * 1000)
			throw new RuntimeException('Restore drill exceeds the recovery time policy');
		// A successful report from another database/schema is not evidence for this update.
		$verified = $backups->verifyForUpdate($backup['bundle_id'], $schema);
		self::assertAge($verified['created_at'] ?? null, $rpo, null, 'backup_archive');
		$backups->verifyForUpdate($drill['bundle_id'], $schema);
		(new RecoveryBundleRepository($settings))->find($backup['bundle_id'])->verifyFiles($settings->backupDirectory());
	}

	public static function assertReport(?array $report, string $type, int $maximumAge, array $components, ?int $now = null): void
	{
		if (($report['format'] ?? null) !== 1 || ($report['type'] ?? null) !== $type || ($report['status'] ?? null) !== 'success'
			|| !is_string($report['bundle_id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $report['bundle_id'])
			|| !is_array($report['components'] ?? null) || !array_is_list($report['components'])
			|| count(array_filter($report['components'], 'is_string')) !== count($report['components'])
			|| array_diff($components, $report['components']))
			throw new RuntimeException('Verified recovery evidence is missing or incomplete: ' . $type);
		self::assertAge($report['completed_at'] ?? null, $maximumAge, $now, $type);
	}

	public static function assertAge(mixed $timestamp, int $maximumAge, ?int $now = null, string $evidence = 'update_backup'): void
	{
		$now ??= time();
		$parsed = is_string($timestamp) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $timestamp) ? strtotime($timestamp) : false;
		if (!in_array($evidence, array('backup', 'drill', 'backup_archive', 'update_backup'), true)) throw new RuntimeException('Unknown recovery evidence type');
		if ($maximumAge < 1 || $parsed === false || gmdate('Y-m-d\TH:i:s\Z', $parsed) !== $timestamp)
			throw new RuntimeException('Recovery evidence invalid: ' . $evidence);
		if ($parsed > $now)
			throw new RuntimeException('Recovery evidence future: ' . $evidence);
		if ($now - $parsed > $maximumAge)
			throw new RuntimeException('Recovery evidence expired: ' . $evidence . '; timestamp=' . $timestamp . '; max_age=' . $maximumAge);
	}
}
