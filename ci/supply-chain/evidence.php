<?php

declare(strict_types=1);
try {
    $directory = $argv[2];
    $verification = json_decode(file_get_contents($directory . '/image-verification.json'), true, 64, JSON_THROW_ON_ERROR);
    $buildSha = $verification[0]['optional']['com.gitlab.ci.commit.sha'] ?? '';
    if (!preg_match('/^[a-f0-9]{40}$/D', $buildSha)) throw new RuntimeException('Missing signed build SHA');
    if ($argv[1] === 'build-sha') { echo $buildSha; exit; }
    [$sourceSha, $treeSha, $version, $digest] = array_slice($argv, 3);
    if (!preg_match('/^[a-f0-9]{40}$/D', $sourceSha) || !preg_match('/^[a-f0-9]{40}$/D', $treeSha)
        || !preg_match('/^sha256:[a-f0-9]{64}$/D', $digest) || !preg_match('/^v[0-9]+\.[0-9]+\.[0-9]+$/D', $version)
        || ($verification[0]['critical']['image']['docker-manifest-digest'] ?? '') !== $digest) throw new RuntimeException('Invalid release identity');
    $required = require __DIR__ . '/evidence-files.php';
    $hashes = [];
    foreach ($required as $file) {
        $path = $directory . '/' . $file;
        if (!is_file($path) || filesize($path) === 0) throw new RuntimeException('Missing evidence');
        if (in_array($file, ['provenance.json', 'buildkit-sbom.json'], true)) {
            $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data) || !$data) throw new RuntimeException('Missing attestation');
            foreach (['linux/amd64', 'linux/arm64'] as $platform) {
                if (!is_array($data[$platform] ?? null) || !$data[$platform]) throw new RuntimeException('Missing platform attestation');
                $field = $file === 'provenance.json' ? 'buildType' : 'spdxVersion';
                if (!str_contains(json_encode($data[$platform], JSON_THROW_ON_ERROR), '"' . $field . '"'))
                    throw new RuntimeException('Incomplete platform attestation');
            }
        }
        $hashes[$file] = hash_file('sha256', $path);
    }
    $manifest = ['format' => 1, 'release_tag' => $version, 'source_sha' => $sourceSha, 'build_source_sha' => $buildSha,
        'source_tree' => $treeSha, 'image_digest' => $digest, 'created_at' => gmdate('c'), 'sha256' => $hashes];
    file_put_contents($directory . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
} catch (Throwable) { fwrite(STDERR, "Release evidence is incomplete or inconsistent.\n"); exit(1); }
