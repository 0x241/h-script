<?php

declare(strict_types=1);
require dirname(__DIR__) . '/ci/supply-chain/Policy.php';

function supplyAssert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function supplyReject(callable $call): void {
    try { $call(); } catch (Throwable) { return; }
    throw new RuntimeException('Invalid scanner evidence was accepted');
}
$configuration = json_decode(file_get_contents(dirname(__DIR__) . '/ci/supply-chain/policy.json'), true, 32, JSON_THROW_ON_ERROR);
$policy = new SupplyChainPolicy($configuration);
$composer = ['advisories' => [], 'abandoned' => []];
supplyAssert($policy->evaluate($policy->normalize('composer', 'production', $composer, 0))['blocked'] === 0, 'Clean Composer audit rejected');
$composer['advisories']['vendor/package'] = [['advisoryId' => 'TEST-123', 'severity' => 'high']];
$report = $policy->normalize('composer', 'production', $composer, 1);
supplyAssert($policy->evaluate($report)['blocked'] === 1, 'High severity did not block');
$now = time();
$configuration['exceptions'][] = ['scanner' => 'composer', 'scope' => 'production', 'package' => 'vendor/package', 'id' => 'TEST-123',
    'owner' => 'security', 'approved_by' => 'release-owner', 'ticket' => 'SEC-123', 'reason' => 'Mitigated pending upstream patch',
    'reachability' => 'Reviewed call path', 'exploitability' => 'Feature disabled', 'created_at' => gmdate('c', $now - 3600), 'expires_at' => gmdate('c', $now + 3600)];
$exceptionPolicy = new SupplyChainPolicy($configuration);
supplyAssert($exceptionPolicy->evaluate($report, $now)['blocked'] === 0, 'Valid exact exception rejected');
supplyAssert($exceptionPolicy->evaluate($report, $now + 7200)['blocked'] === 1, 'Expired exception did not block promotion');
$otherScope = $report; $otherScope['scope'] = 'toolchain';
supplyAssert($exceptionPolicy->evaluate($otherScope)['blocked'] === 1, 'Exception leaked into another scope');
$configuration['exceptions'][0]['package'] = '*';
supplyReject(fn() => new SupplyChainPolicy($configuration));
$old = $report; $old['checked_at'] = gmdate('c', $now - 90000);
supplyReject(fn() => $policy->evaluate($old));
supplyAssert($policy->evaluate($old, $now, false)['blocked'] === 1, 'Retained mode bypassed findings');
supplyAssert($exceptionPolicy->evaluate($old, $now + 7200, false)['blocked'] === 1, 'Retained mode bypassed expired exception');
$future = $old; $future['checked_at'] = gmdate('c', $now + 3600);
supplyReject(fn() => $policy->evaluate($future, $now, false));
supplyReject(fn() => $policy->normalize('composer', 'production', ['error' => 'offline'], 1));
supplyReject(fn() => $policy->normalize('composer', 'production', ['advisories' => [], 'abandoned' => []], 1));
supplyReject(fn() => $policy->normalize('trivy', 'source', [], 0));
$npm = ['auditReportVersion' => 2, 'vulnerabilities' => ['tool' => ['severity' => 'critical', 'via' => ['transitive-tool']]], 'metadata' => ['vulnerabilities' => []]];
supplyAssert($policy->evaluate($policy->normalize('npm', 'toolchain', $npm, 1))['blocked'] === 1, 'Transitive toolchain finding was lost');
$marker = 'synthetic-' . bin2hex(random_bytes(24));
$trivy = ['SchemaVersion' => 2, 'ArtifactName' => 'fixture', 'Results' => [['Secrets' => [['RuleID' => 'test-key', 'Match' => $marker, 'Code' => $marker]]]]];
$sanitized = $policy->evaluate($policy->normalize('trivy', 'image-amd64', $trivy, 0));
supplyAssert($sanitized['blocked'] === 1 && !str_contains(json_encode($sanitized), $marker), 'Secret match leaked into evidence');
$trivy['Results'] = [['Vulnerabilities' => [['VulnerabilityID' => 'CVE-2099-1000', 'PkgName' => 'libfoo', 'Severity' => 'CRITICAL', 'FixedVersion' => '']]]];
supplyAssert($policy->evaluate($policy->normalize('trivy', 'image-arm64', $trivy, 0))['blocked'] === 1, 'Unfixed critical finding was ignored');

