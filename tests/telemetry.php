<?php

use HScript\Application;
use HScript\Database\Connection;
use HScript\Telemetry\CollectorMode;
use HScript\Telemetry\DnsResolverInterface;
use HScript\Telemetry\InstallationListQuery;
use HScript\Telemetry\InstallationRepository;
use HScript\Telemetry\PassiveDnsResolver;
use HScript\Telemetry\PublicStats;
use HScript\Telemetry\ServiceTokenListQuery;
use HScript\Telemetry\TelemetryPayloadValidator;
use HScript\Telemetry\TelemetryClientInterface;
use HScript\Telemetry\TelemetryReporter;
use HScript\Telemetry\TelemetryServiceTokenRepository;
use HScript\Telemetry\TelemetryValidationException;

require dirname(__DIR__) . '/vendor/autoload.php';

function telemetryAssert(bool $condition, string $message): void
{
	if (!$condition)
		throw new RuntimeException($message);
}

final class TelemetryFakeDnsResolver implements DnsResolverInterface
{
	public int $queries = 0;
	public function resolve(string $domain, string $observedIp): array
	{
		$this->queries++;
		$status = match (true) {
			str_starts_with($domain, 'mismatch.') => 'mismatch',
			str_starts_with($domain, 'unresolved.') => 'unresolved',
			str_starts_with($domain, 'invalid.') || str_ends_with($domain, '.invalid') => 'invalid',
			default => 'matched',
		};
		return array(
			'status' => $status,
			'addresses' => in_array($status, array('matched', 'mismatch'), true) ? array($observedIp) : array(),
			'checked_at' => time(),
			'expires_at' => time() + 3600,
			'error_code' => $status === 'invalid' ? 'domain_invalid' : ($status === 'unresolved' ? 'no_records' : ''),
		);
	}
}

final class TelemetryFakeClient implements TelemetryClientInterface
{
	public array $requests = array();
	public function __construct(private bool $available = true) {}
	public function request(string $method, string $path, array $payload, string $token): array
	{
		$this->requests[] = compact('method', 'path', 'payload', 'token');
		return $this->available
			? array('ok' => true, 'status' => 200, 'error' => '', 'data' => array(
				'dns_status' => 'matched', 'dns_checked_at' => time(), 'dns_error_code' => '',
			))
			: array('ok' => false, 'status' => 0, 'error' => 'network_error', 'data' => array());
	}
}

final class TelemetryFakeConnection extends Connection
{
	public array $installations = array();
	public array $reports = array();
	public array $serviceTokens = array();
	public array $ipHistory = array();
	public array $domainEvents = array();
	public array $config = array();
	public array $users = array(
		99 => array('uID' => 99, 'uLevel' => 99, 'uState' => 1, 'uLogin' => 'root'),
		90 => array('uID' => 90, 'uLevel' => 90, 'uState' => 1, 'uLogin' => 'demo-admin'),
		10 => array('uID' => 10, 'uLevel' => 10, 'uState' => 1, 'uLogin' => 'collector'),
	);
	private int $nextInstallationId = 1;
	private int $nextServiceTokenId = 1;

	private function activeInstallations(): array
	{
		return array_values(array_filter(
			$this->installations,
			static fn(array $row): bool => !empty($row['tiState'])
		));
	}

	private function latestReport(int $installationId): array
	{
		$latest = array();
		foreach ($this->reports as $report)
			if (
				(int)$report['tirInstallationID'] === $installationId
				&& (int)($report['tirSequence'] ?? 0) >= (int)($latest['tirSequence'] ?? 0)
			)
				$latest = $report;
		return $latest;
	}

	private function canonicalDomain(string $domain): string
	{
		$domain = strtolower(rtrim($domain, '.'));
		$domain = preg_replace('/^www\./', '', $domain) ?? '';
		foreach (array('.local', '.localhost', '.test', '.invalid', '.example') as $suffix)
			if (str_ends_with($domain, $suffix))
				return '';
		return $domain;
	}

	private function publicOwners(): array
	{
		$owners = array();
		foreach ($this->activeInstallations() as $row)
		{
			$domain = $this->canonicalDomain((string)$row['tiDomain']);
			if ($domain === '' || (string)($row['tiDnsStatus'] ?? '') !== 'matched')
				continue;
			$current = $owners[$domain] ?? null;
			if (
				$current === null
				|| (int)$row['tiRegisteredAt'] < (int)$current['tiRegisteredAt']
				|| ((int)$row['tiRegisteredAt'] === (int)$current['tiRegisteredAt'] && (int)$row['tiID'] < (int)$current['tiID'])
			)
				$owners[$domain] = $row;
		}
		return $owners;
	}

	public function select($table, $fields = '*', $filter = '', $values = null, $order = '', $limit = '', $group = '')
	{
		return array(
			'table' => $table,
			'filter' => $filter,
			'values' => $values ?? array(),
		);
	}

	public function fetch1Row($query)
	{
		if (isset($query['direct_row']))
			return $query['direct_row'];
		if (($query['table'] ?? '') === 'TelemetryServiceTokens')
		{
			$hash = (string)($query['values'][0] ?? '');
			$now = (int)($query['values'][1] ?? 0);
			foreach ($this->serviceTokens as $token)
				if (
					$token['tstTokenHash'] === $hash
					&& (int)$token['tstState'] === 1
					&& ((int)$token['tstExpiresAt'] === 0 || (int)$token['tstExpiresAt'] > $now)
				)
					return $token;
			return array();
		}
		if (($query['table'] ?? '') === 'InstallationReports')
		{
			$key = (int)($query['values'][0] ?? 0) . ':' . (int)($query['values'][1] ?? 0);
			$report = $this->reports[$key] ?? array();
			return $report ? array('tirPayloadHash' => $report['tirPayloadHash']) : array();
		}
		if (($query['table'] ?? '') !== 'Installations')
			return array();
		if (str_contains((string)($query['filter'] ?? ''), 'tiTokenHash'))
		{
			$hash = (string)($query['values'][0] ?? '');
			foreach ($this->installations as $row)
				if ((string)$row['tiTokenHash'] === $hash)
					return $row;
			return array();
		}
		$id = (string)($query['values'][0] ?? '');
		$row = $this->installations[$id] ?? array();
		if (str_contains((string)$query['filter'], 'tiState=1') && empty($row['tiState']))
			return array();
		return $row;
	}

