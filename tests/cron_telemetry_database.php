<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('H_SCRIPT_LOGIN_THRESHOLD_TEST') !== '1' || getenv('DB_NAME') !== 'fixture') exit(2);

use HScript\Cron\ModuleSchedule;
use HScript\Database\Connection;
use HScript\Telemetry\TelemetryAttemptGate;
use HScript\Telemetry\TelemetryClientInterface;
use HScript\Telemetry\TelemetryReporter;

require dirname(__DIR__) . '/vendor/autoload.php';
$connect = static function (): Connection {
    $connection = new Connection();
    if (!$connection->open(getenv('DB_HOST'), getenv('DB_NAME'), getenv('DB_USER'), getenv('DB_PASSWORD'))) throw new RuntimeException('Fixture unavailable');
    return $connection;
};
$db = $connect();
if (($argv[1] ?? '') === '--worker') {
    echo (new TelemetryAttemptGate($db))->claim(true)['allowed'] ? '1' : '0';
    exit;
}
if (($argv[1] ?? '') === '--cron-worker') {
    if (!(new ModuleSchedule($db))->acquire()) exit(3);
    echo $db->fetch1($db->query('SELECT CONNECTION_ID()')), "\n";
    fflush(STDOUT);
    fgets(STDIN); // The parent kills this process without releasing its lock.
    exit(4);
}
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};
$save = static function (string $property, int $value) use ($db): void {
    if ($db->replace('Cfg', ['Module' => 'Telemetry', 'Prop' => $property, 'Val' => $value]) === false) throw new RuntimeException('Fixture write failed');
};
$save('NetworkAttemptAt', 0);
$save('NextAttemptAt', 0);
$workers = [];
for ($i = 0; $i < 16; $i++) {
    $process = proc_open([PHP_BINARY, __FILE__, '--worker'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Worker unavailable');
    $workers[] = [$process, $pipes];
}
$wins = 0;
foreach ($workers as [$process, $pipes]) {
    $out = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $assert(proc_close($process) === 0 && $error === '', 'Attempt worker failed');
    $wins += (int)$out;
}
$assert($wins === 1, 'Concurrent requests claimed more than one outbound attempt');
$assert(!(new TelemetryAttemptGate($connect()))->claim(true)['allowed'], 'Restart bypassed the shared cooldown');
$save('NetworkAttemptAt', time() - 301);
$save('NextAttemptAt', time() + 86400);
$gate = new TelemetryAttemptGate($db);
$assert(!$gate->claim()['allowed'], 'Automatic attempt ignored daily schedule');
$assert($gate->claim(true)['allowed'], 'Manual retry cannot run after five minutes');

$client = new class implements TelemetryClientInterface {
    public int $calls = 0;
    public function request(string $method, string $path, array $payload, string $token): array {
        $this->calls++;
        return ['ok' => false, 'status' => 503, 'error' => 'synthetic_outage', 'data' => []];
    }
};
$save('NetworkAttemptAt', 0);
$save('NextAttemptAt', 0);
$reporter = new TelemetryReporter($db, [], 'fixture.invalid', $client);
$assert(!$reporter->register(true)['ok'] && $client->calls === 1, 'Offline attempt was not executed once');
$staleReporter = new TelemetryReporter($connect(), [], 'fixture.invalid', $client);
$blocked = $staleReporter->report(null, true);
$assert($blocked['error'] === 'telemetry_cooldown' && $client->calls === 1, 'Failed attempt or stale configuration bypassed cooldown');
$save('NetworkAttemptAt', time() - 301);
$assert($staleReporter->report()['error'] === 'telemetry_cooldown' && $client->calls === 1, 'Fresh process ignored stored next attempt');
$save('NextAttemptAt', 0);
$brokenStorage = new class extends Connection {
    public function query($query, $ph_values = null) { throw new RuntimeException('synthetic_storage_failure'); }
};
$unavailable = new TelemetryReporter($brokenStorage, [], 'fixture.invalid', $client);
$assert($unavailable->register(true)['error'] === 'telemetry_attempt_unavailable' && $client->calls === 1, 'Storage failure must not send network requests');

$first = new ModuleSchedule($db);
$otherDb = $connect();
$second = new ModuleSchedule($otherDb);
$assert($first->acquire(), 'Cron lock was not acquired');
$assert(!$second->acquire(), 'Another cron worker acquired the same lock');
$assert($first->claim('probe', '20261003000000', '20261003000500'), 'New schedule did not run');
$assert(!$first->claim('probe', '20261003000000', '20261003000500'), 'Claim ignored its stored future deadline');
$first->release();
$assert($second->acquire(), 'Completed worker did not release its lock');
$second->release();
$otherDb->close();
// A client disconnect is processed asynchronously by the server. Exercise a
// real worker death and observe server ownership before the next acquisition.
$process = proc_open([PHP_BINARY, __FILE__, '--cron-worker'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) throw new RuntimeException('Cron crash worker unavailable');
try {
    stream_set_timeout($pipes[1], 5);
    $owner = trim((string)fgets($pipes[1]));
    $assert(ctype_digit($owner), 'Cron worker did not acquire its lock');
    $lock = (string)$db->fetch1($db->query("SELECT CONCAT('hscript-cron-', LEFT(SHA2(DATABASE(),256),40))"));
    $lockOwner = static fn() => $db->fetch1($db->query('SELECT IS_USED_LOCK(?)', [$lock]));
    $assert((string)$lockOwner() === $owner && !$first->acquire(), 'Live cron worker did not retain exclusive ownership');
    $assert(proc_terminate($process, 9), 'Cron crash could not be injected');
    $deadline = microtime(true) + 5;
    do {
        $remainingOwner = $lockOwner();
        if ($remainingOwner === null) break;
        usleep(10000);
    } while (microtime(true) < $deadline);
    $assert($remainingOwner === null, 'Dead cron worker retained its server lock');
    $assert($first->acquire(), 'Worker crash left a permanent cron lock');
    $first->release();
} finally {
    if (proc_get_status($process)['running']) proc_terminate($process, 9);
    foreach ($pipes as $pipe) fclose($pipe);
    proc_close($process);
}

// The runner now includes a read-only PAW payout poll. Supply its real empty
// schema, rather than skipping that production branch or using a fake adapter.
require dirname(__DIR__) . '/_dbstru.php';
foreach (['PaymentProviders','PaymentProviderCredentials','Currs','Opers','IdempotencyKeys'] as $table)
    $db->link->exec('CREATE TABLE IF NOT EXISTS `' . $table . '` (' . $_dbstru[$table] . ') ENGINE=InnoDB');
$assert((int)$db->link->query('SELECT COUNT(*) FROM Opers')->fetchColumn() === 0, 'Cron fixture must not contain payout work');

// Exercise runner order and per-module failures without any external providers.
require dirname(__DIR__) . '/module/cron/lib.php';
function useLib($module): void {}
function smsUpdateDeliveryStatuses($count): void { $GLOBALS['runs'][] = 'jobs'; }
if (!defined('HS2_UNIX_MINUTE')) define('HS2_UNIX_MINUTE', 60);
$temporary = sys_get_temp_dir() . '/cron-fixture-' . bin2hex(random_bytes(8));
$_GS = ['module_dir' => $temporary . '/', 'module' => 'cron'];
$_cfg = ['Cron_Enabled' => 1, 'SMS_UpdateCount' => 1];
$_currs = [];
$_oncron = ['balance' => 5, 'depo' => 1, 'integrity' => 1440, 'telemetry' => 1440];
$jobQueue = new class {
    public function registerHandler(...$args): void {}
    public function recoverStale(...$args): void {}
    public function retryFailed(...$args): void {}
    public function processBatch(...$args): void {}
    public function cleanup(...$args): void {}
};
$runs = [];
try {
    foreach ($_oncron as $module => $interval) {
        mkdir($temporary . '/' . $module, 0700, true);
        file_put_contents($temporary . '/' . $module . '/oncron.php', '<?php $GLOBALS["runs"][]=' . var_export($module, true) . ';' . ($module === 'integrity' ? 'throw new RuntimeException("synthetic_integrity_failure");' : ''));
        $db->replace('Cfg', ['Module' => 'Cron', 'Prop' => $module, 'Val' => 0]);
    }
    $failed = false;
    try { opCronRun(); } catch (RuntimeException $exception) { $failed = $exception->getMessage() === 'cron_incomplete'; }
    $assert($failed && $runs === ['depo', 'integrity', 'telemetry', 'balance', 'jobs'], 'Failure or rate provider starved later modules');
    $runs = [];
    $assert(opCronRun() === 'success' && $runs === ['jobs'], 'Repeat manual run ignored module schedule');
    $assert((int)$db->link->query('SELECT COUNT(*) FROM IdempotencyKeys')->fetchColumn() === 0, 'Idle payout poll must not claim or send payments');
} finally {
    foreach ($_oncron as $module => $interval) { unlink($temporary . '/' . $module . '/oncron.php'); rmdir($temporary . '/' . $module); }
    rmdir($temporary);
}
echo "Cron and telemetry database tests passed ($checks checks).\n";
