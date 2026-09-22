<?php
declare(strict_types=1);

use HScript\Database\Connection;
use HScript\Update\RuntimeEnvironment;
use HScript\Update\UpdateCompatibility;

require dirname(__DIR__) . '/vendor/autoload.php';
$database = new Connection();
if (!$database->open(getenv('DB_HOST'), getenv('DB_NAME'), getenv('DB_USER'), getenv('DB_PASSWORD')))
	throw new RuntimeException('Test database connection failed');
$runtime = RuntimeEnvironment::inspect($database);
UpdateCompatibility::fromRoot(dirname(__DIR__))->assertRuntime($runtime);
printf("Runtime database contract passed: %s %s, PHP %s.\n", $runtime['database_engine'], $runtime['database_version'], $runtime['php']);
