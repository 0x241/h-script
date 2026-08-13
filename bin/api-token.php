<?php

declare(strict_types=1);

use HScript\Http\ApiTokenRepository;

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
