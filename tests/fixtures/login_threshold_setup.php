<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

if (getenv('H_SCRIPT_LOGIN_THRESHOLD_TEST') !== '1' || getenv('DB_NAME') !== 'fixture') exit(2);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$db = new HScript\Database\Connection();
if (!$db->open(getenv('DB_HOST'), getenv('DB_NAME'), getenv('DB_USER'), getenv('DB_PASSWORD'))) exit(1);
foreach (array('Account' => array('LoginCaptcha' => 0, 'UseName' => 2), 'Sec' => array('BFC' => 3, 'IP' => 0, 'MinPIN' => 0, 'MinSQA' => 0), 'Sys' => array('NeedReConfig' => 0)) as $module => $properties)
	foreach ($properties as $property => $value) $db->replace('Cfg', array('Module' => $module, 'Prop' => $property, 'Val' => $value));
$hash = hashPassword('synthetic-login-policy-password');
$ids = array();
foreach (array('policy-demo' => 1, 'policy-demo-peer' => 1, 'policy-user' => 1, 'policy-peer' => 1, 'policy-admin' => 90, 'policy-admin-peer' => 90, 'policy-owner' => 99, 'ui-user' => 1) as $login => $level)
{
	$uid = $db->insert('Users', array('uLogin' => $login, 'uPass' => $hash, 'uMail' => $login . '@fixture.invalid', 'uState' => 1, 'uLevel' => $level, 'uLang' => 'en', 'uTheme' => 'light', 'uBFC' => $login === 'policy-peer' ? 2 : 0));
	$db->insert('AddInfo', array('auID' => $uid, 'aName' => $login, 'aIPSec' => 0, 'aTZ' => 0, 'aNeedReConfig' => 0));
	$ids[$login] = $uid;
}
if ($ids['policy-demo'] !== 2 || $ids['policy-demo-peer'] !== 3) throw new RuntimeException('Demo fixture IDs must match existing restrictions');
file_put_contents(dirname(__DIR__, 2) . '/_config.local.php', "<?php \$_cfg['demo_mode']='1';");
foreach (array('Enabled' => 1, 'balance' => 0, 'depo' => 0, 'integrity' => 0, 'telemetry' => 0) as $property => $value)
	$db->replace('Cfg', array('Module' => 'Cron', 'Prop' => $property, 'Val' => $value));
// External rate providers are disabled; the isolated telemetry endpoint is loopback port 9.
foreach (['UpdateBTCRate', 'UpdateETHRate', 'UpdateXRPRate', 'UpdateLTCRate', 'UpdateRates'] as $property)
    $db->replace('Cfg', ['Module' => 'Bal', 'Prop' => $property, 'Val' => 0]);
// A due deposit proves the manual runner executes modules, beyond rendering a success status.
// Fresh installations leave currencies disabled until configured. Enable the
// synthetic currency that backs this fixture's wallet and deposit.
$db->update('Currs', array('cDisabled' => 0), '', 'cID=?d', array(1));
$db->replace('Cfg', array('Module' => 'Depo', 'Prop' => 'ChargeMode', 'Val' => 1));
$db->replace('Cfg', array('Module' => 'Depo', 'Prop' => 'ShowStat', 'Val' => 0));
$plan = $db->insert('Plans', array('pName' => 'Isolated cron accrual', 'pPerc' => 2, 'pPer' => 24, 'pNPer' => 2));
$db->insert('Wallets', array('wuID' => $ids['ui-user'], 'wcID' => 1, 'wBal' => 0, 'wLock' => 100, 'wOut' => 0));
$db->update('Users', array('uBal' => 100), '', 'uID=?d', array($ids['ui-user']));
$db->insert('Deps', array('duID' => $ids['ui-user'], 'dcID' => 1, 'dpID' => $plan, 'dState' => 1,
    'dZ0' => 100, 'dZD' => 100, 'dNTS' => timeToStamp(time() - 60), 'dLTS' => timeToStamp(time() - 86400)));
foreach (array('CASHIN' => 25, 'CASHOUT' => 23, 'CALCIN' => 15, 'REF' => 2, 'BONUS' => 2, 'TR' => 1) as $operation => $number)
	for ($i = 0; $i < $number; $i++)
		$db->insert('Opers', array('ouID' => $ids['ui-user'], 'oOper' => $operation, 'ocID' => 1, 'oSum' => 10, 'oState' => 3, 'oTS' => timeToStamp(), 'oMemo' => 'UI fixture'));
$db->insert('Opers', array('ouID' => $ids['policy-peer'], 'oOper' => 'CASHIN', 'ocID' => 1, 'oSum' => 10, 'oState' => 3, 'oTS' => timeToStamp(), 'oMemo' => 'Other account operation'));
echo "Login policy browser fixtures configured.\n";
