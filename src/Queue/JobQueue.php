<?php

namespace HScript\Queue;

use HScript\Database\Connection;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Persists background jobs in MySQL and executes registered handlers.
 *
 * Workers claim rows with an atomic state transition so concurrent cron runs
 * cannot execute the same pending job. Failed and stale jobs remain retryable.
 */
final class JobQueue
{
	public const STATE_PENDING = 0;
	public const STATE_PROCESSING = 1;
	public const STATE_DONE = 2;
	public const STATE_FAILED = 3;

	private Connection $db;
	private array $handlers = array();

	/**
	 * @param Connection $db Database containing the Jobs table.
	 */
	public function __construct(Connection $db)
	{
		$this->db = $db;
	}

	/**
	 * Registers a handler receiving the decoded payload and source job row.
	 *
	 * @throws RuntimeException When the job type is invalid.
	 */
	public function registerHandler(string $type, callable $handler): void
	{
		$type = $this->normalizeType($type);
		$this->handlers[$type] = $handler;
	}

	/**
	 * Adds a pending job and returns its database identifier.
	 *
	 * @param array<string, mixed> $payload JSON-serializable handler payload.
	 * @throws RuntimeException When the type or payload is invalid.
	 */
	public function dispatch(string $type, array $payload, int $maxAttempts = 3): int
	{
		$type = $this->normalizeType($type);
		$maxAttempts = max(1, min(99, $maxAttempts));
		return (int)$this->db->insert('Jobs', array(
			'jType' => $type,
			'jPayload' => $this->encodePayload($payload),
			'jState' => self::STATE_PENDING,
			'jAttempts' => 0,
			'jMaxAttempts' => $maxAttempts,
			'jCTS' => timeToStamp(),
			'jPTS' => 0,
			'jDTS' => 0,
			'jError' => '',
		));
	}

	/**
	 * Claims and executes one pending job.
	 *
	 * @return bool True when a job was claimed, including failed executions.
	 */
	public function processNext(): bool
	{
		while (true)
		{
			$job = $this->db->fetch1Row($this->db->select(
				'Jobs',
				'*',
				'jState=?d and jAttempts<jMaxAttempts',
				array(self::STATE_PENDING),
				'jCTS, jID',
				1
			));
			if (!$job)
				return false;
			$jobId = (int)$job['jID'];
			// The conditional update is the queue lock: only one worker can move
			// this row from pending to processing.
			$claimed = $this->db->update(
				'Jobs',
				array(
					'jState' => self::STATE_PROCESSING,
					'jAttempts=' => 'jAttempts+1',
					'jPTS' => timeToStamp(),
					'jError' => '',
				),
				'',
				'jID=?d and jState=?d and jAttempts<jMaxAttempts',
				array($jobId, self::STATE_PENDING)
			);
			if (!$claimed)
				continue;
			$job = $this->db->fetch1Row(
				$this->db->select('Jobs', '*', 'jID=?d', array($jobId))
			);
			$this->run($job);
			return true;
		}
	}

	/**
	 * Processes at most the requested number of jobs.
	 *
	 * @return int Number of claimed jobs.
	 */
	public function processBatch(int $limit = 10): int
	{
		$processed = 0;
		$limit = max(0, $limit);
		while ($processed < $limit && $this->processNext())
			$processed++;
		return $processed;
	}

	/**
	 * Requeues one failed job when attempts remain.
	 */
	public function retry(int $jobId): void
	{
		if ($jobId <= 0)
			return;
		$this->db->update(
			'Jobs',
			array(
				'jState' => self::STATE_PENDING,
				'jDTS' => 0,
			),
			'',
			'jID=?d and jState=?d and jAttempts<jMaxAttempts',
			array($jobId, self::STATE_FAILED)
		);
	}

	/**
	 * Requeues a limited set of failed jobs after a cooldown.
	 *
	 * @return int Number of rows changed.
	 */
	public function retryFailed(int $limit = 10, int $delaySeconds = 60): int
	{
		$limit = max(0, $limit);
		if ($limit === 0)
			return 0;
		$ids = $this->db->fetchRows($this->db->select(
			'Jobs',
			'jID',
			'jState=?d and jAttempts<jMaxAttempts and jPTS<=?',
			array(self::STATE_FAILED, timeToStamp(time() - max(0, $delaySeconds))),
			'jPTS, jID',
			$limit
		), 1);
		if (!$ids)
			return 0;
		return (int)$this->db->update(
			'Jobs',
			array(
				'jState' => self::STATE_PENDING,
				'jDTS' => 0,
			),
			'',
			'jID ?i and jState=?d and jAttempts<jMaxAttempts',
			array($ids, self::STATE_FAILED)
		);
	}

