<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/fixtures/financial_runtime.php';
use HScript\Finance\{Decimal,StoredAmount,FinancialReconciler};

function depositSeed(string $principal='100.000001', string $compounded='0.000001', string $profit='0.000001', int $cid=1): int
{
    global $db;
    financialAssert(opChangeStoredBalance(1,$cid,'100',$principal,0)===true,'seed exact backing');
    $did=$db->insert('Deps',['duID'=>1,'dcID'=>$cid,'dpID'=>1,'dState'=>1,'dZ0'=>$principal,'dZD'=>$principal,
        'dZC'=>$compounded,'dZP'=>$profit,'dCompnd'=>'33.33','dNTS'=>'20261001000000']);
    if (!Decimal::of($profit)->isZero()) $db->insert('Opers',['ouID'=>1,'ocID'=>$cid,'oOper'=>'CALCIN','oState'=>3,'oSum'=>$profit,'oTag'=>$did]);
    return $did;
}
function depositReconciled(): void
{
    foreach (['locked_principal','deposit_amount','deposit_state','deposit_profit_history','deposit_owner_currency_plan','user_total'] as $check) {
        if (!isset(FinancialReconciler::queries()[$check])) continue;
        financialAssert((int)financialValue('SELECT COUNT(*) FROM ('.FinancialReconciler::queries()[$check].') f')===0,$check);
    }
}

// Commission removes money once and never invents principal. Opening plan/bonus use gross.
financialReset(); opChangeBalance(1,1,'200',0,0);
$_currs[1]['cGIVEComis']='2'; $pdo->exec('UPDATE Plans SET pMin=100,pBonus=10');
$did=opDepoCreate(1,1,'100',0,1);
financialAssert(is_int($did),'gross minimum still chooses opening plan');
financialAssert(financialValue("SELECT dZ0 FROM Deps WHERE dID=$did")==='98.000000','initial net principal');
financialAssert(financialValue("SELECT dZD FROM Deps WHERE dID=$did")==='108.000000','net plus gross-based bonus');
financialWallet(1,1,'100.000000','108.000000'); depositReconciled();
financialAssert(opDepoAdd(1,1,'10',$did)===true,'fee topup');
financialWallet(1,1,'90.000000','117.800000');
financialAssert(financialValue("SELECT dZD FROM Deps WHERE dID=$did")==='117.800000','topup adds actual net'); depositReconciled();

// Internal compound GIVE is subject to the same fee; saved profit remains gross earnings.
financialReset(); $_currs[1]['cGIVEComis']='2'; opChangeBalance(1,1,100,0,0);
$did=opDepoCreate(1,1,100,50,1); financialAssert(opDepoCharge(financialDepo($did))===true,'compound with GIVE fee');
financialWallet(1,1,'4.900000','102.800000');
financialAssert(financialValue("SELECT dZC FROM Deps WHERE dID=$did")==='4.800000','compound net after fee');
financialAssert(financialValue("SELECT dZP FROM Deps WHERE dID=$did")==='9.800000','earned profit independent of reinvest fee');depositReconciled();

// Historical six places survive admin TAKE, partial withdrawal, and 100% maturity.
financialReset(); $did=depositSeed(); $before=financialValue("SELECT dZ0 FROM Deps WHERE dID=$did");
financialAssert(is_int($oid=opDepoClose(1,1,$did)),'exact admin close'); financialWallet(1,1,'200.000001');
financialAssert(financialValue("SELECT oSum FROM Opers WHERE oID=$oid")==='100.000001','TAKE stores exact old principal');
financialAssert(financialValue("SELECT dZ0 FROM Deps WHERE dID=$did")===$before,'old initial principal untouched');depositReconciled();
financialReset(); $did=depositSeed();
financialAssert(opDepoSub(1,1,'10',$did)===true,'partial old principal'); financialWallet(1,1,'109.000000','90.000001');depositReconciled();
financialReset(); $did=depositSeed();
financialAssert(opDepoSub(1,1,'100.000001',$did)===true,'full early stored principal');financialWallet(1,1,'190.000001');depositReconciled();
financialReset(); $did=depositSeed(); $pdo->exec('UPDATE Plans SET pNPer=1,pPerc=0');
$request=financialDepo($did); financialAssert(opDepoCharge($request)===true,'100% terminal identity');
financialWallet(1,1,'200.000001');
financialAssert(financialValue("SELECT dZD FROM Deps WHERE dID=$did")==='100.000001','terminal history retained');
$before=financialFingerprint(); financialAssert(opDepoCharge($request)===true && financialFingerprint()===$before,'terminal replay exact');depositReconciled();

