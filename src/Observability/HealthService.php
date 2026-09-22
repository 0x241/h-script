<?php

namespace HScript\Observability;

use HScript\Cache\RedisCache;
use HScript\Database\Connection;
use HScript\Update\SchemaUpdateGate;
use Throwable;

/** Produces bounded operator-only liveness and dependency-readiness results. */
final class HealthService
{
	public function __construct(private string $projectRoot) {}

	public function process(): array
	{
		$ready = is_readable($this->projectRoot . '/vendor/autoload.php')
			&& is_readable($this->projectRoot . '/VERSION');
		return array(
			'format' => 1,
			'checked_at' => gmdate('Y-m-d\TH:i:s\Z'),
			'status' => $ready ? 'healthy' : 'unhealthy',
			'checks' => array('bootstrap' => $ready ? 'ok' : 'failed'),
		);
	}

	public function readiness(Connection $database, RedisCache $cache): array
	{
		$checks = array();
		$databaseReady = false;
		try { $databaseReady = (int)$database->fetch1($database->query('SELECT 1')) === 1; }
		catch (Throwable) { $databaseReady = false; }
		$checks['database'] = $databaseReady ? 'ok' : 'failed';

		$redisEnabled = !self::disabled(getenv('REDIS_ENABLED'));
		$checks['redis'] = !$redisEnabled ? 'disabled' : ($cache->isAvailable() ? 'ok' : 'degraded');

		$cronEnabled = $databaseReady ? $this->configurationFlag($database, 'Cron', 'Enabled') : null;
		$cron = $this->cronCheck($cronEnabled);
		$checks['cron'] = $cron['status'];
		if ($cron['age_seconds'] !== null)
			MetricRegistry::gauge('cron_lag_seconds', (float)$cron['age_seconds'], array('state' => $cron['metric_state']));

		$queue = $databaseReady ? $this->queueCheck($database) : self::unknownQueue();
		$checks['queue'] = $queue['status'];
		foreach (array('pending', 'processing', 'failed') as $state)
			if ($queue[$state] !== null) MetricRegistry::gauge('queue_depth', (float)$queue[$state], array('state' => $state));
		if ($queue['oldest_age_seconds'] !== null)
			MetricRegistry::gauge('queue_oldest_age_seconds', (float)$queue['oldest_age_seconds'], array('state' => 'pending'));

		$migrationRequired = SchemaUpdateGate::requiresTrafficGate($this->projectRoot);
		$maintenance = is_file($this->projectRoot . '/.cfg/maintenance.json');
		$checks['migration'] = $migrationRequired ? 'required' : 'ready';
		$checks['maintenance'] = $maintenance ? 'active' : 'inactive';
		MetricRegistry::gauge('migration_required', $migrationRequired ? 1.0 : 0.0, array('state' => $migrationRequired ? 'required' : 'ready'));

		$dnsBacklog = $databaseReady ? $this->dnsBacklog($database) : null;
		$checks['dns'] = $dnsBacklog === null ? 'unknown'
			: ($dnsBacklog > self::limit('OBSERVABILITY_DNS_BACKLOG_MAX', 1000, 0, 100000000) ? 'backlog' : 'ok');
		if ($dnsBacklog !== null) MetricRegistry::gauge('dns_backlog', (float)$dnsBacklog);

		$hardFailure = !$databaseReady || $migrationRequired || $maintenance;
		$degraded = in_array('degraded', $checks, true)
			|| in_array('stale', $checks, true) || in_array('missing', $checks, true)
			|| in_array('backlog', $checks, true) || in_array('unknown', $checks, true);
		$status = $hardFailure ? 'not_ready' : ($degraded ? 'degraded' : 'ready');
		$result = array(
			'format' => 1,
			'checked_at' => gmdate('Y-m-d\TH:i:s\Z'),
			'status' => $status,
			'checks' => $checks,
			'queue' => array(
				'pending' => $queue['pending'],
				'processing' => $queue['processing'],
				'failed' => $queue['failed'],
				'oldest_age_seconds' => $queue['oldest_age_seconds'],
			),
			'cron_age_seconds' => $cron['age_seconds'],
			'dns_backlog' => $dnsBacklog,
		);
		(new OperationalStateRepository($this->projectRoot))->recordReadiness($result);
		StructuredLogger::event(
			$status === 'ready' ? 'info' : ($status === 'degraded' ? 'warning' : 'error'),
			'health', 'readiness_checked', $status === 'ready' ? 'success' : ($status === 'degraded' ? 'degraded' : 'failure'),
			0, '', array('state' => $status)
		);
		return $result;
	}

	public function databaseUnavailable(): array
	{
		$result = array(
			'format' => 1,
			'checked_at' => gmdate('Y-m-d\TH:i:s\Z'),
			'status' => 'not_ready',
			'checks' => array(
				'database' => 'failed',
				'redis' => 'unknown',
				'cron' => 'unknown',
				'queue' => 'unknown',
				'migration' => 'unknown',
				'maintenance' => 'unknown',
				'dns' => 'unknown',
			),
		);
		(new OperationalStateRepository($this->projectRoot))->recordReadiness($result);
		StructuredLogger::event('error', 'health', 'readiness_checked', 'failure', 0, '', array('state' => 'not_ready'));
		return $result;
	}