	public function insert($table, $values, $fields = '', $asReplace = false)
	{
		if ($table === 'TelemetryServiceTokens')
		{
			$values['tstID'] = $this->nextServiceTokenId++;
			$this->serviceTokens[$values['tstID']] = $values;
			return $values['tstID'];
		}
		if ($table === 'InstallationReports')
		{
			$key = (int)$values['tirInstallationID'] . ':' . (int)$values['tirSequence'];
			$this->reports[$key] = $values;
			return count($this->reports);
		}
		if ($table === 'InstallationDomainEvents')
		{
			$values['tdeID'] = count($this->domainEvents) + 1;
			$this->domainEvents[] = $values;
			return $values['tdeID'];
		}
		telemetryAssert($table === 'Installations', 'Unexpected telemetry insert table');
		$values['tiID'] = $this->nextInstallationId++;
		$this->installations[$values['tiPublicID']] = $values;
		return $values['tiID'];
	}

	public function update($table, $values, $fields = '', $filter = '', $parameters = null)
	{
		if ($table === 'TelemetryServiceTokens')
		{
			$id = (int)($parameters[0] ?? 0);
			if (!isset($this->serviceTokens[$id]))
				return 0;
			$this->serviceTokens[$id] = array_replace($this->serviceTokens[$id], $values);
			return 1;
		}
		telemetryAssert($table === 'Installations', 'Unexpected telemetry update table');
		$id = (int)($parameters[0] ?? 0);
		foreach ($this->installations as &$installation)
			if ((int)$installation['tiID'] === $id)
			{
				$installation = array_replace($installation, $values);
				return 1;
			}
		unset($installation);
		return 0;
	}

