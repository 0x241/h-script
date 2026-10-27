<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/fixtures/financial_runtime.php';
use HScript\Finance\CurrencyRateCatalog;
financialReset();
$db->replace('Cfg',['Module'=>'Bal','Prop'=>'LastUpdate','Val'=>'20261024000000']);
$db->replace('Cfg',['Module'=>'Bal','Prop'=>'FutureMetadata','Val'=>'preserve']);
$current=['RateUSD'=>'1','RateSOL'=>'0','CASHINText'=>'original','RegBonus'=>'0','AFMMode'=>'0'];
$cfgHash=static fn()=>hash('sha256',serialize($pdo->query('SELECT * FROM Cfg ORDER BY Module,Prop')->fetchAll()));
$financial=financialFingerprint();
CurrencyRateCatalog::save($db,$current,['RateUSD'=>'1','RateBNB'=>' 500,125000000000000001 ','UpdateBNBRate'=>'1','RateJPY'=>'0.006666666666666667']);
financialAssert(financialValue("SELECT Val FROM Cfg WHERE Module='Bal' AND Prop='RateBNB'")==='500.125000000000000001','exact manual quote never becomes float');
financialAssert(financialValue("SELECT Val FROM Cfg WHERE Module='Bal' AND Prop='UpdateBNBRate'")==='1','enabled extra quote flag');
financialAssert(financialValue("SELECT Val FROM Cfg WHERE Module='Bal' AND Prop='FutureMetadata'")==='preserve' && financialValue("SELECT Val FROM Cfg WHERE Module='Bal' AND Prop='LastUpdate'")==='20261024000000','save preserves metadata and publication stamp');
foreach([['RateBNB'=>'1e5'],['RateBNB'=>'-1'],['RateBNB'=>['1']],['RateDAI'=>'1'],['RateBNB'=>'0.0000000000000000001'],['UpdateBNBRate'=>'2'],['RegBonus'=>'-1'],['RegBonus'=>'abc']]as$bad){$before=$cfgHash();try{CurrencyRateCatalog::save($db,$current,$bad);throw new LogicException('Invalid quote accepted');}catch(InvalidArgumentException|Brick\Math\Exception\MathException){financialAssert($cfgHash()===$before,'invalid configuration is atomic');}}
$before=$cfgHash();$db->rejectRateProperty='RateSOL';try{CurrencyRateCatalog::save($db,$current,['RateUSD'=>'2']);throw new LogicException('Late failure accepted');}catch(RuntimeException$e){if($e instanceof LogicException)throw$e;financialAssert($e->getMessage()==='rate_update_failed' && $cfgHash()===$before,'late database failure restores all rates and settings');}$db->rejectRateProperty=null;
// Configuration changes never mutate monetary rows or operation snapshots.
financialAssert($pdo->query('SELECT COUNT(*) FROM Opers')->fetchColumn()==0 && $pdo->query('SELECT COUNT(*) FROM Hist')->fetchColumn()==0,'no financial side effects');
echo "Currency rate SQL: $assertions assertions passed\n";
