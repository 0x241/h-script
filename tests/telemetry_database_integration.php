<?php

declare(strict_types=1);

use HScript\Database\Connection;
use HScript\Telemetry\CollectorSchema;
use HScript\Telemetry\DnsResolverInterface;
use HScript\Telemetry\InstallationListQuery;
use HScript\Telemetry\InstallationRepository;
use HScript\Telemetry\ServiceTokenListQuery;
use HScript\Telemetry\TelemetryIngestionCounterRepository;
use HScript\Telemetry\TelemetryPayloadValidator;
use HScript\Telemetry\TelemetryServiceTokenRepository;
use HScript\Update\VersionedMigration;

if (getenv('H_SCRIPT_TELEMETRY_DB_TEST') !== '1')
{
	echo "Telemetry database integration test skipped.\n";
	return;
}

$databaseName = (string)(getenv('H_SCRIPT_TELEMETRY_DB_NAME') ?: '');
if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $databaseName))
	throw new RuntimeException('Isolated telemetry test database name is required');

$root = dirname(__DIR__);
$domain = (string)(getenv('APP_DOMAIN') ?: 'hs.local');
$_SERVER += array(
	'SERVER_NAME' => $domain,
	'HTTP_HOST' => $domain,
	'SCRIPT_NAME' => '/tests/telemetry_database_integration.php',
	'SERVER_PORT' => 80,
	'REQUEST_URI' => '/',
	'SERVER_ADDR' => '127.0.0.1',
	'REMOTE_ADDR' => '127.0.0.1',
);
chdir($root);
require $root . '/vendor/autoload.php';
global $_cfg;
$_cfg = array();
require $root . '/_config.php';
$_cfg['db_name'] = $databaseName;
require $root . '/module/dbinit.php';

$assert = static function (bool $condition, string $message): void {
	if (!$condition) throw new RuntimeException($message);
};
if (getenv('H_SCRIPT_TELEMETRY_EMPTY_BASE') !== '1')
{
	$statements = preg_split('/;\s*(?:\r?\n|$)/', (string)file_get_contents($root . '/migrations/20260725_add_installation_telemetry.sql'));
	foreach (is_array($statements) ? $statements : array() as $statement)
		if (trim($statement) !== '')
			$db->query($statement);
}

$migration = VersionedMigration::fromFile($root . '/migrations/versioned/202609101200_telemetry_ingestion.php');
$migration->apply($db);
$migration->apply($db);
$proofMigration = VersionedMigration::fromFile($root . '/migrations/versioned/202609221200_domain_proof.php');
$proofMigration->apply($db);
$proofMigration->apply($db);
$db->query(
	'CREATE TABLE IF NOT EXISTS SchemaState (
	 ssKey varchar(32) not null,
	 ssVersion varchar(32) not null,
	 ssUpdatedAt bigint unsigned default 0,
	 PRIMARY KEY (ssKey)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);
$db->replace('SchemaState', array('ssKey' => 'current', 'ssVersion' => '1.0.0', 'ssUpdatedAt' => time()));
$assert(!CollectorSchema::ready($db), 'Collector schema gate accepted a mismatched schema version');
$db->replace('SchemaState', array('ssKey' => 'current', 'ssVersion' => HScript\Application::schemaVersion(), 'ssUpdatedAt' => time()));
$assert(CollectorSchema::ready($db), 'Collector schema gate rejected the migrated schema');
foreach (array('InstallationIpHistory', 'InstallationDomainEvents', 'TelemetryIngestionCounters') as $table)
	$assert((int)$db->fetch1($db->query(
		'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
		array($table)
	)) === 1, 'Telemetry migration did not create ' . $table);
foreach (array('TIVERSIONLIST', 'TIREPORTSTATE', 'TISTATSCONSENT', 'TIIPLIST') as $index)
	$assert((int)$db->fetch1($db->query(
		'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\'Installations\' AND INDEX_NAME=?',
		array($index)
	)) > 0, 'Telemetry migration did not create list index ' . $index);

