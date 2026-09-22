<?php

declare(strict_types=1);
require __DIR__ . '/Policy.php';
try {
    $mode = $argv[5] ?? 'fresh';
    if (!in_array($mode, ['fresh', 'retained'], true)) throw new RuntimeException('Invalid verification mode');
    $directory = $argv[1];
    $manifest = json_decode(file_get_contents($directory . '/manifest.json'), true, 64, JSON_THROW_ON_ERROR);
    if (($manifest['format'] ?? null) !== 1 || $manifest['release_tag'] !== $argv[2] || $manifest['source_sha'] !== $argv[3]
        || $manifest['image_digest'] !== $argv[4] || !is_array($manifest['sha256'] ?? null)) throw new RuntimeException('Identity mismatch');
    foreach (require __DIR__ . '/evidence-files.php' as $required)
        if (!isset($manifest['sha256'][$required])) throw new RuntimeException('Required evidence missing');
    foreach ($manifest['sha256'] as $file => $hash) {
        if (basename($file) !== $file || !is_file($directory . '/' . $file) || !hash_equals($hash, hash_file('sha256', $directory . '/' . $file)))
            throw new RuntimeException('Evidence hash mismatch');
    }
    $policy = new SupplyChainPolicy(json_decode(file_get_contents(__DIR__ . '/policy.json'), true, 32, JSON_THROW_ON_ERROR));
    foreach (['composer', 'npm-production', 'npm-toolchain', 'source', 'shared', 'image-amd64', 'image-arm64'] as $name) {
        if (!isset($manifest['sha256'][$name . '.report.json'])) throw new RuntimeException('Missing scan');
        // Retained evidence keeps its original timestamps/signatures. This only
        // relaxes scan age, never integrity, scope or current exception expiry.
        $report = $policy->evaluate(json_decode(file_get_contents($directory . '/' . $name . '.report.json'), true, 64, JSON_THROW_ON_ERROR), null, $mode === 'fresh');
        $scope = in_array($name, ['composer', 'npm-production'], true) ? 'production' : ($name === 'npm-toolchain' ? 'toolchain' : $name);
        $scanner = str_starts_with($name, 'npm') ? 'npm' : ($name === 'composer' ? 'composer' : 'trivy');
        if ($report['scope'] !== $scope || $report['scanner'] !== $scanner) throw new RuntimeException('Wrong scan scope');
        if ($report['blocked'] > 0) throw new RuntimeException('Policy failed');
    }
    echo "Release evidence and current exception policy passed.\n";
} catch (Throwable) { fwrite(STDERR, "Release evidence verification failed closed.\n"); exit(1); }
