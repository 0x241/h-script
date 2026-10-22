<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('H_SCRIPT_FINANCIAL_TEST') !== '1' || getenv('DB_NAME') !== 'fixture') exit(2);

use HScript\Database\Connection;
use HScript\Finance\IdempotencyConflict;
use HScript\Finance\IdempotencyRequest;
use HScript\Finance\IdempotencyResult;
use HScript\Finance\IdempotencyStore;
use HScript\Update\MigrationLoader;
use HScript\Update\MigrationRunner;
use HScript\Update\SchemaStateRepository;
use HScript\Update\VersionedMigration;

$worker = ($argv[1] ?? '') === '--worker';
if (!$worker) echo "Idempotency test starting.\n";
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
ini_set('display_errors', '1');
ini_set('error_log', '/dev/stderr');
$db = new Connection();
if (!$db->open(getenv('DB_HOST'), 'fixture', getenv('DB_USER'), getenv('DB_PASSWORD'))) throw new RuntimeException('Fixture unavailable');
$pdo = $db->link;
if (!$worker) echo "Fixture connection ready.\n";
$store = new IdempotencyStore($db);
$fingerprint = IdempotencyRequest::fingerprint(['amount' => '1.000000', 'currency_id' => 1]);
$effect = static function () use ($pdo): IdempotencyResult {
    $pdo->exec("INSERT INTO Opers (ouID,ocID,oOper,oSum,oState) VALUES (1,1,'CASHIN',1,3)");
    $id = (int)$pdo->lastInsertId();
    $pdo->exec('UPDATE Wallets SET wBal=wBal+1 WHERE wuID=1 AND wcID=1');
    $pdo->exec('UPDATE Users SET uBal=uBal+1 WHERE uID=1');
    return new IdempotencyResult(['operation_id' => $id, 'amount' => '1.000000'], 201, $id);
};
if (($argv[1] ?? '') === '--worker') {
    $key = $argv[2];
    $stage = $argv[3] ?? '';
    $result = $store->execute('test:cashin', $key, 1, $fingerprint, static function () use ($effect, $stage): IdempotencyResult {
        if ($stage === 'claim') { echo "ready\n"; fflush(STDOUT); while (true) usleep(100000); }
        $result = $effect();
        if ($stage === 'operation') { echo "ready\n"; fflush(STDOUT); while (true) usleep(100000); }
        usleep(10000);
        return $result;
    });
    if ($stage === 'commit') { echo "ready\n"; fflush(STDOUT); while (true) usleep(100000); }
    echo json_encode(['data' => $result->data, 'status' => $result->status, 'operation_id' => $result->operationId]);
    exit;
}
require $root . '/_dbstru.php';
foreach (['Cfg', 'SchemaState', 'SchemaMigrations', 'UpdateRuns', 'Users', 'Wallets', 'Opers'] as $table)
    $pdo->exec('CREATE TABLE `' . $table . '` (' . $_dbstru[$table] . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$pdo->exec("INSERT INTO Users (uID,uLogin,uBal) VALUES (1,'synthetic-idempotency',0)");
$pdo->exec('INSERT INTO Wallets (wuID,wcID,wBal) VALUES (1,1,0)');
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++; if (!$condition) throw new RuntimeException($message);
};
$state = new SchemaStateRepository($db);
$state->setCurrentVersion('1.0.3');
$state->setInstalledApplicationVersion('1.0.8');
echo "Fixture schema ready.\n";
$runner = new MigrationRunner($db, $state, new MigrationLoader($root . '/migrations/versioned'));
$rejected = false;
try { $runner->run('1.0.4', false); } catch (RuntimeException $exception) { $rejected = str_contains($exception->getMessage(), 'verified backup'); }
$assert($rejected, 'Registry must require verified backup');
try { $applied = $runner->run('1.0.4', true); }
catch (RuntimeException $exception) {
    echo json_encode($pdo->query("SELECT CONSTRAINT_NAME,CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE()")->fetchAll()), "\n";
    throw $exception;
}
$assert($applied === ['202610031500_financial_idempotency'], 'Explicit registry migration did not run');
$assert($runner->run('1.0.4', true) === [], 'Registry rerun is not stable');
$migration = VersionedMigration::fromFile($root . '/migrations/versioned/202610031500_financial_idempotency.php');
$migration->apply($db);
$assert($state->currentVersion() === '1.0.4', 'Registry did not advance schema');
$pdo->exec('ALTER TABLE IdempotencyKeys DROP INDEX uq_idempotency_namespace_key');
$rejected = false;
try { $migration->apply($db); } catch (RuntimeException) { $rejected = true; }
$assert($rejected, 'Migration silently accepted missing uniqueness');
$pdo->exec('ALTER TABLE IdempotencyKeys ADD UNIQUE KEY uq_idempotency_namespace_key (ikNamespace,ikKeyHash)');
$migration->apply($db);
$pdo->exec('ALTER TABLE IdempotencyKeys MODIFY ikNamespace varchar(96) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL');
$rejected = false;
try { $migration->apply($db); } catch (RuntimeException) { $rejected = true; }
$assert($rejected, 'Migration accepted case-insensitive identities');
$pdo->exec('ALTER TABLE IdempotencyKeys MODIFY ikNamespace varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL');
$migration->apply($db);
$assert($fingerprint === IdempotencyRequest::fingerprint(['currency_id' => 1, 'amount' => '1.000000']), 'Object field order changes fingerprint');
$assert(IdempotencyRequest::fingerprint(['items' => [1, 2]]) !== IdempotencyRequest::fingerprint(['items' => [2, 1]]), 'List order was discarded');
foreach (['', 'with space', 'unsafe/key', str_repeat('a', 129), 'ключ'] as $key) {
    $rejected = false;
    try { $store->execute('test:cashin', $key, 1, $fingerprint, $effect); } catch (InvalidArgumentException) { $rejected = true; }
    $assert($rejected, 'Invalid request key accepted');
}
$first = $store->execute('test:cashin', 'sequential', 1, $fingerprint, $effect, 2592000);
$again = $store->execute('test:cashin', 'sequential', 1, $fingerprint, static function (): never { throw new RuntimeException('Replay executed work'); });
$assert(!$first->replayed && $again->replayed && $first->data === $again->data && $first->status === $again->status && $first->operationId === $again->operationId, 'Retry did not return the original result');
$conflict = false;
try { $store->execute('test:cashin', 'sequential', 1, IdempotencyRequest::fingerprint(['amount' => '2.000000']), $effect); } catch (IdempotencyConflict) { $conflict = true; }
$assert($conflict, 'Same key with different body did not conflict');
$otherOwner = $store->execute('test:cashin', 'sequential', 2, $fingerprint, static fn(): IdempotencyResult => new IdempotencyResult(['owner' => 2]));
$assert(!$otherOwner->replayed && $otherOwner->data === ['owner' => 2], 'Another owner received the previous owner response');
$otherRoute = $store->execute('test:withdraw', 'sequential', 1, $fingerprint, static fn(): IdempotencyResult => new IdempotencyResult(['route' => 'withdraw']));
$assert(!$otherRoute->replayed, 'Distinct namespace reused another route result');
$caseKey = $store->execute('test:cashin', 'Sequential', 1, $fingerprint, static fn(): IdempotencyResult => new IdempotencyResult(['case' => true]));
$assert(!$caseKey->replayed, 'Case-sensitive key was collapsed');
$pdo->exec('UPDATE IdempotencyKeys SET ikExpiresAt=1');
$assert($store->execute('test:cashin', 'sequential', 1, $fingerprint, $effect)->replayed, 'Expiry enabled a second financial effect');
$assert((int)$pdo->query('SELECT COUNT(*) FROM Opers')->fetchColumn() === 1, 'Exact retries created operations');
$assert($pdo->query('SELECT ikKeyHash FROM IdempotencyKeys LIMIT 1')->fetchColumn() !== 'sequential', 'Raw client key was stored');
foreach (['x', str_repeat('k', 128)] as $key)
    $assert(!$store->execute('test:boundaries', $key, 1, $fingerprint, static fn(): IdempotencyResult => new IdempotencyResult([]))->replayed, 'Valid key boundary rejected');