// The migrated shared operation path preserves trusted child quotes after scale drift.
foreach (['TR','EX'] as $kind) {
    financialReset(); $_currs[1]['cNumDec']=6; $_currs[2]['cNumDec']=6;
    opChangeBalance(1,1,'100.000002',0,0);
    $oid=opOperCreate(1,$kind,1,'100.000001',['uid2'=>2,'cid2'=>2]);
    financialAssert(is_int($oid) && opOperConfirm(1,$oid)===true,'pending exact paired source');
    $_currs[1]['cNumDec']=2; $_currs[2]['cNumDec']=2;
    financialAssert(opOperComplete(1,$oid)===true,'paired completion after scale drift');
    financialWallet(1,1,'0.000001');
    if ($kind==='TR') financialWallet(2,1,'100.000001');
    else financialWallet(1,2,'50.000001');
    $check=$kind==='TR'?'transfer_conservation':'exchange_conservation';
    financialAssert((int)financialValue('SELECT COUNT(*) FROM ('.FinancialReconciler::queries()[$check].') f')===0,'exact child conservation');
}
financialReset(); $_currs[1]['cNumDec']=6; opChangeBalance(1,1,0,'100.000001',0);
$oid=opOperCreate(1,'TAKE',1,'100.000001',['tag'=>1],'',false,true);
$_currs[1]['cNumDec']=2;
financialAssert(opOperConfirm(1,$oid,[],true)===true && opOperCancel(1,$oid,[],true)===true,'cancel old TAKE restores all six places');
financialWallet(1,1,'0.000000','100.000001');

// A deficit belongs to the group: even closing a individually covered deposit refuses.
foreach (['add','sub','close','charge','plan','compound','create'] as $action) {
    financialReset(); $did=depositSeed('100','0','0');
    $db->insert('Deps',['duID'=>1,'dcID'=>1,'dpID'=>1,'dState'=>1,'dZD'=>'100','dZ0'=>'100','dNTS'=>'20261001000000']);
    opChangeStoredBalance(1,1,0,'98',0); $before=financialFingerprint();
    $result=match($action) {
        'add'=>opDepoAdd(1,1,10,$did), 'sub'=>opDepoSub(1,1,10,$did), 'close'=>opDepoClose(1,1,$did),
        'charge'=>opDepoCharge(financialDepo($did)), 'plan'=>opDepoChangePlan(1,1,$did),
        'compound'=>opDepoChangeCompnd(1,$did,0), 'create'=>opDepoCreate(1,1,10,0,1),
    };
    financialAssert($result==='depo_principal_mismatch' && financialFingerprint()===$before,'shared deficit fails closed: '.$action);
}

// Native cap, ceil division (rate=3), current deposit excluded from capacity counts.
financialReset(); $_cfg['Bal_RateEUR']='3'; $_cfg['Depo_OneFromGroup']=1;
$pdo->exec('UPDATE Plans SET pMin=1,pMax=6,pMaxCount=1,pGroup=1');
$plan=$db->fetch1Row($db->select('Plans','*','pID=1')); $plan['pID']=2;$plan['pMin']='6';$plan['pMax']='30';$db->insert('Plans',$plan);
$did=depositSeed('1','0.9','0',2);
financialAssert(opDepoChangePlan(1,1,$did)===0,'same plan allowed with capacity=1');
financialAssert(opDepoAdd(1,2,'1',$did,true)===true,'same group transition excludes self');
financialAssert(financialValue("SELECT dpID FROM Deps WHERE dID=$did")===2,'new eligible plan');
financialAssert(financialValue("SELECT dZC FROM Deps WHERE dID=$did")==='0.000000','minimum 6base/3rate leaves no compounded principal');depositReconciled();
// Non-integer quotient ceiling avoids .33 * 3 < 1 for worked principal.
$pdo->exec('UPDATE Plans SET pMin=1,pMax=30 WHERE pID=1');
financialAssert(opDepoChangePlan(1,1,$did)===1,'manual native cap plan');
financialAssert((string)opDepoMinimumNative('1',2)==='0.34','cap minimum uses ceiling');
foreach ([['pMaxCount'=>1],['pHidden'=>1],['pGroupReq'=>9],['pMin'=>100],['pCompndMax'=>0]] as $properties) {
    financialReset(); $did=depositSeed();
    $plan=$db->fetch1Row($db->select('Plans','*','pID=1'));$plan['pID']=2;
    foreach($properties as $k=>$v) $plan[$k]=$v;
    if (isset($properties['pMin'])) $plan['pMin']='101';
    $db->insert('Plans',$plan);
    if(isset($properties['pMaxCount'])) $db->insert('Deps',['duID'=>1,'dcID'=>1,'dpID'=>2,'dState'=>1,'dZD'=>0]);
    $before=financialFingerprint();$result=opDepoChangePlan(1,2,$did);
    financialAssert(is_string($result) && financialFingerprint()===$before,'ineligible plan rejected');
}
financialReset();$did=depositSeed();$plan=$db->fetch1Row($db->select('Plans','*','pID=1'));$plan['pID']=2;$plan['pHidden']=1;$db->insert('Plans',$plan);
financialAssert(opDepoChangePlan(1,2,$did,true)===1,'explicit admin may select hidden eligible plan');
$before=financialFingerprint();financialAssert(opDepoChangePlan(2,1,$did,true)==='depo_wrong' && financialFingerprint()===$before,'plan ownership enforced');