$dns = new class implements DnsResolverInterface {
	public function resolve(string $domain, string $observedIp): array
	{
		return array(
			'status' => $domain === 'mismatch.example.org' ? 'mismatch' : 'matched',
			'addresses' => array($domain === 'mismatch.example.org' ? '1.1.1.1' : $observedIp),
			'checked_at' => time(),
			'expires_at' => time() + 3600,
			'error_code' => '',
		);
	}
};
$repository = new InstallationRepository($db, $dns);
$validator = new TelemetryPayloadValidator();
$now = time();
$token = 'hsi_' . str_repeat('e', 64);
$registration = $validator->registration(array(
	'schema_version' => 1,
	'installation_id' => '123e4567-e89b-42d3-a456-426614174010',
	'domain' => 'matched.example.org',
	'version' => '1.0.4',
	'installed_at' => $now - 86400,
	'stats_consent' => true,
), $now);
$registrationResult = $repository->register($registration, $token, '8.8.8.8');
$assert($registrationResult['state'] === 'created', 'Registration failed: ' . json_encode($registrationResult));
$report = $validator->report(array(
	'schema_version' => 1,
	'installation_id' => $registration['installation_id'],
	'domain' => $registration['domain'],
	'version' => '1.0.4',
	'reported_at' => $now,
	'sequence' => intdiv($now, 86400),
	'stats_consent' => true,
	'public_stats' => array(
		'worked_days' => 1,
		'users_total' => 3,
		'users_online' => 1,
		'active_deposits' => 1,
		'closed_deposits' => 0,
		'cash_in_base' => 10.0,
		'cash_out_base' => 0.0,
		'referral_paid_base' => 0.0,
		'reinvested_base' => 0.0,
		'base_currency' => 'USD',
		'cash_in_by_currency' => array('USD' => 10.0),
		'cash_out_by_currency' => array(),
	),
), $now);
$assert($repository->report($report, $token, '2001:4860:4860::8888')['state'] === 'accepted', 'Report failed');
$assert($repository->report($report, $token, '2001:4860:4860::8888')['state'] === 'duplicate', 'Duplicate report was not idempotent');
$internalId = (int)$db->fetch1($db->select('Installations', 'tiID', 'tiPublicID=?', array($registration['installation_id'])));
$db->insert('InstallationIpHistory', array(
	'tihInstallationID' => $internalId,
	'tihIP' => '9.9.9.9',
	'tihFirstSeenAt' => $now - 91 * 86400,
	'tihLastSeenAt' => $now - 91 * 86400,
	'tihRequestCount' => 1,
));
$assert($repository->report($report, $token, '2001:4860:4860::8888')['state'] === 'duplicate', 'Duplicate report cleanup failed');
$assert((int)$db->count('InstallationIpHistory', 'tihIP=?', array('9.9.9.9')) === 0, 'IP history older than 90 days was retained');
for ($octet = 1; $octet <= 24; $octet++)
	$assert($repository->report($report, $token, '11.0.0.' . $octet)['state'] === 'duplicate', 'IP history request failed');
$assert((int)$db->count('InstallationIpHistory', 'tihInstallationID=?d', array($internalId)) === 20, 'IP history was not capped at 20 unique addresses');
$assert((string)$db->fetch1($db->select('Installations', 'tiLastIP', 'tiID=?d', array($internalId))) === '11.0.0.24', 'Duplicate heartbeat did not update the current source IP');
$assert((int)$repository->publicMetrics()['platforms'] === 1, 'Matched installation was excluded from public metrics');
$assert($repository->register(array_replace($registration, array('domain' => 'mismatch.example.org')), $token, '8.8.8.8')['state'] === 'updated', 'Domain re-registration failed');
$assert((int)$repository->publicMetrics()['platforms'] === 0, 'DNS mismatch remained in public metrics');
$assert($db->count('InstallationIpHistory') === 20, 'Normalized IP history retention changed after domain registration');
$assert($db->count('InstallationDomainEvents') === 1, 'Domain change event was not retained');