	public function query($query, $values = null)
	{
		$normalized = ltrim($query);
		if (in_array(trim($normalized), array('START TRANSACTION', 'COMMIT', 'ROLLBACK'), true))
			return 1;
		if (str_starts_with($normalized, 'SELECT * FROM Installations'))
			return array('direct_row' => $this->installations[(string)($values[0] ?? '')] ?? array());
		if (str_starts_with($normalized, 'SELECT COUNT(*) FROM TelemetryServiceTokens t'))
			return array('direct_row' => array('token_count' => count($this->serviceTokens)));
		if (str_starts_with($normalized, 'SELECT t.tstID,'))
		{
			$rows = array_values($this->serviceTokens);
			usort($rows, static fn(array $left, array $right): int => (int)$right['tstID'] <=> (int)$left['tstID']);
			return array_map(function (array $row): array {
				unset($row['tstTokenHash']);
				$row['uLogin'] = $this->users[(int)$row['tstuID']]['uLogin'] ?? null;
				return $row;
			}, $rows);
		}
		if (str_starts_with($normalized, 'SELECT COUNT(*) AS total,'))
		{
			$now = (int)($values[0] ?? 0);
			$summary = array('total' => count($this->serviceTokens), 'active' => 0, 'paused' => 0, 'expired' => 0, 'revoked' => 0);
			foreach ($this->serviceTokens as $row)
			{
				if ((int)$row['tstState'] === 0) $summary['revoked']++;
				elseif ((int)$row['tstState'] === 2) $summary['paused']++;
				elseif ((int)$row['tstExpiresAt'] > 0 && (int)$row['tstExpiresAt'] <= $now) $summary['expired']++;
				else $summary['active']++;
			}
			return array('direct_row' => $summary);
		}
		if (str_starts_with($normalized, 'SELECT tstuID,'))
		{
			$now = (int)($values[0] ?? 0);
			$owners = array();
			foreach ($this->serviceTokens as $row)
			{
				$id = (int)$row['tstuID'];
				$owners[$id] ??= array('tstuID' => $id, 'token_total' => 0, 'token_active' => 0, 'token_paused' => 0, 'token_revoked' => 0, 'last_created_at' => 0, 'last_used_at' => 0);
				$owners[$id]['token_total']++;
				if ((int)$row['tstState'] === 0 || ((int)$row['tstState'] === 1 && (int)$row['tstExpiresAt'] > 0 && (int)$row['tstExpiresAt'] <= $now)) $owners[$id]['token_revoked']++;
				elseif ((int)$row['tstState'] === 2) $owners[$id]['token_paused']++;
				else $owners[$id]['token_active']++;
				$owners[$id]['last_created_at'] = max($owners[$id]['last_created_at'], (int)$row['tstCreatedAt']);
				$owners[$id]['last_used_at'] = max($owners[$id]['last_used_at'], (int)$row['tstLastUsedAt']);
			}
			return array_values($owners);
		}
		if (str_starts_with($normalized, 'INSERT INTO InstallationIpHistory'))
		{
			$key = (int)$values[0] . ':' . (string)$values[1];
			if (isset($this->ipHistory[$key]))
			{
				$this->ipHistory[$key]['tihLastSeenAt'] = (int)$values[3];
				$this->ipHistory[$key]['tihRequestCount']++;
			}
			else
				$this->ipHistory[$key] = array(
					'tihID' => count($this->ipHistory) + 1,
					'tihInstallationID' => (int)$values[0],
					'tihIP' => (string)$values[1],
					'tihFirstSeenAt' => (int)$values[2],
					'tihLastSeenAt' => (int)$values[3],
					'tihRequestCount' => 1,
				);
			return 1;
		}
		if (str_starts_with($normalized, 'DELETE FROM InstallationIpHistory') || str_starts_with($normalized, 'DELETE FROM InstallationDomainEvents'))
			return 0;
		if (str_starts_with($normalized, 'SELECT tihInstallationID'))
			return array_values($this->ipHistory);
		if (str_starts_with($normalized, 'SELECT COUNT(*) FROM Installations i WHERE'))
			return array('direct_row' => array('installation_count' => count($this->activeInstallations())));
		if (str_starts_with($normalized, 'SELECT i.tiID,'))
		{
			$rows = array();
			foreach ($this->activeInstallations() as $installation)
			{
				$latest = $this->latestReport((int)$installation['tiID']);
				$rows[] = array_merge($installation, array(
					'tirDay' => $latest['tirDay'] ?? null,
					'tirStats' => $latest['tirStats'] ?? null,
					'tirDataSource' => $latest['tirDataSource'] ?? null,
				));
			}
			usort($rows, static fn(array $left, array $right): int => array((int)$right['tiLastSeenAt'], (int)$right['tiID']) <=> array((int)$left['tiLastSeenAt'], (int)$left['tiID']));
			return $rows;
		}
		if (str_starts_with($normalized, 'SELECT c.tiID, c.tiDomain'))
		{
			$rows = array();
			foreach ($this->publicOwners() as $owner)
				$rows[] = array('tiID' => $owner['tiID'], 'tiDomain' => $owner['tiDomain'], 'tiRegisteredAt' => $owner['tiRegisteredAt']);
			usort($rows, static fn(array $left, array $right): int => array((int)$left['tiRegisteredAt'], (int)$left['tiID']) <=> array((int)$right['tiRegisteredAt'], (int)$right['tiID']));
			return $rows;
		}
		if (str_starts_with($normalized, 'SELECT COUNT(*) AS installations_total'))
		{
			$active = $this->activeInstallations();
			$cutoff = (int)($values[0] ?? 0);
			return array('direct_row' => array(
				'installations_total' => count($active),
				'installations_active_24h' => count(array_filter($active, static fn(array $row): bool => (int)$row['tiLastSeenAt'] >= $cutoff)),
			));
		}
		if (str_starts_with($normalized, 'SELECT COUNT(DISTINCT'))
			return array('direct_row' => array('platforms_total' => count($this->publicOwners())));
		if (str_starts_with($normalized, 'SELECT tiVersion AS version'))
		{
			$versions = array();
			foreach ($this->activeInstallations() as $row)
				$versions[(string)$row['tiVersion']] = ($versions[(string)$row['tiVersion']] ?? 0) + 1;
			ksort($versions);
			return array_map(
				static fn(string $version, int $count): array => array('version' => $version, 'installation_count' => $count),
				array_keys($versions),
				array_values($versions)
			);
		}
		if (str_starts_with($normalized, 'SELECT COUNT(*) AS installations_sharing'))
		{
			$now = (int)($values[0] ?? time());
			$aggregate = array('installations_sharing' => 0, 'worked_days' => 0, 'users_total' => 0, 'users_online' => 0, 'active_deposits' => 0, 'closed_deposits' => 0);
			foreach ($this->publicOwners() as $owner)
			{
				$latest = $this->latestReport((int)$owner['tiID']);
				$stats = !empty($owner['tiStatsConsent']) ? json_decode((string)($latest['tirStats'] ?? ''), true) : null;
				if (!is_array($stats)) continue;
				$aggregate['installations_sharing']++;
				$installedAt = (int)$owner['tiInstalledAt'];
				$aggregate['worked_days'] += $installedAt >= 946684800 && $installedAt <= $now ? (int)floor(($now - $installedAt) / 86400) : 0;
				foreach (array('users_total', 'users_online', 'active_deposits', 'closed_deposits') as $field)
					$aggregate[$field] += max(0, (int)($stats[$field] ?? 0));
			}
			return array('direct_row' => $aggregate);
		}
		if (str_starts_with($normalized, 'SELECT UPPER(JSON_UNQUOTE'))
		{
			$rows = array();
			foreach ($this->publicOwners() as $owner)
			{
				$latest = $this->latestReport((int)$owner['tiID']);
				$stats = !empty($owner['tiStatsConsent']) ? json_decode((string)($latest['tirStats'] ?? ''), true) : null;
				if (!is_array($stats)) continue;
				$currency = strtoupper((string)($stats['base_currency'] ?? ''));
				if (!preg_match('/^[A-Z0-9]{2,10}$/', $currency)) continue;
				$rows[$currency] ??= array('base_currency' => $currency, 'cash_in' => 0.0, 'cash_out' => 0.0, 'referral_paid' => 0.0, 'reinvested' => 0.0);
				$rows[$currency]['cash_in'] += (float)$stats['cash_in_base'];
				$rows[$currency]['cash_out'] += (float)$stats['cash_out_base'];
				$rows[$currency]['referral_paid'] += (float)$stats['referral_paid_base'];
				$rows[$currency]['reinvested'] += (float)$stats['reinvested_base'];
			}
			ksort($rows);
			return array_values($rows);
		}
		if (str_starts_with($normalized, 'SELECT currency_rows.currency'))
		{
			$rows = array();
			foreach ($this->publicOwners() as $owner)
			{
				$latest = $this->latestReport((int)$owner['tiID']);
				$stats = !empty($owner['tiStatsConsent']) ? json_decode((string)($latest['tirStats'] ?? ''), true) : null;
				if (!is_array($stats)) continue;
				foreach (array_unique(array_merge(array_keys($stats['cash_in_by_currency'] ?? array()), array_keys($stats['cash_out_by_currency'] ?? array()))) as $currency)
				{
					$rows[$currency] ??= array('currency' => $currency, 'cash_in' => 0.0, 'cash_out' => 0.0);
					$rows[$currency]['cash_in'] += (float)($stats['cash_in_by_currency'][$currency] ?? 0);
					$rows[$currency]['cash_out'] += (float)($stats['cash_out_by_currency'][$currency] ?? 0);
				}
			}
			ksort($rows);
			return array_values($rows);
		}
		throw new RuntimeException('Unexpected telemetry query');
	}

