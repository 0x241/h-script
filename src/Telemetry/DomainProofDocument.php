<?php

namespace HScript\Telemetry;

/** Only a locally persisted collector challenge is public; never reflect request input. */
final class DomainProofDocument
{
	public const CLOCK_SKEW = 60;

	public static function fromConfig(array $config, ?int $now = null): ?array
	{
		$now ??= time();
		$proof = json_decode((string)($config['Telemetry_DomainProof'] ?? ''), true);
		if (!is_array($proof) || !is_string($proof['nonce'] ?? null)
			|| !preg_match('/^[a-f0-9]{64}$/', $proof['nonce'])
			|| (int)($proof['expires_at'] ?? 0) <= $now
			|| (int)$proof['expires_at'] > $now + DomainProofService::CHALLENGE_LIFETIME + self::CLOCK_SKEW
			|| ($proof['installation_id'] ?? '') !== ($config['Telemetry_InstallationID'] ?? null)
			|| ($proof['domain'] ?? '') !== ($config['Telemetry_Domain'] ?? null))
			return null;
		return array_intersect_key($proof, array_flip(array('installation_id', 'domain', 'nonce', 'expires_at')));
	}
}
