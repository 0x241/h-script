<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

$dockerConfig = array(
    'db_host' => 'database:3306',
    'db_name' => 'hscript',
    'db_credentials_env' => 1,
);
if (!hsHasDatabaseConfiguration($dockerConfig)) {
    throw new RuntimeException('Docker environment credentials must mark the database as configured.');
}

$legacyConfig = array(
    'db_host' => 'localhost',
    'db_name' => 'hscript',
    'db_login' => 'encoded-login',
);
if (!hsHasDatabaseConfiguration($legacyConfig)) {
    throw new RuntimeException('Legacy encoded credentials must remain supported.');
}

$incompleteConfigs = array(
    array(),
    array('db_host' => 'database:3306', 'db_credentials_env' => 1),
    array('db_name' => 'hscript', 'db_credentials_env' => 1),
    array('db_host' => 'database:3306', 'db_name' => 'hscript'),
);
foreach ($incompleteConfigs as $config) {
    if (hsHasDatabaseConfiguration($config)) {
        throw new RuntimeException('Incomplete database configuration was accepted.');
    }
}

echo "Database configuration tests passed.\n";
