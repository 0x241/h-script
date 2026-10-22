<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Exercise PHP guards even when the server ignores every Apache access rule.
$root = dirname(__DIR__);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!is_resource($socket)) throw new RuntimeException('Cannot reserve test port');
$address = stream_socket_get_name($socket, false);
fclose($socket);
$log = tempnam(sys_get_temp_dir(), 'hs-test-access-');
$process = proc_open([PHP_BINARY, '-S', $address, '-t', $root], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
if (!is_resource($process)) throw new RuntimeException('Cannot start isolated PHP server');
$checks = 0;
try {
    $ready = false;
    for ($i = 0; $i < 100; $i++) {
        $probe = @stream_socket_client('tcp://' . $address, $errno, $error, .1);
        if (is_resource($probe)) { fclose($probe); $ready = true; break; }
        usleep(20000);
    }
    if (!$ready) throw new RuntimeException('Isolated PHP server did not start');
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS));
    foreach ($entries as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') continue;
        $relative = substr($file->getPathname(), strlen($root) + 1);
        foreach (['GET', 'POST'] as $method) {
            $context = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 2]]);
            $response = file_get_contents('http://' . $address . '/' . $relative, false, $context);
            if (!str_contains($http_response_header[0] ?? '', ' 404 ') || $response !== '') throw new RuntimeException('PHP test entry point did not deny HTTP: ' . $relative . ' (' . ($http_response_header[0] ?? 'no status') . ', ' . strlen((string)$response) . ' bytes)');
            $checks++;
        }
    }
    foreach ([[], ['H_SCRIPT_FINANCIAL_FIXTURE' => '1', 'APP_DOMAIN' => 'hs.local', 'APP_ENV' => 'production'],
        ['H_SCRIPT_FINANCIAL_FIXTURE' => '1', 'APP_DOMAIN' => 'example.com', 'APP_ENV' => 'local']] as $environment) {
        $command = proc_open([PHP_BINARY, __DIR__ . '/fixtures/financial_stand.php', '--status'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, $environment);
        if (!is_resource($command) || proc_close($command) !== 64) throw new RuntimeException('Financial stand environment guard failed');
        $checks++;
    }
    echo "Test HTTP access and financial stand environment guards passed: $checks checks.\n";
} finally {
    proc_terminate($process);
    proc_close($process);
    unlink($log);
}