	public function fetchRows($query, $singleField = false)
	{
		return is_array($query) ? array_values($query) : array();
	}

	public function count($table, $filter = '', $values = null, $field = '')
	{
		if ($table === 'Users')
		{
			$id = (int)($values[0] ?? 0);
			$user = $this->users[$id] ?? array();
			return !empty($user)
				&& (int)$user['uLevel'] === TelemetryServiceTokenRepository::ISSUER_LEVEL
				&& (int)$user['uState'] === 1
				? 1
				: 0;
		}
		if ($table === 'TelemetryServiceTokens')
		{
			$id = (int)($values[0] ?? 0);
			return isset($this->serviceTokens[$id])
				&& (int)$this->serviceTokens[$id]['tstState'] !== 0
				&& (
					!isset($values[1])
					|| (int)$this->serviceTokens[$id]['tstuID'] === (int)$values[1]
				)
				? 1
				: 0;
		}
		return 0;
	}

	public function delete($table, $filter = '', $phValues = null, $order = '', $limit = '')
	{
		if ($table === 'InstallationIpHistory')
			return 0;
		return parent::delete($table, $filter, $phValues, $order, $limit);
	}

	public function replace($table, $fieldsAndValues, $fields = '')
	{
		if ($table === 'Cfg')
		{
			$this->config[(string)$fieldsAndValues['Module'] . '_' . (string)$fieldsAndValues['Prop']] = $fieldsAndValues['Val'];
			return 1;
		}
		return parent::replace($table, $fieldsAndValues, $fields);
	}
}

$expectedApplicationVersion = trim((string)file_get_contents(dirname(__DIR__) . '/VERSION'));
telemetryAssert(Application::NAME === 'H-Script', 'Application name is invalid');
telemetryAssert(Application::version() === $expectedApplicationVersion, 'Application version is invalid');
telemetryAssert(
	CollectorMode::enabled(
		array(
			'telemetry_collector_enabled' => '1',
			'telemetry_collector_domain' => 'h-script.com',
		),
		'www.h-script.com:443'
	),
	'Collector was not enabled on the configured domain'
);
telemetryAssert(
	!CollectorMode::enabled(
		array(
			'telemetry_collector_enabled' => '1',
			'telemetry_collector_domain' => 'h-script.com',
		),
		'customer.example'
	),
	'Collector was enabled on an untrusted domain'
);
telemetryAssert(
	CollectorMode::ingestionEnabled(
		array(
			'telemetry_collector_enabled' => '1',
			'telemetry_ingestion_enabled' => '1',
			'telemetry_collector_domain' => 'h-script.com',
			'demo_mode' => '1',
		),
		'h-script.com',
		true
	),
	'Collector ingestion was not enabled with every required condition'
);
telemetryAssert(
	!CollectorMode::ingestionEnabled(
		array(
			'telemetry_collector_enabled' => '1',
			'telemetry_ingestion_enabled' => '0',
			'telemetry_collector_domain' => 'h-script.com',
		),
		'h-script.com',
		true
	),
	'Collector ingestion ignored its independent flag'
);
foreach (array(
	array(
		'config' => array('telemetry_collector_enabled' => '0', 'telemetry_ingestion_enabled' => '1', 'telemetry_collector_domain' => 'h-script.com'),
		'domain' => 'h-script.com',
		'schema_ready' => true,
	),
	array(
		'config' => array('telemetry_collector_enabled' => '1', 'telemetry_ingestion_enabled' => '1', 'telemetry_collector_domain' => 'h-script.com'),
		'domain' => 'other.example.org',
		'schema_ready' => true,
	),
	array(
		'config' => array('telemetry_collector_enabled' => '1', 'telemetry_ingestion_enabled' => '1', 'telemetry_collector_domain' => 'h-script.com'),
		'domain' => 'h-script.com',
		'schema_ready' => false,
	),
) as $disabledIngestion)
	telemetryAssert(
		!CollectorMode::ingestionEnabled($disabledIngestion['config'], $disabledIngestion['domain'], $disabledIngestion['schema_ready']),
		'Collector ingestion started without every required condition'
	);

$validator = new TelemetryPayloadValidator();
$validationNow = time();
$validRegistration = array(
	'schema_version' => TelemetryPayloadValidator::SCHEMA_VERSION,
	'installation_id' => '123e4567-e89b-42d3-a456-426614174099',
	'domain' => 'telemetry.example.org',
	'version' => Application::version(),
	'installed_at' => $validationNow - 3600,
	'stats_consent' => false,
);
telemetryAssert(
	$validator->registration($validRegistration, $validationNow)['domain'] === 'telemetry.example.org',
	'Valid registration payload was rejected'
);
$unknownFieldRejected = false;
try { $validator->registration(array_merge($validRegistration, array('ip' => '203.0.113.1')), $validationNow); }
catch (TelemetryValidationException $exception) { $unknownFieldRejected = $exception->errorCode() === 'field_unknown'; }
telemetryAssert($unknownFieldRejected, 'Client-supplied IP field was accepted');
$wrongTypeRejected = false;
try { $validator->registration(array_replace($validRegistration, array('stats_consent' => 1)), $validationNow); }
catch (TelemetryValidationException $exception) { $wrongTypeRejected = $exception->errorCode() === 'field_type_invalid'; }
telemetryAssert($wrongTypeRejected, 'Non-boolean consent was accepted');

