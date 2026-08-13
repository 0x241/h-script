<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use HScript\Security\SecretCipher;

putenv('APP_DATA_KEY=test-only-data-key-with-at-least-32-bytes');

$context = 'array:2:currency-1';
$encrypted = SecretCipher::encrypt('{"token":"secret"}', $context);
if (!is_string($encrypted) || !str_starts_with($encrypted, 'v2:')) {
    fwrite(STDERR, "SecretCipher did not produce a v2 payload\n");
    exit(1);
}
if (str_contains($encrypted, 'secret')) {
    fwrite(STDERR, "SecretCipher leaked plaintext\n");
    exit(1);
}
if (SecretCipher::decrypt($encrypted, $context) !== '{"token":"secret"}') {
    fwrite(STDERR, "SecretCipher round-trip failed\n");
    exit(1);
}
if (SecretCipher::decrypt($encrypted, 'wrong-context') !== null) {
    fwrite(STDERR, "SecretCipher accepted the wrong context\n");
    exit(1);
}

$tampered = $encrypted;
$tampered[strlen($tampered) - 1] = $tampered[strlen($tampered) - 1] === 'A' ? 'B' : 'A';
if (SecretCipher::decrypt($tampered, $context) !== null) {
    fwrite(STDERR, "SecretCipher accepted a tampered payload\n");
    exit(1);
}

echo "SecretCipher checks passed\n";
