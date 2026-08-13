<?php

namespace HScript\Telemetry;

use HScript\Application;
use HScript\Database\Connection;

/**
 * Registers installations and aggregates deduplicated collector statistics.
 *
 * Public metrics count one installation per canonical domain and retain the
 * latest daily report for dashboards and service API consumers.
 */
final class InstallationRepository
{
	private Connection $db;

	public function __construct(Connection $db)
	{
		$this->db = $db;
	}

	public function register(array $installation, string $token, string $ip): string
	{
		$id = (string)$installation['installation_id'];
		$tokenHash = self::tokenHash($token);
		$existing = $this->db->fetch1Row($this->db->select(
			'Installations',
			'*',
			'tiPublicID=?',
			array($id),
			'',
			1
		));
		$now = time();

		if ($existing)
		{
			if (!hash_equals((string)$existing['tiTokenHash'], $tokenHash))
				return 'identity_conflict';
			$this->db->update('Installations', array(
				'tiDomain' => $installation['domain'],
				'tiVersion' => $installation['version'],
				'tiInstalledAt' => $installation['installed_at'],
				'tiLastSeenAt' => $now,
				'tiStatsConsent' => !empty($installation['stats_consent']) ? 1 : 0,
				'tiState' => 1,
				'tiLastIP' => $ip,
			), '', 'tiID=?d', array($existing['tiID']));
			return 'updated';
		}

		$this->db->insert('Installations', array(
			'tiPublicID' => $id,
			'tiDomain' => $installation['domain'],
			'tiVersion' => $installation['version'],
			'tiInstalledAt' => $installation['installed_at'],
			'tiRegisteredAt' => $now,
			'tiLastSeenAt' => $now,
			'tiLastReportAt' => 0,
			'tiTokenHash' => $tokenHash,
			'tiStatsConsent' => !empty($installation['stats_consent']) ? 1 : 0,
			'tiState' => 1,
			'tiLastIP' => $ip,
		));
		return 'created';
	}

