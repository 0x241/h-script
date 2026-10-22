<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/fixtures/financial_runtime.php';
use HScript\Finance\{Decimal,FinancialOperationException,FinancialReconciler};

function referralProjection(): string
{
    global $pdo;
    $projection=[];
    foreach (['Users','Wallets','Deps','Opers','Hist','Cfg','IdempotencyKeys','Jobs'] as $table) {
        $rows=array_map('serialize',$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC));
        sort($rows);$projection[$table]=$rows;
    }
    return hash('sha256',serialize($projection));
}
function referralEnabled(string $kind, array $percs): void
{
    global $_cfg,$_onload;
    $_onload=['RefSys'=>true];
    $_cfg['Ref__'.$kind]=$percs;
}

$fixture=json_decode(file_get_contents(__DIR__.'/fixtures/decimal_referral_cases.json'),true,32,JSON_THROW_ON_ERROR);
$oracleCases=0;
foreach ($fixture['cases'] as $case) {
    financialReset();$_cfg['UI_NumDec']=$case['scale'];$_currs[1]['cNumDec']=$case['scale'];
    $before=referralProjection();
    $result=_refPayByLevels(1,1,$case['source'],[$case['percent']],0,'aPerc');
    if ($case['error']) {
        financialAssert($result===$case['error'],'SQL oracle refusal '.$case['id']);
        financialAssert(referralProjection()===$before,'SQL oracle no effect');
    } elseif ($case['expected']==='0.000000') {
        financialAssert($result===true && referralProjection()===$before,'rounded zero skips');
    } else {
        financialAssert($result===true,'SQL oracle payout '.$case['id']);
        financialWallet(2,1,$case['expected']);
        financialAssert(financialValue("SELECT oSum FROM Opers WHERE oOper='REF'")===$case['expected'],'stored oracle reward');
        financialAssert(financialValue("SELECT oTag FROM Opers WHERE oOper='REF'")==='1','source user tag');
    }
    $oracleCases++;
}

// Exact source is not recurrency-rounded before the six-place percentage boundary.
financialReset();financialAssert(_refPayByLevels(1,1,'0.049995',['10'],0,'aPerc')===true,'two-stage half-up');financialWallet(2,1,'0.010000');
financialReset();$_currs[1]['cNumDec']=6;
financialAssert(_refPayByLevels(1,1,'100.000001',['100'],0,'aPerc')===true,'old exact source');financialWallet(2,1,'100.000001');
foreach (['.5','+.5',',5','.5e0'] as $percent) {
    financialReset();financialAssert(_refPayByLevels(1,1,100,[$percent],0,'aPerc')===true,'PHP numeric flat rate '.$percent);financialWallet(2,1,'0.500000');
}
financialReset();financialAssert(_refPayByLevels(1,1,100,['-.5','5.'],0,'aPerc')===true,'signed/trailing decimal rates');financialWallet(3,1,'5.000000');
foreach (['1e-9','0.000000001'] as $percent) {
    financialReset();$_currs[1]['cNumDec']=6;financialAssert(_refPayByLevels(1,1,1000000,[$percent],0,'aPerc')===true,'scientific percent independent precision');financialWallet(2,1,'0.000010');
}
foreach ([['.5-5.','0.5','5.000000'],['.5e-1-+.5','0.05','0.500000'],[',5-5,','0.5','5.000000']] as [$entry,$metric,$reward]) {
    financialReset();$db->insert('Deps',['duID'=>1,'dcID'=>1,'dpID'=>1,'dState'=>3,'dZD'=>$metric]);
    financialAssert(_refPayByLevels(1,1,100,[$entry],3,'aPerc')===true,'PHP numeric tier '.$entry);financialWallet(2,1,$reward);
}

// Short per-sponsor overrides are indexed by absolute level; absent rates skip.
financialReset();$pdo->exec("UPDATE AddInfo SET aPerc='7' WHERE auID=3");
financialAssert(_refPayByLevels(1,1,100,['5','2'],0,'aPerc')===true,'short override');
financialWallet(2,1,'5.000000');financialAssert((int)financialValue('SELECT COUNT(*) FROM Wallets WHERE wuID=3')===0,'no shifted override');
financialReset();$pdo->exec('UPDATE Users SET uState=0 WHERE uID=2');
financialAssert(_refPayByLevels(1,1,100,['5','2'],0,'aPerc')===true,'inactive sponsor skips');financialWallet(3,1,'2.000000');
financialAssert((int)financialValue("SELECT COUNT(*) FROM Opers WHERE ouID=2 AND oOper='REF'")===0,'inactive no reward');
financialReset();$pdo->exec('UPDATE Users SET uRef=999 WHERE uID=1');
financialAssert(_refPayByLevels(1,1,100,['5'],0,'aPerc')===true,'missing sponsor skips');
financialAssert((int)financialValue('SELECT COUNT(*) FROM Opers')===0,'missing no effects');
financialReset();financialAssert(_refPayByLevels(1,1,100,['-5','2'],0,'aPerc')===true,'nonpositive rate skips');financialWallet(3,1,'2.000000');