$transport = new class implements HScript\Telemetry\DomainProofTransport {
	public array $document = array();
	public int $calls = 0;
	public $duringFetch = null;
	public function fetch(string $domain): array {
		$this->calls++;
		if ($this->duringFetch) ($this->duringFetch)();
		return $this->document;
	}
};
$proofService = new HScript\Telemetry\DomainProofService($db, $transport);
$proofId = $registration['installation_id'];
$proofDomain = 'mismatch.example.org';
$assert($proofService->execute('challenge', $proofId, $proofDomain, 'wrong')['state'] === 'invalid_token', 'Proof accepted wrong token');
$assert($proofService->execute('challenge', $proofId, 'other.example.org', $token)['state'] === 'domain_registration_required', 'Proof accepted unregistered domain');
$assert($transport->calls === 0, 'Unauthenticated proof caused network traffic');
$challenge = $proofService->execute('challenge', $proofId, $proofDomain, $token);
$assert($challenge['state'] === 'challenge', 'Challenge was not issued');
$assert($proofService->execute('challenge', $proofId, $proofDomain, $token)['state'] === 'proof_retry_later', 'Active challenge could be overwritten');
$transport->document = array('installation_id' => $proofId, 'domain' => $proofDomain, 'nonce' => str_repeat('0', 64), 'expires_at' => $challenge['expires_at']);
$assert($proofService->execute('verify', $proofId, $proofDomain, $token)['state'] === 'proof_mismatch', 'Wrong nonce accepted');
$assert($proofService->execute('verify', $proofId, $proofDomain, $token)['state'] === 'proof_retry_later' && $transport->calls === 1, 'Network cooldown bypassed');
$db->update('Installations', array('tiDomainProofAttemptAt' => 0), '', 'tiID=?d', array($internalId));
$transport->document['nonce'] = $challenge['nonce'];
$verified = $proofService->execute('verify', $proofId, $proofDomain, $token);
$assert($verified['state'] === 'verified' && $verified['verified_until'] > time(), 'Valid HTTPS proof rejected');
$assert((int)$repository->publicMetrics()['platforms'] === 1, 'HTTPS-verified proxy domain excluded');
$assert($repository->publicMetrics()['processed'] === 10.0, 'Verified domain missing from aggregate SQL');
$proofRow = $repository->dashboard()['installations'][0];
$assert($proofRow['domain_verified'] && $proofRow['counted_in_public_metrics'] && $proofRow['dns_status'] === 'mismatch', 'Proof changed DNS diagnosis or list eligibility');
$assert(!isset($proofRow['tiDomainProofHash'], $proofRow['nonce']), 'Challenge leaked through list');
$assert((string)$db->fetch1($db->select('Installations', 'tiDomainProofHash', 'tiID=?d', array($internalId))) === '', 'Consumed challenge retained');
$proofService->execute('verify', $proofId, $proofDomain, $token);
$assert($transport->calls === 2, 'Verified state triggered replay network call');
$db->update('Installations', array('tiDomainVerifiedUntil' => time() - 1), '', 'tiID=?d', array($internalId));
$assert((int)$repository->publicMetrics()['platforms'] === 0, 'Expired proof still counted');
$challenge = $proofService->execute('challenge', $proofId, $proofDomain, $token);
$transport->document['nonce'] = $challenge['nonce'];
$transport->document['expires_at'] = $challenge['expires_at'];
$db->update('Installations', array('tiDomainProofAttemptAt' => 0), '', 'tiID=?d', array($internalId));
$transport->duringFetch = static function () use ($db, $internalId): void {
	$db->update('Installations', array('tiDomain' => 'changed.example.org'), '', 'tiID=?d', array($internalId));
};
$assert($proofService->execute('verify', $proofId, $proofDomain, $token)['state'] === 'proof_challenge_expired', 'Domain change race verified old domain');
$repository->register(array_replace($registration, array('domain' => $proofDomain)), $token, '8.8.8.8');
$reset = $db->fetch1Row($db->select('Installations', '*', 'tiID=?d', array($internalId)));
$assert((int)$reset['tiDomainVerifiedUntil'] === 0 && $reset['tiDomainProofHash'] === '', 'Domain change did not invalidate proof');
$challenge = $proofService->execute('challenge', $proofId, $proofDomain, $token);
$db->update('Installations', array('tiDomainProofExpiresAt' => time() - 1), '', 'tiID=?d', array($internalId));
$assert($proofService->execute('verify', $proofId, $proofDomain, $token)['state'] === 'proof_challenge_expired', 'Expired challenge accepted');

