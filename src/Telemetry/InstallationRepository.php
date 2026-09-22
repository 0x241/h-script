<?php

namespace HScript\Telemetry;

use HScript\Application;
use HScript\Database\Connection;
use Throwable;

/** Stores strictly authenticated self-reported telemetry and DNS/IP observations. */
final class InstallationRepository
{
	private const IP_HISTORY_DAYS = 90;
	private const IP_HISTORY_LIMIT = 20;
	private const DOMAIN_EVENT_LIMIT = 50;

	private DnsResolverInterface $dns;

	public function __construct(private Connection $db, ?DnsResolverInterface $dns = null)
	{
		$this->dns = $dns ?? new PassiveDnsResolver();
	}

	public function register(array $installation, string $token, string $ip): array
	{
		$id = (string)$installation['installation_id'];
		$tokenHash = self::tokenHash($token);
		$ip = self::normalizeIp($ip);
		$existing = $this->findByPublicId($id);
		$tokenOwner = $this->db->fetch1Row($this->db->select(
			'Installations',
			'tiID, tiPublicID',
			'tiTokenHash=?',
			array($tokenHash),
			'',
			1
		));
		if ($tokenOwner && (string)$tokenOwner['tiPublicID'] !== $id)
			return array('state' => 'token_conflict');

		$now = time();
		$domainChanged = $existing && !hash_equals((string)$existing['tiDomain'], (string)$installation['domain']);
		$ipChanged = $existing && self::normalizeIp((string)($existing['tiLastIP'] ?? '')) !== $ip;
		if ($existing && !hash_equals((string)$existing['tiTokenHash'], $tokenHash))
			return array('state' => 'identity_conflict');

		$dns = $this->dnsState($existing, (string)$installation['domain'], $ip, $domainChanged || $ipChanged, $now);
		$dnsValues = $this->dnsValues($dns);
		if ($domainChanged)
			$dnsValues += array('tiDomainVerifiedAt' => 0, 'tiDomainVerifiedUntil' => 0,
				'tiDomainProofHash' => '', 'tiDomainProofExpiresAt' => 0,
				'tiDomainProofAttemptAt' => 0, 'tiDomainProofError' => '');
		if ($existing)
		{
			$updated = $this->db->update('Installations', array_merge(array(
				'tiDomain' => $installation['domain'],
				'tiVersion' => $installation['version'],
				'tiInstalledAt' => $installation['installed_at'],
				'tiLastSeenAt' => $now,
				'tiStatsConsent' => !empty($installation['stats_consent']) ? 1 : 0,
				'tiState' => 1,
				'tiLastIP' => $ip,
			), $dnsValues), '', 'tiID=?d AND tiDomain=? AND tiTokenHash=?', array($existing['tiID'], $existing['tiDomain'], $tokenHash));
			if ((int)$updated === 0)
			{
				$current = $this->findByPublicId($id);
				// A concurrent domain change must not carry the other domain's proof back.
				if (!$current || !hash_equals((string)$current['tiDomain'], (string)$installation['domain'])
					|| !hash_equals((string)$current['tiTokenHash'], $tokenHash))
					return array('state' => 'identity_conflict');
				return $this->accepted('updated', $dns);
			}
			if ($domainChanged)
				$this->recordDomainChange(
					(int)$existing['tiID'],
					(string)$existing['tiDomain'],
					(string)$installation['domain'],
					$ip,
					$now
				);
			$this->recordIp((int)$existing['tiID'], $ip, $now);
			return $this->accepted('updated', $dns);
		}

		$internalId = (int)$this->db->insert('Installations', array_merge(array(
			'tiPublicID' => $id,
			'tiDomain' => $installation['domain'],
			'tiVersion' => $installation['version'],
			'tiInstalledAt' => $installation['installed_at'],
			'tiRegisteredAt' => $now,
			'tiLastSeenAt' => $now,
			'tiLastReportAt' => 0,
			'tiLastReportedAt' => 0,
			'tiLastSequence' => 0,
			'tiTokenHash' => $tokenHash,
			'tiStatsConsent' => !empty($installation['stats_consent']) ? 1 : 0,
			'tiState' => 1,
			'tiLastIP' => $ip,
			'tiDataSource' => 'external-self-reported',
		), $dnsValues));
		$this->recordIp($internalId, $ip, $now);
		return $this->accepted('created', $dns);
	}

