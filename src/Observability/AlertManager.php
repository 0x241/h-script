<?php

namespace HScript\Observability;

use HScript\Recovery\CommandRunner;
use Throwable;

/** Emits bounded local alert evidence with suppression; notifier failures are fail-open. */
final class AlertManager
{
	private array $policies;
	private string $directory;

	public function __construct(private string $projectRoot, ?string $policyPath = null)
	{
		$this->directory = rtrim($projectRoot, '/') . '/.cfg/observability';
		$this->policies = $this->loadPolicies($policyPath ?: rtrim($projectRoot, '/') . '/resources/observability-alerts.json');
	}

	public function evaluateReadiness(array $readiness): array
	{
		$checks = is_array($readiness['checks'] ?? null) ? $readiness['checks'] : array();
		$events = array();
		if (($checks['database'] ?? '') === 'failed') $events[] = 'database_unavailable';
		if (($checks['redis'] ?? '') === 'degraded') $events[] = 'redis_degraded';
		if (in_array(($checks['cron'] ?? ''), array('missing', 'stale', 'unknown'), true)) $events[] = 'cron_stale';
		if (in_array(($checks['queue'] ?? ''), array('backlog', 'unknown'), true)) $events[] = 'queue_backlog';
		if (($checks['migration'] ?? '') === 'required') $events[] = 'migration_required';
		if (in_array(($checks['dns'] ?? ''), array('backlog', 'unknown'), true)) $events[] = 'dns_backlog';
		$result = array();
		foreach ($events as $event) $result[$event] = $this->emit($event);
		return $result;
	}

	public function emit(string $eventCode): string
	{
		set_error_handler(static fn(): bool => true);
		try
		{
			$policy = $this->policies[$eventCode] ?? null;
			if (!is_array($policy)) return 'unknown';
			if (!PrivateStorage::directory($this->directory)) return 'failed';
			$state = $this->state();
			$now = time();
			$last = (int)($state[$eventCode] ?? 0);
			if ($last > 0 && $now - $last < $policy['suppression_seconds']) return 'suppressed';

			$alerts = $this->directory . '/alerts';
			if (!PrivateStorage::directory($alerts)) return 'failed';
			$alert = array(
				'format' => 1,
				'created_at' => gmdate('Y-m-d\TH:i:s\Z', $now),
				'event_code' => $eventCode,
				'severity' => $policy['severity'],
				'owner' => $policy['owner'],
				'runbook' => $policy['runbook'],
				'correlation_id' => CorrelationContext::current(),
			);
			$path = $alerts . '/' . gmdate('Ymd-His', $now) . '-' . $eventCode . '-' . bin2hex(random_bytes(4)) . '.json';
			if (!$this->writeJson($path, $alert)) return 'failed';
			$state[$eventCode] = $now;
			$this->writeJson($this->directory . '/alert-state.json', $state);
			StructuredLogger::event($policy['severity'], 'alert', $eventCode, 'failure', 0, '', array(
				'owner' => $policy['owner'],
				'runbook' => $policy['runbook'],
				'suppression_seconds' => $policy['suppression_seconds'],
			));
			$this->notify($path);
			return 'emitted';
		}
		catch (Throwable)
		{
			return 'failed';
		}
		finally { restore_error_handler(); }
	}

	private function notify(string $path): void
	{
		$command = trim((string)(getenv('OBSERVABILITY_ALERT_COMMAND') ?: ''));
		if ($command === '') return;
		try
		{
			if (!str_starts_with($command, '/') || !is_file($command) || !is_executable($command))
				throw new \RuntimeException('Alert command is invalid');
			$result = (new CommandRunner())->run(array($command, $path), timeoutSeconds: 5.0);
			if ($result->exitCode() !== 0) throw new \RuntimeException('Alert command failed');
		}
		catch (Throwable)
		{
			StructuredLogger::event('error', 'alert', 'notifier_unavailable', 'degraded', 0, '', array('delivery' => 'failed'));
		}
	}

	private function loadPolicies(string $path): array
	{
		try
		{
			$data = json_decode((string)file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
			if (!is_array($data) || ($data['format'] ?? null) !== 1 || !is_array($data['policies'] ?? null)) return array();
			$result = array();
			foreach ($data['policies'] as $code => $policy)
			{
				if (!is_string($code) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/', $code) || !is_array($policy)) continue;
				$severity = (string)($policy['severity'] ?? '');
				$owner = (string)($policy['owner'] ?? '');
				$runbook = (string)($policy['runbook'] ?? '');
				$suppression = $policy['suppression_seconds'] ?? null;
				if (!in_array($severity, array('warning', 'error', 'critical'), true)
					|| !preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $owner)
					|| !preg_match('~^docs/observability\.md#[a-z0-9-]+$~', $runbook)
					|| !is_int($suppression) || $suppression < 0 || $suppression > 86400) continue;
				$result[$code] = array('severity' => $severity, 'owner' => $owner, 'runbook' => $runbook, 'suppression_seconds' => $suppression);
			}
			return $result;
		}
		catch (Throwable)
		{
			return array();
		}
	}

	private function state(): array
	{
		$path = $this->directory . '/alert-state.json';
		if (!is_file($path) || is_link($path)) return array();
		try
		{
			$data = json_decode((string)file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
			return is_array($data) ? $data : array();
		}
		catch (Throwable)
		{
			return array();
		}
	}

	private function writeJson(string $path, array $data): bool
	{
		return PrivateStorage::writeJson($path, $data);
	}
}
