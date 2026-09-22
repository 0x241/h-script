<?php

namespace HScript\Telemetry;

use HScript\Database\Connection;
use Throwable;

/** Persists bounded rejection counts without request payloads or client addresses. */
final class TelemetryIngestionCounterRepository
{
	public function __construct(private Connection $database) {}

	public function record(string $code): void
	{
		$code = strtolower(trim($code));
		if (!preg_match('/^[a-z0-9_]{1,64}$/', $code))
			$code = 'request_rejected';
		$now = time();
		try
		{
			$this->database->query(
				'INSERT INTO TelemetryIngestionCounters (ticDay, ticCode, ticCount, ticFirstAt, ticLastAt)
				 VALUES (?, ?, 1, ?d, ?d)
				 ON DUPLICATE KEY UPDATE ticCount=ticCount+1, ticLastAt=VALUES(ticLastAt)',
				array(gmdate('Y-m-d', $now), $code, $now, $now)
			);
			$this->database->delete(
				'TelemetryIngestionCounters',
				'ticDay<?',
				array(gmdate('Y-m-d', $now - 90 * 86400))
			);
		}
		catch (Throwable $exception)
		{
			error_log('Telemetry rejection counter failed: ' . $exception->getMessage());
		}
	}
}