	/**
	 * Marks abandoned processing jobs as failed so they can be retried.
	 */
	public function recoverStale(int $olderThanSeconds = 600): int
	{
		return (int)$this->db->update(
			'Jobs',
			array(
				'jState' => self::STATE_FAILED,
				'jDTS' => timeToStamp(),
				'jError' => 'Worker timeout',
			),
			'',
			'jState=?d and jPTS<?',
			array(
				self::STATE_PROCESSING,
				timeToStamp(time() - max(1, $olderThanSeconds)),
			)
		);
	}

	/**
	 * Deletes completed and terminally failed jobs older than the retention period.
	 */
	public function cleanup(int $olderThanDays = 30): int
	{
		$olderThanDays = max(1, $olderThanDays);
		return (int)$this->db->delete(
			'Jobs',
			'jState ?i and jDTS>0 and jDTS<?',
			array(
				array(self::STATE_DONE, self::STATE_FAILED),
				timeToStamp(time() - ($olderThanDays * HS2_UNIX_DAY)),
			)
		);
	}

	/**
	 * Replaces the JSON payload of a queued job.
	 *
	 * @param array<string, mixed> $payload JSON-serializable data.
	 */
	public function updatePayload(int $jobId, array $payload): bool
	{
		if ($jobId <= 0)
			return false;
		return $this->db->update(
			'Jobs',
			array('jPayload' => $this->encodePayload($payload)),
			'',
			'jID=?d',
			array($jobId)
		) !== false;
	}

	/**
	 * Decodes and validates a stored job payload.
	 *
	 * @return array<string, mixed>
	 * @throws RuntimeException When the payload is not valid JSON object data.
	 */
	public static function decodePayload(string $payload): array
	{
		try
		{
			$value = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
		}
		catch (JsonException $e)
		{
			throw new RuntimeException('Invalid job payload: ' . $e->getMessage(), 0, $e);
		}
		if (!is_array($value))
			throw new RuntimeException('Invalid job payload');
		return $value;
	}

	private function run(array $job): void
	{
		$jobId = (int)($job['jID'] ?? 0);
		$type = (string)($job['jType'] ?? '');
		try
		{
			if (!isset($this->handlers[$type]))
				throw new RuntimeException('No handler registered for job type: ' . $type);
			$payload = self::decodePayload((string)($job['jPayload'] ?? ''));
			$result = ($this->handlers[$type])($payload, $job);
			if (is_array($result))
			{
				$payload['result'] = $result;
				$encodedPayload = $this->encodePayload($payload);
			}
			else
				$encodedPayload = (string)$job['jPayload'];
			$this->db->update(
				'Jobs',
				array(
					'jPayload' => $encodedPayload,
					'jState' => self::STATE_DONE,
					'jDTS' => timeToStamp(),
					'jError' => '',
				),
				'',
				'jID=?d and jState=?d',
				array($jobId, self::STATE_PROCESSING)
			);
		}
		catch (Throwable $e)
		{
			$this->db->update(
				'Jobs',
				array(
					'jState' => self::STATE_FAILED,
					'jDTS' => timeToStamp(),
					'jError' => $e->getMessage(),
				),
				'',
				'jID=?d and jState=?d',
				array($jobId, self::STATE_PROCESSING)
			);
			error_log('Job #' . $jobId . ' (' . $type . ') failed: ' . $e->getMessage());
		}
	}

	private function encodePayload(array $payload): string
	{
		try
		{
			return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		}
		catch (JsonException $e)
		{
			throw new RuntimeException('Unable to encode job payload: ' . $e->getMessage(), 0, $e);
		}
	}

	private function normalizeType(string $type): string
	{
		$type = strtolower(trim($type));
		if (!preg_match('/^[a-z][a-z0-9_.-]{0,49}$/', $type))
			throw new RuntimeException('Invalid job type');
		return $type;
	}
}