$foundRowsDatabase = new Connection();
$foundRowsDatabase->link = new PDO('mysql:host=database;dbname=fixture;charset=utf8mb4', getenv('DB_USER'), getenv('DB_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false, PDO::MYSQL_ATTR_FOUND_ROWS => true,
]);
$foundRowsStore = new IdempotencyStore($foundRowsDatabase);
$assert($foundRowsStore->execute('test:cashin', 'sequential', 1, $fingerprint, static function (): never { throw new RuntimeException('FOUND_ROWS replay executed work'); })->replayed, 'FOUND_ROWS option bypassed replay');
$assert(!$foundRowsStore->execute('test:options', 'foundrows-new', 1, $fingerprint, static fn(): IdempotencyResult => new IdempotencyResult([]))->replayed, 'FOUND_ROWS option rejected a new claim');
foreach (["UPDATE IdempotencyKeys SET ikState='unsafe'", "UPDATE IdempotencyKeys SET ikResponse='not-json'"] as $sql) {
    $rejected = false;
    try { $pdo->exec($sql); } catch (PDOException) { $rejected = true; }
    $assert($rejected, 'Database did not enforce the idempotency CHECK constraint');
}

$workers = [];
for ($i = 0; $i < 16; $i++) {
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_log=/dev/stderr', __FILE__, '--worker', 'concurrent'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Worker unavailable');
    $workers[] = [$process, $pipes];
}
$responses = [];
foreach ($workers as [$process, $pipes]) {
    $out = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $assert(proc_close($process) === 0 && $error === '', 'Concurrent worker failed: ' . $error);
    $responses[] = $out;
}
$assert(count(array_unique($responses)) === 1 && json_decode($responses[0], true)['status'] === 201, 'Concurrent responses differ');
$assert((int)$pdo->query('SELECT COUNT(*) FROM Opers')->fetchColumn() === 2, 'Concurrent request produced duplicate operations');
$assert($pdo->query('SELECT wBal FROM Wallets')->fetchColumn() === '2.000000' && $pdo->query('SELECT uBal FROM Users')->fetchColumn() === '2.000000', 'Concurrent duplicate changed balances twice');

