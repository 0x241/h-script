<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

set_exception_handler(function (Throwable $e) {
	fwrite(STDERR, "TEST FAILURE: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
	exit(1);
});

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require_once $root . '/module/lib.php';
require_once $root . '/module/account/ga/class.GoogleAuthenticator.php';

use HScript\Security\FailedLoginPolicy;

final class LoginGaTestDb
{
	public array $users = array();
	public array $updates = array();

	public function select(string $table, string $fields = '*', string $where = '', array $params = array()): array
	{
		return array('table' => $table, 'fields' => $fields, 'where' => $where, 'params' => $params);
	}

	public function fetch1Row(array $query): ?array
	{
		$login = (string)($query['params'][0] ?? '');
		return $this->users[$login] ?? null;
	}

	public function update(string $table, array $fields, string $where = '', string $whereFormat = '', array $params = array()): int
	{
		$this->updates[] = array('table' => $table, 'fields' => $fields, 'params' => $params);
		$uid = (int)($params[0] ?? 0);
		foreach ($this->users as $login => &$user)
		{
			if ((int)$user['uID'] === $uid)
			{
				if (isset($fields['uBFC=']))
				{
					$user['uBFC'] = min((int)$user['uBFC'] + 1, 2147483647);
				}
				if (isset($fields['uPass']))
				{
					$user['uPass'] = $fields['uPass'];
				}
			}
		}
		return 1;
	}
}

$db = new LoginGaTestDb();
$ga = new GoogleAuthenticator();
$secret = 'JBSWY3DPEHPK3PXP';
$validTotp = $ga->getCode($secret);
$password = 'correct-user-password';
$passwordHash = hashPassword($password);

$db->users['victim-ga'] = array(
	'uID' => 42,
	'uLogin' => 'victim-ga',
	'uPass' => $passwordHash,
	'aGA' => $secret,
	'aFailedLoginThreshold' => null,
	'uBFC' => 0,
);

$db->users['normal-user'] = array(
	'uID' => 7,
	'uLogin' => 'normal-user',
	'uPass' => $passwordHash,
	'aGA' => null,
	'aFailedLoginThreshold' => null,
	'uBFC' => 0,
);

$_cfg = array(
	'Const_Salt' => 'synthetic-salt',
	'Sec_BFC' => 3,
);

require_once $root . '/module/account/login/lib.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
	$checks++;
	if (!$condition) throw new RuntimeException($message);
};

// 1. Attacker submits valid TOTP as password without knowing account password
// Must be rejected with login_not_found and increment uBFC
$result = opLoginPrepare('victim-ga', $validTotp);
$assert($result === 'login_not_found', 'TOTP in password field must be rejected; got: ' . var_export($result, true));
$assert($db->users['victim-ga']['uBFC'] === 1, 'Failed password attempt with TOTP must increment uBFC; got: ' . $db->users['victim-ga']['uBFC']);

// 2. Attacker submits wrong password with valid TOTP code in gaCode
$result = opLoginPrepare('victim-ga', 'wrong-password', $validTotp);
$assert($result === 'login_not_found', 'Wrong password with valid TOTP must be rejected; got: ' . var_export($result, true));
$assert($db->users['victim-ga']['uBFC'] === 2, 'Failed attempt with wrong password must increment uBFC; got: ' . $db->users['victim-ga']['uBFC']);

// 3. User submits correct password with wrong TOTP code
// Must return GA_wrong and increment uBFC
$result = opLoginPrepare('victim-ga', $password, '000000');
$assert($result === 'GA_wrong', 'Wrong TOTP code must return GA_wrong; got: ' . var_export($result, true));
$assert($db->users['victim-ga']['uBFC'] === 3, 'Failed TOTP code attempt must increment uBFC; got: ' . $db->users['victim-ga']['uBFC']);

// 4. User submits correct password with empty TOTP code
// Must return GA_wrong and increment uBFC
$result = opLoginPrepare('victim-ga', $password, '');
$assert($result === 'GA_wrong', 'Empty TOTP code must return GA_wrong; got: ' . var_export($result, true));
$assert($db->users['victim-ga']['uBFC'] === 4, 'Empty TOTP code attempt must increment uBFC; got: ' . $db->users['victim-ga']['uBFC']);

// 5. User submits correct password AND valid TOTP code
// Must succeed and return user ID
$result = opLoginPrepare('victim-ga', $password, $validTotp);
$assert($result === 42, 'Correct password and valid TOTP code must return UID; got: ' . var_export($result, true));

// 6. Normal user without GA logs in with just password
$result = opLoginPrepare('normal-user', $password);
$assert($result === 7, 'Normal user login must succeed with password; got: ' . var_export($result, true));

// 7. Normal user fails with wrong password
$result = opLoginPrepare('normal-user', 'wrong-pass');
$assert($result === 'login_not_found', 'Normal user wrong password must fail; got: ' . var_export($result, true));
$assert($db->users['normal-user']['uBFC'] === 1, 'Normal user failure must increment uBFC');

echo "Login GA authentication tests passed: $checks checks.\n";