// Interleave a second registration while the first request resolves DNS.
$db->update('Installations', array('tiDnsExpiresAt' => 0), '', 'tiID=?d', array($internalId));
$racingDns = new class($db, $internalId) implements DnsResolverInterface {
	public function __construct(private Connection $db, private int $id) {}
	public function resolve(string $domain, string $observedIp): array {
		$this->db->update('Installations', array('tiDomain' => 'concurrent.example.org',
			'tiDomainVerifiedAt' => time(), 'tiDomainVerifiedUntil' => time() + 604800), '', 'tiID=?d', array($this->id));
		return array('status' => 'mismatch', 'addresses' => array('1.1.1.1'), 'checked_at' => time(), 'expires_at' => time() + 3600, 'error_code' => '');
	}
};
$raced = (new InstallationRepository($db, $racingDns))->register(array_replace($registration, array('domain' => $proofDomain)), $token, '8.8.8.8');
$assert($raced['state'] === 'identity_conflict', 'Stale registration overwrote concurrently verified domain');
$assert((string)$db->fetch1($db->select('Installations', 'tiDomain', 'tiID=?d', array($internalId))) === 'concurrent.example.org', 'Concurrent domain binding lost');
$repository->register(array_replace($registration, array('domain' => $proofDomain)), $token, '8.8.8.8');
$assert((int)$repository->publicMetrics()['platforms'] === 0, 'Concurrent proof transferred to a different domain');

(new TelemetryIngestionCounterRepository($db))->record('invalid_token');
(new TelemetryIngestionCounterRepository($db))->record('invalid_token');
$assert((int)$db->fetch1($db->select('TelemetryIngestionCounters', 'ticCount', 'ticCode=?', array('invalid_token'))) === 2, 'Rejected request counter is not aggregated');
$db->insert('TelemetryIngestionCounters', array(
	'ticDay' => gmdate('Y-m-d', $now - 91 * 86400),
	'ticCode' => 'expired_counter',
	'ticCount' => 1,
	'ticFirstAt' => $now - 91 * 86400,
	'ticLastAt' => $now - 91 * 86400,
));
(new TelemetryIngestionCounterRepository($db))->record('invalid_token');
$assert($db->count('TelemetryIngestionCounters', 'ticCode=?', array('expired_counter')) === 0, 'Expired rejection counter was retained');

$listNow = time();
$expected = array(
	'version' => array('1.0.3' => 0, '1.0.4' => 1),
	'connection' => array('no_report' => 0, 'active' => 1, 'inactive' => 0),
	'sharing' => array('enabled' => 1, 'disabled' => 0),
	'dns' => array('matched' => 0, 'mismatch' => 1, 'unresolved' => 0, 'invalid' => 0),
	'combined' => 0,
);
$dnsStates = array('matched', 'mismatch', 'unresolved', 'invalid');
for ($number = 1; $number <= 55; $number++)
{
	$version = $number % 2 === 0 ? '1.0.3' : '1.0.4';
	$consent = $number % 2 === 0 ? 1 : 0;
	$dnsStatus = $dnsStates[($number - 1) % count($dnsStates)];
	$hasReport = $number % 3 !== 0;
	$connection = !$hasReport ? 'no_report' : ($number % 4 === 0 ? 'inactive' : 'active');
	$lastSeen = $connection === 'inactive' ? $listNow - 172800 - $number : $listNow - $number;
	if ($number >= 54)
		$lastSeen = $listNow + 10;
	$ip = $number === 7 ? '2001:db8::7' : '11.1.0.' . $number;
	$publicId = sprintf('00000000-0000-4000-8000-%012d', $number);
	$db->insert('Installations', array(
		'tiPublicID' => $publicId,
		'tiDomain' => sprintf('node-%02d.example.org', $number),
		'tiVersion' => $version,
		'tiInstalledAt' => $listNow - 86400,
		'tiRegisteredAt' => $listNow - 3600 + $number,
		'tiLastSeenAt' => $lastSeen,
		'tiLastReportAt' => $hasReport ? $lastSeen : 0,
		'tiLastReportedAt' => $hasReport ? $lastSeen : 0,
		'tiLastSequence' => 0,
		'tiTokenHash' => hash('sha256', 'seed-token-' . $number),
		'tiStatsConsent' => $consent,
		'tiState' => 1,
		'tiLastIP' => $ip,
		'tiDataSource' => 'external-self-reported',
		'tiDnsStatus' => $dnsStatus,
		'tiDnsAddresses' => json_encode(array($ip), JSON_THROW_ON_ERROR),
		'tiDnsCheckedAt' => $listNow - $number,
		'tiDnsExpiresAt' => $listNow + 3600,
		'tiDnsErrorCode' => '',
	));
	$expected['version'][$version]++;
	$expected['connection'][$connection]++;
	$expected['sharing'][$consent ? 'enabled' : 'disabled']++;
	$expected['dns'][$dnsStatus]++;
	if ($version === '1.0.3' && $consent === 1 && $dnsStatus === 'mismatch')
		$expected['combined']++;
}