foreach (['claim', 'operation', 'commit'] as $stage) {
    $before = (int)$pdo->query('SELECT COUNT(*) FROM Opers')->fetchColumn();
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_log=/dev/stderr', __FILE__, '--worker', 'crash-' . $stage, $stage], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Crash worker unavailable');
    $assert(trim((string)fgets($pipes[1])) === 'ready', 'Crash boundary was not reached');
    proc_terminate($process, 9);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
    $assert($error === '', 'Crash worker failed before injection');
    $result = $store->execute('test:cashin', 'crash-' . $stage, 1, $fingerprint, $effect);
    $assert($result->replayed === ($stage === 'commit'), 'Crash recovery selected wrong state');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM Opers')->fetchColumn() === $before + 1, 'Crash/retry duplicated or lost a local effect');
}
$before = (int)$pdo->query('SELECT COUNT(*) FROM Opers')->fetchColumn();
$failed = false;
try { $store->execute('test:cashin', 'failure', 1, $fingerprint, static function () use ($effect): never { $effect(); throw new RuntimeException('synthetic_failure'); }); }
catch (RuntimeException $exception) { $failed = $exception->getMessage() === 'synthetic_failure'; }
$assert($failed && (int)$pdo->query('SELECT COUNT(*) FROM Opers')->fetchColumn() === $before, 'Failure did not roll back its operation');
$assert(!$store->execute('test:cashin', 'failure', 1, $fingerprint, $effect)->replayed, 'Rolled-back claim survived');
$before = (int)$pdo->query('SELECT COUNT(*) FROM Opers')->fetchColumn();
$failed = false;
try { $store->execute('test:cashin', 'oversized', 1, $fingerprint, static function () use ($effect): IdempotencyResult { $effect(); return new IdempotencyResult(['body' => str_repeat('x', 65537)]); }); }
catch (RuntimeException $exception) { $failed = str_contains($exception->getMessage(), 'storage bound'); }
$assert($failed && (int)$pdo->query('SELECT COUNT(*) FROM Opers')->fetchColumn() === $before, 'Response overflow committed a financial effect');
$pdo->beginTransaction();
$failed = false;
try { $store->execute('test:cashin', 'nested', 1, $fingerprint, $effect); } catch (LogicException) { $failed = true; }
$assert($failed && $pdo->inTransaction(), 'Nested caller transaction was silently ended');
$pdo->rollBack();
$count = (int)$pdo->query('SELECT COUNT(*) FROM Opers')->fetchColumn();
$assert($pdo->query('SELECT wBal FROM Wallets')->fetchColumn() === number_format($count, 6, '.', '') && $pdo->query('SELECT uBal FROM Users')->fetchColumn() === number_format($count, 6, '.', ''), 'Final financial effects and balance totals diverge');
$assert((int)$pdo->query("SELECT COUNT(*) FROM IdempotencyKeys WHERE ikState='pending'")->fetchColumn() === 0, 'Committed pending keys remained');
echo "Idempotency foundation database tests passed ($checks checks).\n";
