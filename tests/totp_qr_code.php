<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/module/account/ga/class.GoogleAuthenticator.php';

$qrDataUri = (new GoogleAuthenticator())->getQRUrl(
    'admin@hs.local',
    'JBSWY3DPEHPK3PXP'
);
$prefix = 'data:image/svg+xml;base64,';

if (!str_starts_with($qrDataUri, $prefix)) {
    throw new RuntimeException('TOTP QR code did not return an SVG data URI');
}

$svg = base64_decode(substr($qrDataUri, strlen($prefix)), true);
if ($svg === false || !str_contains($svg, '<svg') || !str_contains($svg, '<path')) {
    throw new RuntimeException('TOTP QR code returned invalid SVG');
}

echo "TOTP QR code test passed.\n";
