<?php

namespace HScript\Telemetry;

use HScript\Database\Connection;
use Throwable;

/** Short-lived one-use challenges, bound to the authenticated installation/domain. */
final class DomainProofService
{
	public const LIFETIME = 604800;
	public const CHALLENGE_LIFETIME = 600;

	public function __construct(private Connection $db, private DomainProofTransport $http = new DomainProofHttpClient()) {}

	public static function fresh(array $row, ?int $now = null): bool
	{
		$now ??= time();
		return (int)($row['tiDomainVerifiedAt'] ?? 0) > 0
			&& (int)$row['tiDomainVerifiedAt'] <= $now
			&& (int)($row['tiDomainVerifiedUntil'] ?? 0) > $now;
	}

	public function execute(string $operation, string $id, string $domain, string $token): array
	{
		$row = $this->db->fetch1Row($this->db->select('Installations', '*', 'tiPublicID=? AND tiState=1', array($id), '', 1));
		if (!$row || !hash_equals((string)$row['tiTokenHash'], hash('sha256', $token)))
			return array('state' => 'invalid_token');
		if (!hash_equals((string)$row['tiDomain'], $domain))
			return array('state' => 'domain_registration_required');
		$now = time();
		if (self::fresh($row, $now) && (int)$row['tiDomainVerifiedUntil'] > $now + 86400)
			return array('state' => 'verified', 'verified_until' => (int)$row['tiDomainVerifiedUntil']);
		$where = 'tiID=?d AND tiDomain=? AND tiTokenHash=? AND tiState=1';
		$params = array($row['tiID'], $domain, hash('sha256', $token));
		if ($operation === 'challenge')
		{
			$nonce = bin2hex(random_bytes(32));
			$this->db->update('Installations', array(
				'tiDomainProofHash' => hash('sha256', $nonce),
				'tiDomainProofExpiresAt' => $now + self::CHALLENGE_LIFETIME,
			), '', $where . ' AND tiDomainProofExpiresAt<=?d', array_merge($params, array($now)));
			if ((int)$this->db->rowCount() !== 1) return array('state' => 'proof_retry_later');
			return array('state' => 'challenge', 'nonce' => $nonce, 'expires_at' => $now + self::CHALLENGE_LIFETIME);
		}
		if ($operation !== 'verify' || (int)$row['tiDomainProofExpiresAt'] <= $now || !preg_match('/^[a-f0-9]{64}$/', (string)$row['tiDomainProofHash']))
			return array('state' => 'proof_challenge_expired');
		$hash = (string)$row['tiDomainProofHash'];
		$where .= ' AND tiDomainProofHash=? AND tiDomainProofExpiresAt>?d';
		$params = array_merge($params, array($hash, $now));
		// Atomically claim the network attempt; concurrent calls never fan out.
		$this->db->update('Installations', array('tiDomainProofAttemptAt' => $now), '',
			$where . ' AND tiDomainProofAttemptAt<?d', array_merge($params, array($now - 60)));
		if ((int)$this->db->rowCount() !== 1) return array('state' => 'proof_retry_later');
		$error = '';
		try
		{
			$proof = $this->http->fetch($domain);
			if (($proof['installation_id'] ?? null) !== $id || ($proof['domain'] ?? null) !== $domain
				|| !is_string($proof['nonce'] ?? null) || !preg_match('/^[a-f0-9]{64}$/', $proof['nonce'])
				|| !hash_equals($hash, hash('sha256', $proof['nonce']))
				|| (int)($proof['expires_at'] ?? 0) !== (int)$row['tiDomainProofExpiresAt'])
				$error = 'proof_mismatch';
		}
		catch (Throwable $exception)
		{
			$error = in_array($exception->getMessage(), array('proof_domain_invalid', 'proof_dns_unavailable',
				'proof_address_blocked', 'proof_https_failed', 'proof_response_invalid'), true)
				? $exception->getMessage() : 'proof_https_failed';
		}
		$finished = time();
		$params[count($params) - 1] = $finished;
		$values = array('tiDomainProofError' => $error);
		if ($error === '')
			$values += array('tiDomainVerifiedAt' => $finished, 'tiDomainVerifiedUntil' => $finished + self::LIFETIME,
				'tiDomainProofHash' => '', 'tiDomainProofExpiresAt' => 0);
		$this->db->update('Installations', $values, '', $where, $params);
		if ((int)$this->db->rowCount() !== 1) return array('state' => 'proof_challenge_expired');
		return array('state' => $error ?: 'verified', 'verified_until' => $error === '' ? $finished + self::LIFETIME : 0);
	}
}
