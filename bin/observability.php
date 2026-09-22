<?php

declare(strict_types=1);

use HScript\Backup\DatabaseCredentials;
use HScript\Cache\RedisCache;
use HScript\Database\Connection;
use HScript\Observability\AlertManager;
use HScript\Observability\CorrelationContext;
use HScript\Observability\HealthService;
use HScript\Observability\OperationalStateRepository;

$root = dirname(__DIR__);
$_SERVER += array(
	'SERVER_NAME' => (string)(getenv('APP_DOMAIN') ?: 'localhost'),
	'SCRIPT_NAME' => '/bin/observability.php',
	'SERVER_PORT' => 80,
	'REQUEST_URI' => '/',
	'SERVER_ADDR' => '127.0.0.1',
	'REMOTE_ADDR' => '127.0.0.1',
);

chdir($root);
require $root . '/vendor/autoload.php';
CorrelationContext::setActorClass('system');

$json = static function (array $data): void {
	echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
};

try
{
	$command = strtolower((string)($argv[1] ?? ''));
	$health = new HealthService($root);
	if ($command === 'process')
	{
		$result = $health->process();
		$json($result);
		exit($result['status'] === 'healthy' ? 0 : 1);
	}
	if ($command === 'status')
	{
		$json(array(
			'process' => $health->process(),
			'readiness' => (new OperationalStateRepository($root))->readiness(),
		));
		exit(0);
	}
	if ($command !== 'readiness')
	{
		fwrite(STDERR, "Usage: php bin/observability.php process|readiness|status\n");
		exit(2);
	}

	global $_cfg;
	$_cfg = array();
	if (is_file($root . '/_config.php')) require $root . '/_config.php';
	if (is_file($root . '/_config.local.php')) require $root . '/_config.local.php';
	$result = null;
	try
	{
		if (!hsHasDatabaseConfiguration($_cfg)) throw new RuntimeException('Database configuration is unavailable');
		$credentials = DatabaseCredentials::fromConfig($_cfg, (string)$_SERVER['SERVER_NAME']);
		$database = new Connection();
		if (!$database->open($credentials->connectionHost(), $credentials->database(), $credentials->username(), $credentials->password()))
			throw new RuntimeException('Database is unavailable');
		try { $result = $health->readiness($database, RedisCache::fromEnvironment()); }
		finally { $database->close(); }
	}
	catch (Throwable)
	{
		$result = $health->databaseUnavailable();
	}
	$alerts = (new AlertManager($root))->evaluateReadiness($result);
	$result['alerts'] = $alerts;
	$json($result);
	exit($result['status'] === 'ready' ? 0 : 3);
}
catch (Throwable)
{
	$json(array(
		'format' => 1,
		'checked_at' => gmdate('Y-m-d\TH:i:s\Z'),
		'status' => 'not_ready',
		'error_code' => 'observability_failed',
	));
	exit(1);
}
