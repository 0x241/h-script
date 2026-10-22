<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (__FILE__ !== '/fixture/gateway_http_audit_lock.php'
    || getenv('H_SCRIPT_GATEWAY_HTTP_CAPTURE') !== '1' || getenv('H_SCRIPT_FINANCIAL_TEST') !== '1'
    || getenv('APP_ENV') !== 'development' || getenv('APP_DOMAIN') !== 'fixture.invalid'
    || getenv('DB_NAME') !== 'fixture' || !in_array($argv[1] ?? '', ['hold', 'release'], true)) exit(64);

umask(0077);
$marker='/tmp/gateway-http-audit-lock-';
if ($argv[1] === 'release') {
    if (file_put_contents($marker.'release','release') !== 7) throw new RuntimeException('Audit control release failed');
    exit;
}
$path='/var/www/html/logs/observability.ndjson';
$lock=fopen($path.'.lock','c+b');
if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('Audit control lock failed');
try {
    $before=is_file($path) ? hash_file('sha256',$path) : hash('sha256','');
    if (file_put_contents($marker.'ready','ready') !== 5) throw new RuntimeException('Audit control readiness failed');
    $deadline=microtime(true)+120;
    while (!is_file($marker.'release') && microtime(true)<$deadline) usleep(20000);
    if (!is_file($marker.'release')) throw new RuntimeException('Audit control release timeout');
    $after=is_file($path) ? hash_file('sha256',$path) : hash('sha256','');
    if (!hash_equals($before,$after)) throw new RuntimeException('Shared audit sink changed while locked');
    if (file_put_contents($marker.'done','unchanged') !== 9) throw new RuntimeException('Audit control completion failed');
} finally { fclose($lock); }
