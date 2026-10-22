<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/fixtures/financial_runtime.php';
require dirname(__DIR__) . '/module/balance/onload.php';
use HScript\Finance\{Decimal,Money,RateUpdater,CentralBankRateProvider,CoinbaseRateProvider};

financialReset();
$fixture=json_decode(file_get_contents(__DIR__.'/fixtures/decimal_base_conversion_cases.json'),true,32,JSON_THROW_ON_ERROR);
foreach ($fixture['cases'] as $case) {
    $_currs[2]['cNumDec']=$case['scale']; $_cfg['Bal_RateEUR']=$case['rate'];
    $result=opAmountFromBase($case['amount'],2);
    financialAssert($case['error']!==null ? $result===$case['error'] : $result instanceof Money && (string)$result===$case['expected'],'independent base conversion oracle');
}
$_currs[2]['cNumDec']=3; $_cfg['Bal_RateEUR']='2'; $_currs[2]['cCurr']='translated <EUR> & label';
$_currs[2]['cEXIn']='99'; $_currs[2]['cEXOut']='-100';
financialAssert((string)opAmountFromBase('10.005',2)==='5.003','canonical currency ID and raw division, no EX adjustments');
financialAssert((string)opAmountFromBase(Decimal::of('10.005'),2)==='5.003','already computed decimals remain typed');
financialAssert(opAmountFromBase('1',999)==='psys_wrong','unknown currency');
foreach (['0','-1','bad',NAN,INF] as $bad) financialAssert(opAmountFromBase($bad,2)==='sum_wrong','invalid amount');
foreach (['0','-1','bad',NAN,INF] as $bad) {
    $_cfg['Bal_RateEUR']=$bad; $before=financialFingerprint();
    financialAssert(opAmountFromBase('1',2)==='ex_rate_not_set' && $before===financialFingerprint(),'invalid rate has no effects');
}
foreach ([['3',8,'1','sum_precision'],['0.1',2,'1000000','sum_overflow'],['1000',2,'1','sum_wrong']] as [$rate,$scale,$amount,$error]) {
    $_cfg['Bal_RateEUR']=$rate; $_currs[2]['cNumDec']=$scale; $before=financialFingerprint();
    financialAssert(opAmountFromBase($amount,2)===$error && financialFingerprint()===$before,'storage/rounded-zero guard before any financial write');
}
financialReset(); $_cfg['Bal_RegBonus']='10.005'; $_currs[2]['cNumDec']=3;
onBalanceEvent('RegComplete',1,[]);
financialAssert((int)financialValue('SELECT COUNT(*) FROM Opers')===1,'unsupported/missing and unrepresentable bonus currencies skipped independently');
financialAssert(financialValue('SELECT oSum FROM Opers')==='5.003000','bonus raw amount conversion persisted exactly');
financialWallet(1,2,'5.003000');
financialAssert((int)financialValue('SELECT COUNT(*) FROM Opers WHERE oState=3')===1,'bonus settled');
financialReset(); $_cfg['Bal_RegBonus']='6'; $_cfg['Bal_RateBTC']='2'; $_currs[2]['cEXIn']='99';
onBalanceEvent('RegComplete',1,[]);
financialAssert((int)financialValue('SELECT COUNT(*) FROM Opers')===2,'each supported currency gets its separate bonus');
financialWallet(1,2,'3.000000'); financialWallet(1,3,'3.000000');
financialReset(); $_cfg['Const_IntCurr']=1; $_cfg['Bal_RegBonus']='10.005'; $_cfg['Bal_RateUSD']='bad';
onBalanceEvent('RegComplete',1,[]);
financialWallet(1,1,'10.010000');
financialAssert((int)financialValue('SELECT COUNT(*) FROM Opers')===1,'internal bonus uses base money directly without rate');
foreach (['0','-1','bad',INF,'10000000'] as $bonus) {
    financialReset(); $_cfg['Const_IntCurr']=1; $_cfg['Bal_RegBonus']=$bonus; $before=financialFingerprint();
    onBalanceEvent('RegComplete',1,[]);
    financialAssert(financialFingerprint()===$before,'invalid/overflow internal bonus has no effects');
}
financialReset(); $_cfg['Bal_RegBonus']='5'; $before=financialFingerprint();
onBalanceEvent('OtherEvent',1,[]); financialAssert(financialFingerprint()===$before,'unrelated event no effects');

