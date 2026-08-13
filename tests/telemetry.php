<?php

use HScript\Application;
use HScript\Database\Connection;
use HScript\Telemetry\CollectorMode;
use HScript\Telemetry\InstallationRepository;
use HScript\Telemetry\PublicStats;
use HScript\Telemetry\TelemetryServiceTokenRepository;

require dirname(__DIR__) . '/vendor/autoload.php';

function telemetryAssert(bool $condition, string $message): void
{
	if (!$condition)
		throw new RuntimeException($message);
}

final class TelemetryFakeConnection extends Connection
{
	public array $installations = array();
	public array $reports = array();
	public array $serviceTokens = array();
	public array $users = array(
		99 => array('uID' => 99, 'uLevel' => 99, 'uState' => 1, 'uLogin' => 'root'),
		90 => array('uID' => 90, 'uLevel' => 90, 'uState' => 1, 'uLogin' => 'demo-admin'),
		10 => array('uID' => 10, 'uLevel' => 10, 'uState' => 1, 'uLogin' => 'collector'),
	);
	private int $nextInstallationId = 1;
	private int $nextServiceTokenId = 1;

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
		if (($query['table'] ?? '') !== 'Installations')
			return array();
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
		if (str_starts_with(ltrim($query), 'INSERT INTO InstallationReports'))
		{
			$key = (int)$values[0] . ':' . (string)$values[1];
			$this->reports[$key] = array(
				'tirInstallationID' => (int)$values[0],
				'tirDay' => (string)$values[1],
				'tirVersion' => (string)$values[2],
				'tirStats' => (string)$values[3],
				'tirCreatedAt' => (int)$values[4],
				'tirUpdatedAt' => (int)$values[5],
			);
			return 1;
		}
		if (str_starts_with(ltrim($query), 'SELECT i.*'))
		{
			$rows = array();
			foreach ($this->installations as $installation)
			{
				if (empty($installation['tiState']))
					continue;
				$latest = array();
				foreach ($this->reports as $report)
					if ((int)$report['tirInstallationID'] === (int)$installation['tiID'])
						$latest = $report;
				$rows[] = array_merge($installation, array(
					'tirDay' => $latest['tirDay'] ?? null,
					'tirStats' => $latest['tirStats'] ?? null,
				));
			}
			return $rows;
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
}

telemetryAssert(Application::NAME === 'H-Script', 'Application name is invalid');
telemetryAssert(Application::VERSION === '1.0.0', 'Application version is invalid');
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
	!CollectorMode::enabled(
		array(
			'telemetry_collector_enabled' => '0',
			'telemetry_collector_domain' => 'h-script.com',
		),
		'h-script.com'
	),
	'Collector ignored the disabled flag'
);

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

telemetryAssert($publicStats['users_total'] === 25, 'Public user count is invalid');
telemetryAssert($publicStats['cash_in_by_currency']['BTC'] === 0.25, 'Currency aggregate is invalid');
telemetryAssert(!isset($publicStats['lastuser']), 'Last user leaked into telemetry');
telemetryAssert(!isset($publicStats['lastinop']), 'Last operation leaked into telemetry');

$db = new TelemetryFakeConnection();
$repository = new InstallationRepository($db);
$token = 'hsi_' . str_repeat('a', 64);
$installation = array(
	'installation_id' => '123e4567-e89b-42d3-a456-426614174000',
	'domain' => 'example.com',
	'version' => '1.0.0',
	'installed_at' => time() - 3600,
	'stats_consent' => true,
);

telemetryAssert($repository->register($installation, $token, '192.0.2.10') === 'created', 'Installation was not created');
$stored = $db->installations[$installation['installation_id']];
telemetryAssert($stored['tiTokenHash'] === hash('sha256', $token), 'Installation token is not hashed');
telemetryAssert(!in_array($token, $stored, true), 'Plain installation token was persisted');
telemetryAssert($repository->register($installation, $token, '192.0.2.11') === 'updated', 'Idempotent registration failed');
telemetryAssert(
	$repository->register($installation, 'hsi_' . str_repeat('b', 64), '192.0.2.12') === 'identity_conflict',
	'Identity takeover was accepted'
);

telemetryAssert(
	$repository->report(
		$installation['installation_id'],
		$token,
		'1.0.0',
		true,
		$publicStats,
		'192.0.2.10'
	),
	'Daily report was rejected'
);
$dashboard = $repository->dashboard();
telemetryAssert($dashboard['summary']['installations_total'] === 1, 'Installation summary is invalid');
telemetryAssert($dashboard['public_stats']['users_total'] === 25, 'Public statistics were not aggregated');
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
	$repository->register($duplicateInstallation, $duplicateToken, '192.0.2.20') === 'created',
	'Duplicate-domain installation was not recorded for audit'
);
$inflatedStats = array_replace($publicStats, array(
	'cash_in_base' => 9999999,
	'cash_in_by_currency' => array('USD' => 9999999),
));
telemetryAssert(
	$repository->report(
		$duplicateInstallation['installation_id'],
		$duplicateToken,
		'1.0.0',
		true,
		$inflatedStats,
		'192.0.2.20'
	),
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
	$repository->register($localInstallation, $localToken, '127.0.0.1') === 'created',
	'Local installation was not retained for diagnostics'
);
telemetryAssert(
	$repository->report(
		$localInstallation['installation_id'],
		$localToken,
		'1.0.0',
		true,
		$inflatedStats,
		'127.0.0.1'
	),
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

echo "Telemetry component tests passed.\n";
