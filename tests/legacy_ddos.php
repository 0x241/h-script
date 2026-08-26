<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$auth = (string)file_get_contents($root . '/module/auth.php');
$dockerfile = (string)file_get_contents($root . '/Dockerfile');

if (
    is_file($root . '/_a-ddos/a-ddos.php')
    || is_file($root . '/_a-ddos/a-ddos.html')
    || is_file($root . '/a-ddos/a-ddos.php')
    || is_file($root . '/a-ddos/a-ddos.html')
) {
    throw new RuntimeException('Legacy browser-loop anti-DDoS module is still present.');
}
if (stripos($auth, 'a-ddos') !== false || stripos($dockerfile, 'a-ddos') !== false) {
    throw new RuntimeException('Legacy anti-DDoS module is still referenced by the runtime.');
}

echo "Legacy anti-DDoS cleanup tests passed.\n";
