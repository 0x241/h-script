<?php

namespace HScript\Telemetry;

use HScript\Database\Connection;
use InvalidArgumentException;

/**
 * Issues and authenticates isolated read tokens for collector consumers.
 *
 * Plaintext secrets are returned only at issuance; only SHA-256 hashes are
 * persisted in the collector database.
 */
final class TelemetryServiceTokenRepository
{
	public const SCOPE = 'telemetry:read';
	public const ISSUER_LEVEL = 10;

	private Connection $db;

	public function __construct(Connection $db)
	{
		$this->db = $db;
	}

	/**
	 * Issues a service token for an active collector account.
	 *
	 * @return array{id:int, token:string, scope:string, expires_at:int}
	 */
	public function issue(int $userId, string $name, int $expiresAt = 0): array
	{
		if (
			$userId <= 0
			|| !$this->db->count(
				'Users',
				'uID=?d and uLevel=?d and uState=1',
				array($userId, self::ISSUER_LEVEL)
			)
		)
			throw new InvalidArgumentException('Collector account not found');

		$name = self::normalizeName($name);
		$token = 'hst_' . bin2hex(random_bytes(32));
		$id = $this->db->insert('TelemetryServiceTokens', array(
			'tstuID' => $userId,
			'tstName' => $name,
			'tstTokenHash' => self::hash($token),
			'tstTokenPrefix' => substr($token, 0, 12),
			'tstScope' => self::SCOPE,
			'tstState' => 1,
			'tstCreatedAt' => time(),
			'tstExpiresAt' => max(0, $expiresAt),
			'tstLastUsedAt' => 0,
			'tstLastIP' => '',
		));
		if (!$id)
			throw new \RuntimeException('Telemetry service token could not be created');

		return array(
			'id' => (int)$id,
			'token' => $token,
			'scope' => self::SCOPE,
			'expires_at' => max(0, $expiresAt),
		);
	}

	/**
	 * Authenticates a token and records its most recent use.
	 *
	 * @return array{token_id:int, name:string, scope:string, expires_at:int}|null
	 */
	public function authenticate(string $token, string $ip): ?array
	{
		if (!preg_match('/^hst_[a-f0-9]{64}$/', $token))
			return null;

		$tokenHash = self::hash($token);
		$now = time();
		$row = $this->db->fetch1Row($this->db->select(
			'TelemetryServiceTokens',
			'tstID, tstuID, tstName, tstTokenHash, tstScope, tstExpiresAt',
			'tstTokenHash=? and tstState=1 and (tstExpiresAt=0 or tstExpiresAt>?)',
			array($tokenHash, $now),
			'',
			1
		));
		if (!$row || !hash_equals((string)$row['tstTokenHash'], $tokenHash))
			return null;
		// A disabled/removed consumer must not authenticate with a surviving token,
		// including an issuance already in flight when emergency stop committed.
		if (!$this->db->count('Users', 'uID=?d and uLevel=?d and uState=1', array((int)$row['tstuID'], self::ISSUER_LEVEL)))
			return null;

		$this->db->update('TelemetryServiceTokens', array(
			'tstLastUsedAt' => $now,
			'tstLastIP' => $ip,
		), '', 'tstID=?d', array($row['tstID']));

		return array(
			'token_id' => (int)$row['tstID'],
			'name' => (string)$row['tstName'],
			'scope' => (string)$row['tstScope'],
			'expires_at' => (int)$row['tstExpiresAt'],
		);
	}

	public function listAll(): array
	{
		$rows = $this->db->fetchRows($this->db->select(
			'TelemetryServiceTokens LEFT JOIN Users ON uID=tstuID',
			'tstID, tstuID, tstName, tstTokenPrefix, tstScope, tstState, tstCreatedAt, '
				. 'tstExpiresAt, tstLastUsedAt, tstLastIP, uLogin',
			'',
			null,
			'tstID DESC'
		));
		return is_array($rows) ? $rows : array();
	}

