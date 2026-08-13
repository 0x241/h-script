<?php

namespace HScript\Http;

use HScript\Database\Connection;
use InvalidArgumentException;

/**
 * Issues, hashes, authenticates, scopes, and revokes REST API bearer tokens.
 */
final class ApiTokenRepository
{
	public const AVAILABLE_SCOPES = array(
		'user:read',
		'balance:read',
		'operations:read',
		'deposit:write',
		'withdraw:write',
	);

	private Connection $db;

	public function __construct(Connection $db)
	{
		$this->db = $db;
	}

	public function authenticate(string $token, string $ip): ?array
	{
		if (!$this->isTokenFormatValid($token))
			return null;

		$now = time();
		$row = $this->db->fetch1Row($this->db->select(
			'ApiTokens JOIN Users ON uID=atuID',
			'atID, atuID, atName, atScopes, atExpiresAt, uLogin, uLevel, uState',
			'atTokenHash=? and atState=1 and uState=1 and (atExpiresAt=0 or atExpiresAt>?)',
			array(self::hash($token), $now),
			'',
			1
		));
		if (!$row)
			return null;

		$this->db->update('ApiTokens', array(
			'atLastUsedAt' => $now,
			'atLastIP' => $ip,
		), '', 'atID=?d', array($row['atID']));

		return array(
			'token_id' => (int)$row['atID'],
			'user_id' => (int)$row['atuID'],
			'name' => (string)$row['atName'],
			'scopes' => self::parseScopes((string)$row['atScopes']),
			'expires_at' => (int)$row['atExpiresAt'],
			'login' => (string)$row['uLogin'],
			'level' => (int)$row['uLevel'],
		);
	}

	public function issue(int $userId, string $name, array $scopes = array('*'), int $expiresAt = 0): array
	{
		if ($userId <= 0 || !$this->db->count('Users', 'uID=?d and uState=1', array($userId)))
			throw new InvalidArgumentException('Active user not found');

		$name = trim($name);
		if ($name === '' || mb_strlen($name) > 100)
			throw new InvalidArgumentException('Token name must contain 1 to 100 characters');

		$scopes = self::normalizeScopes($scopes);
		$token = 'hs_' . bin2hex(random_bytes(32));
		$id = $this->db->insert('ApiTokens', array(
			'atuID' => $userId,
			'atName' => $name,
			'atTokenHash' => self::hash($token),
			'atScopes' => implode(' ', $scopes),
			'atState' => 1,
			'atCreatedAt' => time(),
			'atExpiresAt' => max(0, $expiresAt),
			'atLastUsedAt' => 0,
			'atLastIP' => '',
		));
		if (!$id)
			throw new \RuntimeException('API token could not be created');
		return array(
			'id' => (int)$id,
			'token' => $token,
			'scopes' => $scopes,
			'expires_at' => max(0, $expiresAt),
		);
	}

	public function revoke(int $tokenId, int $userId = 0): bool
	{
		$filter = 'atID=?d';
		$params = array($tokenId);
		if ($userId > 0)
		{
			$filter .= ' and atuID=?d';
			$params[] = $userId;
		}
		if (!$this->db->count('ApiTokens', $filter . ' and atState<>0', $params))
			return false;
		return $this->db->update('ApiTokens', array('atState' => 0), '', $filter, $params) !== false;
	}

	public function listForUser(int $userId): array
	{
		$rows = $this->db->fetchRows($this->db->select(
			'ApiTokens',
			'atID, atName, atScopes, atState, atCreatedAt, atExpiresAt, atLastUsedAt, atLastIP',
			'atuID=?d',
			array($userId),
			'atID'
		));
		return is_array($rows) ? $rows : array();
	}

	public function listAll(): array
	{
		$rows = $this->db->fetchRows($this->db->select(
			'ApiTokens LEFT JOIN Users ON uID=atuID',
			'atID, atuID, atName, atScopes, atState, atCreatedAt, atExpiresAt, atLastUsedAt, atLastIP, uLogin, uMail',
			'',
			null,
			'atID DESC'
		));
		return is_array($rows) ? $rows : array();
	}

	public function update(
		int $tokenId,
		string $name,
		array $scopes,
		int $expiresAt,
		bool $enabled,
		int $userId = 0
	): bool
	{
		$filter = 'atID=?d and atState<>0';
		$params = array($tokenId);
		if ($userId > 0)
		{
			$filter .= ' and atuID=?d';
			$params[] = $userId;
		}
		if ($tokenId <= 0 || !$this->db->count('ApiTokens', $filter, $params))
			return false;

		$name = trim($name);
		if ($name === '' || mb_strlen($name) > 100)
			throw new InvalidArgumentException('Token name must contain 1 to 100 characters');

		$scopes = self::normalizeScopes($scopes);
		return $this->db->update('ApiTokens', array(
			'atName' => $name,
			'atScopes' => implode(' ', $scopes),
			'atState' => $enabled ? 1 : 2,
			'atExpiresAt' => max(0, $expiresAt),
		), '', $filter, $params) !== false;
	}

	public static function allows(array $auth, string $scope): bool
	{
		$scopes = $auth['scopes'] ?? array();
		return in_array('*', $scopes, true) || in_array($scope, $scopes, true);
	}

	public static function hash(string $token): string
	{
		return hash('sha256', $token);
	}

	private function isTokenFormatValid(string $token): bool
	{
		return (bool)preg_match('/^hs(?:3)?_[A-Fa-f0-9]{64}$/', $token);
	}

	private static function parseScopes(string $scopes): array
	{
		return self::normalizeScopes(preg_split('/[\s,]+/', trim($scopes)) ?: array());
	}

	public static function normalizeScopes(array $scopes): array
	{
		$normalized = array();
		foreach ($scopes as $scope)
		{
			$scope = strtolower(trim((string)$scope));
			if ($scope === '')
				continue;
			if ($scope !== '*' && !in_array($scope, self::AVAILABLE_SCOPES, true))
				throw new InvalidArgumentException('Invalid API scope: ' . $scope);
			$normalized[$scope] = $scope;
		}
		if (!$normalized)
			throw new InvalidArgumentException('At least one API scope is required');
		return array_values($normalized);
	}
}