// Tier metrics preserve old all-state/mixed-currency type3 and active-own type4.
foreach ([3,4] as $type) foreach (['99.999999','100.000000','100.000001','19999999.999998'] as $metric) {
    financialReset();$owner=$type===3?1:2;
    $first=Decimal::of($metric)->compareTo(Decimal::of('9999999.999999'))>0?'9999999.999999':$metric;
    $rest=(string)Decimal::of($metric)->minus(Decimal::of($first));
    $db->insert('Deps',['duID'=>$owner,'dcID'=>1,'dpID'=>1,'dState'=>$type===3?3:1,'dZD'=>$first]);
    if ($rest!=='0.000000' && $rest!=='0') $db->insert('Deps',['duID'=>$owner,'dcID'=>2,'dpID'=>1,'dState'=>1,'dZD'=>$rest]);
    financialAssert(_refPayByLevels(1,1,100,['0-1','100-2','19999999.999998-3'], $type,'aPerc')===true,'exact tier '.$type.':'.$metric);
    $reward=Decimal::of($metric)->compareTo(Decimal::of('100'))<0?'1.000000':($metric==='19999999.999998'?'3.000000':'2.000000');
    financialWallet(2,1,$reward);
}
financialReset();financialAssert(_refPayByLevels(1,1,100,['0-1','1-2'],3,'aPerc')===true,'NULL aggregate is zero');financialWallet(2,1,'1.000000');
financialReset();$db->insert('Deps',['duID'=>1,'dcID'=>1,'dpID'=>1,'dState'=>3,'dZD'=>1]);
financialAssert(_refPayByLevels(1,1,100,['0-1','1-2','2-3'],2,'aPerc')===true,'type2 counts users with any deposit');financialWallet(2,1,'2.000000');
financialReset();$pdo->exec("UPDATE AddInfo SET aPerc='0-7' WHERE auID=2");
financialAssert(_refPayByLevels(1,1,100,['0-1'],4,'aPerc')===true,'personal tier override');financialWallet(2,1,'7.000000');
financialReset();$before=referralProjection();
financialAssert(_refPayByLevels(1,1,100,['0-5','100-6','bad'],3,'aPerc')==='ref_config_wrong','malformed unused tier refused');
financialAssert(referralProjection()===$before,'whole config validated before payout');
$db->insert('Deps',['duID'=>1,'dcID'=>1,'dpID'=>1,'dState'=>3,'dZD'=>'0.01']);
financialAssert(_refPayByLevels(1,1,100,['0-1','1e-2-5'],3,'aPerc')===true,'scientific minimum parses independently');financialWallet(2,1,'5.000000');

// Late invalid configuration/storage/balance/ref receipt errors roll back all levels.
foreach (['bad','20000000'] as $late) {
    financialReset();
    $before=referralProjection();
    $result=_refPayByLevels(1,1,'100.000001',['5',$late],0,'aPerc');
    financialAssert(is_string($result),'late refusal '.$late);financialAssert(referralProjection()===$before,'all levels rolled back '.$late);
}
financialReset();opChangeStoredBalance(3,1,'9999999.999999',0,0);$before=referralProjection();
financialAssert(_refPayByLevels(1,1,100,['5','2'],0,'aPerc')==='bal_overflow','late wallet overflow');financialAssert(referralProjection()===$before,'overflow preserves earlier recipient');
financialReset();$db->rejectInfoOperation='REF';$before=referralProjection();
financialAssert(_refPayByLevels(1,1,100,['5','2'],0,'aPerc')==='db_error','REF insert refusal');financialAssert(referralProjection()===$before,'REF insert rollback');
$db->rejectInfoOperation=null;financialAssert(_refPayByLevels(1,1,100,['5','2'],0,'aPerc')===true,'same chain retry');

