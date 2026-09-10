<?php

use HScript\Database\Connection;

return array(
	'id' => '202609071200_phase1_probe',
	'from' => '1.0.0',
	'to' => '1.0.1',
	'classification' => 'backup-required',
	'up' => static function (Connection $database): void {
		$exists = (bool)$database->fetch1($database->query(
			'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
			array('Phase1MigrationProbe')
		));
		if (!$exists)
			$database->query(
				'CREATE TABLE Phase1MigrationProbe (probeID int not null, PRIMARY KEY (probeID)) ENGINE=InnoDB'
			);
	},
);
