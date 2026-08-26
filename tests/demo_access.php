<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$auth = (string)file_get_contents($root . '/module/auth.php');
$loginController = (string)file_get_contents($root . '/module/account/login/index.php');
$loginTemplate = (string)file_get_contents($root . '/tpl/account/login/index.twig');
$runtimeConfig = (string)file_get_contents($root . '/docker/runtime/write-config.php');
$installer = (string)file_get_contents($root . '/docker/runtime/install-db.php');
$compose = (string)file_get_contents($root . '/docker-compose.yml');
$envExample = (string)file_get_contents($root . '/docker/env.example');
$ci = (string)file_get_contents($root . '/.gitlab-ci.yml');
$english = (string)file_get_contents($root . '/lang/en.json');
$russian = (string)file_get_contents($root . '/lang/ru.json');

foreach (array(
    "array('1', 'true', 'yes', 'on')",
    "!empty(\$_cfg['Demo_Mode'])",
) as $fragment) {
    if (!str_contains($auth, $fragment)) {
        throw new RuntimeException("Runtime demo-mode detection is missing: $fragment.");
    }
}

foreach (array(
    'demo_access_admin_login',
    'demo_access_admin_password',
) as $fragment) {
    if (!str_contains($runtimeConfig, $fragment) || !str_contains($loginController, $fragment)) {
        throw new RuntimeException("Demo access configuration is missing: $fragment.");
    }
}

foreach (array(
    'auth.demo_access.title',
    'auth.demo_access.admin',
    'demo_access_account.password',
) as $fragment) {
    if (!str_contains($loginTemplate, $fragment)) {
        throw new RuntimeException("Demo access banner is missing: $fragment.");
    }
}

foreach (array($runtimeConfig, $installer, $compose, $envExample, $ci, $loginController, $loginTemplate, $english, $russian) as $source) {
    if (stripos($source, 'demo_user') !== false || stripos($source, 'demo-user') !== false) {
        throw new RuntimeException('The dedicated demo user configuration is still present.');
    }
}

if (str_contains($installer, 'hs_install_seed_user($db, 3')) {
    throw new RuntimeException('The installer still seeds a dedicated regular demo user.');
}

echo "Demo access configuration tests passed.\n";
