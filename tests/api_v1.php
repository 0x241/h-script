<?php

use HScript\Database\Connection;
use HScript\Http\ApiRateLimiter;
use HScript\Http\ApiRequest;
use HScript\Http\ApiTokenRepository;

require dirname(__DIR__) . '/vendor/autoload.php';

function apiAssert(bool $condition, string $message): void
{
	if (!$condition)
		throw new RuntimeException($message);
}

final class ApiRateLimitFakeConnection extends Connection
{
	private array $counts = array();

	public function query($query, $values = null)
	{
		$key = (string)$values[0];
		$window = (int)$values[1];
		$this->counts[$key][$window] = ($this->counts[$key][$window] ?? 0) + 1;
		return 1;
	}

	public function select($table, $fields = '*', $filter = '', $values = null, $order = '', $limit = '', $group = '')
	{
		return array((string)$values[0], (int)$values[1]);
	}

	public function fetch1($query)
	{
		return $this->counts[$query[0]][$query[1]] ?? 0;
	}

	public function delete($table, $filter = '', $values = null, $order = '', $limit = '')
	{
		return 0;
	}
}

final class ApiTokenFakeConnection extends Connection
{
	public array $inserted = array();
	public array $updated = array();

	public function count($table, $filter = '', $values = null, $field = '')
	{
		return in_array($table, array('Users', 'ApiTokens'), true) ? 1 : 0;
	}

	public function insert($table, $values, $fields = '', $asReplace = false)
	{
		$this->inserted = array('table' => $table, 'values' => $values);
		return 41;
	}

	public function update($table, $values, $fields = '', $filter = '', $parameters = null)
	{
		$this->updated[] = array(
			'table' => $table,
			'values' => $values,
			'filter' => $filter,
			'parameters' => $parameters,
		);
		return 1;
	}
}

$oldServer = $_SERVER;
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer hs3_' . str_repeat('a', 64);
apiAssert(
	ApiRequest::bearerToken() === 'hs3_' . str_repeat('a', 64),
	'Bearer token parsing failed'
);
unset($_SERVER['HTTP_AUTHORIZATION']);
$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'bearer hs3_' . str_repeat('b', 64);
apiAssert(
	ApiRequest::bearerToken() === 'hs3_' . str_repeat('b', 64),
	'Redirected Authorization header parsing failed'
);
$_SERVER = $oldServer;

$token = 'hs_' . str_repeat('c', 64);
apiAssert(
	ApiTokenRepository::hash($token) === hash('sha256', $token),
	'Token hashing changed'
);
apiAssert(
	ApiTokenRepository::allows(array('scopes' => array('*')), 'balance:read'),
	'Wildcard scope is not accepted'
);
apiAssert(
	ApiTokenRepository::allows(array('scopes' => array('user:read')), 'user:read'),
	'Exact scope is not accepted'
);
apiAssert(
	!ApiTokenRepository::allows(array('scopes' => array('user:read')), 'balance:read'),
	'Unassigned scope is accepted'
);
apiAssert(
	ApiTokenRepository::normalizeScopes(ApiTokenRepository::AVAILABLE_SCOPES) === ApiTokenRepository::AVAILABLE_SCOPES,
	'Available API scopes cannot be normalized'
);
$invalidScopeRejected = false;
try
{
	ApiTokenRepository::normalizeScopes(array('unknown:read'));
}
catch (InvalidArgumentException $e)
{
	$invalidScopeRejected = true;
}
apiAssert($invalidScopeRejected, 'Unknown API scope is accepted');

$tokenConnection = new ApiTokenFakeConnection();
$tokenRepository = new ApiTokenRepository($tokenConnection);
$issued = $tokenRepository->issue(1, 'Test integration', array('user:read'), time() + 3600);
apiAssert($issued['id'] === 41, 'Issued token ID is invalid');
apiAssert((bool)preg_match('/^hs_[a-f0-9]{64}$/', $issued['token']), 'Issued token format is invalid');
apiAssert(
	$tokenConnection->inserted['values']['atTokenHash'] === ApiTokenRepository::hash($issued['token']),
	'Issued token is not stored as a hash'
);
apiAssert(
	!in_array($issued['token'], $tokenConnection->inserted['values'], true),
	'Plaintext token was persisted'
);
apiAssert(
	$tokenRepository->update(41, 'Paused integration', array('balance:read'), 0, false, 7),
	'Token update failed'
);
apiAssert(
	$tokenConnection->updated[0]['values']['atState'] === 2,
	'Token was not paused'
);
apiAssert(
	$tokenConnection->updated[0]['filter'] === 'atID=?d and atState<>0 and atuID=?d' &&
	$tokenConnection->updated[0]['parameters'] === array(41, 7),
	'Token update is not restricted to its owner'
);
apiAssert($tokenRepository->revoke(41), 'Token revoke failed');
apiAssert(
	$tokenConnection->updated[1]['values']['atState'] === 0,
	'Token was not revoked'
);

$limiter = new ApiRateLimiter(new ApiRateLimitFakeConnection(), 60, 60);
for ($request = 1; $request <= 60; $request++)
	apiAssert($limiter->consume('ip', '127.0.0.1')['allowed'], 'Request within rate limit was rejected');
$limited = $limiter->consume('ip', '127.0.0.1');
apiAssert(!$limited['allowed'], 'Request 61 was not rate limited');
apiAssert($limited['remaining'] === 0, 'Rate-limit remaining count is invalid');

echo "API v1 component tests passed.\n";