	public function report(array $payload, string $token, string $ip): array
	{
		$id = (string)$payload['installation_id'];
		$ip = self::normalizeIp($ip);
		$payloadHash = hash('sha256', json_encode(
			$payload,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
		));
		$now = time();
		$this->db->beginJob();
		try
		{
			$installation = $this->db->fetch1Row($this->db->query(
				'SELECT * FROM Installations WHERE tiPublicID=? AND tiState=1 LIMIT 1 FOR UPDATE',
				array($id)
			));
			if (!$installation || !hash_equals((string)$installation['tiTokenHash'], self::tokenHash($token)))
			{
				$this->db->cancelJob();
				return array('state' => 'invalid_token');
			}
			if (!hash_equals((string)$installation['tiDomain'], (string)$payload['domain']))
			{
				$this->db->cancelJob();
				return array('state' => 'domain_registration_required');
			}

			$sequence = (int)$payload['sequence'];
			$reportedAt = (int)$payload['reported_at'];
			$lastSequence = (int)($installation['tiLastSequence'] ?? 0);
			$lastReportedAt = (int)($installation['tiLastReportedAt'] ?? 0);
			if ($sequence < $lastSequence || $reportedAt < $lastReportedAt)
			{
				$this->db->cancelJob();
				return array('state' => 'replay_rejected');
			}
			if ($sequence === $lastSequence && $lastSequence > 0)
			{
				$existingHash = (string)$this->db->fetch1($this->db->select(
					'InstallationReports',
					'tirPayloadHash',
					'tirInstallationID=?d and tirSequence=?d',
					array($installation['tiID'], $sequence),
					'',
					1
				));
				$this->db->endJob();
				if ($existingHash === '' || !hash_equals($existingHash, $payloadHash))
					return array('state' => 'replay_rejected');
				$this->recordIp((int)$installation['tiID'], $ip, $now);
				$dns = $this->refreshDnsIfExpired($installation, $ip, $now);
				$this->db->update('Installations', array(
					'tiLastSeenAt' => $now,
					'tiLastIP' => $ip,
				), '', 'tiID=?d', array($installation['tiID']));
				return $this->accepted('duplicate', $dns);
			}

			$encodedStats = !empty($payload['stats_consent']) && is_array($payload['public_stats'] ?? null)
				? json_encode($payload['public_stats'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
				: 'null';
			$this->db->update('Installations', array(
				'tiVersion' => $payload['version'],
				'tiLastSeenAt' => $now,
				'tiLastReportAt' => $now,
				'tiLastReportedAt' => $reportedAt,
				'tiLastSequence' => $sequence,
				'tiStatsConsent' => !empty($payload['stats_consent']) ? 1 : 0,
				'tiLastIP' => $ip,
			), '', 'tiID=?d', array($installation['tiID']));
			$this->db->insert('InstallationReports', array(
				'tirInstallationID' => $installation['tiID'],
				'tirDay' => gmdate('Y-m-d', $reportedAt),
				'tirSequence' => $sequence,
				'tirReportedAt' => $reportedAt,
				'tirVersion' => $payload['version'],
				'tirStats' => $encodedStats,
				'tirPayloadHash' => $payloadHash,
				'tirDataSource' => 'self-reported',
				'tirCreatedAt' => $now,
				'tirUpdatedAt' => $now,
			));
			$this->db->endJob();
		}
		catch (Throwable $exception)
		{
			try { $this->db->cancelJob(); } catch (Throwable) {}
			throw $exception;
		}

		$this->recordIp((int)$installation['tiID'], $ip, $now);
		$dns = $this->refreshDnsIfExpired($installation, $ip, $now);
		return $this->accepted('accepted', $dns);
	}

	/** Returns one bounded page plus independent unfiltered aggregate queries. */
	public function dashboard(InstallationListQuery|array|bool|null $query = null, bool $includeSensitive = false): array
	{
		if (is_bool($query))
		{
			$includeSensitive = $query;
			$query = null;
		}
		if (!$query instanceof InstallationListQuery)
			$query = InstallationListQuery::fromArray(is_array($query) ? $query : array());

		$now = time();
		$listFilter = $this->listFilter($query, $now);
		$total = (int)$this->db->fetch1($this->db->query(
			'SELECT COUNT(*) FROM Installations i WHERE ' . $listFilter['where'],
			$listFilter['parameters']
		));
		$totalPages = $total > 0 ? (int)ceil($total / $query->perPage()) : 0;
		$page = $totalPages > 0 ? min($query->page(), $totalPages) : 1;
		$offset = ($page - 1) * $query->perPage();

		$fields = 'i.tiID, i.tiPublicID, i.tiDomain, i.tiVersion, i.tiInstalledAt,
		 i.tiRegisteredAt, i.tiLastSeenAt, i.tiLastReportAt, i.tiStatsConsent,
		 i.tiDnsStatus, i.tiDnsCheckedAt, i.tiDnsErrorCode, i.tiDataSource,
		 i.tiDomainVerifiedAt, i.tiDomainVerifiedUntil, i.tiDomainProofError,
		 r.tirDay, r.tirStats, r.tirDataSource';
		if ($includeSensitive)
			$fields .= ', i.tiLastIP, i.tiDnsAddresses';
		$rows = $this->db->fetchRows($this->db->query(
			'SELECT ' . $fields . '
			 FROM Installations i
			 LEFT JOIN InstallationReports r
			  ON r.tirInstallationID=i.tiID AND r.tirSequence=i.tiLastSequence
			 WHERE ' . $listFilter['where'] . '
			 ORDER BY i.tiLastSeenAt DESC, i.tiID DESC
			 LIMIT ' . $offset . ', ' . $query->perPage(),
			$listFilter['parameters']
		));
		$rows = is_array($rows) ? $rows : array();

		$ipHistory = $includeSensitive ? $this->ipHistoryForRows($rows, $now) : array();
		$canonicalDomains = array();
		foreach ($rows as $row)
		{
			$canonical = self::canonicalDomain((string)$row['tiDomain']);
			if ($canonical !== '')
				$canonicalDomains[$canonical] = true;
		}
		$domainOwners = $this->domainOwners(array_keys($canonicalDomains));

		$installations = array();
		foreach ($rows as $row)
		{
			$stats = null;
			if (!empty($row['tiStatsConsent']) && !empty($row['tirStats']))
			{
				$decodedStats = json_decode((string)$row['tirStats'], true);
				if (is_array($decodedStats))
					$stats = $decodedStats;
			}
			$canonicalDomain = self::canonicalDomain((string)$row['tiDomain']);
			$dnsStatus = (string)($row['tiDnsStatus'] ?? 'unresolved');
			$verified = DomainProofService::fresh($row, $now);
			$eligible = $dnsStatus === 'matched' || $verified;
			$counted = $canonicalDomain !== '' && $eligible
				&& (int)($domainOwners[$canonicalDomain] ?? 0) === (int)$row['tiID'];
			$metricState = $canonicalDomain === ''
				? 'ineligible_domain'
				: (!$eligible ? 'dns_' . $dnsStatus : ($counted ? 'counted' : 'duplicate_domain'));
			$normalizedStats = is_array($stats)
				? $this->normalizedStats($stats, (int)$row['tiInstalledAt'], $now)
				: null;
			$connectionState = (int)$row['tiLastReportAt'] <= 0
				? 'no_report'
				: ((int)$row['tiLastSeenAt'] >= $now - 86400 ? 'active' : 'inactive');
			$installation = array(
				'id' => (string)$row['tiPublicID'],
				'domain' => (string)$row['tiDomain'],
				'version' => (string)$row['tiVersion'],
				'outdated' => version_compare((string)$row['tiVersion'], Application::version(), '<'),
				'installed_at' => (int)$row['tiInstalledAt'],
				'registered_at' => (int)$row['tiRegisteredAt'],
				'last_seen_at' => (int)$row['tiLastSeenAt'],
				'last_report_at' => (int)$row['tiLastReportAt'],
				'connection_state' => $connectionState,
				'shares_public_stats' => (bool)$row['tiStatsConsent'],
				'report_day' => (string)($row['tirDay'] ?? ''),
				'public_stats' => $normalizedStats,
				'data_classification' => 'self-reported',
				'dns_status' => $dnsStatus,
				'domain_verified' => $verified,
				'domain_verified_until' => (int)($row['tiDomainVerifiedUntil'] ?? 0),
				'domain_proof_error' => (string)($row['tiDomainProofError'] ?? ''),
				'dns_checked_at' => (int)($row['tiDnsCheckedAt'] ?? 0),
				'dns_error_code' => (string)($row['tiDnsErrorCode'] ?? ''),
				'counted_in_public_metrics' => $counted,
				'public_metric_state' => $metricState,
			);
			if ($includeSensitive)
			{
				$addresses = json_decode((string)($row['tiDnsAddresses'] ?? '[]'), true);
				$installation['last_ip'] = (string)($row['tiLastIP'] ?? '');
				$installation['dns_addresses'] = is_array($addresses) ? array_values($addresses) : array();
				$installation['ip_history'] = $ipHistory[(int)$row['tiID']] ?? array();
			}
			$installations[] = $installation;
		}

		return array(
			'data_classification' => 'self-reported',
			'summary' => $this->summary($now),
			'public_stats' => $this->publicAggregate($now),
			'filters' => $query->filters(),
			'pagination' => array(
				'page' => $page,
				'per_page' => $query->perPage(),
				'total' => $total,
				'total_pages' => $totalPages,
				'has_previous' => $page > 1,
				'has_next' => $page < $totalPages,
			),
			'installations' => $installations,
		);
	}

	public function publicMetrics(): array
	{
		$now = time();
		$summary = $this->summary($now);
		$public = $this->publicAggregate($now);
		$baseUsd = $public['base_totals_by_currency']['USD']['cash_in'] ?? null;
		$nativeUsd = $public['cash_in_by_currency']['USD'] ?? 0;
		return array(
			'processed' => max(0.0, is_numeric($baseUsd) ? (float)$baseUsd : (float)$nativeUsd),
			'processed_currency' => 'USD',
			'platforms' => max(0, (int)$summary['platforms_total']),
			'updated_at' => $now,
			'data_classification' => 'self-reported',
			'eligibility' => 'dns_matched_or_https_verified',
		);
	}

	private function listFilter(InstallationListQuery $query, int $now): array
	{
		$clauses = array('i.tiState=1');
		$parameters = array();
		if ($query->search() !== '')
		{
			$pattern = '%' . self::escapeLike($query->search()) . '%';
			$clauses[] = "(LOWER(i.tiDomain) LIKE ? ESCAPE '=' OR LOWER(i.tiPublicID) LIKE ? ESCAPE '=')";
			$parameters[] = $pattern;
			$parameters[] = $pattern;
		}
		if ($query->version() !== '')
		{
			$clauses[] = 'i.tiVersion=?';
			$parameters[] = $query->version();
		}
		if ($query->connection() === 'no_report')
			$clauses[] = 'i.tiLastReportAt=0';
		elseif ($query->connection() === 'active')
		{
			$clauses[] = 'i.tiLastReportAt>0 AND i.tiLastSeenAt>=?d';
			$parameters[] = $now - 86400;
		}
		elseif ($query->connection() === 'inactive')
		{
			$clauses[] = 'i.tiLastReportAt>0 AND i.tiLastSeenAt<?d';
			$parameters[] = $now - 86400;
		}
		if ($query->sharing() !== '')
		{
			$clauses[] = 'i.tiStatsConsent=?d';
			$parameters[] = $query->sharing() === 'enabled' ? 1 : 0;
		}
		if ($query->dnsStatus() !== '')
		{
			$clauses[] = 'i.tiDnsStatus=?';
			$parameters[] = $query->dnsStatus();
		}
		if ($query->ip() !== '')
		{
			$clauses[] = 'i.tiLastIP=?';
			$parameters[] = $query->ip();
		}
		return array('where' => implode(' AND ', $clauses), 'parameters' => $parameters);
	}

	private static function escapeLike(string $value): string
	{
		return str_replace(array('=', '%', '_'), array('==', '=%', '=_'), $value);
	}

	private function ipHistoryForRows(array $rows, int $now): array
	{
		$ids = array_values(array_unique(array_map(static fn(array $row): int => (int)$row['tiID'], $rows)));
		if (!$ids)
			return array();
		$historyRows = $this->db->fetchRows($this->db->query(
			'SELECT tihInstallationID, tihIP, tihFirstSeenAt, tihLastSeenAt, tihRequestCount
			 FROM InstallationIpHistory
			 WHERE tihInstallationID ?i AND tihLastSeenAt>=?d
			 ORDER BY tihInstallationID, tihLastSeenAt DESC, tihID DESC',
			array($ids, $now - self::IP_HISTORY_DAYS * 86400)
		));
		$history = array();
		foreach (is_array($historyRows) ? $historyRows : array() as $row)
		{
			$key = (int)$row['tihInstallationID'];
			if (count($history[$key] ?? array()) >= self::IP_HISTORY_LIMIT)
				continue;
			$history[$key][] = array(
				'ip' => (string)$row['tihIP'],
				'first_seen_at' => (int)$row['tihFirstSeenAt'],
				'last_seen_at' => (int)$row['tihLastSeenAt'],
				'request_count' => (int)$row['tihRequestCount'],
			);
		}
		return $history;
	}

	private function domainOwners(array $canonicalDomains): array
	{
		if (!$canonicalDomains)
			return array();
		$candidates = array();
		foreach ($canonicalDomains as $domain)
		{
			$candidates[$domain] = true;
			$candidates['www.' . $domain] = true;
		}
		$rows = $this->db->fetchRows($this->db->query(
			'SELECT c.tiID, c.tiDomain, c.tiRegisteredAt
			 FROM Installations c
			 WHERE c.tiState=1 AND ' . self::eligibilityExpression('c') . ' AND c.tiDomain ?i
			 ORDER BY c.tiRegisteredAt, c.tiID',
			array(array_keys($candidates))
		));
		$owners = array();
		foreach (is_array($rows) ? $rows : array() as $row)
		{
			$domain = self::canonicalDomain((string)$row['tiDomain']);
			if ($domain !== '' && !isset($owners[$domain]))
				$owners[$domain] = (int)$row['tiID'];
		}
		return $owners;
	}

	private function summary(int $now): array
	{
		$totals = $this->db->fetch1Row($this->db->query(
			'SELECT COUNT(*) AS installations_total,
			 SUM(CASE WHEN tiLastSeenAt>=?d THEN 1 ELSE 0 END) AS installations_active_24h
			 FROM Installations WHERE tiState=1',
			array($now - 86400)
		));
		$canonical = self::canonicalDomainExpression('i');
		$platforms = (int)$this->db->fetch1($this->db->query(
			'SELECT COUNT(DISTINCT ' . $canonical . ')
			 FROM Installations i WHERE i.tiState=1 AND ' . self::eligibilityExpression('i')
		));
		$versionRows = $this->db->fetchRows($this->db->query(
			'SELECT tiVersion AS version, COUNT(*) AS installation_count
			 FROM Installations WHERE tiState=1 GROUP BY tiVersion ORDER BY tiVersion'
		));
		$versions = array();
		foreach (is_array($versionRows) ? $versionRows : array() as $row)
			$versions[(string)$row['version']] = (int)$row['installation_count'];
		return array(
			'installations_total' => (int)($totals['installations_total'] ?? 0),
			'platforms_total' => $platforms,
			'installations_active_24h' => (int)($totals['installations_active_24h'] ?? 0),
			'current_version' => Application::version(),
			'versions' => $versions,
		);
	}

	private function publicAggregate(int $now): array
	{
		$public = self::emptyPublicAggregate();
		$owners = self::publicOwnerSubquery();
		$eligibleJoin = ' FROM Installations i INNER JOIN (' . $owners . ') public_owner ON public_owner.owner_id=i.tiID
			 INNER JOIN InstallationReports r ON r.tirInstallationID=i.tiID AND r.tirSequence=i.tiLastSequence';
		$eligibleWhere = ' WHERE i.tiStatsConsent=1 AND JSON_TYPE(r.tirStats)=\'OBJECT\'';
		$scalar = $this->db->fetch1Row($this->db->query(
			'SELECT COUNT(*) AS installations_sharing,
			 COALESCE(SUM(CASE WHEN i.tiInstalledAt>=946684800 AND i.tiInstalledAt<=?d THEN FLOOR((?d-i.tiInstalledAt)/86400) ELSE 0 END), 0) AS worked_days,
			 COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(r.tirStats, \'$.users_total\')) AS UNSIGNED)), 0) AS users_total,
			 COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(r.tirStats, \'$.users_online\')) AS UNSIGNED)), 0) AS users_online,
			 COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(r.tirStats, \'$.active_deposits\')) AS UNSIGNED)), 0) AS active_deposits,
			 COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(r.tirStats, \'$.closed_deposits\')) AS UNSIGNED)), 0) AS closed_deposits'
			. $eligibleJoin . $eligibleWhere,
			array($now, $now)
		));
		foreach (array('installations_sharing', 'worked_days', 'users_total', 'users_online', 'active_deposits', 'closed_deposits') as $field)
			$public[$field] = (int)($scalar[$field] ?? 0);

		$currencyExpression = "UPPER(JSON_UNQUOTE(JSON_EXTRACT(r.tirStats, '$.base_currency')))";
		$baseRows = $this->db->fetchRows($this->db->query(
			'SELECT ' . $currencyExpression . ' AS base_currency,
			 COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(r.tirStats, \'$.cash_in_base\')) AS DECIMAL(30,8))), 0) AS cash_in,
			 COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(r.tirStats, \'$.cash_out_base\')) AS DECIMAL(30,8))), 0) AS cash_out,
			 COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(r.tirStats, \'$.referral_paid_base\')) AS DECIMAL(30,8))), 0) AS referral_paid,
			 COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(r.tirStats, \'$.reinvested_base\')) AS DECIMAL(30,8))), 0) AS reinvested'
			. $eligibleJoin . $eligibleWhere . ' AND ' . $currencyExpression . " REGEXP '^[A-Z0-9]{2,10}$'"
			. ' GROUP BY base_currency ORDER BY base_currency'
		));
		foreach (is_array($baseRows) ? $baseRows : array() as $row)
		{
			$currency = (string)$row['base_currency'];
			$public['base_totals_by_currency'][$currency] = array(
				'cash_in' => max(0.0, (float)$row['cash_in']),
				'cash_out' => max(0.0, (float)$row['cash_out']),
				'referral_paid' => max(0.0, (float)$row['referral_paid']),
				'reinvested' => max(0.0, (float)$row['reinvested']),
			);
		}

		$currencyRows = $this->db->fetchRows($this->db->query(
			'SELECT currency_rows.currency,
			 COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(r.tirStats, CONCAT(\'$.cash_in_by_currency.\', currency_rows.currency))) AS DECIMAL(30,8))), 0) AS cash_in,
			 COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(r.tirStats, CONCAT(\'$.cash_out_by_currency.\', currency_rows.currency))) AS DECIMAL(30,8))), 0) AS cash_out'
			. $eligibleJoin . '
			 INNER JOIN JSON_TABLE(
			  JSON_KEYS(JSON_MERGE_PATCH(
			   COALESCE(JSON_EXTRACT(r.tirStats, \'$.cash_in_by_currency\'), JSON_OBJECT()),
			   COALESCE(JSON_EXTRACT(r.tirStats, \'$.cash_out_by_currency\'), JSON_OBJECT())
			  )),
			  \'$[*]\' COLUMNS(currency VARCHAR(10) PATH \'$\')
			 ) currency_rows'
			. $eligibleWhere . ' AND currency_rows.currency REGEXP \'^[A-Z0-9]{2,10}$\'
			 GROUP BY currency_rows.currency ORDER BY currency_rows.currency'
		));
		foreach (is_array($currencyRows) ? $currencyRows : array() as $row)
		{
			$currency = (string)$row['currency'];
			$public['cash_in_by_currency'][$currency] = max(0.0, (float)$row['cash_in']);
			$public['cash_out_by_currency'][$currency] = max(0.0, (float)$row['cash_out']);
		}
		ksort($public['cash_in_by_currency']);
		ksort($public['cash_out_by_currency']);
		ksort($public['base_totals_by_currency']);
		return $public;
	}

	private static function publicOwnerSubquery(): string
	{
		$canonical = self::canonicalDomainExpression('c');
		return 'SELECT CAST(RIGHT(MIN(CONCAT(LPAD(c.tiRegisteredAt, 20, \'0\'), LPAD(c.tiID, 20, \'0\'))), 20) AS UNSIGNED) AS owner_id
		 FROM Installations c
		 WHERE c.tiState=1 AND ' . self::eligibilityExpression('c') . '
		 GROUP BY ' . $canonical;
	}

	private static function eligibilityExpression(string $alias): string
	{
		return '(' . $alias . '.tiDnsStatus=\'matched\' OR (' . $alias . '.tiDomainVerifiedAt>0 AND '
			. $alias . '.tiDomainVerifiedAt<=UNIX_TIMESTAMP() AND '
			. $alias . '.tiDomainVerifiedUntil>UNIX_TIMESTAMP()))';
	}

	private static function emptyPublicAggregate(): array
	{
		return array(
			'installations_sharing' => 0,
			'worked_days' => 0,
			'users_total' => 0,
			'users_online' => 0,
			'active_deposits' => 0,
			'closed_deposits' => 0,
			'cash_in_by_currency' => array(),
			'cash_out_by_currency' => array(),
			'base_totals_by_currency' => array(),
		);
	}

	private static function canonicalDomainExpression(string $alias): string
	{
		$domain = "LOWER(TRIM(TRAILING '.' FROM {$alias}.tiDomain))";
		return "CASE WHEN {$domain} LIKE 'www.%' THEN SUBSTRING({$domain}, 5) ELSE {$domain} END";
	}

	private function findByPublicId(string $id): array
	{
		return $this->db->fetch1Row($this->db->select('Installations', '*', 'tiPublicID=?', array($id), '', 1));
	}

	private function dnsState(array $existing, string $domain, string $ip, bool $force, int $now): array
	{
		if (!$force && $existing && (int)($existing['tiDnsExpiresAt'] ?? 0) > $now)
			return $this->storedDns($existing);
		return $this->dns->resolve($domain, $ip);
	}

	private function refreshDnsIfExpired(array $installation, string $ip, int $now): array
	{
		$ipChanged = self::normalizeIp((string)($installation['tiLastIP'] ?? '')) !== $ip;
		$mustRefresh = $ipChanged || (int)($installation['tiDnsExpiresAt'] ?? 0) <= $now;
		$dns = $this->dnsState($installation, (string)$installation['tiDomain'], $ip, $mustRefresh, $now);
		if ($mustRefresh)
			$this->db->update('Installations', $this->dnsValues($dns), '', 'tiID=?d', array($installation['tiID']));
		return $dns;
	}

	private function storedDns(array $row): array
	{
		$addresses = json_decode((string)($row['tiDnsAddresses'] ?? '[]'), true);
		return array(
			'status' => (string)($row['tiDnsStatus'] ?? 'unresolved'),
			'addresses' => is_array($addresses) ? array_values($addresses) : array(),
			'checked_at' => (int)($row['tiDnsCheckedAt'] ?? 0),
			'expires_at' => (int)($row['tiDnsExpiresAt'] ?? 0),
			'error_code' => (string)($row['tiDnsErrorCode'] ?? ''),
		);
	}

	private function dnsValues(array $dns): array
	{
		return array(
			'tiDnsStatus' => $dns['status'],
			'tiDnsAddresses' => json_encode($dns['addresses'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
			'tiDnsCheckedAt' => $dns['checked_at'],
			'tiDnsExpiresAt' => $dns['expires_at'],
			'tiDnsErrorCode' => $dns['error_code'],
		);
	}

	private function accepted(string $state, array $dns): array
	{
		return array(
			'state' => $state,
			'dns_status' => $dns['status'],
			'dns_checked_at' => $dns['checked_at'],
			'dns_error_code' => $dns['error_code'],
			'data_classification' => 'self-reported',
		);
	}

	private function recordIp(int $installationId, string $ip, int $now): void
	{
		if ($installationId <= 0 || $ip === '')
			return;
		$this->db->query(
			'INSERT INTO InstallationIpHistory (tihInstallationID, tihIP, tihFirstSeenAt, tihLastSeenAt, tihRequestCount)
			 VALUES (?d, ?, ?d, ?d, 1)
			 ON DUPLICATE KEY UPDATE tihLastSeenAt=VALUES(tihLastSeenAt), tihRequestCount=tihRequestCount+1',
			array($installationId, $ip, $now, $now)
		);
		$this->db->delete('InstallationIpHistory', 'tihInstallationID=?d and tihLastSeenAt<?d', array($installationId, $now - self::IP_HISTORY_DAYS * 86400));
		$this->db->query(
			'DELETE FROM InstallationIpHistory WHERE tihID IN (
			 SELECT tihID FROM (
			  SELECT tihID FROM InstallationIpHistory WHERE tihInstallationID=?d
			  ORDER BY tihLastSeenAt DESC, tihID DESC LIMIT 20, 18446744073709551615
			 ) stale
			)',
			array($installationId)
		);
	}

	private function recordDomainChange(int $installationId, string $old, string $new, string $ip, int $now): void
	{
		$this->db->insert('InstallationDomainEvents', array(
			'tdeInstallationID' => $installationId,
			'tdeOldDomain' => $old,
			'tdeNewDomain' => $new,
			'tdeObservedIP' => $ip,
			'tdeCreatedAt' => $now,
		));
		$this->db->query(
			'DELETE FROM InstallationDomainEvents WHERE tdeID IN (
			 SELECT tdeID FROM (
			  SELECT tdeID FROM InstallationDomainEvents WHERE tdeInstallationID=?d
			  ORDER BY tdeCreatedAt DESC, tdeID DESC LIMIT ' . self::DOMAIN_EVENT_LIMIT . ', 18446744073709551615
			 ) stale
			)',
			array($installationId)
		);
	}

	private function normalizedStats(array $stats, int $installedAt, int $now): array
	{
		$workedDays = $installedAt >= 946684800 && $installedAt <= $now ? (int)floor(($now - $installedAt) / 86400) : 0;
		return array(
			'worked_days' => max(0, $workedDays),
			'users_total' => max(0, (int)($stats['users_total'] ?? 0)),
			'users_online' => max(0, (int)($stats['users_online'] ?? 0)),
			'active_deposits' => max(0, (int)($stats['active_deposits'] ?? 0)),
			'closed_deposits' => max(0, (int)($stats['closed_deposits'] ?? 0)),
			'base_currency' => strtoupper((string)($stats['base_currency'] ?? '')),
			'cash_in_base' => max(0, (float)($stats['cash_in_base'] ?? 0)),
			'cash_out_base' => max(0, (float)($stats['cash_out_base'] ?? 0)),
			'referral_paid_base' => max(0, (float)($stats['referral_paid_base'] ?? 0)),
			'reinvested_base' => max(0, (float)($stats['reinvested_base'] ?? 0)),
			'cash_in_by_currency' => self::amountMap($stats['cash_in_by_currency'] ?? array()),
			'cash_out_by_currency' => self::amountMap($stats['cash_out_by_currency'] ?? array()),
		);
	}

	private static function tokenHash(string $token): string { return hash('sha256', $token); }

	private static function normalizeIp(string $ip): string
	{
		return PassiveDnsResolver::normalizeIp($ip);
	}

	private static function sumAmounts(array &$target, array $source): void
	{
		foreach ($source as $currency => $amount)
		{
			$currency = strtoupper((string)$currency);
			if (preg_match('/^[A-Z0-9]{2,10}$/', $currency) && is_numeric($amount))
				$target[$currency] = ($target[$currency] ?? 0.0) + max(0, (float)$amount);
		}
	}

	private static function amountMap(mixed $source): array
	{
		if (!is_array($source)) return array();
		$result = array();
		self::sumAmounts($result, $source);
		ksort($result);
		return $result;
	}

	private static function canonicalDomain(string $domain): string
	{
		$domain = strtolower(trim($domain));
		$domain = preg_replace('/^www\./', '', rtrim($domain, '.')) ?? '';
		return PassiveDnsResolver::normalizeDomain($domain);
	}
}
