<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use HScript\Http\CspReportCollector;
use HScript\Http\CspReportSummary;

require dirname(__DIR__) . '/vendor/autoload.php';
if (getenv('H_SCRIPT_NDJSON_RUNTIME_TEST') !== '1' || !is_link(dirname(__DIR__) . '/logs')) throw new RuntimeException('Use the isolated Docker runtime layout');
$directory = dirname(__DIR__) . '/logs';
$body = json_encode(array('csp-report' => array('effective-directive' => 'script-src', 'blocked-uri' => 'inline', 'document-uri' => 'https://fixture.invalid/login?private=secret', 'source-file' => 'https://fixture.invalid/static/js/app.js?private=secret')));
$status = CspReportCollector::handle(array('REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/csp-report', 'REMOTE_ADDR' => '192.0.2.1'), $body, $directory);
if ($status !== 204) throw new RuntimeException('CSP collector rejected the standard Docker logs alias');
$summary = CspReportSummary::summarize($directory . '/csp-violations.ndjson');
if ($summary['history']['records'] !== 1 || str_contains(json_encode($summary), 'secret')) throw new RuntimeException('CSP runtime history summary failed');
echo "Docker runtime logs symlink: CSP collector 204, private NDJSON append and sanitized summary passed.\n";
