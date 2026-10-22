<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use HScript\Http\CspReportCollector;

require dirname(__DIR__) . '/vendor/autoload.php';

$directory = sys_get_temp_dir() . '/hscript-csp-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
try
{
	$server = array(
		'REQUEST_METHOD' => 'POST',
		'CONTENT_TYPE' => 'application/csp-report',
		'REMOTE_ADDR' => '203.0.113.10',
	);
	$body = json_encode(array('csp-report' => array(
		'effective-directive' => 'script-src-attr',
		'blocked-uri' => 'https://blocked.example/path?secret=do-not-store',
		'document-uri' => 'https://site.example/ru/login?token=do-not-store',
		'source-file' => 'https://site.example/static/js/app.js?session=do-not-store',
		'line-number' => 12,
		'status-code' => 200,
	)), JSON_THROW_ON_ERROR);
	if (CspReportCollector::handle($server, $body, $directory) !== 204)
		throw new RuntimeException('Valid CSP report was rejected');
	$stored = (string)file_get_contents($directory . '/csp-violations.ndjson');
	if (str_contains($stored, 'do-not-store') || str_contains($stored, '203.0.113.10')
		|| !str_contains($stored, 'https://blocked.example') || !str_contains($stored, '"document_surface":"authentication"')
		|| !str_contains($stored, '/static/js/app.js') || !str_contains($stored, '"source_kind":"web"'))
		throw new RuntimeException('CSP report stored secrets or lost classification');
	if (CspReportCollector::handle($server, str_repeat('x', 8193), $directory) !== 413
		|| CspReportCollector::handle(array_replace($server, array('CONTENT_TYPE' => 'text/plain')), $body, $directory) !== 415)
		throw new RuntimeException('CSP report size or content-type limit failed');
	for ($i = 1; $i < 30; $i++)
		if (CspReportCollector::handle($server, $body, $directory) !== 204)
			throw new RuntimeException('CSP rate limit fired too early');
	if (CspReportCollector::handle($server, $body, $directory) !== 429)
		throw new RuntimeException('CSP rate limit did not fire');
	$modern = json_encode(array(array('type' => 'csp-violation', 'body' => array(
		'effectiveDirective' => 'img-src', 'blockedURL' => 'data', 'documentURL' => 'https://site.example/ru/news/1?key=secret',
	))), JSON_THROW_ON_ERROR);
	$records = CspReportCollector::records($modern);
	if (count($records ?? array()) !== 1 || $records[0]['blocked_origin'] !== 'data'
		|| $records[0]['document_surface'] !== 'public' || str_contains(json_encode($records), 'secret'))
		throw new RuntimeException('Modern CSP report was not normalized safely');
	if (CspReportCollector::records('{invalid') !== null)
		throw new RuntimeException('Malformed CSP report was accepted');
	$extension = json_encode(array('csp-report' => array(
		'effective-directive' => 'style-src-elem',
		'source-file' => 'chrome-extension://private-id/content.js?token=do-not-store',
	)), JSON_THROW_ON_ERROR);
	$extensionRecords = CspReportCollector::records($extension);
	if (($extensionRecords[0]['source_kind'] ?? '') !== 'browser-extension'
		|| str_contains(json_encode($extensionRecords), 'private-id'))
		throw new RuntimeException('Browser extension source was not classified privately');
	echo "CSP report tests passed.\n";
}
finally
{
	foreach (glob($directory . '/*') ?: array() as $file) unlink($file);
	rmdir($directory);
}