	public function report(string $id, string $token, string $version, bool $statsConsent, ?array $stats, string $ip): bool
	{
		$installation = $this->authenticate($id, $token);
		if (!$installation)
			return false;

		$now = time();
		$this->db->update('Installations', array(
			'tiVersion' => $version,
			'tiLastSeenAt' => $now,
			'tiLastReportAt' => $now,
			'tiStatsConsent' => $statsConsent ? 1 : 0,
			'tiLastIP' => $ip,
		), '', 'tiID=?d', array($installation['tiID']));

		$encodedStats = $statsConsent && $stats !== null
			? json_encode($stats, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
			: 'null';
		$this->db->query(
			'INSERT INTO InstallationReports
			 (tirInstallationID, tirDay, tirVersion, tirStats, tirCreatedAt, tirUpdatedAt)
			 VALUES (?d, ?, ?, ?, ?d, ?d)
			 ON DUPLICATE KEY UPDATE
			 tirVersion=VALUES(tirVersion), tirStats=VALUES(tirStats), tirUpdatedAt=VALUES(tirUpdatedAt)',
			array(
				$installation['tiID'],
				gmdate('Y-m-d', $now),
				$version,
				$encodedStats,
				$now,
				$now,
			)
		);
		return true;
	}

	public function dashboard(): array
	{
		$rows = $this->db->fetchRows($this->db->query(
			'SELECT i.*, r.tirDay, r.tirStats
			 FROM Installations i
			 LEFT JOIN (
			   SELECT r1.*
			   FROM InstallationReports r1
			   INNER JOIN (
			     SELECT tirInstallationID, MAX(tirDay) AS latest_day
			     FROM InstallationReports
			     GROUP BY tirInstallationID
			   ) latest
			   ON latest.tirInstallationID=r1.tirInstallationID
			   AND latest.latest_day=r1.tirDay
			 ) r ON r.tirInstallationID=i.tiID
			 WHERE i.tiState=1
			 ORDER BY i.tiLastSeenAt DESC'
		));
		if (!is_array($rows))
			$rows = array();

		$versions = array();
		$installations = array();
		$domainOwners = array();
		foreach ($rows as $row)
		{
			$canonicalDomain = self::canonicalDomain((string)$row['tiDomain']);
			if ($canonicalDomain === '')
				continue;
			if (
				!isset($domainOwners[$canonicalDomain])
				|| (int)$row['tiRegisteredAt'] < $domainOwners[$canonicalDomain]['registered_at']
				|| (
					(int)$row['tiRegisteredAt'] === $domainOwners[$canonicalDomain]['registered_at']
					&& (int)$row['tiID'] < $domainOwners[$canonicalDomain]['id']
				)
			)
				$domainOwners[$canonicalDomain] = array(
					'id' => (int)$row['tiID'],
					'registered_at' => (int)$row['tiRegisteredAt'],
				);
		}
		$now = time();
		$public = array(
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
		$active24h = 0;
		foreach ($rows as $row)
		{
			$version = (string)$row['tiVersion'];
			$versions[$version] = ($versions[$version] ?? 0) + 1;
			if ((int)$row['tiLastSeenAt'] >= $now - 86400)
				$active24h++;

			$stats = null;
			if (!empty($row['tiStatsConsent']) && !empty($row['tirStats']))
			{
				$decodedStats = json_decode((string)$row['tirStats'], true);
				if (is_array($decodedStats))
					$stats = $decodedStats;
			}

			$canonicalDomain = self::canonicalDomain((string)$row['tiDomain']);
			$countedInPublicMetrics = $canonicalDomain !== ''
				&& (int)($domainOwners[$canonicalDomain]['id'] ?? 0) === (int)$row['tiID'];
			$publicMetricState = $canonicalDomain === ''
				? 'ineligible_domain'
				: ($countedInPublicMetrics ? 'counted' : 'duplicate_domain');

			$normalizedStats = null;
			if (is_array($stats))
			{
				$installedAt = (int)$row['tiInstalledAt'];
				$workedDays = $installedAt >= 946684800 && $installedAt <= $now
					? (int)floor(($now - $installedAt) / 86400)
					: 0;
				$normalizedStats = array(
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

			$installations[] = array(
				'id' => (string)$row['tiPublicID'],
				'domain' => (string)$row['tiDomain'],
				'version' => $version,
				'outdated' => version_compare($version, Application::VERSION, '<'),
				'installed_at' => (int)$row['tiInstalledAt'],
				'registered_at' => (int)$row['tiRegisteredAt'],
				'last_seen_at' => (int)$row['tiLastSeenAt'],
				'last_report_at' => (int)$row['tiLastReportAt'],
				'shares_public_stats' => (bool)$row['tiStatsConsent'],
				'report_day' => (string)($row['tirDay'] ?? ''),
				'public_stats' => $normalizedStats,
				'counted_in_public_metrics' => $countedInPublicMetrics,
				'public_metric_state' => $publicMetricState,
			);

			if (!$countedInPublicMetrics || !is_array($normalizedStats))
				continue;
			$public['installations_sharing']++;
			foreach (array('worked_days', 'users_total', 'users_online', 'active_deposits', 'closed_deposits') as $field)
				$public[$field] += $normalizedStats[$field];
			self::sumAmounts($public['cash_in_by_currency'], $normalizedStats['cash_in_by_currency']);
			self::sumAmounts($public['cash_out_by_currency'], $normalizedStats['cash_out_by_currency']);

			$baseCurrency = $normalizedStats['base_currency'];
			if (preg_match('/^[A-Z0-9]{2,10}$/', $baseCurrency))
			{
				if (!isset($public['base_totals_by_currency'][$baseCurrency]))
					$public['base_totals_by_currency'][$baseCurrency] = array(
						'cash_in' => 0.0,
						'cash_out' => 0.0,
						'referral_paid' => 0.0,
						'reinvested' => 0.0,
					);
				$public['base_totals_by_currency'][$baseCurrency]['cash_in'] += $normalizedStats['cash_in_base'];
				$public['base_totals_by_currency'][$baseCurrency]['cash_out'] += $normalizedStats['cash_out_base'];
				$public['base_totals_by_currency'][$baseCurrency]['referral_paid'] += $normalizedStats['referral_paid_base'];
				$public['base_totals_by_currency'][$baseCurrency]['reinvested'] += $normalizedStats['reinvested_base'];
			}
		}
		ksort($versions);
		ksort($public['cash_in_by_currency']);
		ksort($public['cash_out_by_currency']);
		ksort($public['base_totals_by_currency']);

		return array(
			'summary' => array(
				'installations_total' => count($rows),
				'platforms_total' => count($domainOwners),
				'installations_active_24h' => $active24h,
				'current_version' => Application::VERSION,
				'versions' => $versions,
			),
			'public_stats' => $public,
			'installations' => $installations,
		);
	}

	public function publicMetrics(): array
	{
		$dashboard = $this->dashboard();
		$public = $dashboard['public_stats'];
		$baseUsd = $public['base_totals_by_currency']['USD']['cash_in'] ?? null;
		$nativeUsd = $public['cash_in_by_currency']['USD'] ?? 0;
		$processed = is_numeric($baseUsd) ? (float)$baseUsd : (float)$nativeUsd;

		return array(
			'processed' => max(0.0, $processed),
			'processed_currency' => 'USD',
			'platforms' => max(0, (int)$dashboard['summary']['platforms_total']),
			'updated_at' => time(),
		);
	}

	private function authenticate(string $id, string $token): ?array
	{
		$row = $this->db->fetch1Row($this->db->select(
			'Installations',
			'*',
			'tiPublicID=? and tiState=1',
			array($id),
			'',
			1
		));
		if (!$row || !hash_equals((string)$row['tiTokenHash'], self::tokenHash($token)))
			return null;
		return $row;
	}

	private static function tokenHash(string $token): string
	{
		return hash('sha256', $token);
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
		if (!is_array($source))
			return array();
		$result = array();
		self::sumAmounts($result, $source);
		ksort($result);
		return $result;
	}

	private static function canonicalDomain(string $domain): string
	{
		$domain = strtolower(trim($domain));
		if (str_contains($domain, '://'))
			$domain = (string)(parse_url($domain, PHP_URL_HOST) ?: '');
		$domain = preg_replace('/:\d+$/', '', $domain) ?? '';
		$domain = preg_replace('/^www\./', '', $domain) ?? '';
		$domain = rtrim($domain, '.');
		if (
			$domain === ''
			|| !str_contains($domain, '.')
			|| filter_var($domain, FILTER_VALIDATE_IP) !== false
			|| !preg_match('/^[a-z0-9.-]+$/', $domain)
		)
			return '';
		foreach (array('.local', '.localhost', '.test', '.invalid', '.example') as $suffix)
			if (str_ends_with($domain, $suffix))
				return '';
		return $domain;
	}
}
