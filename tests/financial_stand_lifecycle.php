<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('H_SCRIPT_FINANCIAL_TEST') !== '1' || getenv('DB_NAME') !== 'fixture') exit(2);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/_dbstru.php';
$pdo = new PDO('mysql:host=database;dbname=fixture', getenv('DB_USER'), getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach ($_dbstru as $table => $definition) $pdo->exec('CREATE TABLE IF NOT EXISTS `' . $table . '` (' . $definition . ') ENGINE=InnoDB');
$checks = 0;
$assert = static function (bool $ok): void { global $checks; if (!$ok) throw new RuntimeException('Financial stand lifecycle failed'); $checks++; };
$digest = static function () use ($pdo): array {
    $result = [];
    foreach (['Users','AddInfo','Currs','Wallets','Opers','Plans','Deps','Hist','Cfg','News','FAQ','Review','Tickets','TMsg','Msg','MBox'] as $table) {
        $hash = hash_init('sha256');
        $query = $pdo->query('SELECT * FROM `' . $table . '` ORDER BY 1,2');
        while ($row = $query->fetch(PDO::FETCH_ASSOC)) hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR) . "\n");
        $result[$table] = hash_final($hash);
    }
    return $result;
};
$run = static function (array $arguments): array {
    $output = tempnam(sys_get_temp_dir(), 'hs-stand-output-');
    $errors = tempnam(sys_get_temp_dir(), 'hs-stand-errors-');
    try {
        // Separate private files avoid a blocked stderr pipe while legacy diagnostics are emitted.
        $process = proc_open(array_merge([PHP_BINARY, __DIR__ . '/fixtures/financial_stand.php'], $arguments), [0 => ['file','/dev/null','r'], 1 => ['file',$output,'w'], 2 => ['file',$errors,'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start isolated financial fixture');
        $code = proc_close($process);
        $result = json_decode(file_get_contents($output), true);
        if ($result === null && $code === 0) {
            preg_match_all('/Undefined array key "([A-Za-z0-9_]+)"/', file_get_contents($output) . file_get_contents($errors), $matches);
            throw new RuntimeException('Financial fixture output contained unexpected diagnostics (keys: ' . implode(',', array_unique($matches[1])) . ')');
        }
        return [$code, $result];
    } finally { unlink($output); unlink($errors); }
};
$before = $digest();
[$code, $seed] = $run(['--users=16','--rounds=1']);
$assert($code === 0 && ($seed['rows']['Users'] ?? 0) === 16);
$assert(($seed['rows']['Opers'] ?? 0) > 100 && ($seed['rows']['Deps'] ?? 0) === 80);
[$code, $again] = $run(['--users=16','--rounds=1']);
$assert($code === 0 && ($again['status'] ?? '') === 'fixture_already_seeded');
$assert($again['rows'] === $seed['rows']);
$pdo->exec("UPDATE Cfg SET Val='seeding' WHERE Module='Fixture7' AND Prop='Status'");
[$code] = $run(['--users=16','--rounds=1']);
$assert($code === 1);
$pdo->exec("UPDATE Cfg SET Val='completed' WHERE Module='Fixture7' AND Prop='Status'");
$original = $pdo->query("SELECT uID,uRef FROM Users WHERE uLogin NOT LIKE 'zz-fixture7-%' ORDER BY uID LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$fake = $pdo->query("SELECT uID FROM Users WHERE uLogin LIKE 'zz-fixture7-%' ORDER BY uID LIMIT 1")->fetchColumn();
$pdo->prepare('UPDATE Users SET uRef=? WHERE uID=?')->execute([$fake,$original['uID']]);
try {
    [$code] = $run(['--clear']);
    $assert($code === 1);
    [$code, $status] = $run(['--status']);
    $assert($code === 0 && $status['rows'] === $seed['rows']);
} finally { $pdo->prepare('UPDATE Users SET uRef=? WHERE uID=?')->execute([$original['uRef'],$original['uID']]); }
[$code, $clear] = $run(['--clear']);
$assert($code === 0 && array_sum($clear['rows']) === 0);
$assert($digest() === $before);
echo "Financial stand lifecycle passed: $checks checks (seed, repeat, partial-run refusal, cross-link guard, marker-only cleanup, original rows preserved).\n";
