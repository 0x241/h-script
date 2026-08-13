<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$domain = (string)(getenv('APP_DOMAIN') ?: 'localhost');
$_SERVER['SERVER_NAME'] = $domain;
$_SERVER['HTTP_HOST'] = $domain;
$_SERVER += array(
	'SCRIPT_NAME' => '/bin/migrate-jobs.php',
	'SERVER_PORT' => 80,
	'REQUEST_URI' => '/',
	'SERVER_ADDR' => '127.0.0.1',
	'REMOTE_ADDR' => '127.0.0.1',
);

chdir($root);
require $root . '/vendor/autoload.php';

global $_cfg;
$_cfg = array();
if (is_file($root . '/_config.php'))
	require $root . '/_config.php';
if (is_file($root . '/_config.local.php'))
	require $root . '/_config.local.php';
if (!hsHasDatabaseConfiguration($_cfg))
{
	fwrite(STDERR, "Application database configuration was not found.\n");
	exit(1);
}

require $root . '/module/dbinit.php';
require $root . '/_dbstru.php';

$dropLegacy = in_array('--drop-legacy', $argv, true);
$tableExists = static function (string $table) use ($db): bool
{
	return (bool)$db->fetch1($db->query(
		'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
		array($table)
	));
};

if (!$tableExists('Jobs'))
{
	$db->query(
		'CREATE TABLE Jobs (' . $_dbstru['Jobs'] . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
	);
	echo "Created Jobs table.\n";
}

$migrated = 0;
$skipped = 0;
if ($tableExists('Queue'))
{
	$knownLegacyIds = array();
	$existingJobs = $db->fetchRows($db->select('Jobs', 'jPayload', 'jType=?', array('sms')));
	foreach ($existingJobs as $job)
	{
		try
		{
			$payload = json_decode((string)$job['jPayload'], true, 512, JSON_THROW_ON_ERROR);
		}
		catch (JsonException)
		{
			continue;
		}
		$legacyId = (int)($payload['legacy_queue_id'] ?? 0);
		if ($legacyId > 0)
			$knownLegacyIds[$legacyId] = true;
	}

	$legacyRows = $db->fetchRows($db->select('Queue', '*', '', array(), 'qID'));
	foreach ($legacyRows as $row)
	{
		$legacyId = (int)$row['qID'];
		if (isset($knownLegacyIds[$legacyId]))
		{
			$skipped++;
			continue;
		}
		$legacyState = (int)$row['qState'];
		$state = match ($legacyState)
		{
			0, 1 => 0,
			2, 3 => 2,
			default => 3,
		};
		$deliveryStatus = match ($legacyState)
		{
			2 => 'sent',
			3 => 'delivered',
			4 => 'failed',
			9 => 'suspended',
			default => 'queued',
		};
		$payload = array(
			'uid' => (int)$row['quID'],
			'from' => (string)$row['qFrom'],
			'to' => (string)$row['qTo'],
			'text' => (string)$row['qText'],
			'translit' => (bool)$row['qTranslit'],
			'test' => (bool)$row['qTest'],
			'legacy_queue_id' => $legacyId,
			'result' => array(
				'provider_id' => (string)$row['qKey'],
				'provider_status' => (string)$row['qError'],
				'delivery_status' => $deliveryStatus,
				'parts' => (int)$row['qParts'],
				'price' => (float)$row['qPrice'],
			),
		);
		$db->insert('Jobs', array(
			'jType' => 'sms',
			'jPayload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
			'jState' => $state,
			'jAttempts' => min(3, max(0, (int)$row['qErrCnt'])),
			'jMaxAttempts' => 3,
			'jCTS' => (int)$row['qTS'],
			'jPTS' => (int)$row['qSTS'],
			'jDTS' => $state >= 2 ? (int)$row['qSTS'] : 0,
			'jError' => $state === 3 ? (string)$row['qError'] : '',
		));
		$migrated++;
	}

	$legacyCount = (int)$db->count('Queue');
	$migratedLegacyIds = array();
	foreach ($db->fetchRows($db->select('Jobs', 'jPayload', 'jType=?', array('sms'))) as $job)
	{
		try
		{
			$payload = json_decode((string)$job['jPayload'], true, 512, JSON_THROW_ON_ERROR);
		}
		catch (JsonException)
		{
			continue;
		}
		$legacyId = (int)($payload['legacy_queue_id'] ?? 0);
		if ($legacyId > 0)
			$migratedLegacyIds[$legacyId] = true;
	}
	if (count($migratedLegacyIds) < $legacyCount)
		throw new RuntimeException('Not all legacy Queue rows were migrated; Queue was preserved');

	if ($dropLegacy)
	{
		$db->query('DROP TABLE Queue');
		echo "Dropped legacy Queue table after verification.\n";
	}
	else
		echo "Legacy Queue table preserved. Re-run with --drop-legacy after taking a database backup.\n";
}

if ($tableExists('Cfg'))
{
	clearstatcache();
	$db->replace('Cfg', array(
		'Module' => 'Const',
		'Prop' => 'DBVer',
		'Val' => is_file($root . '/_dbstru.php') ? (int)filemtime($root . '/_dbstru.php') : 0,
	));
}

echo "Jobs migration complete: migrated=$migrated skipped=$skipped.\n";
