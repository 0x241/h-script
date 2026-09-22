<?php
declare(strict_types=1);

use HScript\Backup\BackupService;
use HScript\Backup\BackupSettings;
use HScript\Backup\DatabaseCredentials;
use HScript\Backup\DatabaseRestoreService;
use HScript\Backup\PhpStreamingBackupAdapter;
use HScript\Database\Connection;
use HScript\Recovery\IsolatedDatabase;
use HScript\Recovery\RuntimeArchiveService;

if (getenv('H_SCRIPT_UPDATE_SERVICE_TEST') !== '1') throw new RuntimeException('Disposable database opt-in required');
require dirname(__DIR__) . '/vendor/autoload.php';
$db = new Connection();
if (!$db->open(getenv('DB_HOST'), getenv('DB_NAME'), getenv('DB_USER'), getenv('DB_PASSWORD')))
    throw new RuntimeException('Disposable source unavailable');
$source = new DatabaseCredentials(getenv('DB_HOST'), getenv('DB_NAME'), getenv('DB_USER'), getenv('DB_PASSWORD'));
$temporary = sys_get_temp_dir() . '/hscript-json-backup-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
$previous = array();
foreach (array('RECOVERY_DRILL_DB_HOST'=>getenv('DB_HOST'), 'RECOVERY_DRILL_DB_ADMIN_USER'=>'root',
    'RECOVERY_DRILL_DB_ADMIN_PASSWORD'=>'synthetic-isolated-root', 'RECOVERY_DRILL_DB_PREFIX'=>'json_drill') as $key=>$value) {
    $previous[$key] = getenv($key); putenv($key . '=' . $value);
}
$isolated = new IsolatedDatabase();
$restored = new Connection();
try {
    $db->query('CREATE TABLE BackupJsonProbe (id INT PRIMARY KEY, payload JSON NULL, bytes LONGBLOB, amount DECIMAL(30,8))');
    $payload = json_encode(array('text'=>"Привет 🌍; \\\"", 'nested'=>array(true, null, 42)), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $binary = "\x00\xff\x80'\\;\n";
    $db->query('INSERT INTO BackupJsonProbe VALUES (?, ?, ?, ?), (2, NULL, ?, ?)', array(1, $payload, $binary, '12345678901234567890.12345678', '', '0.00000000'));
    $backups = new BackupService($db, $source, new BackupSettings($temporary, 'external'), array(new PhpStreamingBackupAdapter()));
    $manifest = $backups->create('plain');
    if ($manifest['adapter'] !== 'php-stream') throw new RuntimeException('Fallback adapter not exercised');
    // Force the PDO importer too; never depend on whether the CLI happens to fail.
    $target = $isolated->create();
    (new DatabaseRestoreService($backups, $source, '/bin/false'))->restore($manifest['id'], $target, $target->database());
    if (!$restored->open($target->connectionHost(), $target->database(), $target->username(), $target->password()))
        throw new RuntimeException('Disposable restored database unavailable');
    $rows = $restored->fetchRows($restored->query('SELECT id, payload, HEX(bytes) AS bytes_hex, amount FROM BackupJsonProbe ORDER BY id'));
    if (count($rows) !== 2 || json_decode($rows[0]['payload'], true, 32, JSON_THROW_ON_ERROR) !== json_decode($payload, true, 32, JSON_THROW_ON_ERROR)
        || $rows[0]['bytes_hex'] !== strtoupper(bin2hex($binary)) || $rows[0]['amount'] !== '12345678901234567890.12345678'
        || $rows[1]['payload'] !== null || $rows[1]['bytes_hex'] !== '' || $rows[1]['amount'] !== '0.00000000')
        throw new RuntimeException('JSON, binary, null or decimal backup round-trip mismatch');
    echo "Forced PHP backup / PDO restore: native JSON, Unicode, binary, null and decimal round-trip passed.\n";
} finally {
    $restored->close();
    $isolated->drop();
    $db->query('DROP TABLE IF EXISTS BackupJsonProbe');
    $db->close();
    foreach ($previous as $key=>$value) putenv($value === false ? $key : $key . '=' . $value);
    RuntimeArchiveService::removeTree($temporary);
}