// Hidden active plans still occupy a group for visible candidates.
financialReset();$_cfg['Depo_OneFromGroup']=1;$pdo->exec('UPDATE Plans SET pGroup=1');
$plan=$db->fetch1Row($db->select('Plans','*','pID=1'));$plan['pID']=2;$plan['pHidden']=1;$db->insert('Plans',$plan);
$did=depositSeed('100','0','0');$pdo->exec("UPDATE Deps SET dpID=2 WHERE dID=$did");
$before=financialFingerprint();
financialAssert(opDepoCreate(1,1,10,0,1)==='plan_wrong' && financialFingerprint()===$before,'hidden group blocks visible creation');
financialAssert(opDepoChangePlan(1,1,$did)===1,'moving current hidden plan excludes its own occupancy');
// A different active deposit cannot move into a group occupied by hidden principal.
$pdo->exec("UPDATE Deps SET dpID=2 WHERE dID=$did");$plan['pID']=3;$plan['pHidden']=0;$plan['pGroup']=2;$db->insert('Plans',$plan);
$other=$db->insert('Deps',['duID'=>1,'dcID'=>1,'dpID'=>3,'dState'=>1,'dZ0'=>10,'dZD'=>10,'dNTS'=>'20261001000000']);
opChangeStoredBalance(1,1,0,'10',0);$before=financialFingerprint();
financialAssert(opDepoChangePlan(1,1,$other)==='plan_wrong' && financialFingerprint()===$before,'hidden occupied target refuses manual transition');
// Automatic transition also counts the other hidden plan after excluding current.
$pdo->exec("UPDATE Plans SET pGroup=1,pMax=20 WHERE pID=3");$before=financialFingerprint();
financialAssert(opDepoAdd(1,1,15,$other)==='plan_not_found' && financialFingerprint()===$before,'hidden group refuses automatic transition');

// Python stdlib decimal oracle exercises actual SQL writers and wallets at every scale.
$fixture=json_decode(file_get_contents(__DIR__.'/fixtures/decimal_deposit_cases.json'),true,32,JSON_THROW_ON_ERROR);
$sqlVectors=0;
foreach ($fixture['cases'] as $case) {
    if (!in_array($case['principal'],['123.499950','99999.999999'],true)) continue;
    financialReset();$_currs[1]['cNumDec']=$case['scale'];$_cfg['UI_NumDec']=$case['scale'];
    $pdo->exec('UPDATE Plans SET pMax=9999999');
    $did=depositSeed($case['principal'],$case['compounded'],$case['previousProfit']);
    $db->update('Plans',['pPerc'=>$case['percent'],'pReturn'=>$case['returnPercent'],'pNPer'=>$case['terminal']?1:2],'','pID=1');
    $request=financialDepo($did);$before=financialFingerprint();$result=opDepoCharge($request);
    if ($case['expected']===null) {
        financialAssert($result==='sum_precision' && financialFingerprint()===$before,'SQL oracle precision refusal');$sqlVectors++;continue;
    }
    financialAssert($result===true,'SQL oracle accrual: '.$case['id']); $expected=$case['expected'];
    financialAssert(financialValue("SELECT dZP FROM Deps WHERE dID=$did")===$expected['totalProfit'],'SQL cumulative exact profit');
    $available=Decimal::of('100')->plus(Decimal::of($expected['profit']))->plus(Decimal::of($expected['returned']))->minus(Decimal::of($expected['reinvest']));
    $locked=Decimal::of($case['principal'])->plus(Decimal::of($expected['reinvest']))->minus(Decimal::of($expected['release']));
    financialWallet(1,1,StoredAmount::exact($available),StoredAmount::exact($locked));depositReconciled();
    $after=financialFingerprint();financialAssert(opDepoCharge($request)===true && financialFingerprint()===$after,'SQL boundary replay');
    $sqlVectors++;
}

