<?php

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/module/account/register/lib.php';

final class RegistrationDbStub
{
	private array $users = array(
		'sponsor' => 42,
		'self' => 7,
	);

	public function count(string $table, string $where = '', array $params = array()): int
	{
		return 0;
	}

	public function select(string $table, string $fields = '*', string $where = '', array $params = array()): array
	{
		return array('params' => $params);
	}

	public function fetch1(array $query): int
	{
		$login = (string)($query['params'][0] ?? '');
		return (int)($this->users[$login] ?? 0);
	}
}

$assertSame = static function ($expected, $actual, string $case): void
{
	if ($expected !== $actual)
		throw new RuntimeException($case . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
};

$params = static function (string $login, string $referrer = ''): array
{
	return array(
		'aName' => 'Test user',
		'uLogin' => $login,
		'uMail' => $login . '@example.com',
		'uPass' => 'test-password',
		'Pass2' => 'test-password',
		'uRef' => $referrer,
	);
};

$db = new RegistrationDbStub();
$_cfg = array(
	'Account_UseName' => 0,
	'Const_NoLogins' => 0,
	'Account_MinLogin' => 3,
	'Account_LoginRegx' => '',
	'Account_MinPass' => 8,
	'Account_PassRegx' => '',
	'Sec_MinPIN' => 0,
	'SMS_REG' => 0,
	'Account_RegMode' => 2,
	'Sec_MinSQA' => 0,
);

$adminWithoutReferrer = $params('admin-created');
$assertSame(true, opRegisterUserCheck($adminWithoutReferrer, 0, true), 'admin can omit inviter');
$assertSame(0, $adminWithoutReferrer['uRef'], 'empty admin inviter is stored as zero');

$publicWithoutReferrer = $params('public-created');
$assertSame('ref_empty', opRegisterUserCheck($publicWithoutReferrer, 0, false), 'public required-referrer mode is unchanged');

$adminWithReferrer = $params('admin-referred', 'sponsor');
$assertSame(true, opRegisterUserCheck($adminWithReferrer, 0, true), 'admin inviter is accepted');
$assertSame(42, $adminWithReferrer['uRef'], 'admin inviter login resolves to user id');

$adminWithUnknownReferrer = $params('admin-unknown', 'missing');
$assertSame('ref_not_found', opRegisterUserCheck($adminWithUnknownReferrer, 0, true), 'unknown admin inviter is rejected');

$adminSelfReferrer = $params('self-edit', 'self');
$adminSelfReferrer['uPass'] = '';
$assertSame('ref_is_self', opRegisterUserCheck($adminSelfReferrer, 7, true), 'self invitation is rejected');

echo "Account registration tests passed.\n";
