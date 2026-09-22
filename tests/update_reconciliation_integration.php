<?php
declare(strict_types=1);

use HScript\Database\Connection;
use HScript\Observability\OperationalStateRepository;
use HScript\Recovery\RecoveryDrillVerifier;
use HScript\Update\SchemaStateRepository;
use HScript\Update\UpdateHealthChecker;

if (getenv('H_SCRIPT_UPDATE_SERVICE_TEST') !== '1') throw new RuntimeException('Isolated test database opt-in required');
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$db = new Connection();
if (!$db->open(getenv('DB_HOST'), getenv('DB_NAME'), getenv('DB_USER'), getenv('DB_PASSWORD'))) throw new RuntimeException('Test DB unavailable');
$checker = new UpdateHealthChecker($db, $root);
$state = new SchemaStateRepository($db);
$configuration = $db->fetchRows($db->query("SELECT * FROM Cfg WHERE Module IN ('Cron','Telemetry','Const')"));
$balances = $db->fetchRows($db->query('SELECT uID,uBal FROM Users'));
$schema = $state->currentVersion();
$routes = file_get_contents($root . '/module/_config.php');
$cronPath = $root . '/.cfg/observability/cron-success.json';
$cronBefore = is_file($cronPath) ? file_get_contents($cronPath) : null;
function reconciliationReject(callable $operation, string $message): void
{
	try { $operation(); } catch (RuntimeException) { return; }
	throw new RuntimeException($message);
}
function reconciliationFingerprint(Connection $db): string
{
	$tables = $db->fetchRows($db->query('SHOW TABLES'), 1);
	$result = array();
	foreach ($tables as $table)
	{
		$rows = array_map('serialize', $db->fetchRows($db->query('SELECT * FROM ' . $db->field($table))));
		sort($rows);
		$result[$table] = $rows;
	}
	ksort($result);
	return hash('sha256', serialize($result));
}
function reconciliationCli(array $environment = array()): array
{
	$process = proc_open(array(PHP_BINARY, dirname(__DIR__) . '/bin/update.php', 'reconcile'),
		array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes,
		dirname(__DIR__), array_replace(getenv(), $environment));
	if (!is_resource($process)) throw new RuntimeException('Reconciliation CLI could not start');
	fclose($pipes[0]);
	$output = stream_get_contents($pipes[1]); fclose($pipes[1]);
	$error = stream_get_contents($pipes[2]); fclose($pipes[2]);
	$exit = proc_close($process);
	if ($error !== '') throw new RuntimeException('Reconciliation CLI leaked error details');
	return array($exit, json_decode($output, true, 32, JSON_THROW_ON_ERROR));
}
try
{
	$db->replace('Cfg', array('Module' => 'Cron', 'Prop' => 'Enabled', 'Val' => '1'));
	// Synthetic success metadata only: the isolated network cannot call a collector.
	$db->replace('Cfg', array('Module' => 'Telemetry', 'Prop' => 'LastSuccessAt', 'Val' => (string)time()));
	(new OperationalStateRepository($root))->recordCron('success');
	$before = reconciliationFingerprint($db);
	[$exit, $result] = reconciliationCli();
	if ($exit !== 0 || $result['status'] !== 'ready') throw new RuntimeException('Healthy reconciliation CLI failed');
	[$exit, $result] = reconciliationCli(array('DB_PASSWORD' => 'synthetic-rejected-credential'));
	if ($exit !== 1 || $result['status'] !== 'not_ready' || $result['error_code'] !== 'reconciliation_failed')
		throw new RuntimeException('Database connection failure did not fail closed');
	if (isset($result['failed_checks'])) throw new RuntimeException('Unknown connection failure invented check details');
	if ($db->query('START TRANSACTION READ ONLY') === false) throw new RuntimeException('Read-only transaction failed');
	try
	{
		if ($checker->reconcile()['status'] !== 'ready') throw new RuntimeException('Healthy fixture rejected');
		$writeBlocked = false;
		try { $writeBlocked = $db->query('UPDATE Users SET uBal=uBal') === false; }
		catch (Throwable) { $writeBlocked = true; }
		if (!$writeBlocked) throw new RuntimeException('Database did not enforce read-only transaction');
	}
	finally { $db->query('ROLLBACK'); }
	if ($before !== reconciliationFingerprint($db)) throw new RuntimeException('Reconciliation changed database rows');
	foreach (array('0', (string)(time() - 200000), (string)(time() + 1000)) as $timestamp)
	{
		$db->replace('Cfg', array('Module' => 'Telemetry', 'Prop' => 'LastSuccessAt', 'Val' => $timestamp));
		reconciliationReject(fn() => $checker->reconcile(), 'Missing/stale/future telemetry accepted');
	}
	$db->replace('Cfg', array('Module' => 'Telemetry', 'Prop' => 'LastSuccessAt', 'Val' => (string)time()));
	$db->query('UPDATE Users SET uBal=-1');
	reconciliationReject(fn() => $checker->reconcile(), 'Financial violation accepted');
	foreach ($balances as $row) $db->update('Users', array('uBal' => $row['uBal']), '', 'uID=?', array($row['uID']));
	file_put_contents($cronPath, json_encode(array('updated_at' => gmdate('c', time() - 10000), 'status' => 'success')));
	reconciliationReject(fn() => $checker->reconcile(), 'Stale cron accepted');
	(new OperationalStateRepository($root))->recordCron('success');
	$state->setInstalledApplicationVersion('1.0.0');
	reconciliationReject(fn() => $checker->reconcile(), 'Application drift accepted');
	[$exit, $result] = reconciliationCli();
	if ($exit !== 1 || ($result['failed_checks'] ?? null) !== array('installed_application'))
		throw new RuntimeException('Reconciliation CLI did not identify application drift safely');
	$state->setInstalledApplicationVersion(trim(file_get_contents($root . '/VERSION')));
	$state->setCurrentVersion('1.0.0');
	reconciliationReject(fn() => $checker->reconcile(), 'Schema drift accepted');
	$state->setCurrentVersion($schema);
	file_put_contents($root . '/module/_config.php', '<?php $_rwlinks=[]; $_oncron=[];');
	reconciliationReject(fn() => $checker->reconcile(), 'Broken routes accepted');
	file_put_contents($root . '/module/_config.php', $routes);
	file_put_contents($root . '/.cfg/maintenance.json', '{}');
	reconciliationReject(fn() => $checker->reconcile(), 'Maintenance accepted');
	unlink($root . '/.cfg/maintenance.json');
	$db->query("INSERT INTO SchemaMigrations (smID,smChecksum,smFromVersion,smToVersion,smClassification,smStatus,smAppVersion) VALUES ('synthetic_unknown',?, '1.0.0','1.0.1','backup-required','applied','1.0.4')", array(str_repeat('a', 64)));
	reconciliationReject(fn() => $checker->reconcile(), 'Unknown migration accepted');
	$db->delete('SchemaMigrations', 'smID=?', array('synthetic_unknown'));
	$job = $db->insert('Jobs', array('jType' => 'phase4_fixture', 'jPayload' => '{}', 'jState' => 99));
	try { reconciliationReject(fn() => $checker->reconcile(), 'Invalid queue state accepted'); }
	finally { $db->delete('Jobs', 'jID=?', array($job)); }
	$db->query('RENAME TABLE Wallets TO Phase4HiddenWallets');
	try { reconciliationReject(fn() => $checker->reconcile(), 'Failed financial query interpreted as zero'); }
	finally { $db->query('RENAME TABLE Phase4HiddenWallets TO Wallets'); }
	if ($checker->reconcile()['status'] !== 'ready') throw new RuntimeException('Restored fixture rejected');
	echo "Read-only reconciliation, lifecycle/finance/query/cron/telemetry/route failure tests passed.\n";
}
finally
{
	$db->delete('Cfg', "Module IN ('Cron','Telemetry','Const')");
	foreach ($configuration as $row) $db->insert('Cfg', $row);
	foreach ($balances as $row) $db->update('Users', array('uBal' => $row['uBal']), '', 'uID=?', array($row['uID']));
	$state->setCurrentVersion($schema);
	$db->delete('SchemaMigrations', 'smID=?', array('synthetic_unknown'));
	$db->delete('Jobs', 'jType=?', array('phase4_fixture'));
	file_put_contents($root . '/module/_config.php', $routes);
	if (is_file($root . '/.cfg/maintenance.json')) unlink($root . '/.cfg/maintenance.json');
	if ($cronBefore !== null) file_put_contents($cronPath, $cronBefore);
	elseif (is_file($cronPath)) unlink($cronPath);
	$db->close();
}