$root = dirname(__DIR__);
$ci = file_get_contents($root . '/.gitlab-ci.yml');
foreach (['validate:composer-audit:', 'validate:npm-audit:', 'scan:source:', 'release:evidence:', 'sh ci/supply-chain/evidence.sh publication', 'expire_in: never'] as $required)
    supplyAssert(str_contains($ci, $required), 'Missing CI gate: ' . $required);
supplyAssert(!str_contains($ci, '--ignore-unfixed'), 'CI silently ignores unpatched issues');
$dockerfile = file_get_contents($root . '/Dockerfile');
preg_match_all('/^ARG\s+(\w+)/m', $dockerfile, $args);
foreach ($args[1] as $argument)
    supplyAssert(in_array($argument, ['APP_VERSION', 'VCS_REF', 'BUILD_DATE', 'SOURCE_DATE_EPOCH'], true), 'Unapproved build argument may expose secrets');
supplyAssert(!preg_match('/^COPY\s+\.\s/m', $dockerfile), 'Build bypasses source allowlist');
foreach (['ci/', 'tests/', '.env', 'module/_config/pass'] as $excluded)
    supplyAssert(str_contains(file_get_contents($root . '/.dockerignore'), $excluded), 'Sensitive path missing from build exclusions');
foreach (['.gitignore', '.dockerignore'] as $ignoreFile) {
    $ignore = file_get_contents($root . '/' . $ignoreFile);
    supplyAssert((bool)preg_match('~^/docs/$~m', $ignore), 'Local docs are not excluded: ' . $ignoreFile);
    supplyAssert(!preg_match('~^!/?docs(?:/|$)~m', $ignore), 'Local docs have an allowlist exception: ' . $ignoreFile);
}
supplyAssert(!preg_match('~^COPY[^\n]*\bdocs/~m', $dockerfile), 'Build requires local docs');
supplyAssert(!str_contains(file_get_contents($root . '/README.md'), '](docs/'), 'README links to unbundled local docs');