$publicStats = PublicStats::fromDepositStats(array(
	'worked' => 12,
	'users' => 25,
	'usersonline' => 3,
	'zin2' => array('USD' => 100.5, 'BTC' => 0.25),
	'zout2' => array('USD' => 40),
	'zin' => 100.5,
	'zout' => 40,
	'zref' => 2,
	'zreinv' => 7,
	'deps' => 8,
	'depsclosed' => 4,
	'lastuser' => array('uLogin' => 'must-not-leak'),
	'lastinop' => array('uLogin' => 'must-not-leak'),
), array(1 => array('cCurrID' => 'USD')));
$validReport = array(
	'schema_version' => TelemetryPayloadValidator::SCHEMA_VERSION,
	'installation_id' => $validRegistration['installation_id'],
	'domain' => $validRegistration['domain'],
	'version' => Application::version(),
	'reported_at' => $validationNow,
	'sequence' => intdiv($validationNow, 86400),
	'stats_consent' => true,
	'public_stats' => $publicStats,
);
telemetryAssert($validator->report($validReport, $validationNow)['public_stats']['users_total'] === 25, 'Valid report payload was rejected');
foreach (array(
	array('payload' => array_replace($validReport, array('schema_version' => 2)), 'code' => 'field_range_invalid'),
	array('payload' => array_replace($validReport, array('reported_at' => $validationNow - 259200, 'sequence' => intdiv($validationNow - 259200, 86400))), 'code' => 'field_range_invalid'),
	array('payload' => array_replace($validReport, array('public_stats' => array_replace($publicStats, array('cash_in_base' => -1)))), 'code' => 'field_range_invalid'),
	array('payload' => array_replace($validReport, array('public_stats' => array_replace($publicStats, array('cash_in_base' => NAN)))), 'code' => 'field_type_invalid'),
	array('payload' => array_replace($validReport, array('public_stats' => array_replace($publicStats, array('users_total' => 1000000001)))), 'code' => 'field_range_invalid'),
) as $invalidCase)
{
	$rejected = false;
	try { $validator->report($invalidCase['payload'], $validationNow); }
	catch (TelemetryValidationException $exception) { $rejected = $exception->errorCode() === $invalidCase['code']; }
	telemetryAssert($rejected, 'Invalid report payload was accepted');
}

$dnsQuery = static function (string $name, int $type, float $timeout): array {
	if ($name === 'telemetry.example.org')
		return array('records' => array(array('name' => $name, 'type' => 'CNAME', 'target' => 'origin.example.org', 'ttl' => 120)), 'error' => '');
	if ($name === 'origin.example.org' && $type === 1)
		return array('records' => array(array('name' => $name, 'type' => 'A', 'address' => '93.184.216.34', 'ttl' => 120)), 'error' => '');
	return array('records' => array(), 'error' => 'no_records');
};
$passiveDns = new PassiveDnsResolver($dnsQuery, 1.0);
$matchedDns = $passiveDns->resolve('telemetry.example.org', '93.184.216.34');
telemetryAssert($matchedDns['status'] === 'matched', 'CNAME to matching public A record was not matched');
telemetryAssert($matchedDns['expires_at'] - $matchedDns['checked_at'] === 3600, 'DNS cache minimum was not enforced');
telemetryAssert(
	$passiveDns->resolve('telemetry.example.org', '8.8.8.8')['status'] === 'mismatch',
	'DNS mismatch was not classified'
);
telemetryAssert(
	$passiveDns->resolve('invalid.local', '8.8.8.8')['status'] === 'invalid',
	'Invalid DNS name was not rejected before lookup'
);

$reporterDb = new TelemetryFakeConnection();
$reporterClient = new TelemetryFakeClient();
$reporter = new TelemetryReporter($reporterDb, array(
	'Telemetry_SharePublicStats' => 1,
	'Telemetry_Registered' => 0,
), 'reporter.example.org', $reporterClient);
telemetryAssert($reporter->register()['ok'], 'Mandatory registration failed');
$registrationRequest = $reporterClient->requests[0];
telemetryAssert($registrationRequest['path'] === 'register', 'Reporter used the wrong registration route');
telemetryAssert(!array_key_exists('ip', $registrationRequest['payload']), 'Reporter submitted a client IP');
telemetryAssert($registrationRequest['payload']['schema_version'] === 1, 'Reporter omitted contract schema version');
telemetryAssert(preg_match('/^hsi_[a-f0-9]{64}$/', $registrationRequest['token']) === 1, 'Reporter token was not generated');
telemetryAssert(isset($reporterDb->config['Telemetry_InstallationID'], $reporterDb->config['Telemetry_Token']), 'Identity was not persisted before registration');
telemetryAssert(($reporterDb->config['Telemetry_DnsStatus'] ?? '') === 'matched', 'Reporter did not persist DNS status');
$reporter->report($publicStats);
$reporter->report($publicStats);
$dailyRequests = array_values(array_filter($reporterClient->requests, static fn(array $request): bool => $request['path'] === 'report'));
$firstDailyPayload = $dailyRequests[0]['payload'];
$secondDailyPayload = $dailyRequests[1]['payload'];
telemetryAssert($firstDailyPayload === $secondDailyPayload, 'Daily retry payload is not idempotent');
telemetryAssert(isset($firstDailyPayload['domain'], $firstDailyPayload['sequence']), 'Daily report lacks domain or sequence');

