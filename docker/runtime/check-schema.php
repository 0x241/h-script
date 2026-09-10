<?php

declare(strict_types=1);

use HScript\Update\SchemaUpdateGate;

$domain = (string)(getenv('APP_DOMAIN') ?: 'localhost');
$_SERVER += array(
	'SERVER_NAME' => $domain,
	'HTTP_HOST' => $domain,
	'SCRIPT_NAME' => '/rw.php',
	'SERVER_PORT' => 80,
	'REQUEST_URI' => '/',
	'SERVER_ADDR' => '127.0.0.1',
	'REMOTE_ADDR' => '127.0.0.1',
);

chdir('/var/www/html');
require 'vendor/autoload.php';

global $_cfg;
$_cfg = array();
if (is_file('_config.php')) require '_config.php';
if (is_file('_config.local.php')) require '_config.local.php';
if (!hsHasDatabaseConfiguration($_cfg)) exit(0);

require 'module/dbinit.php';
$gate = (new SchemaUpdateGate($db, '/var/www/html'))->refresh();
if ($gate !== null)
	fwrite(STDERR, "CMS update completion is required; only the Configurator route is available\n");
