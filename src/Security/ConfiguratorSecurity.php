<?php

namespace HScript\Security;

use HScript\Http\ClientIp;
use JsonException;
use RuntimeException;

final class ConfiguratorSecurity
{
	private string $root;
	private string $directory;

	public function __construct(string $projectRoot)
	{
		$root = realpath($projectRoot);
		if ($root === false || !is_dir($root)) throw new RuntimeException('Project root could not be resolved');
		$this->root = rtrim($root, '/');
		$this->directory = $this->root . '/.cfg/security';
	}

	public function clientIp(?array $server = null): string
	{
		return ClientIp::resolve($server);
	}

	public function assertAllowed(string $ip): void
	{
		$allowlist = trim((string)(getenv('CONFIGURATOR_ALLOWED_CIDRS') ?: ''));
		if ($allowlist === '') return;
		$matched = false;
		foreach (preg_split('/[\s,]+/', $allowlist, -1, PREG_SPLIT_NO_EMPTY) as $cidr)
		{
			if (!ClientIp::isValidCidr($cidr))
				throw new RuntimeException('Configurator CIDR allowlist is invalid');
			if (ClientIp::matchesCidr($ip, $cidr)) $matched = true;
		}
		if (!$matched) throw new RuntimeException('Configurator access is not allowed from this IP');
	}

	public function allowlistConfigured(): bool
	{
		return trim((string)(getenv('CONFIGURATOR_ALLOWED_CIDRS') ?: '')) !== '';
	}

	public function consumeRateLimit(string $action, string $identifier, int $limit, int $windowSeconds): array
	{
		$this->ensureDirectory();
		$path = $this->directory . '/rate-limits.json';
		$stream = fopen($path, 'c+');
		if ($stream === false || !flock($stream, LOCK_EX))
			throw new RuntimeException('Configurator rate limit storage is unavailable');
		try
		{
			$contents = stream_get_contents($stream);
			if ($contents === false) throw new RuntimeException('Configurator rate limit storage could not be read');
			try { $data = $contents === '' ? array('format' => 1, 'buckets' => array()) : json_decode($contents, true, 32, JSON_THROW_ON_ERROR); }
			catch (JsonException $exception) { throw new RuntimeException('Configurator rate limit storage is invalid', 0, $exception); }
			if (!is_array($data) || ($data['format'] ?? null) !== 1 || !is_array($data['buckets'] ?? null))
				throw new RuntimeException('Configurator rate limit storage is invalid');
			$now = time();
			$windowSeconds = max(1, $windowSeconds);
			$window = intdiv($now, $windowSeconds);
			$key = hash('sha256', $action . "\0" . $identifier);
			foreach ($data['buckets'] as $bucketKey => $bucket)
				if (!is_array($bucket) || (int)($bucket['expires'] ?? 0) < $now)
					unset($data['buckets'][$bucketKey]);
			$count = isset($data['buckets'][$key]) && (int)($data['buckets'][$key]['window'] ?? -1) === $window
				? (int)$data['buckets'][$key]['count'] + 1
				: 1;
			$reset = ($window + 1) * $windowSeconds;
			$data['buckets'][$key] = array('window' => $window, 'count' => $count, 'expires' => $reset + $windowSeconds);
			$json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
			rewind($stream);
			if (!ftruncate($stream, 0) || fwrite($stream, $json) !== strlen($json) || !fflush($stream))
				throw new RuntimeException('Configurator rate limit storage could not be written');
			chmod($path, 0600);
			return array('allowed' => $count <= max(1, $limit), 'retry_after' => max(1, $reset - $now));
		}
		finally
		{
			flock($stream, LOCK_UN);
			fclose($stream);
		}
	}

	public function audit(string $event, string $outcome, string $ip, array $context = array()): void
	{
		try
		{
			$this->ensureDirectory();
			$record = array(
				'ts' => gmdate('Y-m-d\TH:i:s\Z'),
				'event' => $this->safeToken($event),
				'outcome' => $this->safeToken($outcome),
				'ip' => filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0',
				'context' => array(),
			);
			foreach ($context as $key => $value)
			{
				$key = $this->safeToken((string)$key);
				if (preg_match('/pass|secret|token|credential|sql|query/', $key))
				{
					$record['context'][$key] = '[redacted]';
					continue;
				}
				if (!is_scalar($value) && $value !== null) continue;
				$record['context'][$key] = substr(preg_replace('/[^A-Za-z0-9_.:\/-]/', '_', (string)$value) ?? '', 0, 160);
			}
			$line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
			$path = $this->directory . '/audit.ndjson';
			if (is_file($path) && filesize($path) > 5242880)
			{
				$previous = $path . '.1';
				if (is_file($previous)) unlink($previous);
				rename($path, $previous);
			}
			if (file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === false)
				throw new RuntimeException('Audit record could not be written');
			chmod($path, 0600);
		}
		catch (\Throwable $exception)
		{
			error_log('Configurator audit unavailable: ' . $exception->getMessage());
		}
	}

	public function recentAudit(int $limit = 30): array
	{
		$path = $this->directory . '/audit.ndjson';
		if (!is_file($path) || is_link($path) || !is_readable($path)) return array();
		$lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		if (!is_array($lines)) return array();
		$result = array();
		foreach (array_slice($lines, -max(1, min($limit, 100))) as $line)
		{
			try { $record = json_decode($line, true, 16, JSON_THROW_ON_ERROR); }
			catch (JsonException) { continue; }
			if (is_array($record)) $result[] = $record;
		}
		return array_reverse($result);
	}

	public static function localAddress(string $ip): bool
	{
		return in_array($ip, array('127.0.0.1', '::1'), true);
	}

	private function ensureDirectory(): void
	{
		if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory))
			throw new RuntimeException('Configurator security directory could not be created');
		if (!is_writable($this->directory)) throw new RuntimeException('Configurator security directory is not writable');
		chmod($this->directory, 0700);
	}

	private function safeToken(string $value): string
	{
		$value = strtolower(trim($value));
		return preg_match('/^[a-z0-9_.-]{1,64}$/', $value) ? $value : 'invalid';
	}
}