	public function cronCheck(?bool $enabled): array
	{
		if ($enabled === null) return array('status' => 'unknown', 'metric_state' => 'missing', 'age_seconds' => null);
		if (!$enabled) return array('status' => 'disabled', 'metric_state' => 'ok', 'age_seconds' => 0);
		$heartbeat = (new OperationalStateRepository($this->projectRoot))->cronSuccess();
		$timestamp = is_array($heartbeat) ? strtotime((string)($heartbeat['updated_at'] ?? '')) : false;
		if ($timestamp === false || $timestamp > time() || ($heartbeat['status'] ?? '') !== 'success')
			return array('status' => 'missing', 'metric_state' => 'missing', 'age_seconds' => null);
		$age = max(0, time() - $timestamp);
		$stale = $age > self::limit('OBSERVABILITY_CRON_MAX_AGE_SECONDS', 180, 30, 86400);
		return array('status' => $stale ? 'stale' : 'ok', 'metric_state' => $stale ? 'stale' : 'ok', 'age_seconds' => $age);
	}

	public function queueCheck(Connection $database): array
	{
		try
		{
			$counts = array('pending' => 0, 'processing' => 0, 'failed' => 0);
			$query = $database->query('SELECT jState, COUNT(*) AS total FROM Jobs WHERE jState IN (0,1,3) GROUP BY jState');
			if ($query === false) throw new \RuntimeException('Queue check failed');
			$rows = $database->fetchRows($query);
			if (!is_array($rows)) throw new \RuntimeException('Queue check failed');
			foreach ($rows as $row)
			{
				$name = array(0 => 'pending', 1 => 'processing', 3 => 'failed')[self::countValue($row['jState'] ?? null)] ?? null;
				if ($name === null) throw new \RuntimeException('Queue state is invalid');
				$counts[$name] = self::countValue($row['total'] ?? null);
			}
			$oldest = self::queryCount($database, 'SELECT COALESCE(MIN(jCTS),0) FROM Jobs WHERE jState=0');
			$oldestAge = $oldest > 0 ? max(0, time() - (int)\stampToTime($oldest)) : 0;
			$backlog = $counts['pending'] > self::limit('OBSERVABILITY_QUEUE_MAX_DEPTH', 1000, 1, 10000000)
				|| $counts['failed'] > self::limit('OBSERVABILITY_QUEUE_MAX_FAILED', 100, 0, 10000000)
				|| $oldestAge > self::limit('OBSERVABILITY_QUEUE_MAX_AGE_SECONDS', 900, 1, 2592000);
			return array('status' => $backlog ? 'backlog' : 'ok', 'oldest_age_seconds' => $oldestAge) + $counts;
		}
		catch (Throwable)
		{
			return self::unknownQueue();
		}
	}

	private function dnsBacklog(Connection $database): ?int
	{
		try
		{
			$exists = self::queryCount($database,
				'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', array('Installations')
			);
			if ($exists !== 1) return 0;
			return self::queryCount($database, "SELECT COUNT(*) FROM Installations WHERE tiState=1 AND tiDnsStatus<>'matched'");
		}
		catch (Throwable)
		{
			return null;
		}
	}

	private function configurationFlag(Connection $database, string $module, string $property): ?bool
	{
		try
		{
			$query = $database->select('Cfg', 'Val', 'Module=? and Prop=?', array($module, $property), '', 1);
			if ($query === false) return null;
			$value = $database->fetch1($query);
			if (!is_string($value) && !is_int($value)) return null;
			return !self::disabled($value) && (string)$value !== '';
		}
		catch (Throwable)
		{
			return null;
		}
	}

	private static function unknownQueue(): array
	{
		return array('status' => 'unknown', 'pending' => null, 'processing' => null, 'failed' => null, 'oldest_age_seconds' => null);
	}

	private static function queryCount(Connection $database, string $sql, array $parameters = array()): int
	{
		$query = $database->query($sql, $parameters);
		if ($query === false) throw new \RuntimeException('Dependency check failed');
		return self::countValue($database->fetch1($query));
	}

	private static function countValue(mixed $value): int
	{
		if ((!is_int($value) && (!is_string($value) || !ctype_digit($value))) || (int)$value < 0)
			throw new \RuntimeException('Dependency count is invalid');
		return (int)$value;
	}

	private static function disabled(mixed $value): bool
	{
		return in_array(strtolower(trim((string)$value)), array('0', 'false', 'no', 'off'), true);
	}

	private static function limit(string $name, int $default, int $minimum, int $maximum): int
	{
		$value = getenv($name);
		$value = is_string($value) && ctype_digit($value) ? (int)$value : $default;
		return max($minimum, min($value, $maximum));
	}
}
