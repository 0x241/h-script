<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$controller = (string)file_get_contents($root . '/module/captcha/setup.php');
$template = (string)file_get_contents($root . '/tpl/captcha/setup.twig');

foreach (array('Turnstile_SiteKey', 'Turnstile_SecretKey') as $field) {
    if (!str_contains($controller, "'" . $field . "'")) {
        throw new RuntimeException($field . ' is not preserved when submitted empty.');
    }
    if (!preg_match("/'" . preg_quote($field, '/') . "'\\s*:\\s*\\{0:\\s*'P'/", $template)) {
        throw new RuntimeException($field . ' is not rendered as an empty password field.');
    }
}

echo "CAPTCHA secret rendering tests passed.\n";
