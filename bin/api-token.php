<?php

declare(strict_types=1);

use HScript\Http\ApiTokenRepository;
use HScript\Backup\DatabaseCredentials;
use HScript\Database\Connection;
use HScript\Telemetry\CollectorMode;
use HScript\Telemetry\TelemetryServiceTokenRepository;
use HScript\Observability\CorrelationContext;
use HScript\Observability\StructuredLogger;

$root = dirname(__DIR__);
$domain = (string)(getenv('APP_DOMAIN') ?: 'localhost');
$_SERVER += array(
	'SERVER_NAME' => $domain,
	'HTTP_HOST' => $domain,
	'SCRIPT_NAME' => '/bin/api-token.php',
	'SERVER_PORT' => 80,
	'REQUEST_URI' => '/',
	'SERVER_ADDR' => '127.0.0.1',
	'REMOTE_ADDR' => '127.0.0.1',
);

chdir($root);
require $root . '/vendor/autoload.php';

// Keep the emergency operation in the existing operator token CLI. Do not use
// web dbinit, which may render an error and terminate with exit status zero.
if (($argv[1] ?? '') === 'stop-collector-consumer')
{
	try
	{
		if (PHP_SAPI !== 'cli' || count($argv) !== 4 || !preg_match('/^[1-9][0-9]{0,9}$/', $argv[2])
			|| $argv[3] !== '--confirm-consumer=' . $argv[2])
			throw new InvalidArgumentException('Explicit consumer confirmation required');
		$_cfg = array();
		if (is_file($root . '/_config.php')) require $root . '/_config.php';
		if (is_file($root . '/_config.local.php')) require $root . '/_config.local.php';
		if (!CollectorMode::enabled($_cfg, $domain)) throw new RuntimeException('Collector mode required');
		$credentials = DatabaseCredentials::fromConfig($_cfg, $domain);
		$db = new Connection();
		if (!$db->open($credentials->connectionHost(), $credentials->database(), $credentials->username(), $credentials->password()))
			throw new RuntimeException('Database unavailable');
		(new TelemetryServiceTokenRepository($db))->stopConsumer((int)$argv[2]);
		CorrelationContext::setActorClass('system');
		StructuredLogger::event('warning', 'telemetry', 'collector_consumer_stopped', 'success', 0, StructuredLogger::resourceId('consumer', $argv[2]));
		echo json_encode(array('status'=>'stopped','consumer_id'=>(int)$argv[2]), JSON_THROW_ON_ERROR) . PHP_EOL;
		exit(0);
	}
	catch (Throwable)
	{
		echo json_encode(array('status'=>'failed','error_code'=>'collector_consumer_stop_failed')) . PHP_EOL;
		exit(1);
	}
}

global $_cfg;
$_cfg = array();
if (!is_file($root . '/_config.php') && !is_file($root . '/_config.local.php'))
{
	fwrite(STDERR, "Application config _config.php or _config.local.php was not found.\n");
	exit(1);
}
if (is_file($root . '/_config.php'))
	require $root . '/_config.php';
if (is_file($root . '/_config.local.php'))
	require $root . '/_config.local.php';
require $root . '/module/dbinit.php';

$usage = static function (): void {
	fwrite(STDERR, "Usage:\n");
	fwrite(STDERR, "  php bin/api-token.php create <user-id> <name> [scopes] [expires-at]\n");
	fwrite(STDERR, "  php bin/api-token.php list <user-id>\n");
	fwrite(STDERR, "  php bin/api-token.php revoke <token-id> [user-id]\n");
	fwrite(STDERR, "  php bin/api-token.php stop-collector-consumer <user-id> --confirm-consumer=<user-id>\n");
};

try
{
	$repository = new ApiTokenRepository($db);
	$command = strtolower((string)($argv[1] ?? ''));
	switch ($command)
	{
	case 'create':
		$userId = (int)($argv[2] ?? 0);
		$name = trim((string)($argv[3] ?? ''));
		$scopeInput = trim((string)($argv[4] ?? '*'));
		$scopes = preg_split('/[\s,]+/', $scopeInput) ?: array();
		$expiresAt = 0;
		if (!empty($argv[5]))
		{
			$expiresAt = ctype_digit((string)$argv[5])
				? (int)$argv[5]
				: (int)strtotime((string)$argv[5]);
			if ($expiresAt <= time())
				throw new InvalidArgumentException('Expiration must be a future Unix timestamp or date');
		}
		$result = $repository->issue($userId, $name, $scopes, $expiresAt);
		$result['notice'] = 'Store this token securely; it cannot be shown again.';
		echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
		break;

	case 'list':
		$userId = (int)($argv[2] ?? 0);
		if ($userId <= 0)
			throw new InvalidArgumentException('A positive user ID is required');
		echo json_encode(
			array('tokens' => $repository->listForUser($userId)),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		) . PHP_EOL;
		break;

	case 'revoke':
		$tokenId = (int)($argv[2] ?? 0);
		$userId = (int)($argv[3] ?? 0);
		if ($tokenId <= 0)
			throw new InvalidArgumentException('A positive token ID is required');
		if (!$repository->revoke($tokenId, $userId))
			throw new RuntimeException('Active token not found');
		echo json_encode(array('revoked' => true, 'token_id' => $tokenId)) . PHP_EOL;
		break;

	default:
		$usage();
		exit(2);
	}
}
catch (Throwable $e)
{
	fwrite(STDERR, $e->getMessage() . PHP_EOL);
	exit(1);
}
