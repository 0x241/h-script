<?php

declare(strict_types=1);

/**
 * Phase 1 review gate. The snapshot is deliberately exact: a changed bypass
 * requires a new inventory review even when the number of bypasses is stable.
 */
function securityInventoryFind(string $source, string $path): array
{
    $twig = str_ends_with($path, '.twig');
    $php = str_ends_with($path, '.php');
    $patterns = $twig ? array(
        'twig_raw' => '/\|\s*raw\b/i',
        'twig_sanitized_html' => '/\|\s*safe_html\b/i',
        'twig_autoescape' => '/\{%\s*autoescape\b/i',
        'script_tag' => '/<script\b/i',
        'event_attribute' => '/(?<![$\w])\bon[a-z]+\s*=/i',
        'htmx_code' => '/\b(?:data-)?hx-on(?:-|:)\w*/i',
        'unsafe_scheme' => '/\b(?:javascript|vbscript)\s*:/i',
        'htmx_eval' => '/\b(?:data-)?hx-(?:vals|headers)\s*=\s*[\'\"]\s*(?:js|javascript)\s*:/i',
    ) : ($php ? array(
        'twig_safe_html_registration' => '/\bis_safe\s*[\'\"]\s*=>\s*\[[\'\"]html[\'\"]\]/i',
        'php_script_tag' => '/<script\b/i',
        'php_event_attribute' => '/(?<![$\w])\bon[a-z]+\s*=/i',
    ) : array());

    $findings = array();
    foreach (preg_split('/\r\n|\r|\n/', $source) as $lineNumber => $line) {
        foreach ($patterns as $kind => $pattern) {
            $count = preg_match_all($pattern, $line);
            if ($count === false) {
                throw new RuntimeException("Invalid inventory scanner pattern: $kind");
            }
            if ($count === 0) {
                continue;
            }
            $findings[] = array(
                'path' => $path,
                'line' => $lineNumber + 1,
                'kind' => $kind,
                'count' => $count,
                'sha256' => hash('sha256', trim($line)),
            );
        }
    }
    return $findings;
}

function securityInventoryScan(string $root): array
{
    $findings = array();
    foreach (array('tpl', 'module', 'lib', 'src') as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $root . '/' . $directory,
            FilesystemIterator::SKIP_DOTS
        ));
        foreach ($iterator as $file) {
            if (!$file->isFile() || !preg_match('/\.(?:twig|php)$/', $file->getFilename())) {
                continue;
            }
            $path = substr($file->getPathname(), strlen($root) + 1);
            array_push($findings, ...securityInventoryFind(
                (string)file_get_contents($file->getPathname()),
                str_replace(DIRECTORY_SEPARATOR, '/', $path)
            ));
        }
    }
    usort($findings, static fn(array $a, array $b): int =>
        [$a['path'], $a['line'], $a['kind']] <=> [$b['path'], $b['line'], $b['kind']]
    );
    return $findings;
}

function securityInventoryTest(): void
{
    $twig = securityInventoryFind(
        '{{ value|raw }}{{ value|safe_html }}<script src="x"></script><a onclick="run()" href="javascript:run()">',
        'tpl/fixture.twig'
    );
    $kinds = array_column($twig, 'kind');
    foreach (array('twig_raw', 'twig_sanitized_html', 'script_tag', 'event_attribute', 'unsafe_scheme') as $kind) {
        if (!in_array($kind, $kinds, true)) {
            throw new RuntimeException("Scanner missed $kind");
        }
    }
    $php = securityInventoryFind("['is_safe' => ['html']]", 'src/fixture.php');
    if (count($php) !== 1 || $php[0]['kind'] !== 'twig_safe_html_registration') {
        throw new RuntimeException('Scanner missed the implicit Twig escaping bypass');
    }
    echo "Security inventory scanner tests passed.\n";
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $root = dirname(__DIR__, 2);
    $mode = $argv[1] ?? '--check';
    if ($mode === '--self-test') {
        securityInventoryTest();
        exit(0);
    }
    $current = securityInventoryScan($root);
    if ($mode === '--dump') {
        echo json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        exit(0);
    }
    if ($mode !== '--check') {
        fwrite(STDERR, "Usage: php ci/security/check-inventory.php [--check|--dump|--self-test]\n");
        exit(2);
    }
    $snapshotPath = __DIR__ . '/browser-bypass-inventory.json';
    $approved = json_decode((string)file_get_contents($snapshotPath), true, 512, JSON_THROW_ON_ERROR);
    if ($current !== $approved) {
        fwrite(STDERR, "Browser bypass inventory changed. Review sources and update ci/security/browser-bypass-inventory.json with --dump.\n");
        exit(1);
    }
    echo 'Security inventory matched: ' . count($current) . " reviewed findings.\n";
}