$demoClient = new TelemetryFakeClient();
$demoReporter = new TelemetryReporter(new TelemetryFakeConnection(), array(
	'Telemetry_SharePublicStats' => 1,
	'Telemetry_Registered' => 1,
	'demo_mode' => '1',
), 'demo.example.org', $demoClient);
$demoReporter->report($publicStats);
telemetryAssert($demoClient->requests[0]['payload']['stats_consent'] === false, 'Demo aggregates were not isolated');
telemetryAssert(!isset($demoClient->requests[0]['payload']['public_stats']), 'Demo aggregates entered an external report');
$offlineDb = new TelemetryFakeConnection();
$offlineResult = (new TelemetryReporter($offlineDb, array(), 'offline.example.org', new TelemetryFakeClient(false)))->register();
telemetryAssert(!$offlineResult['ok'], 'Unavailable collector was reported as available');
telemetryAssert(isset($offlineDb->config['Telemetry_InstallationID'], $offlineDb->config['Telemetry_Token']), 'Fail-open registration did not persist identity');
telemetryAssert((int)($offlineDb->config['Telemetry_NextAttemptAt'] ?? 0) > time(), 'Fail-open registration did not schedule a retry');
$telemetryRoot = dirname(__DIR__);
$telemetryCron = (string)file_get_contents($telemetryRoot . '/module/telemetry/oncron.php');
$telemetryRoutes = (string)file_get_contents($telemetryRoot . '/module/_config.php');
$telemetryInstaller = (string)file_get_contents($telemetryRoot . '/module/_config/install.php');
$telemetrySettings = (string)file_get_contents($telemetryRoot . '/module/system/admin/setup_telemetry.php');
telemetryAssert(str_contains($telemetryRoutes, "'telemetry' => 1440") && str_contains($telemetryCron, '$telemetryReporter->report('), 'Daily cron retry is not wired');
telemetryAssert(str_contains($telemetryInstaller, "['Telemetry_Enabled'] = 1") && str_contains($telemetrySettings, "['Telemetry_Enabled'] = 1"), 'Mandatory general telemetry can be disabled');
telemetryAssert(!str_contains($telemetrySettings, 'telemetryEnabled'), 'Telemetry settings expose a general telemetry off switch');
telemetryAssert(
	!CollectorMode::enabled(
		array(
			'telemetry_collector_enabled' => '0',
			'telemetry_collector_domain' => 'h-script.com',
		),
		'h-script.com'
	),
	'Collector ignored the disabled flag'
);

$listQuery = InstallationListQuery::fromArray(array(
	'page' => '2',
	'per_page' => '100',
	'q' => 'HTTPS://WWW.Example.COM:443/path',
	'version' => '1.0.4-rc.1',
	'connection' => 'active',
	'sharing' => 'enabled',
	'dns_status' => 'matched',
	'ip' => '2001:0db8:0:0:0:0:0:1',
));
telemetryAssert($listQuery->page() === 2 && $listQuery->perPage() === 100, 'Valid list pagination was rejected');
telemetryAssert($listQuery->search() === 'www.example.com', 'Search domain was not normalized');
telemetryAssert($listQuery->ip() === '2001:db8::1', 'Exact IPv6 filter was not normalized');
telemetryAssert(
	$listQuery->queryParameters(3)['page'] === 3 && $listQuery->queryParameters(3)['connection'] === 'active',
	'Pagination link parameters did not preserve active filters'
);
foreach (array(
	array('per_page' => '75'),
	array('page' => '0'),
	array('q' => 'example.org/path'),
	array('version' => 'latest'),
	array('connection' => 'recent'),
	array('sharing' => 'yes'),
	array('dns_status' => 'unknown'),
	array('ip' => '999.1.1.1'),
) as $invalidListInput)
{
	$listInputRejected = false;
	try { InstallationListQuery::fromArray($invalidListInput); }
	catch (InvalidArgumentException) { $listInputRejected = true; }
	telemetryAssert($listInputRejected, 'Invalid installation list query was accepted');
}
$tokenListQuery = ServiceTokenListQuery::fromArray(array(
	'token_page' => '2',
	'token_per_page' => '50',
	'token_q' => ' Ops_% ',
	'token_status' => 'paused',
));
telemetryAssert($tokenListQuery->page() === 2 && $tokenListQuery->perPage() === 50, 'Valid token pagination was rejected');
telemetryAssert($tokenListQuery->search() === 'ops_%', 'Token search was not normalized');
telemetryAssert($tokenListQuery->queryParameters(4)['token_page'] === 4, 'Token filters were not preserved in pagination');
foreach (array(
	array('token_page' => '0'),
	array('token_per_page' => '10'),
	array('token_status' => 'disabled'),
	array('token_q' => "bad\nquery"),
) as $invalidTokenListInput)
{
	$tokenListInputRejected = false;
	try { ServiceTokenListQuery::fromArray($invalidTokenListInput); }
	catch (InvalidArgumentException) { $tokenListInputRejected = true; }
	telemetryAssert($tokenListInputRejected, 'Invalid service-token list query was accepted');
}

telemetryAssert($publicStats['users_total'] === 25, 'Public user count is invalid');
telemetryAssert($publicStats['cash_in_by_currency']['BTC'] === 0.25, 'Currency aggregate is invalid');
telemetryAssert(!isset($publicStats['lastuser']), 'Last user leaked into telemetry');
telemetryAssert(!isset($publicStats['lastinop']), 'Last operation leaked into telemetry');

