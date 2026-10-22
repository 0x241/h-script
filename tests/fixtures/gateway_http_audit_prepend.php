<?php

declare(strict_types=1);

// Only disposable HTTP stands mount this file outside the document root.
// The production logger deliberately skips a busy sink. Give each callback
// its own private sink so wire verification can require EVERY request's audit.
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
$privateAddress = filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
    && (str_starts_with($remote, '127.') || str_starts_with($remote, '10.')
        || str_starts_with($remote, '192.168.') || preg_match('/^172\.(?:1[6-9]|2[0-9]|3[01])\./D', $remote));
if (__FILE__ !== '/fixture/gateway_http_audit_prepend.php' || PHP_SAPI !== 'fpm-fcgi'
    || getenv('H_SCRIPT_GATEWAY_HTTP_CAPTURE') !== '1' || getenv('H_SCRIPT_FINANCIAL_TEST') !== '1'
    || getenv('APP_ENV') !== 'development' || getenv('APP_DOMAIN') !== 'fixture.invalid'
    || getenv('DB_NAME') !== 'fixture' || ($_SERVER['HTTP_HOST'] ?? '') !== 'fixture.invalid'
    || !$privateAddress
    || !in_array($_SERVER['SCRIPT_FILENAME'] ?? '', ['/var/www/html/rw.php', '/var/www/html/index.php'], true)) {
    if (PHP_SAPI === 'cli') exit(64);
    http_response_code(404); exit;
}
if (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) === '/balance/status') {
    // Never accept a log path or filename from headers, query or provider data.
    putenv('OBSERVABILITY_LOG_PATH=/var/www/html/logs/fixture-callback-' . bin2hex(random_bytes(16)) . '.ndjson');
}