	/** Returns one bounded token page and unfiltered effective-status totals. */
	public function listPage(ServiceTokenListQuery|array|null $query = null): array
	{
		if (!$query instanceof ServiceTokenListQuery)
			$query = ServiceTokenListQuery::fromArray(is_array($query) ? $query : array());

		$now = time();
		$filter = $this->listFilter($query, $now);
		$total = (int)$this->db->fetch1($this->db->query(
			'SELECT COUNT(*) FROM TelemetryServiceTokens t LEFT JOIN Users u ON u.uID=t.tstuID WHERE ' . $filter['where'],
			$filter['parameters']
		));
		$totalPages = $total > 0 ? (int)ceil($total / $query->perPage()) : 0;
		$page = $totalPages > 0 ? min($query->page(), $totalPages) : 1;
		$offset = ($page - 1) * $query->perPage();
		$rows = $this->db->fetchRows($this->db->query(
			'SELECT t.tstID, t.tstuID, t.tstName, t.tstTokenPrefix, t.tstScope, t.tstState,
			 t.tstCreatedAt, t.tstExpiresAt, t.tstLastUsedAt, t.tstLastIP, u.uLogin
			 FROM TelemetryServiceTokens t LEFT JOIN Users u ON u.uID=t.tstuID
			 WHERE ' . $filter['where'] . '
			 ORDER BY t.tstID DESC
			 LIMIT ' . $offset . ', ' . $query->perPage(),
			$filter['parameters']
		));

		return array(
			'summary' => $this->summary($now),
			'filters' => $query->filters(),
			'pagination' => array(
				'page' => $page,
				'per_page' => $query->perPage(),
				'total' => $total,
				'total_pages' => $totalPages,
				'has_previous' => $page > 1,
				'has_next' => $page < $totalPages,
			),
			'tokens' => is_array($rows) ? $rows : array(),
		);
	}

	/** Aggregates token state per collector account without loading token rows. */
	public function ownerSummaries(int $now = 0): array
	{
		$now = $now > 0 ? $now : time();
		$rows = $this->db->fetchRows($this->db->query(
			'SELECT tstuID,
			 COUNT(*) AS token_total,
			 SUM(CASE WHEN tstState=1 AND (tstExpiresAt=0 OR tstExpiresAt>?d) THEN 1 ELSE 0 END) AS token_active,
			 SUM(CASE WHEN tstState=2 THEN 1 ELSE 0 END) AS token_paused,
			 SUM(CASE WHEN tstState=0 OR (tstState=1 AND tstExpiresAt>0 AND tstExpiresAt<=?d) THEN 1 ELSE 0 END) AS token_revoked,
			 MAX(tstCreatedAt) AS last_created_at,
			 MAX(tstLastUsedAt) AS last_used_at
			 FROM TelemetryServiceTokens GROUP BY tstuID',
			array($now, $now)
		));
		$summary = array();
		foreach (is_array($rows) ? $rows : array() as $row)
			$summary[(int)$row['tstuID']] = $row;
		return $summary;
	}

	public function listForUser(int $userId): array
	{
		if ($userId <= 0)
			return array();
		$rows = $this->db->fetchRows($this->db->select(
			'TelemetryServiceTokens',
			'tstID, tstuID, tstName, tstTokenPrefix, tstScope, tstState, tstCreatedAt, '
				. 'tstExpiresAt, tstLastUsedAt, tstLastIP',
			'tstuID=?d',
			array($userId),
			'tstID DESC'
		));
		return is_array($rows) ? $rows : array();
	}

	/**
	 * Replaces an owned service token and revokes the previous secret.
	 *
	 * @return array{id:int, token:string, scope:string, expires_at:int}
	 */
	public function reissue(
		int $tokenId,
		int $userId,
		string $name,
		int $expiresAt = 0
	): array {
		if (!$this->ownsActiveToken($tokenId, $userId))
			throw new InvalidArgumentException('Service token not found');

		$replacement = $this->issue($userId, $name, $expiresAt);
		if (!$this->revoke($tokenId, $userId))
		{
			$this->revoke((int)$replacement['id'], $userId);
			throw new \RuntimeException('Previous service token could not be revoked');
		}
		return $replacement;
	}

	public function update(
		int $tokenId,
		string $name,
		int $expiresAt,
		bool $enabled
	): bool {
		if ($tokenId <= 0 || !$this->db->count(
			'TelemetryServiceTokens',
			'tstID=?d and tstState<>0',
			array($tokenId)
		))
			return false;

		return $this->db->update('TelemetryServiceTokens', array(
			'tstName' => self::normalizeName($name),
			'tstState' => $enabled ? 1 : 2,
			'tstExpiresAt' => max(0, $expiresAt),
		), '', 'tstID=?d and tstState<>0', array($tokenId)) !== false;
	}

