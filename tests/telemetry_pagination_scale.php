<?php

declare(strict_types=1);

use HScript\Database\Connection;
use HScript\Telemetry\InstallationListQuery;
use HScript\Telemetry\InstallationRepository;
use HScript\Telemetry\ServiceTokenListQuery;
use HScript\Telemetry\TelemetryServiceTokenRepository;

require dirname(__DIR__) . '/vendor/autoload.php';

function telemetryScaleAssert(bool $condition, string $message): void
{
	if (!$condition)
		throw new RuntimeException($message);
}

/**
 * Simulates million-row collector tables while materializing only database
 * result sets. This makes query-count and PHP-memory regressions deterministic.
 */
final class TelemetryScaleConnection extends Connection
{
	public int $queryCount = 0;
	public int $largestResult = 0;

	public function __construct(
		private int $installationCount = 1000000,
		private int $tokenCount = 1000000
	) {}

	public function query($query, $values = null)
	{
		$this->queryCount++;
		$sql = ltrim((string)$query);
		$result = match (true) {
			str_starts_with($sql, 'SELECT COUNT(*) FROM Installations i WHERE') =>
				array('direct_row' => array('installation_count' => $this->installationCount)),
			str_starts_with($sql, 'SELECT i.tiID,') => $this->installationPage($sql),
			str_starts_with($sql, 'SELECT tihInstallationID') => $this->ipHistory($values),
			str_starts_with($sql, 'SELECT c.tiID, c.tiDomain') => $this->domainOwners($values),
			str_starts_with($sql, 'SELECT COUNT(*) AS installations_total') => array('direct_row' => array(
				'installations_total' => $this->installationCount,
				'installations_active_24h' => $this->installationCount,
			)),
			str_starts_with($sql, 'SELECT COUNT(DISTINCT') =>
				array('direct_row' => array('platforms_total' => $this->installationCount)),
			str_starts_with($sql, 'SELECT tiVersion AS version') =>
				array(array('version' => '1.0.4', 'installation_count' => $this->installationCount)),
			str_starts_with($sql, 'SELECT COUNT(*) AS installations_sharing') => array('direct_row' => array(
				'installations_sharing' => 0,
				'worked_days' => 0,
				'users_total' => 0,
				'users_online' => 0,
				'active_deposits' => 0,
				'closed_deposits' => 0,
			)),
			str_starts_with($sql, 'SELECT UPPER(JSON_UNQUOTE'),
			str_starts_with($sql, 'SELECT currency_rows.currency') => array(),
			str_starts_with($sql, 'SELECT COUNT(*) FROM TelemetryServiceTokens t') =>
				array('direct_row' => array('token_count' => $this->tokenCount)),
			str_starts_with($sql, 'SELECT t.tstID,') => $this->tokenPage($sql),
			str_starts_with($sql, 'SELECT COUNT(*) AS total,') => array('direct_row' => array(
				'total' => $this->tokenCount,
				'active' => $this->tokenCount,
				'paused' => 0,
				'expired' => 0,
				'revoked' => 0,
			)),
			str_starts_with($sql, 'SELECT tstuID,') => array(array(
				'tstuID' => 10,
				'token_total' => $this->tokenCount,
				'token_active' => $this->tokenCount,
				'token_paused' => 0,
				'token_revoked' => 0,
				'last_created_at' => time(),
				'last_used_at' => time(),
			)),
			default => throw new RuntimeException('Unexpected scale-test query: ' . substr($sql, 0, 100)),
		};
		$rows = isset($result['direct_row']) ? 1 : count($result);
		$this->largestResult = max($this->largestResult, $rows);
		return $result;
	}

	public function fetch1Row($query)
	{
		return is_array($query) && isset($query['direct_row']) ? $query['direct_row'] : array();
	}

	public function fetchRows($query, $singleField = false)
	{
		return is_array($query) ? array_values($query) : array();
	}

	private function installationPage(string $sql): array
	{
		[$offset, $limit] = $this->limit($sql);
		$rows = array();
		for ($index = 0; $index < min($limit, max(0, $this->installationCount - $offset)); $index++)
		{
			$id = $this->installationCount - $offset - $index;
			$rows[] = array(
				'tiID' => $id,
				'tiPublicID' => sprintf('00000000-0000-4000-8000-%012d', $id),
				'tiDomain' => 'site' . $id . '.com',
				'tiVersion' => '1.0.4',
				'tiInstalledAt' => time() - 86400,
				'tiRegisteredAt' => $id,
				'tiLastSeenAt' => time(),
				'tiLastReportAt' => time(),
				'tiStatsConsent' => 0,
				'tiDnsStatus' => 'matched',
				'tiDnsCheckedAt' => time(),
				'tiDnsErrorCode' => '',
				'tiDataSource' => 'external-self-reported',
				'tiLastIP' => '192.0.2.' . (($id % 250) + 1),
				'tiDnsAddresses' => '[]',
				'tirDay' => gmdate('Y-m-d'),
				'tirStats' => null,
				'tirDataSource' => 'self-reported',
			);
		}
		return $rows;
	}

