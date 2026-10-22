<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/fixtures/financial_runtime.php';
use HScript\Finance\{PlanInput,ManualCharge,IdempotencyStore,IdempotencyResult,IdempotencyRequest,RequestDecimal};
function planFingerprint(): string {
    global $pdo;
    $all=[];
    foreach (['Plans','Deps','Wallets','Opers','Users','AddInfo','Cfg','Hist','Jobs','IdempotencyKeys'] as $table) {
        $rows=array_map('serialize',$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC));sort($rows);$all[$table]=$rows;
    }
    return hash('sha256',serialize($all));
}
function planManualSeed(): void {
    global $db,$_cfg,$_currs;
    financialReset();$_cfg['Depo_ChargeMode']=2;$_cfg['Depo_ManualDay']=0;$_cfg['Depo_ManualPeriod']=0;$_cfg['Depo_LastTime']=0;
    $db->update('Currs',['cDisabled'=>0,'cNumDec'=>6],'','cID=1');$_currs[1]['cDisabled']=0;$_currs[1]['cNumDec']=6;
    $db->update('Plans',['pNPer'=>10],'','pID=1');
    financialAssert(opChangeStoredBalance(1,1,'100','200.000002',0)===true,'Synthetic principal backing');
    foreach ([1,2] as $id) $db->insert('Deps',['dID'=>$id,'duID'=>1,'dcID'=>1,'dpID'=>1,'dState'=>1,'dZ0'=>'100.000001','dZD'=>'100.000001','dNPer'=>0,
        'dCTS'=>timeToStamp(time()-172800),'dLTS'=>timeToStamp(time()-86400),'dNTS'=>timeToStamp(time()-86400)]);
}
function planManual(string $key, array $percentages): IdempotencyResult {
    global $db;
    $boundary=opDepoLastManualChargeDate();$ids=array_keys($percentages);
    $body=['plan_ids'=>$ids,'percentages'=>$percentages,'boundary'=>$boundary];
    return (new IdempotencyStore($db))->execute('web:admin:depo:charge',$key,1,IdempotencyRequest::fingerprint($body),
        static fn()=>new IdempotencyResult(['result'=>ManualCharge::run($ids,$percentages,$boundary,$boundary)]));
}
financialReset();
$fixture=json_decode(file_get_contents(__DIR__.'/fixtures/decimal_plan_cases.json'),true,32,JSON_THROW_ON_ERROR);
foreach ($fixture['valid'] as $i=>$case) {
    $row=PlanInput::normalize($case['input']);$pid=$db->insert('Plans',$row+['pName'=>'Exact fixture '.$i]);
    $saved=$db->fetch1Row($db->select('Plans',implode(',',array_keys($case['expected'])),'pID=?d',[$pid]));
    financialAssert($saved===$case['expected'],'Real signed SQL columns equal independent Python storage oracle');
}
foreach ($fixture['invalid'] as $case) {
    $before=planFingerprint();
    try { $db->insert('Plans',PlanInput::normalize($case['input']));throw new LogicException('Invalid plan stored'); }
    catch (InvalidArgumentException $e) { financialAssert($e->getMessage()===$case['error'] && planFingerprint()===$before,'Invalid plan has no database effects'); }
}
planManualSeed();$before=planFingerprint();
foreach (['2.005','100000'] as $bad) {
    try { planManual('new-'.str_replace('.','-',$bad),[1=>RequestDecimal::normalize($bad,true)]);throw new LogicException('Invalid new manual stored'); }
    catch (InvalidArgumentException|OverflowException) { financialAssert(planFingerprint()===$before,'All markers/claims/principal/queue unchanged on new manual refusal'); }
}
$receipt=planManual('manual-exact',[1=>'2']);
financialAssert($receipt->data['result']===['info'=>'Completed','done'=>2],'Actual manual workflow charges due deposits');
financialAssert(financialValue('SELECT pmnPerc FROM Plans WHERE pID=1')==='2.00' && (int)financialValue('SELECT pmndIDdone FROM Plans WHERE pID=1')===2,'Exact scheduler percentage and cursor');
financialWallet(1,1,'104.000000','200.000002');
$before=planFingerprint();$db->update('Plans',['pPerc'=>'99','pNPer'=>1],'','pID=1');$before=planFingerprint();
financialAssert(planManual('manual-exact',[1=>RequestDecimal::normalize('2e0',true)])->data===$receipt->data && planFingerprint()===$before,'Completed form receipt replays before new workflow and policy');
// Real SQL failure after financial child writes must roll back the complete form transaction.
foreach (['marker','cursor'] as $fault) {
    planManualSeed();FinancialFixtureView::$queueMail=true;
    $condition=$fault==='marker'?'NEW.pmndID > 0':'NEW.pmndIDdone > 0';
    $pdo->exec("CREATE TRIGGER plan_manual_fault BEFORE UPDATE ON Plans FOR EACH ROW BEGIN IF $condition THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic manual marker failure'; END IF; END");
    $before=planFingerprint();
    try { planManual('manual-fault-'.$fault,[1=>'2']);throw new LogicException('SQL fault not reached'); }
    catch (PDOException $e) { financialAssert($e->getCode()==='45000' && planFingerprint()===$before && !$pdo->inTransaction(),'Marker/cursor late SQL fault restores deposits/wallets/source/child claims/configuration/mail queue'); }
    finally { $pdo->exec('DROP TRIGGER plan_manual_fault'); }
    financialAssert(planManual('manual-fault-'.$fault,[1=>'2'])->data['result']['done']===2,'Same key can retry after rollback');
}
planManualSeed();$before=planFingerprint();
$zero=planManual('manual-zero',[1=>'0']);
financialAssert($zero->data['result']['done']===0 && (int)financialValue('SELECT COUNT(*) FROM Opers')===0,'Explicit zero skips accrual');
financialAssert((int)financialValue('SELECT pmndID FROM Plans WHERE pID=1')===0,'Explicit zero does not schedule');
echo json_encode(['status'=>'passed','assertions'=>$assertions,'valid_python_sql_vectors'=>count($fixture['valid']),'invalid_sql_vectors'=>count($fixture['invalid'])],JSON_THROW_ON_ERROR)."\n";
