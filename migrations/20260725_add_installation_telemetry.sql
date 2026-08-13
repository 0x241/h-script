CREATE TABLE IF NOT EXISTS Installations (
    tiID BIGINT UNSIGNED AUTO_INCREMENT,
    tiPublicID CHAR(36) NOT NULL,
    tiDomain VARCHAR(253) NOT NULL,
    tiVersion VARCHAR(32) NOT NULL,
    tiInstalledAt BIGINT UNSIGNED DEFAULT 0,
    tiRegisteredAt BIGINT UNSIGNED DEFAULT 0,
    tiLastSeenAt BIGINT UNSIGNED DEFAULT 0,
    tiLastReportAt BIGINT UNSIGNED DEFAULT 0,
    tiTokenHash CHAR(64) NOT NULL,
    tiStatsConsent TINYINT(1) DEFAULT 0,
    tiState TINYINT(1) DEFAULT 1,
    tiLastIP VARCHAR(45) DEFAULT '',
    PRIMARY KEY (tiID),
    UNIQUE KEY TIPUBLICID (tiPublicID),
    KEY TIDOMAIN (tiDomain),
    KEY TILASTSEEN (tiState, tiLastSeenAt),
    KEY TIVERSION (tiVersion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS InstallationReports (
    tirID BIGINT UNSIGNED AUTO_INCREMENT,
    tirInstallationID BIGINT UNSIGNED NOT NULL,
    tirDay CHAR(10) NOT NULL,
    tirVersion VARCHAR(32) NOT NULL,
    tirStats JSON NULL,
    tirCreatedAt BIGINT UNSIGNED DEFAULT 0,
    tirUpdatedAt BIGINT UNSIGNED DEFAULT 0,
    PRIMARY KEY (tirID),
    UNIQUE KEY TIRDAY (tirInstallationID, tirDay),
    KEY TIRUPDATED (tirUpdatedAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS TelemetryServiceTokens (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
