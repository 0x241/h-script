<?php

use HScript\Database\Connection;

return array(
	'id' => '202609101200_telemetry_ingestion',
	'from' => '1.0.0',
	'to' => '1.0.1',
	'classification' => 'backup-required',
	'up' => static function (Connection $database): void {
		$columnExists = static function (string $table, string $column) use ($database): bool {
			return (int)$database->fetch1($database->query(
				'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',
				array($table, $column)
			)) === 1;
		};
		$indexExists = static function (string $table, string $index) use ($database): bool {
			return (int)$database->fetch1($database->query(
				'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?',
				array($table, $index)
			)) > 0;
		};
		$tableExists = static function (string $table) use ($database): bool {
			return (int)$database->fetch1($database->query(
				'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
				array($table)
			)) === 1;
		};

		if (!$tableExists('Installations'))
			$database->query(
				"CREATE TABLE Installations (
				 tiID BIGINT UNSIGNED AUTO_INCREMENT,
				 tiPublicID CHAR(36) NOT NULL,
				 tiDomain VARCHAR(253) NOT NULL,
				 tiVersion VARCHAR(32) NOT NULL,
				 tiInstalledAt BIGINT UNSIGNED DEFAULT 0,
				 tiRegisteredAt BIGINT UNSIGNED DEFAULT 0,
				 tiLastSeenAt BIGINT UNSIGNED DEFAULT 0,
				 tiLastReportAt BIGINT UNSIGNED DEFAULT 0,
				 tiLastReportedAt BIGINT UNSIGNED DEFAULT 0,
				 tiLastSequence BIGINT UNSIGNED DEFAULT 0,
				 tiTokenHash CHAR(64) NOT NULL,
				 tiStatsConsent TINYINT(1) DEFAULT 0,
				 tiState TINYINT(1) DEFAULT 1,
				 tiLastIP VARCHAR(45) DEFAULT '',
				 tiDataSource VARCHAR(32) NOT NULL DEFAULT 'external-self-reported',
				 tiDnsStatus VARCHAR(16) NOT NULL DEFAULT 'unresolved',
				 tiDnsAddresses JSON NULL,
				 tiDnsCheckedAt BIGINT UNSIGNED DEFAULT 0,
				 tiDnsExpiresAt BIGINT UNSIGNED DEFAULT 0,
				 tiDnsErrorCode VARCHAR(64) NOT NULL DEFAULT '',
				 PRIMARY KEY (tiID),
				 UNIQUE KEY TIPUBLICID (tiPublicID),
				 UNIQUE KEY TITOKENHASH (tiTokenHash),
				 KEY TIDOMAIN (tiDomain),
				 KEY TILASTSEEN (tiState, tiLastSeenAt),
				 KEY TIVERSION (tiVersion),
				 KEY TIVERSIONLIST (tiVersion, tiState, tiLastSeenAt),
				 KEY TIREPORTSTATE (tiState, tiLastReportAt, tiLastSeenAt),
				 KEY TISTATSCONSENT (tiStatsConsent, tiState, tiLastSeenAt),
				 KEY TIDNSSTATUS (tiDnsStatus, tiState, tiLastSeenAt),
				 KEY TIIPLIST (tiLastIP, tiState, tiLastSeenAt)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
			);
		if (!$tableExists('InstallationReports'))
			$database->query(
				"CREATE TABLE InstallationReports (
				 tirID BIGINT UNSIGNED AUTO_INCREMENT,
				 tirInstallationID BIGINT UNSIGNED NOT NULL,
				 tirDay CHAR(10) NOT NULL,
				 tirSequence BIGINT UNSIGNED DEFAULT 0,
				 tirReportedAt BIGINT UNSIGNED DEFAULT 0,
				 tirVersion VARCHAR(32) NOT NULL,
				 tirStats JSON NULL,
				 tirPayloadHash CHAR(64) NOT NULL DEFAULT '',
				 tirDataSource VARCHAR(32) NOT NULL DEFAULT 'self-reported',
				 tirCreatedAt BIGINT UNSIGNED DEFAULT 0,
				 tirUpdatedAt BIGINT UNSIGNED DEFAULT 0,
				 PRIMARY KEY (tirID),
				 UNIQUE KEY TIRDAY (tirInstallationID, tirDay),
				 UNIQUE KEY TIRSEQUENCE (tirInstallationID, tirSequence),
				 KEY TIRUPDATED (tirUpdatedAt)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
			);
		if (!$tableExists('TelemetryServiceTokens'))
			$database->query(
				"CREATE TABLE TelemetryServiceTokens (
				 tstID INT(10) AUTO_INCREMENT,
				 tstuID INT(10) NOT NULL,
				 tstName VARCHAR(100) DEFAULT '',
				 tstTokenHash CHAR(64) NOT NULL,
				 tstTokenPrefix VARCHAR(16) DEFAULT '',
				 tstScope VARCHAR(64) DEFAULT 'telemetry:read',
				 tstState INT(1) DEFAULT 1,
				 tstCreatedAt BIGINT UNSIGNED DEFAULT 0,
				 tstExpiresAt BIGINT UNSIGNED DEFAULT 0,
				 tstLastUsedAt BIGINT UNSIGNED DEFAULT 0,
				 tstLastIP VARCHAR(45) DEFAULT '',
				 PRIMARY KEY (tstID),
				 UNIQUE KEY TSTTOKENHASH (tstTokenHash),
				 KEY TSTSTATE (tstState, tstExpiresAt),
				 KEY TSTUSER (tstuID)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
			);

		$installationColumns = array(
			'tiLastReportedAt' => 'BIGINT UNSIGNED DEFAULT 0 AFTER tiLastReportAt',
			'tiLastSequence' => 'BIGINT UNSIGNED DEFAULT 0 AFTER tiLastReportedAt',
			'tiDataSource' => "VARCHAR(32) NOT NULL DEFAULT 'external-self-reported' AFTER tiLastIP",
			'tiDnsStatus' => "VARCHAR(16) NOT NULL DEFAULT 'unresolved' AFTER tiDataSource",
			'tiDnsAddresses' => 'JSON NULL AFTER tiDnsStatus',
			'tiDnsCheckedAt' => 'BIGINT UNSIGNED DEFAULT 0 AFTER tiDnsAddresses',
			'tiDnsExpiresAt' => 'BIGINT UNSIGNED DEFAULT 0 AFTER tiDnsCheckedAt',
			'tiDnsErrorCode' => "VARCHAR(64) NOT NULL DEFAULT '' AFTER tiDnsExpiresAt",
		);
		foreach ($installationColumns as $column => $definition)
			if (!$columnExists('Installations', $column))
				$database->query('ALTER TABLE Installations ADD COLUMN ' . $database->field($column) . ' ' . $definition);

		$reportColumns = array(
			'tirSequence' => 'BIGINT UNSIGNED DEFAULT 0 AFTER tirDay',
			'tirReportedAt' => 'BIGINT UNSIGNED DEFAULT 0 AFTER tirSequence',
			'tirPayloadHash' => "CHAR(64) NOT NULL DEFAULT '' AFTER tirStats",
			'tirDataSource' => "VARCHAR(32) NOT NULL DEFAULT 'self-reported' AFTER tirPayloadHash",
		);
		foreach ($reportColumns as $column => $definition)
			if (!$columnExists('InstallationReports', $column))
				$database->query('ALTER TABLE InstallationReports ADD COLUMN ' . $database->field($column) . ' ' . $definition);

		$database->query(
			"UPDATE InstallationReports
			 SET tirSequence=FLOOR(UNIX_TIMESTAMP(CONCAT(tirDay, ' 00:00:00')) / 86400),
			     tirReportedAt=IF(tirUpdatedAt>0, tirUpdatedAt, UNIX_TIMESTAMP(CONCAT(tirDay, ' 00:00:00'))),
			     tirPayloadHash=SHA2(CONCAT_WS('|', tirInstallationID, tirDay, tirVersion, COALESCE(tirStats, 'null')), 256),
			     tirDataSource='self-reported'
			 WHERE tirSequence=0 OR tirReportedAt=0 OR tirPayloadHash=''"
		);
		$database->query(
			'UPDATE Installations i LEFT JOIN (
			 SELECT tirInstallationID, MAX(tirSequence) AS last_sequence, MAX(tirReportedAt) AS last_reported
			 FROM InstallationReports GROUP BY tirInstallationID
			) r ON r.tirInstallationID=i.tiID
			SET i.tiLastSequence=COALESCE(r.last_sequence, 0), i.tiLastReportedAt=COALESCE(r.last_reported, 0)'
		);
		if (!$indexExists('InstallationReports', 'TIRSEQUENCE'))
			$database->query('ALTER TABLE InstallationReports ADD UNIQUE KEY TIRSEQUENCE (tirInstallationID, tirSequence)');

		$database->query(
			"UPDATE Installations i
			 INNER JOIN (
			  SELECT tiTokenHash, MIN(tiID) AS keep_id FROM Installations GROUP BY tiTokenHash HAVING COUNT(*)>1
			 ) duplicate ON duplicate.tiTokenHash=i.tiTokenHash AND duplicate.keep_id<>i.tiID
			 SET i.tiState=0, i.tiTokenHash=SHA2(CONCAT(i.tiTokenHash, ':duplicate:', i.tiPublicID), 256)"
		);
		if (!$indexExists('Installations', 'TITOKENHASH'))
			$database->query('ALTER TABLE Installations ADD UNIQUE KEY TITOKENHASH (tiTokenHash)');
		if (!$indexExists('Installations', 'TIDNSSTATUS'))
			$database->query('ALTER TABLE Installations ADD KEY TIDNSSTATUS (tiDnsStatus, tiState, tiLastSeenAt)');
		if (!$indexExists('Installations', 'TIVERSIONLIST'))
			$database->query('ALTER TABLE Installations ADD KEY TIVERSIONLIST (tiVersion, tiState, tiLastSeenAt)');
		if (!$indexExists('Installations', 'TIREPORTSTATE'))
			$database->query('ALTER TABLE Installations ADD KEY TIREPORTSTATE (tiState, tiLastReportAt, tiLastSeenAt)');
		if (!$indexExists('Installations', 'TISTATSCONSENT'))
			$database->query('ALTER TABLE Installations ADD KEY TISTATSCONSENT (tiStatsConsent, tiState, tiLastSeenAt)');
		if (!$indexExists('Installations', 'TIIPLIST'))
			$database->query('ALTER TABLE Installations ADD KEY TIIPLIST (tiLastIP, tiState, tiLastSeenAt)');

		if (!$tableExists('InstallationIpHistory'))
			$database->query(
				'CREATE TABLE InstallationIpHistory (
				 tihID BIGINT UNSIGNED AUTO_INCREMENT,
				 tihInstallationID BIGINT UNSIGNED NOT NULL,
				 tihIP VARCHAR(45) NOT NULL,
				 tihFirstSeenAt BIGINT UNSIGNED DEFAULT 0,
				 tihLastSeenAt BIGINT UNSIGNED DEFAULT 0,
				 tihRequestCount BIGINT UNSIGNED DEFAULT 0,
				 PRIMARY KEY (tihID),
				 UNIQUE KEY TIHIP (tihInstallationID, tihIP),
				 KEY TIHLASTSEEN (tihInstallationID, tihLastSeenAt)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
			);
		$database->query(
			"INSERT IGNORE INTO InstallationIpHistory
			 (tihInstallationID, tihIP, tihFirstSeenAt, tihLastSeenAt, tihRequestCount)
			 SELECT tiID, tiLastIP, IF(tiRegisteredAt>0, tiRegisteredAt, tiLastSeenAt), tiLastSeenAt, 1
			 FROM Installations WHERE tiLastIP<>''"
		);

		if (!$tableExists('InstallationDomainEvents'))
			$database->query(
				'CREATE TABLE InstallationDomainEvents (
				 tdeID BIGINT UNSIGNED AUTO_INCREMENT,
				 tdeInstallationID BIGINT UNSIGNED NOT NULL,
				 tdeOldDomain VARCHAR(253) NOT NULL,
				 tdeNewDomain VARCHAR(253) NOT NULL,
				 tdeObservedIP VARCHAR(45) NOT NULL,
				 tdeCreatedAt BIGINT UNSIGNED DEFAULT 0,
				 PRIMARY KEY (tdeID),
				 KEY TDEINSTALLATION (tdeInstallationID, tdeCreatedAt)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
			);

		if (!$tableExists('TelemetryIngestionCounters'))
			$database->query(
				'CREATE TABLE TelemetryIngestionCounters (
				 ticDay CHAR(10) NOT NULL,
				 ticCode VARCHAR(64) NOT NULL,
				 ticCount BIGINT UNSIGNED DEFAULT 0,
				 ticFirstAt BIGINT UNSIGNED DEFAULT 0,
				 ticLastAt BIGINT UNSIGNED DEFAULT 0,
				 PRIMARY KEY (ticDay, ticCode),
				 KEY TICLAST (ticLastAt)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
			);
	},
);