// Real SQL trigger aborts the second level, including synthetic queued mail.
financialReset();FinancialFixtureView::$queueMail=true;$jobQueue=new HScript\Queue\JobQueue($db);
$pdo->exec("CREATE TRIGGER referral_fault BEFORE INSERT ON Opers FOR EACH ROW BEGIN IF NEW.oOper='REF' AND NEW.ouID=3 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic late referral failure'; END IF; END");
$before=referralProjection();
try { _refPayByLevels(1,1,100,['5','2'],0,'aPerc');throw new LogicException('Trigger accepted'); }
catch (PDOException) { financialAssert(referralProjection()===$before,'real trigger rolls back all levels and mail'); }
finally { $pdo->exec('DROP TRIGGER referral_fault'); }
financialAssert(_refPayByLevels(1,1,100,['5','2'],0,'aPerc')===true,'trigger retry');
financialAssert((int)financialValue('SELECT COUNT(*) FROM Jobs')===2,'only successful payout emails queued');
// Caller savepoint ownership: previous marker survives a failed nested chain.
financialReset();$pdo->beginTransaction();$db->insert('Cfg',['Module'=>'Fixture','Prop'=>'Earlier','Val'=>'kept']);$before=referralProjection();
financialAssert(_refPayByLevels(1,1,100,['5','bad'],0,'aPerc')==='ref_config_wrong','nested late failure');
financialAssert($pdo->inTransaction() && referralProjection()===$before,'nested rollback retains caller');$pdo->rollBack();

// Real event wiring: failure must abort the original source; basis/overrides retained.
financialReset();opChangeBalance(1,1,200,0,0);referralEnabled('DPerc',['5']);$_currs[1]['cGIVEComis']='2';
$pdo->exec("UPDATE Plans SET pBonus=10,pDPerc='10'");
$did=opDepoCreate(1,1,100,0,1);financialAssert(is_int($did),'deposit source success');
financialWallet(2,1,'10.000000');financialWallet(1,1,'100.000000','108.000000');
referralEnabled('PPerc',['3']);$pdo->exec("UPDATE Plans SET pPPerc='20'");
financialAssert(opDepoCharge(financialDepo($did))===true,'charge source success');financialWallet(2,1,'12.160000');
financialReset();opChangeBalance(1,1,200,0,0);referralEnabled('DPerc',['5.']);
financialAssert(is_int(opDepoCreate(1,1,100,0,1)),'trailing decimal rate permits deposit source');financialWallet(2,1,'5.000000');
financialReset();opChangeBalance(1,1,200,0,0);referralEnabled('DPerc',['5','bad']);$before=referralProjection();
financialAssert(opDepoCreate(1,1,100,0,1)==='ref_config_wrong','source creation fails with late referral');financialAssert(referralProjection()===$before,'original deposit and Cfg roll back');
$_cfg['Ref__DPerc']=['5','2'];financialAssert(is_int(opDepoCreate(1,1,100,0,1)),'same source can retry');
financialReset();referralEnabled('Perc',['5']);$_currs[1]['cCASHINComis']='2';
$oid=opOperCreate(1,'CASHIN',1,100,[],'',false,true);
financialAssert(opOperConfirm(1,$oid,['sum'=>'100','date'=>time(),'batch'=>'synthetic-referral-cashin'],true)===true && opOperComplete(1,$oid,[],true)===true,'CASHIN source success');financialWallet(2,1,'4.900000');
financialReset();referralEnabled('Perc',['.5']);
$oid=opOperCreate(1,'CASHIN',1,100,[],'',false,true);
financialAssert(opOperConfirm(1,$oid,['sum'=>'100','date'=>time(),'batch'=>'synthetic-referral-numeric'],true)===true && opOperComplete(1,$oid,[],true)===true,'leading decimal rate permits CASHIN source');financialWallet(2,1,'0.500000');
financialReset();referralEnabled('Perc',['5']);
// Use the real internal CASHIN->EX event (the target quote is the referral basis).
$_cfg['Const_IntCurr']=1;
$oid=opOperCreate(1,'CASHIN',2,100,[],'',false,true);
financialAssert(opOperConfirm(1,$oid,['sum'=>'100','date'=>time(),'batch'=>'synthetic-referral-converted'],true)===true && opOperComplete(1,$oid,[],true)===true,'converted CASHIN source');financialWallet(2,1,'10.000000');
financialAssert((int)financialValue("SELECT COUNT(*) FROM Opers WHERE oOper='REF' AND ocID=2")===0,'converted source pays target currency');
foreach (['negative_wallet','user_total'] as $check) financialAssert((int)financialValue('SELECT COUNT(*) FROM ('.FinancialReconciler::queries()[$check].') f')===0,'final '.$check);
echo json_encode(['status'=>'passed','assertions'=>$assertions,'referral_sql_oracle_vectors'=>$oracleCases,'remaining_referral_contracts'=>[]],JSON_THROW_ON_ERROR)."\n";
