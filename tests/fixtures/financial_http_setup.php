<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('H_SCRIPT_FINANCIAL_TEST') !== '1' || getenv('DB_NAME') !== 'fixture') exit(2);
require dirname(__DIR__,2) . '/vendor/autoload.php';
$db=new HScript\Database\Connection();
if (!$db->open(getenv('DB_HOST'),'fixture',getenv('DB_USER'),getenv('DB_PASSWORD'))) exit(1);
$configuration=[
    'API'=>['Enabled'=>1,'RateLimitIP'=>1000,'RateLimitToken'=>1000],
    'Account'=>['LoginCaptcha'=>0,'UseName'=>2], 'Sec'=>['BFC'=>0,'IP'=>0],
    'Sys'=>['NeedReConfig'=>0], 'Const'=>['IntCurr'=>0],
    'Bal'=>['NeedPIN'=>0,'UpdateBTCRate'=>0,'UpdateETHRate'=>0,'UpdateXRPRate'=>0,'UpdateLTCRate'=>0,'UpdateRates'=>0],
    'SMS'=>['CASHOUT'=>0], 'Depo'=>['AutoDepo'=>0,'ChargeMode'=>2,'ManualPeriod'=>0,'ManualDay'=>0,'ShowStat'=>0], 'Cron'=>['Enabled'=>0],
];
foreach ($configuration as $module=>$properties) foreach ($properties as $property=>$value)
    $db->replace('Cfg',['Module'=>$module,'Prop'=>$property,'Val'=>$value]);
$salt=time();
$db->insert('Currs',['cID'=>2,'cDisabled'=>0,'cHidden'=>0,'cCID'=>'CP','cName'=>'Synthetic Bitcoin','cCurrID'=>'BTC','cNumDec'=>2,'cMTS'=>timeToStamp($salt),
    'cCASHINMode'=>2,'cCASHOUTMode'=>2,'cTRMode'=>2,'cEXMode'=>2,'cGIVEMode'=>1,'cTAKEMode'=>1,
    'cParamsSCI'=>encodeArrayToStr(['id'=>'fixture-merchant','key'=>'synthetic-callback-secret'], '2CP'.$salt,2)]);
$db->insert('Currs',['cID'=>3,'cDisabled'=>0,'cHidden'=>0,'cCID'=>'YM','cName'=>'Synthetic YooMoney','cCurrID'=>'RUB','cNumDec'=>2,'cMTS'=>timeToStamp($salt),
    'cCASHINMode'=>2,'cCASHOUTMode'=>0,'cTRMode'=>0,'cEXMode'=>0,
    'cParamsSCI'=>encodeArrayToStr(['acc'=>'410111222333444','key'=>'synthetic-yoo-secret'], '3YM'.$salt,2)]);
$db->insert('Currs',['cID'=>4,'cDisabled'=>0,'cHidden'=>0,'cCID'=>'WM','cName'=>'Synthetic WebMoney','cCurrID'=>'USD','cNumDec'=>2,'cMTS'=>timeToStamp($salt),
    'cCASHINMode'=>2,'cCASHOUTMode'=>0,'cTRMode'=>0,'cEXMode'=>0,
    'cParams'=>encodeArrayToStr(['acc'=>'Z123456789012'],'4WM'.$salt),
    'cParamsSCI'=>encodeArrayToStr(['key'=>'synthetic-webmoney-secret','timezone'=>'UTC'],'4WM'.$salt,2)]);
$db->insert('Plans',['pID'=>1,'pName'=>'Synthetic plan','pMin'=>1,'pMax'=>100000,'pPerc'=>1,'pPer'=>24,'pNPer'=>10,'pReturn'=>100,'pCompndMax'=>100,'pEnAdd'=>1,'pClComis'=>0]);
foreach (['financial-user','financial-peer'] as $index=>$login) {
    $uid=$db->insert('Users',['uLogin'=>$login,'uPass'=>hashPassword('synthetic-financial-password'),'uMail'=>$login.'@fixture.invalid','uState'=>1,'uLevel'=>1,'uLang'=>'en','uTheme'=>'light','uBal'=>1000]);
    $db->insert('AddInfo',['auID'=>$uid,'aName'=>$login,'aNeedReConfig'=>0,'aIPSec'=>0,'aTZ'=>0]);
    $db->insert('Wallets',['wuID'=>$uid,'wcID'=>2,'wBal'=>1000,'wMTS'=>timeToStamp($salt),
        'wParams'=>encodeArrayToStr(['acc'=>'synthetic-destination-'.$index], '2'.$uid.$salt)]);
    $db->insert('ApiTokens',['atuID'=>$uid,'atName'=>'Synthetic test token','atTokenHash'=>HScript\Http\ApiTokenRepository::hash('hs_'.str_repeat((string)($index+1),64)), 'atScopes'=>'*','atState'=>1,'atCreatedAt'=>time()]);
}
$uid=$db->insert('Users',['uLogin'=>'financial-admin','uPass'=>hashPassword('synthetic-financial-password'),'uMail'=>'financial-admin@fixture.invalid','uState'=>1,'uLevel'=>99,'uLang'=>'en','uTheme'=>'light']);
$db->insert('AddInfo',['auID'=>$uid,'aName'=>'financial-admin','aNeedReConfig'=>0,'aIPSec'=>0,'aTZ'=>0]);
// A due manual deposit has its own owner/plan, independent of cabinet tests.
$uid=$db->insert('Users',['uLogin'=>'financial-manual','uMail'=>'financial-manual@fixture.invalid','uState'=>1,'uLevel'=>1,'uBal'=>200]);
$db->insert('AddInfo',['auID'=>$uid]);
$db->insert('Wallets',['wuID'=>$uid,'wcID'=>2,'wBal'=>100,'wLock'=>100]);
$pid=$db->insert('Plans',['pName'=>'Synthetic manual plan','pMin'=>1,'pMax'=>100000,'pPerc'=>2,'pPer'=>24,'pNPer'=>10,'pReturn'=>100,'pCompndMax'=>100]);
$did=$db->insert('Deps',['duID'=>$uid,'dcID'=>2,'dpID'=>$pid,'dState'=>1,'dZ0'=>100,'dZD'=>100,'dNPer'=>0,
    'dCTS'=>timeToStamp(time()-172800),'dLTS'=>timeToStamp(time()-86400),'dNTS'=>timeToStamp(time()-86400)]);
$db->insert('Opers',['ouID'=>$uid,'ocID'=>2,'oOper'=>'GIVE','oState'=>3,'oSum'=>100,'oTag'=>$did,'oTS'=>timeToStamp(time()-172800)]);
echo "Synthetic financial HTTP fixtures ready.\n";
