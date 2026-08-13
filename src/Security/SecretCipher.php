<?php

namespace HScript\Security;

/**
 * Authenticated encryption for secrets persisted in application tables.
 *
 * The encryption key lives outside the database and generated configuration.
 */
final class SecretCipher
{
	private const PREFIX = 'v2:';
	private const IV_BYTES = 12;
	private const TAG_BYTES = 16;

	public static function encrypt(string $plaintext, string $context): ?string
	{
		$key = self::key();
		if ($key === null || !function_exists('openssl_encrypt'))
			return null;
		$iv = random_bytes(self::IV_BYTES);
		$tag = '';
		$ciphertext = openssl_encrypt(
			$plaintext,
			'aes-256-gcm',
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			$context,
			self::TAG_BYTES
		);
		if ($ciphertext === false || strlen($tag) !== self::TAG_BYTES)
			return null;
		return self::PREFIX . base64_encode($iv . $tag . $ciphertext);
	}

	public static function decrypt(string $payload, string $context): ?string
	{
		if (!self::isEncrypted($payload))
			return null;
		$key = self::key();
		if ($key === null || !function_exists('openssl_decrypt'))
			return null;
		$binary = base64_decode(substr($payload, strlen(self::PREFIX)), true);
		if ($binary === false || strlen($binary) < self::IV_BYTES + self::TAG_BYTES)
			return null;
		$iv = substr($binary, 0, self::IV_BYTES);
		$tag = substr($binary, self::IV_BYTES, self::TAG_BYTES);
		$ciphertext = substr($binary, self::IV_BYTES + self::TAG_BYTES);
		$plaintext = openssl_decrypt(
			$ciphertext,
			'aes-256-gcm',
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			$context
		);
		return $plaintext === false ? null : $plaintext;
	}

	public static function isEncrypted(string $payload): bool
	{
		return str_starts_with($payload, self::PREFIX);
	}

	private static function key(): ?string
	{
		$value = self::environmentValue('APP_DATA_KEY');
		if ($value === '')
			return null;
		$decoded = base64_decode($value, true);
		if ($decoded !== false && strlen($decoded) === 32)
			return $decoded;
		if (preg_match('/^[a-f0-9]{64}$/i', $value))
			return hex2bin($value) ?: null;
		return hash('sha256', $value, true);
	}

	private static function environmentValue(string $name): string
	{
		$file = getenv($name . '_FILE');
		if ($file !== false && $file !== '' && is_readable($file))
			return trim((string)file_get_contents($file));
		$value = getenv($name);
		return $value === false ? '' : (string)$value;
	}
}