$temporary = sys_get_temp_dir() . '/hs-supply-chain-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
$run = static function (string $script, array $arguments) use ($root): array {
    $process = proc_open(array_merge([PHP_BINARY, $root . '/ci/supply-chain/' . $script], $arguments),
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process), $output];
};
try {
    foreach (['safe' => ['h-script/VERSION', 'h-script/migrations/20260716_example.sql'],
        'secret' => ['h-script/.cfg/credential'], 'development' => ['h-script/ci/helper.php'],
        'local-docs' => ['h-script/docs/operator.md'],
        'dump' => ['h-script/upload/private.sql']] as $case => $paths) {
        $path = $temporary . '/' . $case . '.tar';
        $tar = new PharData($path);
        foreach ($paths as $file) $tar->addFromString($file, 'fixture');
        unset($tar);
        [$code] = $run('check-files.php', ['shared', $path]);
        supplyAssert(($code === 0) === ($case === 'safe'), 'Archive gate regression: ' . $case);
    }
    foreach (['safe-source' => 'README.md', 'local-source-docs' => 'docs/operator.md'] as $case => $file) {
        $path = $temporary . '/' . $case . '.tar';
        $tar = new PharData($path);
        $tar->addFromString($file, 'fixture');
        unset($tar);
        [$code] = $run('check-files.php', ['source', $path]);
        supplyAssert(($code === 0) === ($case === 'safe-source'), 'Source docs gate regression: ' . $case);
    }
    file_put_contents($temporary . '/marker', $marker);
    file_put_contents($temporary . '/clean', 'ordinary artifact');
    file_put_contents($temporary . '/dirty.gz', gzencode('deleted intermediate layer: ' . $marker));
    supplyAssert($run('check-canary.php', [$temporary . '/marker', $temporary . '/clean'])[0] === 0, 'Clean canary fixture rejected');
    [$code, $output] = $run('check-canary.php', [$temporary . '/marker', $temporary . '/dirty.gz']);
    supplyAssert($code !== 0 && !str_contains($output, $marker), 'Canary leak missed or disclosed');

    mkdir($temporary . '/evidence');
    $evidence = $temporary . '/evidence';
    $hashes = [];
    foreach (require $root . '/ci/supply-chain/evidence-files.php' as $file) {
        file_put_contents($evidence . '/' . $file, 'fixture');
        $hashes[$file] = hash_file('sha256', $evidence . '/' . $file);
    }
    foreach (['composer', 'npm-production', 'npm-toolchain', 'source', 'shared', 'image-amd64', 'image-arm64'] as $name) {
        $r = ['format' => 1, 'scanner' => str_starts_with($name, 'npm') ? 'npm' : ($name === 'composer' ? 'composer' : 'trivy'),
            'scope' => in_array($name, ['composer', 'npm-production'], true) ? 'production' : ($name === 'npm-toolchain' ? 'toolchain' : $name),
            'checked_at' => gmdate('c'), 'findings' => []];
        file_put_contents($evidence . '/' . $name . '.report.json', json_encode($r));
        $hashes[$name . '.report.json'] = hash_file('sha256', $evidence . '/' . $name . '.report.json');
    }
    $sha = str_repeat('b', 40); $digest = 'sha256:' . str_repeat('a', 64);
    file_put_contents($evidence . '/image-verification.json', json_encode([['critical' => ['image' => ['docker-manifest-digest' => $digest]],
        'optional' => ['com.gitlab.ci.commit.sha' => str_repeat('c', 40)]]]));
    file_put_contents($evidence . '/provenance.json', json_encode(['linux/amd64' => ['SLSA' => ['buildType' => 'fixture']], 'linux/arm64' => ['SLSA' => ['buildType' => 'fixture']]]));
    file_put_contents($evidence . '/buildkit-sbom.json', json_encode(['linux/amd64' => ['SPDX' => ['spdxVersion' => 'SPDX-2.3']], 'linux/arm64' => ['SPDX' => ['spdxVersion' => 'SPDX-2.3']]]));
    supplyAssert($run('evidence.php', ['build', $evidence, $sha, str_repeat('d', 40), 'v1.2.3', $digest])[0] === 0, 'Evidence assembly failed');
    $manifest = json_decode(file_get_contents($evidence . '/manifest.json'), true);
    supplyAssert($manifest['build_source_sha'] !== $manifest['source_sha'], 'Build/release SHA distinction was lost');
    supplyAssert($run('verify-evidence.php', [$evidence, 'v1.2.3', $sha, $digest])[0] === 0, 'Valid evidence rejected');
    supplyAssert($run('verify-evidence.php', [$evidence, 'v1.2.4', $sha, $digest])[0] !== 0, 'Wrong release identity accepted');
    foreach (glob($evidence . '/*.report.json') as $path) {
        $r = json_decode(file_get_contents($path), true);
        $r['checked_at'] = gmdate('c', $now - 172800);
        file_put_contents($path, json_encode($r));
    }
    supplyAssert($run('evidence.php', ['build', $evidence, $sha, str_repeat('d', 40), 'v1.2.3', $digest])[0] === 0, 'Retained fixture assembly failed');
    $retainedHash = hash_file('sha256', $evidence . '/manifest.json');
    supplyAssert($run('verify-evidence.php', [$evidence, 'v1.2.3', $sha, $digest])[0] !== 0, 'Old scans accepted as fresh');
    supplyAssert($run('verify-evidence.php', [$evidence, 'v1.2.3', $sha, $digest, 'retained'])[0] === 0, 'Authentic retained evidence rejected solely for age');
    supplyAssert(hash_file('sha256', $evidence . '/manifest.json') === $retainedHash, 'Retained verification rewrote signed bytes');

    // Exercise the actual shell gate offline: signatures and the network scan
    // boundary are mocked, but all PHP checks and checksum commands are real.
    $publication = $temporary . '/publication';
    mkdir($publication . '/ci/supply-chain', 0700, true);
    mkdir($publication . '/dist/evidence', 0700, true);
    mkdir($publication . '/bin', 0700);
    foreach (glob($root . '/ci/supply-chain/*') as $path)
        copy($path, $publication . '/ci/supply-chain/' . basename($path));
    foreach (glob($evidence . '/*') as $path)
        copy($path, $publication . '/dist/evidence/' . basename($path));
    copy($root . '/tests/fixtures/mock-publication-docker.php', $publication . '/bin/docker');
    chmod($publication . '/bin/docker', 0700);
    copy($root . '/tests/fixtures/mock-publication-refresh.sh', $publication . '/ci/supply-chain/refresh-publication.sh');
    $archiveName = 'h-script-1.2.3-shared-hosting.tar.gz';
    file_put_contents($publication . '/dist/' . $archiveName, 'original archive bytes');
    file_put_contents($publication . '/dist/' . $archiveName . '.sigstore.json', 'original signature bytes');
    file_put_contents($publication . '/dist/SHA256SUMS', hash_file('sha256', $publication . '/dist/' . $archiveName) . '  ' . $archiveName . "\n");
    $checksums = '';
    foreach ([$archiveName, $archiveName . '.sigstore.json', 'SHA256SUMS'] as $file)
        $checksums .= hash_file('sha256', $publication . '/dist/' . $file) . '  ' . $file . "\n";
    file_put_contents($publication . '/dist/evidence/archive-checksums.txt', $checksums);
    supplyAssert($run('evidence.php', ['build', $publication . '/dist/evidence', $sha, str_repeat('d', 40), 'v1.2.3', $digest])[0] === 0, 'Publication fixture assembly failed');
    $publicationHash = hash_file('sha256', $publication . '/dist/evidence/manifest.json');
    $publish = static function (string $mode, array $overrides = []) use ($publication, $sha, $digest): int {
        $environment = array_merge(getenv(), [
            'PATH' => $publication . '/bin:' . getenv('PATH'), 'MOCK_PUBLICATION_ROOT' => $publication,
            'CI_COMMIT_TAG' => 'v1.2.3', 'CI_COMMIT_SHA' => $sha, 'CANDIDATE_DIGEST' => $digest,
            'CI_PROJECT_URL' => 'https://gitlab.example.invalid/project', 'CI_SERVER_URL' => 'https://gitlab.example.invalid',
            'MOCK_BAD_SIGNATURE' => '0', 'MOCK_REFRESH_FAILURE' => '0',
        ], $overrides);
        $process = proc_open(['/bin/sh', 'ci/supply-chain/evidence.sh', $mode],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $publication . '/output', 'w'], 2 => ['file', $publication . '/error', 'w']],
            $pipes, $publication, $environment);
        return proc_close($process);
    };
    supplyAssert($publish('verify') !== 0 && !is_file($publication . '/refresh-calls'), 'Ordinary gate accepted old evidence');
    supplyAssert($publish('publication', ['MOCK_BAD_SIGNATURE' => '1']) !== 0 && !is_file($publication . '/refresh-calls'), 'Unsigned evidence reached refresh');
    supplyAssert($publish('publication') === 0 && is_file($publication . '/refresh-calls'), 'Old publication did not require a fresh scan');
    supplyAssert(hash_file('sha256', $publication . '/dist/evidence/manifest.json') === $publicationHash, 'Publication changed signed manifest');
    supplyAssert(file_get_contents($publication . '/dist/' . $archiveName) === 'original archive bytes', 'Publication changed archive');
    supplyAssert($publish('publication', ['MOCK_REFRESH_FAILURE' => '1']) !== 0, 'Refresh failure permitted publication');
    unlink($publication . '/refresh-calls');
    file_put_contents($publication . '/dist/' . $archiveName, 'corrupt archive');
    supplyAssert($publish('publication') !== 0 && !is_file($publication . '/refresh-calls'), 'Corrupt archive reached refresh');

    file_put_contents($evidence . '/source.report.json', '{}');
    supplyAssert($run('verify-evidence.php', [$evidence, 'v1.2.3', $sha, $digest])[0] !== 0, 'Tampered evidence accepted');
    supplyAssert($run('verify-evidence.php', [$evidence, 'v1.2.3', $sha, $digest, 'retained'])[0] !== 0, 'Retained mode bypassed integrity');
} finally {
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) { if ($entry->isDir()) rmdir($entry->getPathname()); else unlink($entry->getPathname()); }
    rmdir($temporary);
}

echo "Supply-chain policy, expiry, scanner failures, archive gates, canary detection and evidence integrity tests passed.\n";