	public function revoke(int $tokenId, int $userId = 0): bool
	{
		if (!$this->ownsActiveToken($tokenId, $userId))
			return false;
		$filter = 'tstID=?d and tstState<>0';
		$params = array($tokenId);
		if ($userId > 0)
		{
			$filter .= ' and tstuID=?d';
			$params[] = $userId;
		}
		return $this->db->update(
			'TelemetryServiceTokens',
			array('tstState' => 0),
			'',
			$filter,
			$params
		) !== false;
	}

	public static function hash(string $token): string
	{
		return hash('sha256', $token);
	}

	/** Operator emergency stop; no token plaintext/hash leaves the repository. */
	public function stopConsumer(int $userId): void
	{
		if ($userId <= 0) throw new InvalidArgumentException('A positive collector consumer ID is required');
		if ($this->db->beginJob() === false) throw new \RuntimeException('Consumer stop transaction unavailable');
		try
		{
			$row = $this->db->fetch1Row($this->db->query('SELECT uID FROM Users WHERE uID=?d AND uLevel=?d FOR UPDATE', array($userId, self::ISSUER_LEVEL)));
			if (!$row) throw new InvalidArgumentException('Collector consumer not found');
			if ($this->db->update('Users', array('uState'=>3), '', 'uID=?d', array($userId)) === false
				|| $this->db->update('TelemetryServiceTokens', array('tstState'=>0), '', 'tstuID=?d AND tstState<>0', array($userId)) === false)
				throw new \RuntimeException('Collector consumer stop failed');
			if ($this->db->endJob() === false) throw new \RuntimeException('Collector consumer stop commit failed');
		}
		catch (\Throwable $error) { $this->db->cancelJob(); throw $error; }
	}

	private function listFilter(ServiceTokenListQuery $query, int $now): array
	{
		$clauses = array('1=1');
		$parameters = array();
		if ($query->search() !== '')
		{
			$pattern = '%' . self::escapeLike($query->search()) . '%';
			$clauses[] = "(LOWER(t.tstName) LIKE ? ESCAPE '=' OR LOWER(COALESCE(u.uLogin, '')) LIKE ? ESCAPE '=')";
			$parameters[] = $pattern;
			$parameters[] = $pattern;
		}
		if ($query->status() === 'active')
		{
			$clauses[] = 't.tstState=1 AND (t.tstExpiresAt=0 OR t.tstExpiresAt>?d)';
			$parameters[] = $now;
		}
		elseif ($query->status() === 'paused')
			$clauses[] = 't.tstState=2';
		elseif ($query->status() === 'expired')
		{
			$clauses[] = 't.tstState=1 AND t.tstExpiresAt>0 AND t.tstExpiresAt<=?d';
			$parameters[] = $now;
		}
		elseif ($query->status() === 'revoked')
			$clauses[] = 't.tstState=0';
		return array('where' => implode(' AND ', $clauses), 'parameters' => $parameters);
	}

	private function summary(int $now): array
	{
		$row = $this->db->fetch1Row($this->db->query(
			'SELECT COUNT(*) AS total,
			 SUM(CASE WHEN tstState=1 AND (tstExpiresAt=0 OR tstExpiresAt>?d) THEN 1 ELSE 0 END) AS active,
			 SUM(CASE WHEN tstState=2 THEN 1 ELSE 0 END) AS paused,
			 SUM(CASE WHEN tstState=1 AND tstExpiresAt>0 AND tstExpiresAt<=?d THEN 1 ELSE 0 END) AS expired,
			 SUM(CASE WHEN tstState=0 THEN 1 ELSE 0 END) AS revoked
			 FROM TelemetryServiceTokens',
			array($now, $now)
		));
		return array(
			'total' => (int)($row['total'] ?? 0),
			'active' => (int)($row['active'] ?? 0),
			'paused' => (int)($row['paused'] ?? 0),
			'expired' => (int)($row['expired'] ?? 0),
			'revoked' => (int)($row['revoked'] ?? 0),
		);
	}

	private static function escapeLike(string $value): string
	{
		return str_replace(array('=', '%', '_'), array('==', '=%', '=_'), $value);
	}

	private static function normalizeName(string $name): string
	{
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > 100)
			throw new InvalidArgumentException('Token name must contain 1 to 100 characters');
		return $name;
	}

	private function ownsActiveToken(int $tokenId, int $userId = 0): bool
	{
		if ($tokenId <= 0)
			return false;
		$filter = 'tstID=?d and tstState<>0';
		$params = array($tokenId);
		if ($userId > 0)
		{
			$filter .= ' and tstuID=?d';
			$params[] = $userId;
		}
		return $this->db->count('TelemetryServiceTokens', $filter, $params) > 0;
	}
}
