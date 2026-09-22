<?php

namespace HScript\Observability;

use HScript\Http\ClientIp;

/** Holds one bounded correlation identifier across the current execution flow. */
final class CorrelationContext
{
	private const ACTORS = array('public', 'authenticated', 'administrator', 'service', 'system');
	private static ?string $identifier = null;
	private static string $actorClass = 'system';

	public static function initializeHttp(?array $server = null, ?string $trustedProxyCidrs = null): string
	{
		$server ??= $_SERVER;
		$remote = is_scalar($server['REMOTE_ADDR'] ?? null) ? trim((string)$server['REMOTE_ADDR']) : '';
		$candidate = is_scalar($server['HTTP_X_REQUEST_ID'] ?? null)
			? trim((string)$server['HTTP_X_REQUEST_ID']) : '';
		self::$identifier = ClientIp::isTrustedProxy($remote, $trustedProxyCidrs) && self::valid($candidate)
			? $candidate : self::generate();
		self::$actorClass = 'public';
		return self::$identifier;
	}

	public static function current(): string
	{
		return self::$identifier ??= self::generate();
	}

	/** Adopt only identifiers already validated at an internal persistence boundary. */
	public static function adopt(string $identifier, string $actorClass = 'system'): string
	{
		self::$identifier = self::valid($identifier) ? $identifier : self::generate();
		self::setActorClass($actorClass);
		return self::$identifier;
	}

	public static function setActorClass(string $actorClass): void
	{
		self::$actorClass = in_array($actorClass, self::ACTORS, true) ? $actorClass : 'system';
	}

	public static function actorClass(): string
	{
		return self::$actorClass;
	}

	/** @return array{identifier: string, actor_class: string} */
	public static function snapshot(): array
	{
		return array('identifier' => self::current(), 'actor_class' => self::$actorClass);
	}

	/** @param array{identifier?: mixed, actor_class?: mixed} $snapshot */
	public static function restore(array $snapshot): void
	{
		self::adopt((string)($snapshot['identifier'] ?? ''), (string)($snapshot['actor_class'] ?? 'system'));
	}

	public static function valid(string $identifier): bool
	{
		return preg_match('/^(?:[A-Fa-f0-9]{32}|[A-Fa-f0-9]{8}-(?:[A-Fa-f0-9]{4}-){3}[A-Fa-f0-9]{12})$/D', $identifier) === 1;
	}

	public static function reset(): void
	{
		self::$identifier = null;
		self::$actorClass = 'system';
	}

	private static function generate(): string
	{
		return bin2hex(random_bytes(16));
	}
}
