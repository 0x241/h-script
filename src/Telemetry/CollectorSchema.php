<?php

namespace HScript\Telemetry;

use HScript\Application;
use HScript\Database\Connection;
use HScript\Update\SchemaStateRepository;
use Throwable;

final class CollectorSchema
{
	public static function ready(Connection $database): bool
	{
		try
		{
			if ((new SchemaStateRepository($database))->currentVersion() !== Application::schemaVersion())
				return false;
			$required = array(
				'Installations' => array('tiDnsStatus', 'tiLastSequence', 'tiLastIP', 'tiDomainVerifiedAt',
					'tiDomainVerifiedUntil', 'tiDomainProofHash', 'tiDomainProofExpiresAt', 'tiDomainProofAttemptAt', 'tiDomainProofError'),
				'InstallationReports' => array('tirSequence', 'tirPayloadHash'),
				'InstallationIpHistory' => array('tihIP', 'tihLastSeenAt'),
				'InstallationDomainEvents' => array('tdeOldDomain', 'tdeNewDomain'),
				'TelemetryIngestionCounters' => array('ticDay', 'ticCode'),
				'TelemetryServiceTokens' => array('tstTokenHash', 'tstScope'),
			);
			foreach ($required as $table => $columns)
				foreach ($columns as $column)
					if ((int)$database->fetch1($database->query(
						'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',
						array($table, $column)
					)) !== 1)
						return false;
			return true;
		}
		catch (Throwable)
		{
			return false;
		}
	}
}
