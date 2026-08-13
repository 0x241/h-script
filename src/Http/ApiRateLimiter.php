<?php

namespace HScript\Http;

use HScript\Database\Connection;

/**
 * Enforces fixed-window API limits persisted in the ApiRateLimits table.
 */
final class ApiRateLimiter
{
	public const DEFAULT_LIMIT = 60;

	private Connection $db;
	private int $limit;
	private int $windowSeconds;

	public function __construct(Connection $db, int $limit = self::DEFAULT_LIMIT, int $windowSeconds = 60)
	{
		$this->db = $db;
		$this->limit = max(1, $limit);
		$this->windowSeconds = max(1, $windowSeconds);
	}

	public function consume(string $dimension, string $identifier): array
	{
		$now = time();
		$window = intdiv($now, $this->windowSeconds);
		$reset = ($window + 1) * $this->windowSeconds;
		$key = hash('sha256', $dimension . "\0" . $identifier);

		$this->db->query(
			'INSERT INTO ApiRateLimits (arlKey, arlWindow, arlCount, arlUpdatedAt)
			 VALUES (?, ?d, 1, ?d)
			 ON DUPLICATE KEY UPDATE arlCount=arlCount+1, arlUpdatedAt=VALUES(arlUpdatedAt)',
			array($key, $window, $now)
		);
		$count = (int)$this->db->fetch1($this->db->select(
			'ApiRateLimits',
			'arlCount',
			'arlKey=? and arlWindow=?d',
			array($key, $window)
		));

		if (random_int(1, 100) === 1)
			$this->db->delete('ApiRateLimits', 'arlWindow<?d', array($window - 1));

		return array(
			'allowed' => $count <= $this->limit,
			'limit' => $this->limit,
			'remaining' => max(0, $this->limit - $count),
			'reset' => $reset,
			'retry_after' => max(1, $reset - $now),
		);
	}
}