$firstPage = $repository->dashboard(InstallationListQuery::fromArray(array('per_page' => 25)), false);
$assert($firstPage['pagination']['total'] === 56, 'Installation filtered total is invalid');
$assert($firstPage['summary']['installations_total'] === 56, 'Installation overall total was coupled to the page query');
$assert(count($firstPage['installations']) === 25 && $firstPage['pagination']['total_pages'] === 3, 'Installation page was not bounded to 25 rows');
$assert($firstPage['installations'][0]['id'] === '00000000-0000-4000-8000-000000000055', 'Installation stable sort did not use the internal id as a tie-breaker');
$assert($firstPage['installations'][1]['id'] === '00000000-0000-4000-8000-000000000054', 'Installation stable sort order changed within a last-seen tie');
$assert(count($repository->dashboard(InstallationListQuery::fromArray(array('per_page' => 50)))['installations']) === 50, 'Installation page size 50 failed');
$assert(count($repository->dashboard(InstallationListQuery::fromArray(array('per_page' => 100)))['installations']) === 56, 'Installation page size 100 failed');
$lastPage = $repository->dashboard(InstallationListQuery::fromArray(array('page' => 99, 'per_page' => 25)), false);
$assert($lastPage['pagination']['page'] === 3 && count($lastPage['installations']) === 6, 'Out-of-range installation page was not clamped to the final page');

$domainSearch = $repository->dashboard(InstallationListQuery::fromArray(array('q' => 'HTTPS://NODE-07.EXAMPLE.ORG/path')), true);
$assert($domainSearch['pagination']['total'] === 1 && $domainSearch['installations'][0]['domain'] === 'node-07.example.org', 'Normalized domain search failed');
$assert($domainSearch['installations'][0]['last_ip'] === '2001:db8::7', 'Protected list omitted the last observed IP');
$assert($domainSearch['installations'][0]['dns_addresses'] === array('2001:db8::7'), 'Protected details omitted the DNS snapshot');
$idSearch = $repository->dashboard(InstallationListQuery::fromArray(array('q' => '00000000-0000-4000-8000-000000000055')), false);
$assert($idSearch['pagination']['total'] === 1, 'Installation ID search failed');
$assert(!array_key_exists('last_ip', $idSearch['installations'][0]), 'Unprotected installation list exposed an observed IP');
foreach ($expected['version'] as $value => $count)
	$assert($repository->dashboard(InstallationListQuery::fromArray(array('version' => $value)))['pagination']['total'] === $count, 'Exact version filter failed');
$versionLastPage = $repository->dashboard(InstallationListQuery::fromArray(array('version' => '1.0.4', 'page' => 2, 'per_page' => 25)));
$assert($versionLastPage['pagination']['page'] === 2 && count($versionLastPage['installations']) === $expected['version']['1.0.4'] - 25, 'Filtered installation pagination is invalid');
foreach ($expected['connection'] as $value => $count)
	$assert($repository->dashboard(InstallationListQuery::fromArray(array('connection' => $value)))['pagination']['total'] === $count, 'Connection filter failed: ' . $value);
foreach ($expected['sharing'] as $value => $count)
	$assert($repository->dashboard(InstallationListQuery::fromArray(array('sharing' => $value)))['pagination']['total'] === $count, 'Stats-consent filter failed: ' . $value);
foreach ($expected['dns'] as $value => $count)
	$assert($repository->dashboard(InstallationListQuery::fromArray(array('dns_status' => $value)))['pagination']['total'] === $count, 'DNS-status filter failed: ' . $value);
$assert($repository->dashboard(InstallationListQuery::fromArray(array('ip' => '2001:0db8:0:0:0:0:0:7')))['pagination']['total'] === 1, 'Exact normalized IPv6 filter failed');
$combined = $repository->dashboard(InstallationListQuery::fromArray(array('version' => '1.0.3', 'sharing' => 'enabled', 'dns_status' => 'mismatch')));
$assert($combined['pagination']['total'] === $expected['combined'], 'Combined installation filters returned an invalid total');
$historyDetails = $repository->dashboard(InstallationListQuery::fromArray(array('q' => 'mismatch.example.org')), true);
$assert(count($historyDetails['installations'][0]['ip_history']) === 20, 'Protected installation details omitted bounded IP history');