financialReset();
$xml='<ValCurs><Valute><Nominal>1</Nominal><CharCode>USD</CharCode><Value>90,5</Value></Valute><Valute><Nominal>100</Nominal><CharCode>EUR</CharCode><Value>9800</Value></Valute></ValCurs>';
$updater=new RateUpdater(new CoinbaseRateProvider(static fn($url)=>'{"data":{"currency":"BTC","rates":{"USD":"9007199254740993.123456789012345678"}}}'),new CentralBankRateProvider(static fn($url)=>$xml));
financialAssert($updater->refresh(['Bal_UpdateRates'=>1,'Bal_UpdateBTCRate'=>1],$_currs,$db,'20261009120000'),'validated update succeeds');
financialAssert(financialValue("SELECT Val FROM Cfg WHERE Prop='RateBTC'")==='9007199254740993.123456789012345678','Cfg stores exact 18-place rate past binary integer precision');
financialAssert(financialValue("SELECT Val FROM Cfg WHERE Prop='RateEUR'")==='1.0829' && financialValue("SELECT Val FROM Cfg WHERE Prop='RateRUB'")==='0.0110','cross-base persistence retains explicit fixed4');
financialAssert(financialValue("SELECT Val FROM Cfg WHERE Prop='LastUpdate'")==='20261009120000','successful update advances stamp');
$bad=new RateUpdater(new CoinbaseRateProvider(static fn($url)=>'bad'),new CentralBankRateProvider(static fn($url)=>str_replace(['90,5','9800'],['20001','2000100'],$xml)));
$before=financialFingerprint();
financialAssert(!$bad->refresh(['Bal_UpdateRates'=>1,'Bal_UpdateBTCRate'=>1],$_currs,$db,'20261009130000') && financialFingerprint()===$before,'failed update preserves all old rates and LastUpdate');
foreach (['RateBTC','RateUSD','RateEUR','RateRUB','LastUpdate'] as $property) {
    foreach ([false,true] as $throw) {
        $db->rejectRateProperty=$property; $db->throwRateWrite=$throw; $before=financialFingerprint();
        try { $updater->refresh(['Bal_UpdateRates'=>1,'Bal_UpdateBTCRate'=>1],$_currs,$db,'20261009130000'); }
        catch (RuntimeException | PDOException $e) {
            financialAssert($e->getMessage()===($throw?'Synthetic rate write failure':'rate_update_failed'),'write refusal/DB exception propagated');
            financialAssert(!$pdo->inTransaction() && financialFingerprint()===$before,'all quotes and stamp roll back on any failed write');
            continue;
        }
        throw new RuntimeException('Failed rate write accepted');
    }
}
$db->rejectRateProperty=null; $db->throwRateWrite=false;
$before=financialFingerprint(); $pdo->beginTransaction();
financialAssert($updater->refresh(['Bal_UpdateRates'=>1],$_currs,$db,'20261009140000') && $pdo->inTransaction(),'nested publishing does not commit caller transaction');
$pdo->rollBack(); financialAssert(financialFingerprint()===$before,'caller rollback restores all rates and stamp');
$pdo->beginTransaction(); $db->replace('Cfg',['Module'=>'Outer','Prop'=>'Marker','Val'=>'kept']);
$before=financialFingerprint(); $db->rejectRateProperty='RateEUR';
$caught=false;
try { $updater->refresh(['Bal_UpdateRates'=>1],$_currs,$db,'20261009150000'); }
catch (RuntimeException $e) {
    $caught=true;
    financialAssert($e->getMessage()==='rate_update_failed' && $pdo->inTransaction() && financialFingerprint()===$before,'publishing failure preserves caller transaction and earlier writes');
}
financialAssert($caught,'nested publishing refusal cannot report success');
$db->rejectRateProperty=null; $pdo->rollBack();
// The actual cron guard must exit before any request for these fresh stamps.
foreach ([0,3599] as $seconds) {
    $_cfg['Bal_LastUpdate']=timeToStamp(time()-$seconds); $_cfg['Bal_UpdateBTCRate']=1;
    $before=financialFingerprint(); require dirname(__DIR__).'/module/balance/oncron.php';
    financialAssert(financialFingerprint()===$before,'cron one-hour guard no requests or writes');
}
$_cfg['Bal_LastUpdate']=timeToStamp(time()-3600); $_cfg['Bal_UpdateBTCRate']=0; $_cfg['Bal_UpdateRates']=0;
$before=financialFingerprint(); require dirname(__DIR__).'/module/balance/oncron.php';
financialAssert(financialFingerprint()===$before,'due cron with disabled providers leaves timestamp unchanged');
echo json_encode(['status'=>'passed','assertions'=>$assertions,'base_oracle_vectors'=>count($fixture['cases'])],JSON_THROW_ON_ERROR)."\n";
