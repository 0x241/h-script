<?php

declare(strict_types=1);
try {
    $mode = $argv[1] ?? '';
    $path = $argv[2] ?? '';
    if (!in_array($mode, ['source', 'shared', 'evidence'], true)) throw new RuntimeException('Invalid mode');
    $archive = new PharData($path);
    $prefix = 'phar://' . realpath($path) . '/';
    $count = 0;
    foreach (new RecursiveIteratorIterator($archive, RecursiveIteratorIterator::SELF_FIRST) as $entry) {
        $name = substr($entry->getPathname(), strlen($prefix));
        if ($mode === 'evidence') {
            if ($name === 'evidence' && $entry->isDir()) continue;
            if (!preg_match('~^evidence/[a-z0-9.-]+$~D', $name) || !$entry->isFile() || $entry->isLink())
                throw new RuntimeException('Unsafe evidence archive');
            $count++;
            continue;
        }
        if ($mode === 'shared') {
            if ($name === 'h-script' && $entry->isDir()) continue;
            if (!str_starts_with($name, 'h-script/')) throw new RuntimeException('Invalid archive root');
            $name = substr($name, 9);
            // The package provisions an empty runtime directory, never its contents.
            if ($name === '.cfg' && $entry->isDir()) continue;
        }
        if ($name === '' || str_starts_with($name, '/') || str_contains($name, '\\') || preg_match('~(^|/)\.\.(/|$)~', $name)
            || $entry->isLink()) throw new RuntimeException('Unsafe archive entry');
        if (preg_match('~(^|/)(\.git|\.agents|\.codex|\.ssh|\.aws|\.cfg|\.npmrc|auth\.json|\.netrc|id_rsa|id_ed25519)(/|$)~', $name)
            || preg_match('~(^|/)(\.env($|\.(?!example$))|[^/]+\.(pem|key|p12|pfx))$~', $name)
            || (str_ends_with($name, '.sql') && !preg_match('~^migrations/[0-9]+_[a-z0-9_]+\.sql$~D', $name))
            || in_array($name, ['_config.php', '_config.local.php', 'module/_config/pass'], true)) throw new RuntimeException('Forbidden secret-bearing file');
        if (preg_match('~^docs(/|$)~', $name))
            throw new RuntimeException('Forbidden development file');
        if ($mode === 'shared' && preg_match('~^(ci|tests|docker|node_modules|dist|\.github)(/|$)|^(AGENTS\.md|\.gitlab-ci\.yml|package(-lock)?\.json)$~', $name))
            throw new RuntimeException('Forbidden development file');
        if ($entry->isFile()) $count++;
    }
    if ($count < 1) throw new RuntimeException('Empty artifact');
    echo "Artifact path/secret-file allowlist passed.\n";
} catch (Throwable $error) {
    $reason = in_array($error->getMessage(), ['Invalid mode', 'Unsafe evidence archive', 'Invalid archive root', 'Unsafe archive entry',
        'Forbidden secret-bearing file', 'Forbidden development file', 'Empty artifact'], true) ? $error->getMessage() : 'Unreadable archive';
    fwrite(STDERR, "Artifact file gate failed: $reason (no sensitive paths printed).\n");
    exit(1);
}
