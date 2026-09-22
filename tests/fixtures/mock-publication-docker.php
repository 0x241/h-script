#!/usr/bin/env php
<?php
// Offline shell-flow fixture: PHP verification is real, Sigstore is mocked.
// Unexpected Docker operations fail instead of reaching a daemon or registry.
$arguments = array_slice($argv, 1);
$root = getenv('MOCK_PUBLICATION_ROOT');
if (in_array('verify-blob', $arguments, true)) exit(getenv('MOCK_BAD_SIGNATURE') === '1' ? 1 : 0);
$entrypoint = array_search('--entrypoint', $arguments, true);
if ($entrypoint === false || ($arguments[$entrypoint + 1] ?? '') !== 'php') exit(99);
$start = array_search('/src/ci/supply-chain/verify-evidence.php', $arguments, true);
if ($start === false) exit(99);
$command = [PHP_BINARY];
foreach (array_slice($arguments, $start) as $argument) {
    if (str_starts_with($argument, '/src/')) $argument = $root . '/' . substr($argument, 5);
    elseif (str_starts_with($argument, '/reports/')) $argument = $root . '/dist/' . substr($argument, 9);
    $command[] = $argument;
}
$process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
exit(proc_close($process));
