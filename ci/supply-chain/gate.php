<?php

declare(strict_types=1);
require __DIR__ . '/Policy.php';
try {
    $policy = new SupplyChainPolicy(json_decode(file_get_contents(__DIR__ . '/policy.json'), true, 32, JSON_THROW_ON_ERROR));
    if (($argv[1] ?? '') === 'recheck') {
        if (count($argv) < 3) throw new RuntimeException('No reports');
        foreach (array_slice($argv, 2) as $path) {
            $report = $policy->evaluate(json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR));
            if ($report['blocked'] > 0) throw new RuntimeException('Blocking findings');
        }
    } else {
        if (count($argv) !== 6) throw new RuntimeException('Invalid arguments');
        $report = $policy->evaluate($policy->normalize($argv[1], $argv[2], json_decode(file_get_contents($argv[3]), true, 512, JSON_THROW_ON_ERROR), (int)$argv[5]));
        file_put_contents($argv[4], json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        if ($report['blocked'] > 0) throw new RuntimeException('Blocking findings');
    }
    echo "Supply-chain policy passed.\n";
} catch (Throwable) {
    // Never print a scanner payload, secret match, URL with credentials or command stderr.
    fwrite(STDERR, "Supply-chain gate failed; inspect sanitized evidence or rerun the scanner privately.\n");
    exit(1);
}
