<?php

namespace HScript\Telemetry;

use HScript\Application;
use HScript\Database\Connection;
use Throwable;

/**
 * Maintains installation identity and submits registration and heartbeat data.
 *
 * Reporting is fail-open: collector errors are recorded for diagnostics but do
 * not interrupt the installed H-Script application.
 */
final class TelemetryReporter
{
	private const DEFAULT_ENDPOINT = 'https://h-script.com/api/v1/installations';

	private Connection $db;
	private array $config;
	private string $domain;
	private TelemetryClient $client;

	public function __construct(
		Connection $db,
		array $config,
		string $domain = '',
		?TelemetryClient $client = null
	) {
		$this->db = $db;
		$this->config = $config;
		$this->domain = self::normalizeDomain(
			$domain
				?: (string)($config['Telemetry_Domain'] ?? '')
				?: (string)(getenv('APP_DOMAIN') ?: '')
				?: (string)($_SERVER['SERVER_NAME'] ?? '')
		);
		$endpoint = trim((string)($config['telemetry_endpoint'] ?? ''));
		if ($endpoint === '')
			$endpoint = trim((string)(getenv('TELEMETRY_ENDPOINT') ?: self::DEFAULT_ENDPOINT));
		$this->client = $client ?: new TelemetryClient($endpoint);
	}

	public function register(): array
	{
		try
		{
			$identity = $this->ensureIdentity();
			$result = $this->client->request('POST', 'register', array(
				'installation_id' => $identity['id'],
				'domain' => $this->domain,
				'version' => Application::VERSION,
				'installed_at' => $identity['installed_at'],
				'stats_consent' => $this->sharesPublicStats(),
			), $identity['token']);
			$this->recordResult($result, !empty($result['ok']));
			return $result;
		}
		catch (Throwable $exception)
		{
			$result = array('ok' => false, 'status' => 0, 'error' => 'local_error', 'data' => array());
			$this->recordResult($result, false);
			if (function_exists('xAddToLog'))
				\xAddToLog('Telemetry registration failed: ' . $exception->getMessage(), 'telemetry');
			return $result;
		}
	}

	public function report(?array $publicStats = null): array
	{
		try
		{
			$identity = $this->ensureIdentity();
			if (empty($this->config['Telemetry_Registered']))
			{
				$registration = $this->register();
				if (empty($registration['ok']))
					return $registration;
			}

			$payload = array(
				'installation_id' => $identity['id'],
				'version' => Application::VERSION,
				'reported_at' => time(),
				'stats_consent' => $this->sharesPublicStats(),
			);
			if ($this->sharesPublicStats() && $publicStats !== null)
				$payload['public_stats'] = $publicStats;

			$result = $this->client->request('POST', 'report', $payload, $identity['token']);
			if ((int)($result['status'] ?? 0) === 401)
			{
				$this->writeConfig('Registered', 0);
				$registration = $this->register();
				if (empty($registration['ok']))
					return $registration;
				$result = $this->client->request('POST', 'report', $payload, $identity['token']);
			}
			$this->recordResult($result, !empty($result['ok']));
			return $result;
		}
		catch (Throwable $exception)
		{
			$result = array('ok' => false, 'status' => 0, 'error' => 'local_error', 'data' => array());
			$this->recordResult($result, false);
			if (function_exists('xAddToLog'))
				\xAddToLog('Telemetry report failed: ' . $exception->getMessage(), 'telemetry');
			return $result;
		}
	}

	private function ensureIdentity(): array
	{
		$id = trim((string)($this->config['Telemetry_InstallationID'] ?? ''));
		if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id))
		{
			$id = self::uuid();
			$this->writeConfig('InstallationID', $id);
		}

		$token = trim((string)($this->config['Telemetry_Token'] ?? ''));
		if (!preg_match('/^hsi_[a-f0-9]{64}$/', $token))
		{
			$token = 'hsi_' . bin2hex(random_bytes(32));
			$this->writeConfig('Token', $token);
		}

		$installedAt = (int)($this->config['Telemetry_InstalledAt'] ?? 0);
		if ($installedAt <= 0)
		{
			$installedAt = time();
			$this->writeConfig('InstalledAt', $installedAt);
		}
		if ($this->domain === '')
			throw new \RuntimeException('Telemetry domain is empty');
		$this->writeConfig('Domain', $this->domain);

		return array('id' => $id, 'token' => $token, 'installed_at' => $installedAt);
	}

	private function sharesPublicStats(): bool
	{
		return !empty($this->config['Telemetry_SharePublicStats']);
	}

	private function recordResult(array $result, bool $registered): void
	{
		$this->writeConfig('LastAttemptAt', time());
		$this->writeConfig('LastStatus', (int)($result['status'] ?? 0));
		$this->writeConfig('LastError', (string)($result['error'] ?? ''));
		if (!empty($result['ok']))
		{
			$this->writeConfig('LastSuccessAt', time());
			if ($registered)
				$this->writeConfig('Registered', 1);
			$this->storePublicMetrics($result['data']['public_metrics'] ?? null);
		}
	}

	private function storePublicMetrics(mixed $metrics): void
	{
		if (!is_array($metrics))
			return;

		$processed = $metrics['processed'] ?? null;
		$platforms = filter_var($metrics['platforms'] ?? null, FILTER_VALIDATE_INT);
		$updatedAt = filter_var($metrics['updated_at'] ?? null, FILTER_VALIDATE_INT);
		if (!is_numeric($processed) || (float)$processed < 0 || $platforms === false || $platforms < 0)
			return;

		$encoded = json_encode(array(
			'processed' => (float)$processed,
			'processed_currency' => 'USD',
			'platforms' => (int)$platforms,
			'updated_at' => $updatedAt !== false && $updatedAt > 0 ? (int)$updatedAt : time(),
		), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (is_string($encoded))
			$this->writeConfig('PublicMetrics', $encoded);
	}

	private function writeConfig(string $property, mixed $value): void
	{
		$this->db->replace('Cfg', array(
			'Module' => 'Telemetry',
			'Prop' => $property,
			'Val' => $value,
		));
		$this->config['Telemetry_' . $property] = $value;
	}

	private static function uuid(): string
	{
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
	}

	private static function normalizeDomain(string $domain): string
	{
		$domain = strtolower(trim($domain));
		if (str_contains($domain, '://'))
			$domain = (string)(parse_url($domain, PHP_URL_HOST) ?: '');
		$domain = rtrim($domain, '.');
		if (strlen($domain) > 253 || !preg_match('/^[a-z0-9.-]+(?::[0-9]{1,5})?$/', $domain))
			return '';
		return $domain;
	}
}
