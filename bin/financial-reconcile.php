<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use HScript\Backup\DatabaseCredentials;
use HScript\Finance\FinancialAuditSnapshot;
use HScript\Finance\FinancialReconciler;

// Never bootstrap the application: auth, telemetry, queues and migrations can write.
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
try {
    $mode = $argv[1] ?? '--json';
    if (count($argv) > 2 || !in_array($mode, ['--json','--text','--snapshot','--queries'], true)) {
        echo "Usage: php bin/financial-reconcile.php [--json|--text|--snapshot|--queries]\n";
        exit(64);
    }
    if ($mode === '--queries') {
        echo json_encode(FinancialReconciler::queries(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        exit(0);
    }
    if (getenv('DB_HOST') && getenv('DB_NAME') && (getenv('DB_USER') || getenv('DB_USER_FILE'))) {
        $credentials = new DatabaseCredentials(getenv('DB_HOST'), getenv('DB_NAME'), DatabaseCredentials::environmentValue('DB_USER'), DatabaseCredentials::environmentValue('DB_PASSWORD'));
    } else {
        $_cfg = [];
        if (is_file($root . '/_config.php')) require $root . '/_config.php';
        if (is_file($root . '/_config.local.php')) require $root . '/_config.local.php';
        $credentials = DatabaseCredentials::fromConfig($_cfg, (string)(getenv('APP_DOMAIN') ?: 'localhost'));
    }
    $dsn = $credentials->socket() !== '' ? 'unix_socket=' . $credentials->socket() : 'host=' . $credentials->host() . ';port=' . $credentials->port();
    $pdo = new PDO('mysql:' . $dsn . ';dbname=' . $credentials->database() . ';charset=utf8mb4', $credentials->username(), $credentials->password(), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    $result = $mode === '--snapshot' ? (new FinancialAuditSnapshot())->capture($pdo) : (new FinancialReconciler())->run($pdo);
    if ($mode === '--text') {
        echo 'status: ' . $result['status'] . "\n";
        echo 'finding_count: ' . $result['finding_count'] . "\n";
        foreach ($result['checks'] as $name => $check) {
            echo $name . ': ' . $check['count'] . "\n";
            foreach ($check['sample'] as $sample) echo '  ' . json_encode($sample, JSON_THROW_ON_ERROR) . "\n";
        }
        echo 'limitations: ' . implode(', ', $result['limitations']) . "\n";
    } else echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    exit($mode === '--snapshot' || $result['status'] === 'clear' ? 0 : 2);
} catch (Throwable) {
    // Do not expose a DSN, SQL, raw callback, config or exception message.
    echo ($mode ?? '') === '--text'
        ? "status: error\nerror_code: financial_reconciliation_failed\n"
        : "{\"status\":\"error\",\"error_code\":\"financial_reconciliation_failed\"}\n";
    exit(1);
}
