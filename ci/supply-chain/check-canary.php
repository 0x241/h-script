<?php

declare(strict_types=1);
try {
    $marker = trim(file_get_contents($argv[1]));
    if (strlen($marker) < 32) throw new RuntimeException('Invalid marker');
    $checked = 0;
    foreach (array_slice($argv, 2) as $root) {
        $paths = is_dir($root)
            ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS))
            : [new SplFileInfo($root)];
        foreach ($paths as $entry) {
            if (!$entry->isFile()) continue;
            // gzopen transparently reads plain data too; scan uncompressed layers,
            // image config/history, attestations, SBOM, build logs and release tar.
            $stream = gzopen($entry->getPathname(), 'rb');
            if ($stream === false) throw new RuntimeException('Unreadable artifact');
            try {
                $tail = '';
                while (!gzeof($stream)) {
                    $chunk = gzread($stream, 65536);
                    if ($chunk === false) throw new RuntimeException('Unreadable artifact');
                    if (str_contains($tail . $chunk, $marker)) throw new RuntimeException('Canary leaked');
                    $tail = substr($tail . $chunk, -strlen($marker));
                }
            } finally { gzclose($stream); }
            $checked++;
        }
    }
    if ($checked < 1) throw new RuntimeException('No artifacts');
    echo "Synthetic secret absent from image layers/history, SBOM/provenance, release archive and build logs.\n";
} catch (Throwable) { fwrite(STDERR, "Secret boundary test failed; raw canary/artifacts withheld.\n"); exit(1); }
