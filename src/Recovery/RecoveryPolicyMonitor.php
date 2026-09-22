<?php

namespace HScript\Recovery;

use HScript\Observability\MetricRegistry;
use HScript\Observability\CorrelationContext;
use HScript\Observability\StructuredLogger;
use Throwable;

final class RecoveryPolicyMonitor
{
	private CommandRunner $runner;

	public function __construct(
		private RecoverySettings $settings,
		private RecoveryReportRepository $reports,
		?CommandRunner $runner = null
	) {
		$this->runner = $runner ?? new CommandRunner();
	}

	public function evaluate(?int $now = null): array
	{
		$now ??= time();
		$backup = $this->reports->latest('backup');
		$drill = $this->reports->latest('drill');
		$violations = array();
		$targets = array();
		foreach (array('cms', 'collector', 'service_tokens') as $target)
		{
			$rpo = $this->settings->policySeconds($target, 'rpo');
			$rto = $this->settings->policySeconds($target, 'rto');
			$backupAge = $this->reportAge($backup, $now);
			$drillDuration = is_array($drill) && ($drill['status'] ?? '') === 'success' ? (int)ceil(((int)($drill['duration_ms'] ?? 0)) / 1000) : null;
			$targets[$target] = array(
				'rpo_seconds' => $rpo,
				'rto_seconds' => $rto,
				'backup_age_seconds' => $backupAge,
				'last_drill_duration_seconds' => $drillDuration,
			);
			if ($backupAge !== null)
				MetricRegistry::gauge('backup_age_seconds', (float)$backupAge, array('target' => $target));
			if ($backupAge === null || $backupAge > $rpo)
				$violations[] = $target . '_rpo_breached';
			if ($drillDuration === null || $drillDuration > $rto)
				$violations[] = $target . '_rto_breached';
		}
		$drillAge = $this->reportAge($drill, $now);
		if ($drillAge !== null)
			foreach (array('cms', 'collector', 'service_tokens') as $target)
				MetricRegistry::gauge('restore_drill_age_seconds', (float)$drillAge, array('target' => $target));
		if ($drillAge === null || $drillAge > $this->settings->drillMaximumAgeSeconds())
			$violations[] = 'restore_drill_stale';
		if (!is_array($backup) || ($backup['status'] ?? '') !== 'success')
			$violations[] = 'local_backup_failed';
		if (is_array($backup) && !in_array('local_retention', (array)($backup['components'] ?? array()), true))
			$violations[] = 'local_retention_unverified';

		$violations = array_values(array_unique($violations));
		$health = array(
			'format' => 1,
			'checked_at' => gmdate('Y-m-d\TH:i:s\Z', $now),
			'status' => $violations ? 'alert' : 'ok',
			'targets' => $targets,
			'drill_age_seconds' => $drillAge,
			'drill_max_age_seconds' => $this->settings->drillMaximumAgeSeconds(),
			'violations' => $violations,
		);
		$this->reports->recordHealth($health);
		if ($violations)
		{
			StructuredLogger::event('critical', 'recovery', 'recovery_policy_breached', 'failure', 0, '', array('state' => 'alert'));
			$this->notify($health);
		}
		return $health;
	}

	private function notify(array $health): void
	{
		$alert = array(
			'format' => 1,
			'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
			'severity' => 'critical',
			'event_code' => 'recovery_policy_breached',
			'owner' => 'operations',
			'runbook' => 'docs/observability.md#recovery-policy-breached',
			'correlation_id' => CorrelationContext::current(),
			'violations' => $health['violations'],
			'delivery' => $this->settings->alertCommand() === '' ? 'file_only' : 'pending',
		);
		$path = $this->reports->recordAlert($alert);
		if ($this->settings->alertCommand() === '') return;
		try
		{
			// Notification delivery must not block the next scheduled backup.
			$result = $this->runner->run(array($this->settings->alertCommand(), $path), null, array(), '', 5.0);
			if ($result->exitCode() !== 0)
				$this->notifierUnavailable();
		}
		catch (Throwable)
		{
			$this->notifierUnavailable();
		}
	}

	private function notifierUnavailable(): void
	{
		StructuredLogger::event('error', 'recovery', 'notifier_unavailable', 'degraded', 0, '', array('delivery' => 'failed'));
	}

	private function reportAge(?array $report, int $now): ?int
	{
		if (!is_array($report) || ($report['status'] ?? '') !== 'success') return null;
		$timestamp = strtotime((string)($report['completed_at'] ?? ''));
		return $timestamp === false ? null : max(0, $now - $timestamp);
	}
}