	private function ipHistory(mixed $values): array
	{
		$ids = is_array($values) && is_array($values[0] ?? null) ? $values[0] : array();
		return array_map(static fn(int $id): array => array(
			'tihInstallationID' => $id,
			'tihIP' => '192.0.2.' . (($id % 250) + 1),
			'tihFirstSeenAt' => time() - 60,
			'tihLastSeenAt' => time(),
			'tihRequestCount' => 2,
		), $ids);
	}

	private function domainOwners(mixed $values): array
	{
		$domains = is_array($values) && is_array($values[0] ?? null) ? $values[0] : array();
		$rows = array();
		foreach ($domains as $domain)
			if (preg_match('/^site([0-9]+)\.com$/', (string)$domain, $match))
				$rows[] = array('tiID' => (int)$match[1], 'tiDomain' => $domain, 'tiRegisteredAt' => (int)$match[1]);
		return $rows;
	}

	private function tokenPage(string $sql): array
	{
		[$offset, $limit] = $this->limit($sql);
		$rows = array();
		for ($index = 0; $index < min($limit, max(0, $this->tokenCount - $offset)); $index++)
		{
			$id = $this->tokenCount - $offset - $index;
			$rows[] = array(
				'tstID' => $id,
				'tstuID' => 10,
				'tstName' => 'reader-' . $id,
				'tstTokenPrefix' => 'hst_prefix',
				'tstScope' => TelemetryServiceTokenRepository::SCOPE,
				'tstState' => 1,
				'tstCreatedAt' => time(),
				'tstExpiresAt' => 0,
				'tstLastUsedAt' => 0,
				'tstLastIP' => '',
				'uLogin' => 'collector',
			);
		}
		return $rows;
	}

	/** @return array{0:int,1:int} */
	private function limit(string $sql): array
	{
		if (!preg_match('/\bLIMIT ([0-9]+), ([0-9]+)\s*$/', $sql, $match))
			throw new RuntimeException('Bounded page query has no explicit LIMIT');
		$limit = (int)$match[2];
		if ($limit < 1 || $limit > 100)
			throw new RuntimeException('Page query exceeded the 100-row bound');
		return array((int)$match[1], $limit);
	}
}

$database = new TelemetryScaleConnection();
$startMemory = memory_get_usage(false);
$installations = (new InstallationRepository($database))->dashboard(
	InstallationListQuery::fromArray(array('page' => 10000, 'per_page' => 100)),
	true
);
$installationMemory = memory_get_usage(false) - $startMemory;

telemetryScaleAssert($database->queryCount === 10, 'Installation dashboard query count is not constant');
telemetryScaleAssert($database->largestResult <= 100, 'Installation dashboard materialized more than one page');
telemetryScaleAssert(count($installations['installations']) === 100, 'Million-row installation page is not bounded');
telemetryScaleAssert($installations['pagination']['total'] === 1000000, 'Million-row installation total is invalid');
telemetryScaleAssert($installations['pagination']['page'] === 10000, 'Million-row installation page is unstable');
telemetryScaleAssert($installationMemory < 8 * 1024 * 1024, 'Installation page exceeded the 8 MiB PHP memory budget');

$tokenStartQueries = $database->queryCount;
$tokenStartMemory = memory_get_usage(false);
$tokenRepository = new TelemetryServiceTokenRepository($database);
$tokens = $tokenRepository->listPage(ServiceTokenListQuery::fromArray(array(
	'token_page' => 10000,
	'token_per_page' => 100,
)));
$owners = $tokenRepository->ownerSummaries();
$tokenMemory = memory_get_usage(false) - $tokenStartMemory;

telemetryScaleAssert($database->queryCount - $tokenStartQueries === 4, 'Service-token query count is not constant');
telemetryScaleAssert(count($tokens['tokens']) === 100, 'Million-row service-token page is not bounded');
telemetryScaleAssert($tokens['pagination']['total'] === 1000000, 'Million-row service-token total is invalid');
telemetryScaleAssert(!array_key_exists('tstTokenHash', $tokens['tokens'][0]), 'Service-token hash leaked from the page');
telemetryScaleAssert((int)$owners[10]['token_total'] === 1000000, 'Owner token aggregate is invalid');
telemetryScaleAssert($tokenMemory < 8 * 1024 * 1024, 'Service-token page exceeded the 8 MiB PHP memory budget');

echo "Telemetry pagination scale tests passed.\n";