$db = new TelemetryFakeConnection();
$dns = new TelemetryFakeDnsResolver();
$repository = new InstallationRepository($db, $dns);
$token = 'hsi_' . str_repeat('a', 64);
$installation = array(
	'installation_id' => '123e4567-e89b-42d3-a456-426614174000',
	'domain' => 'example.com',
	'version' => Application::version(),
	'installed_at' => time() - 3600,
	'stats_consent' => true,
);

telemetryAssert($repository->register($installation, $token, '192.0.2.10')['state'] === 'created', 'Installation was not created');
$stored = $db->installations[$installation['installation_id']];
telemetryAssert($stored['tiTokenHash'] === hash('sha256', $token), 'Installation token is not hashed');
telemetryAssert(!in_array($token, $stored, true), 'Plain installation token was persisted');
telemetryAssert($repository->register($installation, $token, '192.0.2.11')['state'] === 'updated', 'Idempotent registration failed');
telemetryAssert($dns->queries === 2, 'DNS state was not refreshed after the source IP changed');
telemetryAssert(
	$repository->register($installation, 'hsi_' . str_repeat('b', 64), '192.0.2.12')['state'] === 'identity_conflict',
	'Identity takeover was accepted'
);

$reportedAt = time();
$report = array(
	'schema_version' => TelemetryPayloadValidator::SCHEMA_VERSION,
	'installation_id' => $installation['installation_id'],
	'domain' => $installation['domain'],
	'version' => Application::version(),
	'reported_at' => $reportedAt,
	'sequence' => intdiv($reportedAt, 86400),
	'stats_consent' => true,
	'public_stats' => $publicStats,
);
telemetryAssert(
	$repository->report($report, $token, '192.0.2.10')['state'] === 'accepted',
	'Daily report was rejected'
);
telemetryAssert(
	$repository->report($report, $token, '192.0.2.10')['state'] === 'duplicate',
	'Idempotent daily report was not accepted'
);
$replayReport = array_replace($report, array(
	'reported_at' => $report['reported_at'] - 86400,
	'sequence' => $report['sequence'] - 1,
));
telemetryAssert(
	$repository->report($replayReport, $token, '192.0.2.10')['state'] === 'replay_rejected',
	'Older report sequence was accepted'
);
$wrongDomainReport = array_replace($report, array('domain' => 'changed.example.org'));
telemetryAssert(
	$repository->report($wrongDomainReport, $token, '192.0.2.10')['state'] === 'domain_registration_required',
	'Domain changed without registration was accepted'
);
$dashboard = $repository->dashboard();
telemetryAssert($dashboard['summary']['installations_total'] === 1, 'Installation summary is invalid');
telemetryAssert($dashboard['public_stats']['users_total'] === 25, 'Public statistics were not aggregated');
telemetryAssert(!array_key_exists('last_ip', $dashboard['installations'][0]), 'Sensitive source IP leaked from the default dashboard response');
telemetryAssert(isset($repository->dashboard(true)['installations'][0]['last_ip']), 'Protected collector dashboard omitted source IP');
telemetryAssert($dashboard['installations'][0]['outdated'] === false, 'Current installation marked outdated');
telemetryAssert($dashboard['installations'][0]['report_day'] === gmdate('Y-m-d'), 'Latest report day is missing');
telemetryAssert(
	$dashboard['installations'][0]['public_stats']['users_total'] === 25,
	'Per-installation public statistics are missing'
);
telemetryAssert(
	$dashboard['installations'][0]['public_stats']['worked_days'] === 0,
	'Worked days were not derived from the installation date'
);
telemetryAssert(
	$dashboard['installations'][0]['public_stats']['cash_in_by_currency']['BTC'] === 0.25,
	'Per-installation top-up totals are missing'
);

$metrics = $repository->publicMetrics();
telemetryAssert($metrics['processed'] === 100.5, 'Processed amount is invalid');
telemetryAssert($metrics['processed_currency'] === 'USD', 'Processed currency is invalid');
telemetryAssert($metrics['platforms'] === 1, 'Public platform count is invalid');

$duplicateInstallation = array_replace($installation, array(
	'installation_id' => '123e4567-e89b-42d3-a456-426614174001',
	'domain' => 'www.example.com',
));
$duplicateToken = 'hsi_' . str_repeat('c', 64);
telemetryAssert(
	$repository->register($duplicateInstallation, $duplicateToken, '192.0.2.20')['state'] === 'created',
	'Duplicate-domain installation was not recorded for audit'
);
$inflatedStats = array_replace($publicStats, array(
	'cash_in_base' => 9999999,
	'cash_in_by_currency' => array('USD' => 9999999),
));
telemetryAssert(
	$repository->report(array_replace($report, array(
		'installation_id' => $duplicateInstallation['installation_id'],
		'domain' => $duplicateInstallation['domain'],
		'public_stats' => $inflatedStats,
	)), $duplicateToken, '192.0.2.20')['state'] === 'accepted',
	'Duplicate-domain report was not retained for audit'
);
$deduplicatedDashboard = $repository->dashboard();
telemetryAssert(
	$deduplicatedDashboard['summary']['installations_total'] === 2,
	'Duplicate-domain audit row is missing'
);
telemetryAssert(
	$deduplicatedDashboard['summary']['platforms_total'] === 1,
	'Duplicate domain inflated the public platform count'
);
telemetryAssert(
	$repository->publicMetrics()['processed'] === 100.5,
	'Duplicate domain inflated the public processed amount'
);