// Refusal paths roll back the complete projection, including idempotency and receipt.
foreach (['rejectDepositInsert','rejectDepositLastTime','rejectInfoOperation'] as $flag) {
    financialReset();opChangeBalance(1,1,100,0,0);$pdo->exec('UPDATE Plans SET pBonus=10');
    $db->$flag=$flag==='rejectInfoOperation'?'BONUS':true;$before=financialFingerprint();
    financialAssert(is_string(opDepoCreate(1,1,100,0,1)) && financialFingerprint()===$before,'creation refusal: '.$flag);
}
foreach (['rejectDepositUpdate','rejectWalletUpdate','CALCIN','TAKE','GIVE'] as $flag) {
    financialReset();$did=depositSeed(); if($flag==='TAKE') $pdo->exec('UPDATE Plans SET pNPer=1');
    if(in_array($flag,['CALCIN','TAKE','GIVE'],true))$db->rejectInfoOperation=$flag; else $db->$flag=true;
    $request=financialDepo($did);$before=financialFingerprint();
    financialAssert(is_string(opDepoCharge($request)) && financialFingerprint()===$before,'charge refusal: '.$flag);
    $db->rejectInfoOperation=null;$db->rejectWalletUpdate=$db->rejectDepositUpdate=false;
    financialAssert(opDepoCharge($request)===true,'same failed boundary can retry');depositReconciled();
}
foreach ([['cNumDec'=>8,'amount'=>'0.00000001','expected'=>'sum_precision'],['cNumDec'=>6,'amount'=>'10000000','expected'=>'sum_overflow']] as $case) {
    financialReset();$_currs[1]['cNumDec']=$case['cNumDec'];$before=financialFingerprint();
    financialAssert(opDepoCreate(1,1,$case['amount'],0,1)===$case['expected'] && financialFingerprint()===$before,'storage before writes');
}
foreach (['-1','0','bad'] as $rate) {
    financialReset();$_cfg['Bal_RateEUR']=$rate;opChangeBalance(1,2,100,0,0);$before=financialFingerprint();
    $expected='ex_rate_not_set';
    financialAssert(opDepoCreate(1,2,10,0,1)===$expected && financialFingerprint()===$before,'missing/invalid valuation rate');
}
foreach (['pBonus','pPerc','pReturn'] as $field) {
    financialReset();opChangeBalance(1,1,100,0,0);$pdo->exec("UPDATE Plans SET $field=-1");$before=financialFingerprint();
    financialAssert(opDepoCreate(1,1,100,0,1)==='plan_wrong' && financialFingerprint()===$before,'negative plan refuses new principal');
    $did=depositSeed();$request=financialDepo($did);$before=financialFingerprint();
    if($field!=='pBonus') financialAssert(opDepoCharge($request)==='sum_wrong' && financialFingerprint()===$before,'old negative earnings/return fails closed');
}
// Wide valuation is never reconstructed as a bounded external input or stored amount.
financialReset(); $_cfg['Bal_RateEUR']='999999999999999999999999999999999999.999999999999999999';
financialAssert(strlen((string)opDepoUSD(Decimal::of('9999999.999999'),'EUR'))>36,'wide unstored valuation');
$before=financialFingerprint();financialAssert(opDepoCreate(1,2,'9999999.99',0,1)==='plan_wrong' && financialFingerprint()===$before,'wide valuation safely compared with plan');

financialReset();$did=depositSeed();$before=financialFingerprint();$pdo->beginTransaction();
financialAssert(opDepoAdd(1,1,10,$did)===true && $pdo->inTransaction(),'topup does not commit caller');$pdo->rollBack();
financialAssert(financialFingerprint()===$before,'caller rollback restores topup');
$pdo->beginTransaction();$db->replace('Cfg',['Module'=>'Outer','Prop'=>'Marker','Val'=>'kept']);$before=financialFingerprint();$db->rejectDepositUpdate=true;
financialAssert(is_string(opDepoSub(1,1,10,$did)) && $pdo->inTransaction() && financialFingerprint()===$before,'nested failure retains earlier caller writes');
$db->rejectDepositUpdate=false;$pdo->rollBack();
financialAssert(opDepoCharge(financialDepo($did),'-0.004')===true,'negative sentinel remains automatic before rounding');

echo json_encode(['status'=>'passed','assertions'=>$assertions,'sql_deposit_oracle_vectors'=>$sqlVectors],JSON_THROW_ON_ERROR)."\n";
