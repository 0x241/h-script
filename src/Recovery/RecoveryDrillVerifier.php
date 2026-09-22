<?php

namespace HScript\Recovery;

use HScript\Database\Connection;
use HScript\Update\SchemaStateRepository;
use RuntimeException;
use Throwable;

final class RecoveryDrillVerifier
{
	public function __construct(private string $rowBoundsFile) {}

	public function verify(Connection $database, RecoveryBundleManifest $bundle): array
	{
		$components = array();
		$expected = $bundle->toArray();
		$state = new SchemaStateRepository($database);
		if ($state->currentVersion() !== $expected['schema_version']
			|| $state->installedApplicationVersion() !== $expected['application_version'])
			throw new RecoveryException('drill_version_mismatch', 'Restored schema or application version does not match the bundle');
		$components[] = 'versions';

		$this->verifyRowBounds($database);
		$components[] = 'row_bounds';
		$this->verifyReadOnlyLogin($database);
		$components[] = 'read_only_login';
		return array_merge($components, $this->verifyApplicationState($database));
	}

	/** Shared read-only invariants for restore drills and the existing updater. */
	public function verifyApplicationState(Connection $database, bool $serviceTokens = true): array
	{
		$components = array();
		$this->verifyFinancialInvariants($database);
		$components[] = 'financial_invariants';
		$this->verifyQueue($database);
		$components[] = 'queue';
		$this->verifyInstallationTelemetry($database);
		$components[] = 'installation_telemetry';
		if ($serviceTokens)
		{
			$this->verifyServiceTokens($database);
			$components[] = 'service_token_metadata';
		}
		return $components;
	}

	private function verifyRowBounds(Connection $database): void
	{
		if ($this->rowBoundsFile === '' || !str_starts_with($this->rowBoundsFile, '/') || !is_readable($this->rowBoundsFile))
			throw new RecoveryException('row_bounds_not_configured', 'RECOVERY_ROW_BOUNDS_FILE must reference a readable absolute file');
		try
		{
			$bounds = json_decode((string)file_get_contents($this->rowBoundsFile), true, 32, JSON_THROW_ON_ERROR);
		}
		catch (Throwable $exception)
		{
			throw new RecoveryException('row_bounds_invalid', 'Recovery row bounds are invalid', $exception);
		}
		if (!is_array($bounds) || !$bounds)
			throw new RecoveryException('row_bounds_invalid', 'Recovery row bounds must not be empty');
		foreach ($bounds as $table => $range)
		{
			if (!is_string($table) || !preg_match('/^[A-Za-z0-9_]+$/', $table)
				|| !is_array($range) || count($range) !== 2 || !array_key_exists('min', $range) || !array_key_exists('max', $range)
				|| !is_int($range['min']) || !is_int($range['max'])
				|| $range['min'] < 0 || $range['max'] < $range['min'])
				throw new RecoveryException('row_bounds_invalid', 'Recovery row bound entry is invalid');
			if (!$this->tableExists($database, $table))
				throw new RecoveryException('drill_table_missing', 'Required restored table is missing');
			$count = $this->count($database, 'SELECT COUNT(*) FROM ' . $database->field($table));
			if ($count < $range['min'] || $count > $range['max'])
				throw new RecoveryException('drill_row_bound_breached', 'Restored table row count is outside the configured range');
		}
	}

	private function verifyReadOnlyLogin(Connection $database): void
	{
		$database->query('SET SESSION TRANSACTION READ ONLY');
		$database->query('START TRANSACTION READ ONLY');
		try
		{
			$active = (int)$database->fetch1($database->query(
				'SELECT COUNT(*) FROM Users WHERE uState=1 AND uLevel>=90 AND uLogin<>\'\' AND uPass<>\'\''
			));
			if ($active < 1)
				throw new RecoveryException('drill_read_only_login_failed', 'No active administrator authentication record is readable');
		}
		finally
		{
			$database->query('ROLLBACK');
			$database->query('SET SESSION TRANSACTION READ WRITE');
		}
	}

	private function verifyFinancialInvariants(Connection $database): void
	{
		$checks = array(
			'SELECT COUNT(*) FROM Users WHERE uBal<0',
			'SELECT COUNT(*) FROM Wallets WHERE wBal<0 OR wLock<0 OR wOut<0',
			'SELECT COUNT(*) FROM Deps WHERE dZD<0 OR dZC<0 OR dZP<0',
			'SELECT COUNT(*) FROM Opers WHERE oSum<0 OR oComis<0',
		);
		foreach ($checks as $query)
			if ($this->count($database, $query) !== 0)
				throw new RecoveryException('drill_financial_invariant_failed', 'Restored financial invariant failed');
	}

	private function verifyQueue(Connection $database): void
	{
		if (!$this->tableExists($database, 'Jobs'))
			throw new RecoveryException('drill_queue_missing', 'Restored queue table is missing');
		$invalid = $this->count($database,
			'SELECT COUNT(*) FROM Jobs WHERE jState NOT IN (0,1,2,3) OR jAttempts<0 OR jAttempts>jMaxAttempts OR jMaxAttempts<1 OR JSON_VALID(jPayload)=0'
		);
		if ($invalid !== 0)
			throw new RecoveryException('drill_queue_invalid', 'Restored queue metadata is invalid');
	}

	private function verifyInstallationTelemetry(Connection $database): void
	{
		$values = array();
		$query = $database->select(
			'Cfg', 'Prop, Val', 'Module=? and Prop ?i',
			array('Telemetry', array('InstallationID', 'Token'))
		);
		$rows = $query === false ? false : $database->fetchRows($query);
		if (!is_array($rows)) throw new RecoveryException('drill_query_failed', 'Application state query failed');
		foreach ($rows as $row)
			$values[(string)$row['Prop']] = (string)$row['Val'];
		if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $values['InstallationID'] ?? '')
			|| !preg_match('/^hsi_[a-f0-9]{64}$/', $values['Token'] ?? ''))
			throw new RecoveryException('drill_telemetry_invalid', 'Installation telemetry identity is missing or invalid');
	}

	private function verifyServiceTokens(Connection $database): void
	{
		if (!$this->tableExists($database, 'TelemetryServiceTokens'))
			throw new RecoveryException('drill_service_tokens_missing', 'Service-token registry is missing');
		$invalid = $this->count($database,
			"SELECT COUNT(*) FROM TelemetryServiceTokens WHERE tstTokenHash NOT REGEXP '^[a-f0-9]{64}$' OR tstTokenPrefix NOT REGEXP '^hst_[a-f0-9]{0,8}$' OR tstScope<>'telemetry:read' OR tstState NOT IN (0,1,2)"
		);
		if ($invalid !== 0)
			throw new RecoveryException('drill_service_tokens_invalid', 'Service-token metadata is invalid');
	}

	private function tableExists(Connection $database, string $table): bool
	{
		return $this->count($database,
			'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
			array($table)
		) === 1;
	}

	private function count(Connection $database, string $sql, array $parameters = array()): int
	{
		$query = $database->query($sql, $parameters);
		$value = $query === false ? false : $database->fetch1($query);
		if ((!is_int($value) && (!is_string($value) || !ctype_digit($value))) || (int)$value < 0)
			throw new RecoveryException('drill_query_failed', 'Application state query failed');
		return (int)$value;
	}
}