$db->query(
	'CREATE TABLE IF NOT EXISTS Users (
	 uID INT NOT NULL,
	 uLogin VARCHAR(100) NOT NULL,
	 PRIMARY KEY (uID)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);
$db->query("INSERT INTO Users (uID, uLogin) VALUES (10, 'CollectorAlpha'), (20, 'CollectorBeta') ON DUPLICATE KEY UPDATE uLogin=VALUES(uLogin)");
$tokenExpected = array('active' => 0, 'paused' => 0, 'expired' => 0, 'revoked' => 0);
for ($number = 1; $number <= 58; $number++)
{
	$status = array('paused', 'expired', 'active', 'revoked')[($number - 1) % 4];
	$state = $status === 'revoked' ? 0 : ($status === 'paused' ? 2 : 1);
	$expiresAt = $status === 'expired' ? $listNow - 1 : ($status === 'active' ? $listNow + 86400 : 0);
	$db->insert('TelemetryServiceTokens', array(
		'tstuID' => $number % 2 === 0 ? 10 : 20,
		'tstName' => $number === 7 ? 'Ops_% literal' : 'Monitor ' . $number,
		'tstTokenHash' => hash('sha256', 'service-token-' . $number),
		'tstTokenPrefix' => 'hst_' . str_pad((string)$number, 8, '0', STR_PAD_LEFT),
		'tstScope' => 'telemetry:read',
		'tstState' => $state,
		'tstCreatedAt' => $listNow + $number,
		'tstExpiresAt' => $expiresAt,
		'tstLastUsedAt' => $number % 5 === 0 ? $listNow - $number : 0,
		'tstLastIP' => '',
	));
	$tokenExpected[$status]++;
}
$tokenRepository = new TelemetryServiceTokenRepository($db);
$tokenFirstPage = $tokenRepository->listPage(ServiceTokenListQuery::fromArray(array('token_per_page' => 25)));
$assert($tokenFirstPage['pagination']['total'] === 58 && count($tokenFirstPage['tokens']) === 25, 'Service-token first page was not bounded');
$assert($tokenFirstPage['tokens'][0]['tstName'] === 'Monitor 58', 'Service-token stable sort is invalid');
$assert(!array_key_exists('tstTokenHash', $tokenFirstPage['tokens'][0]), 'Service-token hash leaked into the list projection');
$assert(count($tokenRepository->listPage(ServiceTokenListQuery::fromArray(array('token_per_page' => 50)))['tokens']) === 50, 'Service-token page size 50 failed');
$assert(count($tokenRepository->listPage(ServiceTokenListQuery::fromArray(array('token_per_page' => 100)))['tokens']) === 58, 'Service-token page size 100 failed');
$tokenLastPage = $tokenRepository->listPage(ServiceTokenListQuery::fromArray(array('token_page' => 3, 'token_per_page' => 25)));
$assert(count($tokenLastPage['tokens']) === 8, 'Service-token final page size is invalid');
foreach ($tokenExpected as $status => $count)
{
	$assert($tokenFirstPage['summary'][$status] === $count, 'Service-token aggregate count failed: ' . $status);
	$assert($tokenRepository->listPage(ServiceTokenListQuery::fromArray(array('token_status' => $status)))['pagination']['total'] === $count, 'Service-token effective status filter failed: ' . $status);
}
$literalTokenSearch = $tokenRepository->listPage(ServiceTokenListQuery::fromArray(array('token_q' => 'ops_%')));
$assert($literalTokenSearch['pagination']['total'] === 1 && $literalTokenSearch['tokens'][0]['tstName'] === 'Ops_% literal', 'Service-token wildcard characters were not escaped');
$ownerTokenSearch = $tokenRepository->listPage(ServiceTokenListQuery::fromArray(array('token_q' => 'collectoralpha')));
$assert($ownerTokenSearch['pagination']['total'] === 29, 'Service-token owner search failed');
$ownerSummaries = $tokenRepository->ownerSummaries($listNow);
$assert((int)$ownerSummaries[10]['token_total'] === 29 && (int)$ownerSummaries[20]['token_total'] === 29, 'Service-token owner aggregates are invalid');

echo "Telemetry database integration tests passed.\n";
