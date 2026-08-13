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
			'tstID, tstName, tstTokenHash, tstScope, tstExpiresAt',
			'tstTokenHash=? and tstState=1 and (tstExpiresAt=0 or tstExpiresAt>?)',
			array($tokenHash, $now),
			'',
			1
		));
		if (!$row || !hash_equals((string)$row['tstTokenHash'], $tokenHash))
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