$localInstallation = array_replace($installation, array(
	'installation_id' => '123e4567-e89b-42d3-a456-426614174002',
	'domain' => 'hs.local',
));
$localToken = 'hsi_' . str_repeat('d', 64);
telemetryAssert(
	$repository->register($localInstallation, $localToken, '127.0.0.1')['state'] === 'created',
	'Local installation was not retained for diagnostics'
);
telemetryAssert(
	$repository->report(array_replace($report, array(
		'installation_id' => $localInstallation['installation_id'],
		'domain' => $localInstallation['domain'],
		'public_stats' => $inflatedStats,
	)), $localToken, '127.0.0.1')['state'] === 'accepted',
	'Local installation report was not retained for diagnostics'
);
$localDashboard = $repository->dashboard();
telemetryAssert(
	$localDashboard['summary']['installations_total'] === 3,
	'Local installation audit row is missing'
);
telemetryAssert(
	$localDashboard['summary']['platforms_total'] === 1,
	'Local development domain inflated the public platform count'
);
telemetryAssert(
	$repository->publicMetrics()['processed'] === 100.5,
	'Local development domain inflated the public processed amount'
);

foreach (array('mismatch' => 'e', 'unresolved' => 'f', 'invalid' => '1') as $status => $tokenCharacter)
{
	$statusInstallation = array_replace($installation, array(
		'installation_id' => sprintf('123e4567-e89b-42d3-a456-4266141741%02d', count($db->installations)),
		'domain' => $status . '.example.org',
	));
	$statusToken = 'hsi_' . str_repeat($tokenCharacter, 64);
	telemetryAssert($repository->register($statusInstallation, $statusToken, '198.51.100.10')['dns_status'] === $status, 'DNS status was not retained');
	telemetryAssert(
		$repository->report(array_replace($report, array(
			'installation_id' => $statusInstallation['installation_id'],
			'domain' => $statusInstallation['domain'],
		)), $statusToken, '198.51.100.10')['state'] === 'accepted',
		'Non-matched heartbeat was not retained'
	);
}
telemetryAssert($repository->publicMetrics()['processed'] === 100.5, 'Non-matched DNS status entered public totals');

$serviceTokenRepository = new TelemetryServiceTokenRepository($db);
$lowerLevelRejected = false;
try
{
	$serviceTokenRepository->issue(90, 'Must not be issued');
}
catch (InvalidArgumentException)
{
	$lowerLevelRejected = true;
}
telemetryAssert($lowerLevelRejected, 'Level-90 administrator issued a collector service token');

$mainAdministratorRejected = false;
try
{
	$serviceTokenRepository->issue(99, 'Must be attached to collector');
}
catch (InvalidArgumentException)
{
	$mainAdministratorRejected = true;
}
telemetryAssert(
	$mainAdministratorRejected,
	'Level-99 administrator issued a service token without a collector owner'
);

$collectorServiceToken = $serviceTokenRepository->issue(10, 'Collector self-service', time() + 3600);
telemetryAssert(
	preg_match('/^hst_[a-f0-9]{64}$/', $collectorServiceToken['token']) === 1,
	'Level-10 collector could not issue a service token'
);
$reissuedServiceToken = $serviceTokenRepository->reissue(
	$collectorServiceToken['id'],
	10,
	'Collector replacement',
	time() + 7200
);
telemetryAssert(
	$db->serviceTokens[$collectorServiceToken['id']]['tstState'] === 0,
	'Reissued collector token was not revoked'
);
telemetryAssert(
	$db->serviceTokens[$reissuedServiceToken['id']]['tstuID'] === 10,
	'Reissued collector token changed owner'
);

$issuedServiceToken = $serviceTokenRepository->issue(10, 'Monitoring service', time() + 3600);
telemetryAssert(
	preg_match('/^hst_[a-f0-9]{64}$/', $issuedServiceToken['token']) === 1,
	'Service token format is invalid'
);
$storedServiceToken = $db->serviceTokens[$issuedServiceToken['id']];
telemetryAssert(
	$storedServiceToken['tstTokenHash'] === hash('sha256', $issuedServiceToken['token']),
	'Service token is not hashed'
);
telemetryAssert(
	!in_array($issuedServiceToken['token'], $storedServiceToken, true),
	'Plain service token was persisted'
);
$authenticatedServiceToken = $serviceTokenRepository->authenticate(
	$issuedServiceToken['token'],
	'192.0.2.20'
);
telemetryAssert(
	$authenticatedServiceToken['scope'] === TelemetryServiceTokenRepository::SCOPE,
	'Service token scope is invalid'
);
telemetryAssert(
	$db->serviceTokens[$issuedServiceToken['id']]['tstLastIP'] === '192.0.2.20',
	'Service token usage was not audited'
);
telemetryAssert(
	$serviceTokenRepository->revoke($issuedServiceToken['id']),
	'Service token was not revoked'
);
telemetryAssert(
	$serviceTokenRepository->authenticate($issuedServiceToken['token'], '192.0.2.20') === null,
	'Revoked service token was accepted'
);
$serviceTokenPage = $serviceTokenRepository->listPage();
telemetryAssert($serviceTokenPage['pagination']['total'] === 3, 'Service-token page total is invalid');
telemetryAssert(count($serviceTokenPage['tokens']) === 3, 'Service-token page did not return its bounded rows');
telemetryAssert($serviceTokenPage['summary']['active'] === 1 && $serviceTokenPage['summary']['revoked'] === 2, 'Service-token aggregate status counts are invalid');
telemetryAssert(!array_key_exists('tstTokenHash', $serviceTokenPage['tokens'][0]), 'Service-token hash leaked into the list projection');
telemetryAssert($serviceTokenRepository->ownerSummaries()[10]['token_total'] === 3, 'Owner token summary was not aggregated');

echo "Telemetry component tests passed.\n";
