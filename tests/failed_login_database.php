<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use HScript\Database\Connection;
use HScript\Security\FailedLoginPolicy;
use HScript\Update\MigrationLoader;
use HScript\Update\MigrationRunner;
use HScript\Update\SchemaStateRepository;

if (getenv('H_SCRIPT_LOGIN_THRESHOLD_TEST') !== '1' || getenv('DB_NAME') !== 'fixture') exit(2);
ob_start();
$root = dirname(__DIR__);
chdir($root);
final class LoginTestExit extends RuntimeException {}
final class LoginTestView
{
	public static function showInfo($code, $url = '*'): never { throw new LoginTestExit($code . ':' . $url); }
	public static function setPage(...$arguments): void {}
	public static function SendMailToAdmin(...$arguments): void {}
}
class_alias(LoginTestView::class, 'HScript\\Template\\View');
require $root . '/vendor/autoload.php';
require $root . '/module/lib.php';
require $root . '/module/account/login/lib.php';
function _uid(): int { return (int)($_SESSION['_uid'] ?? 0); }
function moduleToLink($module = ''): string { return $module === 'account/login' ? '/login' : '/cabinet'; }
function useLib($module = ''): void {}
function opConfirmPrepare($uid, $action, $params, $module): void { $GLOBALS['confirmations'][] = array($uid, $action, $params); }
$db = new Connection();
if (!$db->open(getenv('DB_HOST'), getenv('DB_NAME'), getenv('DB_USER'), getenv('DB_PASSWORD'))) throw new RuntimeException('Fixture DB unavailable');
require $root . '/_dbstru.php';
foreach (array('Users', 'AddInfo', 'Hist', 'Cfg', 'SchemaState', 'SchemaMigrations', 'UpdateRuns') as $table)
{
	$definition = $_dbstru[$table];
	if ($table === 'AddInfo') $definition = preg_replace('/^  (?:aFailedLoginThreshold|CONSTRAINT chk_account_login_threshold).*\n/m', '', $definition);
	$db->query('CREATE TABLE `' . $table . '` (' . $definition . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void { $checks++; if (!$condition) throw new RuntimeException($message); };
$hash = password_hash('synthetic-login-password', PASSWORD_BCRYPT, array('cost' => 4));
$uid = $db->insert('Users', array('uLogin' => 'fixture-login', 'uPass' => $hash, 'uState' => 1, 'uLang' => 'en', 'uTheme' => 'light', 'uBFC' => 2));
$db->insert('AddInfo', array('auID' => $uid));
$state = new SchemaStateRepository($db);
$state->setCurrentVersion('1.0.2');
$state->setInstalledApplicationVersion('1.0.7');
$runner = new MigrationRunner($db, $state, new MigrationLoader($root . '/migrations/versioned'));
$rejected = false;
try { $runner->run('1.0.3', false); } catch (RuntimeException $exception) { $rejected = str_contains($exception->getMessage(), 'verified backup'); }
$assert($rejected, 'Migration must require a verified backup');
$assert($runner->run('1.0.3', true) === array('202610031200_account_login_threshold'), 'Migration registry did not apply the account field');
$assert($runner->run('1.0.3', true) === array(), 'Migration retry is not idempotent');
HScript\Update\VersionedMigration::fromFile($root . '/migrations/versioned/202610031200_account_login_threshold.php')->apply($db);
$assert((int)opReadUser($uid)['uBFC'] === 2, 'Partial-DDL retry changed attempts');
$assert($state->currentVersion() === '1.0.3', 'Schema did not advance');
$row = opReadUser($uid);
$assert($row['aFailedLoginThreshold'] === null && (int)$row['uBFC'] === 2, 'Existing accounts must inherit without losing attempts');
// DB enforcement is checked through PDO so rejected values cannot terminate legacy error rendering.
foreach (array(0, -1, 101) as $value)
{
	$rejected = false;
	try { $db->link->exec('UPDATE AddInfo SET aFailedLoginThreshold=' . $value . ' WHERE auID=' . $uid); } catch (PDOException) { $rejected = true; }
	$assert($rejected, 'Database accepted an unsafe threshold');
}
$_cfg = array('Const_Salt' => 'synthetic-salt', 'Sec_BFC' => 0, 'Sec_IP' => 0, 'Sec_LockSite' => 0, 'sys_id' => 'fixture');
$_GS = array('client_ip' => '127.0.0.1', 'https' => false, 'domain' => 'fixture.invalid');
$_SESSION = array();
$confirmations = array();
$login = static function (bool $confirmed = false) use ($uid): string {
	try { opLogin($uid, '/cabinet', $confirmed); }
	catch (LoginTestExit $exception) { return $exception->getMessage(); }
	throw new RuntimeException('Login did not complete or require confirmation');
};
foreach (array(array(3, null), array(0, 2), array(5, 2), array(2, 5), array(1, 100), array(100, 1)) as [$global, $personal])
{
	$_cfg['Sec_BFC'] = $global;
	$db->update('Users', array('uBFC' => 0, 'uPass' => $hash), '', 'uID=?d', array($uid));
	$db->update('AddInfo', array('aFailedLoginThreshold=' => FailedLoginPolicy::assignment($personal)), '', 'auID=?d', array($uid));
	$threshold = FailedLoginPolicy::effective($global, $personal);
	for ($attempt = 1; $attempt <= $threshold; $attempt++)
	{
		$assert(opLoginPrepare('fixture-login', 'wrong-password') === 'login_not_found', 'Wrong password was accepted');
		$assert((int)opReadUser($uid)['uBFC'] === $attempt, 'Failed password was not counted');
		if ($attempt < $threshold) $assert(!FailedLoginPolicy::needsConfirmation($global, $personal, $attempt), 'Early lockout');
	}
	$assert(opLoginPrepare('fixture-login', 'synthetic-login-password') === (int)$uid, 'Correct password failed');
	$assert(str_contains($login(), '/login?brute_force'), 'Correct password must request confirmation at the threshold');
	$assert((int)opReadUser($uid)['uBFC'] === $threshold, 'Requesting confirmation reset the counter');
	$assert(end($confirmations)[1] === 'BFLOGIN', 'Wrong confirmation action');
	$assert(str_starts_with($login(true), 'LogIn:'), 'Confirmed login did not succeed');
	$assert((int)opReadUser($uid)['uBFC'] === 0, 'Successful confirmed login did not reset the counter');
}
$_cfg['Sec_BFC'] = 0;
$db->update('AddInfo', array('aFailedLoginThreshold=' => 'NULL'), '', 'auID=?d', array($uid));
$db->update('Users', array('uBFC' => 7), '', 'uID=?d', array($uid));
$assert(opLoginPrepare('fixture-login', 'wrong-password') === 'login_not_found', 'Disabled policy accepted wrong password');
$assert((int)opReadUser($uid)['uBFC'] === 7, 'Disabled policy changed the counter');
$assert(str_starts_with($login(), 'LogIn:'), 'Disabled inherited policy blocked login');
$assert((int)opReadUser($uid)['uBFC'] === 0, 'Successful disabled-policy login did not reset counter');
$db->update('Users', array('uBFC' => 7), '', 'uID=?d', array($uid));
foreach (array(1, 100, null) as $personal)
{
	$db->update('AddInfo', array('aFailedLoginThreshold=' => FailedLoginPolicy::assignment($personal)), '', 'auID=?d', array($uid));
	$assert((int)opReadUser($uid)['uBFC'] === 7, 'Configuration changed the independent counter');
	$assert(FailedLoginPolicy::parse(opReadUser($uid)['aFailedLoginThreshold']) === $personal, 'Nullable field roundtrip');
}
$assert(opLoginPrepare('unknown-fixture', 'wrong-password') === 'login_not_found', 'Unknown user failure changed');
$assert(opLoginPrepare('', '') === 'login_empty', 'Empty login failure changed');
ob_end_clean();
echo "Failed login database integration passed: $checks checks.\n";
