<?php

use HScript\Database\Connection;

return array(
	'id' => '202610031200_account_login_threshold',
	'from' => '1.0.2',
	'to' => '1.0.3',
	'classification' => 'backup-required',
	'up' => static function (Connection $database): void {
		$column = $database->fetch1Row($database->query(
			'SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',
			array('AddInfo', 'aFailedLoginThreshold')
		));
		if (!$column)
			$database->query('ALTER TABLE AddInfo ADD COLUMN aFailedLoginThreshold smallint unsigned NULL DEFAULT NULL');
		elseif (!preg_match('/^smallint(?:\([0-9]+\))? unsigned$/iD', $column['COLUMN_TYPE']) || $column['IS_NULLABLE'] !== 'YES' || !in_array($column['COLUMN_DEFAULT'], array(null, 'NULL'), true))
			throw new RuntimeException('Existing account login threshold column is incompatible');
		if ((int)$database->fetch1($database->query(
			'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=? AND CONSTRAINT_TYPE=?',
			array('AddInfo', 'chk_account_login_threshold', 'CHECK')
		)) === 0)
			$database->query('ALTER TABLE AddInfo ADD CONSTRAINT chk_account_login_threshold CHECK (aFailedLoginThreshold IS NULL OR aFailedLoginThreshold BETWEEN 1 AND 100)');
		$clause = (string)$database->fetch1($database->query(
			'SELECT cc.CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS cc JOIN information_schema.TABLE_CONSTRAINTS tc ON tc.CONSTRAINT_SCHEMA=cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME=cc.CONSTRAINT_NAME WHERE tc.CONSTRAINT_SCHEMA=DATABASE() AND tc.TABLE_NAME=? AND tc.CONSTRAINT_NAME=?',
			array('AddInfo', 'chk_account_login_threshold')
		));
		if (preg_replace('/[`()\s]/', '', strtolower($clause)) !== 'afailedloginthresholdisnullorafailedloginthresholdbetween1and100')
			throw new RuntimeException('Existing account login threshold constraint is incompatible');
	},
);
