<?php

use HScript\Database\Connection;

return array(
	'id' => '202609221200_domain_proof',
	'from' => '1.0.1',
	'to' => '1.0.2',
	'classification' => 'backup-required',
	'up' => static function (Connection $database): void {
		$columns = array(
			'tiDomainVerifiedAt' => 'bigint unsigned not null default 0',
			'tiDomainVerifiedUntil' => 'bigint unsigned not null default 0',
			'tiDomainProofHash' => "char(64) not null default ''",
			'tiDomainProofExpiresAt' => 'bigint unsigned not null default 0',
			'tiDomainProofAttemptAt' => 'bigint unsigned not null default 0',
			'tiDomainProofError' => "varchar(64) not null default ''",
		);
		foreach ($columns as $column => $definition)
			if ((int)$database->fetch1($database->query(
				'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',
				array('Installations', $column)
			)) === 0)
				$database->query('ALTER TABLE Installations ADD COLUMN `' . $column . '` ' . $definition);
	},
);
