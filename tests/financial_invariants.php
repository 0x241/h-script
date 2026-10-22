<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
use HScript\Finance\FinancialReconciler;
use HScript\Finance\FinancialAuditSnapshot;
require __DIR__ . '/fixtures/financial_runtime.php';

financialReset();
financialAssert(opChangeBalance(1,1,100,0,0)===true,'initial credit');
financialAssert(opChangeBalance(1,2,50,0,0)===true,'second currency');
financialAssert(financialValue('SELECT uBal FROM Users WHERE uID=1')==='150.000000','legacy total sums nominal currencies');
financialAssert(opChangeBalance(1,1,-30,10,20)===true,'internal redistribution');
financialWallet(1,1,'70.000000','10.000000','20.000000');
foreach ([[-71,0,0,'low_bal1'],[0,-11,0,'low_bal2'],[0,0,-21,'low_bal3']] as [$a,$l,$o,$error]) {
    $before=financialFingerprint();
    financialAssert(opChangeBalance(1,1,$a,$l,$o,false)===$error,'overdraft guard even without precheck');
    financialAssert($before===financialFingerprint(),'rejected mutation has no effects');
}
$db->rejectWalletUpdate=true;
$before=financialFingerprint();
financialAssert(opChangeBalance(1,1,1,0,0)==='low_bal','failed conditional update');
financialAssert($before===financialFingerprint(),'failed wallet update rolls back');
$db->rejectWalletUpdate=false;
financialGap('F01',financialValue('SELECT COUNT(*) FROM Hist WHERE hOper=\'BAL\' AND hcID=0 AND hSum=0 AND hoID=0')>0);

financialAssert(\HScript\Finance\DecimalOutput::display('1.005',\HScript\Finance\CurrencyScale::resolve($_currs[1],$_cfg))==='1.01','fiat half-up');
financialAssert(\HScript\Finance\DecimalOutput::display('0.00000001',\HScript\Finance\CurrencyScale::resolve($_currs[3],$_cfg))==='0.00000001','crypto eight-place rounding');
$_currs[1]['cTRComis']=2; $_currs[1]['cTRComisMin']=1; $_currs[1]['cTRComisMax']=3;
financialAssert((string)opCalcComis(1,'TR',10,false)==='1.00','minimum fee');
financialAssert((string)opCalcComis(1,'TR',1000,false)==='3.00','maximum fee');
$_currs[1]['cTRMin']=10; $_currs[1]['cTRMax']=100; $_currs[1]['cTRInt']=1;
financialAssert(opCalcComis(1,'TR',9,false)==='sum_min','inclusive minimum');
financialAssert(opCalcComis(1,'TR',101,false)==='sum_max','inclusive maximum');
financialAssert(opCalcComis(1,'TR',10.5,false)==='sum_not_int','integer restriction');
financialAssert(!is_string(opCalcComis(1,'TR',100,false)),'maximum boundary allowed');

financialReset(); opChangeBalance(1,1,100,0,0);
$_currs[1]['cTRComis']=2;
$id=opOperCreate(1,'TR',1,25,['uid2'=>2]);
financialAssert(is_int($id),'transfer create');
financialAssert(opOperConfirm(1,$id)===true,'transfer confirm');
financialWallet(1,1,'75.000000','0.000000','25.000000');
financialAssert(opOperComplete(1,$id)===true,'transfer complete');
financialWallet(1,1,'75.000000'); financialWallet(2,1,'24.500000');
financialAssert(financialValue('SELECT SUM(uBal) FROM Users')==='99.500000','transfer conservation plus fee');
financialAssert(opOperComplete(1,$id)===true,'sequential completion replay');
financialAssert(opOperCancel(1,$id)==='oper_state_wrong','terminal cancellation rejected');
foreach (['paired_source','paired_child','paired_identity','paired_batch','transfer_conservation'] as $check) financialAssert((int)financialValue('SELECT COUNT(*) FROM ('.FinancialReconciler::queries()[$check].') f')===0,$check);

foreach (['BONUS','REF','CALCIN','SELL','SELL2'] as $operation) {
    financialReset();
    $id=opOperCreate(1,$operation,1,10,[],'',true,true);
    financialAssert(is_int($id),$operation.' income completion'); financialWallet(1,1,'10.000000');
}
foreach (['PENALTY','CALCOUT','BUY'] as $operation) {
    financialReset(); opChangeBalance(1,1,100,0,0);
    $id=opOperCreate(1,$operation,1,10,[],'',true,true);
    financialAssert(is_int($id),$operation.' deduction completion'); financialWallet(1,1,'90.000000');
}
financialReset(); opChangeBalance(1,1,100,0,0);
$id=opOperCreate(1,'BUY2',1,10,[],'',false,true);
financialGap('F10',opOperConfirm(1,$id,[],true)==='oper_unknown');

foreach (['CASHOUT','EX','TR','BUY','GIVE','TAKE'] as $operation) {
    financialReset(); opChangeBalance(1,1,100,100,0);
    $params=['uid2'=>2,'cid2'=>2];
    $id=opOperCreate(1,$operation,1,10,$params,'',false,true);
    financialAssert(opOperConfirm(1,$id,[],true)===true,$operation.' reserve');
    financialAssert(opOperCancel(1,$id)===true,$operation.' cancel');
    financialWallet(1,1,'100.000000','100.000000');
    financialAssert(opOperCancel(1,$id)==='oper_state_wrong',$operation.' cancellation replay');
}

financialReset(); opChangeBalance(1,1,100,0,0);
$_currs[1]['cEXComis']=1; $_currs[2]['cEXIn']=10;
$id=opOperCreate(1,'EX',1,100,['cid2'=>2]);
financialAssert(opOperConfirm(1,$id)===true && opOperComplete(1,$id)===true,'exchange pair');
financialWallet(1,1,'0.000000'); financialWallet(1,2,'55.000000');
financialAssert((int)financialValue('SELECT COUNT(*) FROM ('.FinancialReconciler::queries()['exchange_conservation'].') f')===0,'exchange stored quote');
financialAssert(opCalcExSum(false,3,1)->compareTo(\HScript\Finance\Decimal::of(0))>0,'positive rate');
$_cfg['Bal_RateBTC']=0;
financialAssert(opCalcExSum(false,3,1)==='ex_rate_not_set','zero rate rejected');

financialReset();
$id=opOperCreate(1,'CASHIN',1,20,[],'',false,true);
financialAssert(opOperConfirm(1,$id,['sum'=>'20','date'=>time(),'batch'=>'synthetic-gateway'])===true,'gateway confirm');
financialAssert(opOperComplete(1,$id)===true,'gateway settle');
financialWallet(1,1,'20.000000');
$duplicate=opOperCreate(1,'CASHIN',1,20,[],'',false,true);
financialAssert(opOperConfirm(1,$duplicate,['sum'=>'20','date'=>time(),'batch'=>'synthetic-gateway'])==='batch_exists','sequential batch duplicate');
$id=opOperCreate(1,'CASHOUT',1,10,[],'',false,true);
financialAssert(opOperConfirm(1,$id,[],true)===true,'withdraw reserve');
financialAssert(opOperComplete(1,$id,['date'=>time(),'batch'=>'synthetic-payout'],true)===true,'manual external settlement');
financialWallet(1,1,'10.000000');
financialReset();
$id=opOperCreate(1,'CASHIN',1,100,[],'',false,true);
financialAssert(opOperConfirm(1,$id,['sum'=>90,'date'=>time(),'batch'=>'synthetic-mismatched'])===true,'trusted direct core call may replace CASHIN amount');
financialAssert(opOperComplete(1,$id)===true,'trusted direct core adjusted CASHIN completes');
financialGap('F11',financialValue("SELECT oSum FROM Opers WHERE oID=$id")==='90.000000');

financialReset(); opChangeBalance(1,1,200,0,0);
$did=opDepoCreate(1,1,100,50,1);
financialAssert(is_int($did),'deposit create');
financialWallet(1,1,'100.000000','100.000000');
financialAssert(opDepoAdd(1,1,20,$did)===true,'top-up');
financialWallet(1,1,'80.000000','120.000000');
financialAssert(opDepoSub(1,1,10,$did)===true,'partial withdrawal with plan fee');
financialWallet(1,1,'89.000000','110.000000');
$plan=$db->fetch1Row($db->select('Plans','*','pID=1')); $plan['pID']=2; $db->insert('Plans',$plan);
financialAssert(opDepoChangePlan(1,2,$did)==1,'plan change');
financialAssert(opDepoChangeCompnd(1,$did,50)===0,'unchanged compound setting');
financialAssert(opDepoCharge(financialDepo($did))===true,'non-terminal accrual');
financialWallet(1,1,'94.500000','115.500000');
financialAssert(financialValue("SELECT dZC FROM Deps WHERE dID=$did")==='5.500000','compounded principal');
financialAssert(opDepoCharge(financialDepo($did))===true,'terminal accrual');
financialWallet(1,1,'221.550000');
financialAssert(financialValue("SELECT dState FROM Deps WHERE dID=$did")===2,'deposit completed');
financialAssert(financialValue("SELECT dZP FROM Deps WHERE dID=$did")==='22.550000','cumulative profit');
financialAssert(opDepoCharge(financialDepo($did))==='depo_wrong_state','completed deposit replay rejected');
financialAssert((int)financialValue('SELECT COUNT(*) FROM ('.FinancialReconciler::queries()['deposit_profit_history'].') f')===0,'deposit profit evidence');

financialReset(); opChangeBalance(1,1,100,0,0);
$pdo->exec('UPDATE Plans SET pBonus=10');
$did=opDepoCreate(1,1,100,0,1);
financialWallet(1,1,'0.000000','110.000000');
financialAssert(financialValue("SELECT dZ0 FROM Deps WHERE dID=$did")==='100.000000','initial principal excludes bonus');
financialAssert(is_int(opDepoClose(1,1,$did)),'admin close returns principal operation ID');
financialWallet(1,1,'110.000000');

financialReset(); opChangeBalance(1,1,100,0,0);
$did=opDepoCreate(1,1,100,0,1);
financialAssert(opDepoSub(1,1,100,$did)===true,'full early withdrawal');
financialWallet(1,1,'90.000000');
financialAssert(financialValue("SELECT dState FROM Deps WHERE dID=$did")===3,'early close state');
financialAssert(financialValue("SELECT dZD FROM Deps WHERE dID=$did")==='100.000000','closed dZD is retained history');

// Characterizations of unmet invariants are explicit findings, not acceptance passes.
financialReset(); opChangeBalance(1,1,100,0,0);
$db->rejectTransferChild=true;
financialAssert(opOperCreate(1,'TR',1,25,['uid2'=>2],'',true)==='db_error','failed child aborts source');
financialWallet(1,1,'100.000000');
financialAssert((int)financialValue('SELECT COUNT(*) FROM Opers')===0,'failed pair is rolled back');

financialReset();
$before=financialFingerprint();
financialAssert(opChangeBalance(1,3,'0.00000001',0,0)==='bal_precision','F03 unrepresentable eighth-place mutation rejected');
financialAssert(financialFingerprint()===$before,'F03 rejection preserves all stored values');

financialReset(); opChangeBalance(1,1,100,0,0);
$_currs[1]['cGIVEComis']=2;
$did=opDepoCreate(1,1,100,0,1);
financialAssert(is_int($did) && financialValue("SELECT dZD FROM Deps WHERE dID=$did")==='98.000000','F04 new principal uses actual GIVE net');
financialAssert((int)financialValue('SELECT COUNT(*) FROM ('.FinancialReconciler::queries()['locked_principal'].') f')===0,'F04 principal is fully backed');

financialReset(); opChangeBalance(1,1,100,0,0);
$did=opDepoCreate(1,1,100,0,1);
$db->rejectWalletUpdate=true;
financialAssert(opDepoCharge(financialDepo($did))==='low_bal','failed accrual returns credit error');
financialAssert(financialValue("SELECT dZP FROM Deps WHERE dID=$did")==='0.000000','failed accrual does not advance profit');
$db->rejectWalletUpdate=false;
financialAssert(opDepoCharge(financialDepo($did))===true,'failed accrual can retry same boundary');
financialWallet(1,1,'10.000000','100.000000');

financialReset(); opChangeBalance(1,1,100,0,0);
$did=opDepoCreate(1,1,100,0,1);
$before=financialFingerprint();
financialAssert(opDepoChangePlan(1,999,$did)==='plan_wrong','F06 invalid plan refused');
financialAssert(financialFingerprint()===$before,'F06 invalid target has no effects');
financialAssert((int)financialValue('SELECT COUNT(*) FROM ('.FinancialReconciler::queries()['deposit_owner_currency_plan'].') f')===0,'F06 owned plan remains valid');

financialReset();
_refPayByLevels(1,1,100,[5,2],0,'aPerc');
financialWallet(2,1,'5.000000'); financialWallet(3,1,'2.000000');
$db->update('AddInfo',['aPerc'=>"3\r\n1"],'','auID=?',[2]);
_refPayByLevels(1,1,100,[5,2],0,'aPerc');
financialWallet(2,1,'8.000000'); financialWallet(3,1,'4.000000');
$before=financialValue('SELECT COUNT(*) FROM Opers WHERE oOper=\'REF\'');
_refPayByLevels(1,1,100,[5,2],0,'aPerc');
financialAssert(financialValue('SELECT COUNT(*) FROM Opers WHERE oOper=\'REF\'')===$before+2,'raw payout helper has no source identity; each explicit invocation issues its own rewards');
$pdo->exec("UPDATE AddInfo SET aPerc=''");
_refPayByLevels(1,1,100,['0-1','2-3'],1,'aPerc');
financialWallet(2,1,'12.000000');
financialReset();
$_onload=['RefSys'=>true];$_cfg['Ref__Perc']=[5,2];
$id=opOperCreate(1,'CASHIN',1,100,[],'',false,true);
financialAssert(is_int($id) && opOperConfirm(1,$id,['date'=>time(),'batch'=>'invariant-ref-source'],true)===true && opOperComplete(1,$id,[],true)===true,'F07 actual source issues original rewards');
$before=financialFingerprint();$_cfg['Ref__Perc']=[100,100];
onRefSysEvent('Oper',1,['oid'=>$id,'oper'=>'CASHIN','cid'=>1,'sum'=>'100.0']);
financialAssert(financialFingerprint()===$before,'F07 actual source replay after configuration drift changes no financial table');
financialWallet(2,1,'5.000000');financialWallet(3,1,'2.000000');
financialAssert((int)financialValue("SELECT COUNT(*) FROM IdempotencyKeys WHERE ikNamespace='referral:cashin' AND ikState='completed'")===1,'F07 one durable source binding');
financialReset();
$db->insert('Deps',['dID'=>1,'duID'=>1,'dcID'=>1,'dpID'=>1,'dState'=>3,'dZD'=>100]);
_refPayByLevels(1,1,100,['0-1','100-3'],3,'aPerc');
financialWallet(2,1,'3.000000'); // type 3 intentionally includes closed principal
$db->insert('Deps',['dID'=>2,'duID'=>2,'dcID'=>1,'dpID'=>1,'dState'=>1,'dZD'=>100]);
_refPayByLevels(1,1,100,['0-1','100-3'],4,'aPerc');
financialWallet(2,1,'6.000000'); // type 4 uses sponsor's active principal
financialReset();
$pdo->exec('UPDATE Users SET uRef=2 WHERE uID=3');
$before=financialFingerprint();
financialAssert(_refPayByLevels(1,1,100,[5,2,1],0,'aPerc')==='ref_cycle','F12 repeated sponsor refused');
financialAssert(financialFingerprint()===$before,'F12 earlier rewards and financial history roll back with the cycle');

financialReset(); opChangeBalance(1,1,100,0,0);
// Confirmation is allowed twice for CASHIN, while a CASHOUT confirmation reserves once.
$id=opOperCreate(1,'CASHOUT',1,10,[],'',false,true);
financialAssert(opOperConfirm(1,$id,[],true)===true,'reserve before race model');
financialAssert(opOperConfirm(1,$id,[],true)===true,'repeated confirmation is stable');
financialWallet(1,1,'90.000000','0.000000','10.000000');
financialAssert(count($legacyDiagnostics)===0,'deposit close state has no undefined-key warning');

// Reconciliation must be deterministic, read-only, privacy bounded and fail closed.
financialReset();
$pdo->exec('UPDATE Currs SET cNumDec=2');
$checker=new FinancialReconciler();
$before=financialFingerprint(); $report=$checker->run($pdo);
financialAssert($report['status']==='clear','empty financial baseline');
financialAssert($report===$checker->run($pdo),'repeatable report');
financialAssert($before===financialFingerprint(),'reconciliation writes no rows');
$db->insert('Deps',['dID'=>700,'duID'=>1,'dcID'=>1,'dpID'=>1,'dState'=>3,'dZ0'=>100,'dZD'=>0,'dZC'=>10,'dNTS'=>timeToStamp(0)]);
financialAssert($checker->run($pdo)['checks']['deposit_state']['count']===0,'closed epoch boundary is cleared');
financialAssert($checker->run($pdo)['checks']['deposit_amount']['count']===0,'closed compound amount is historical');
$pdo->exec('UPDATE Deps SET dNTS=NULL WHERE dID=700');
financialAssert($checker->run($pdo)['checks']['deposit_state']['count']===1,'null closed boundary needs investigation');
$pdo->exec('UPDATE Deps SET dState=1,dZD=9,dNTS=20261031000000 WHERE dID=700');
financialAssert($checker->run($pdo)['checks']['deposit_amount']['count']===1,'active compounded principal cannot exceed deposit');
$pdo->exec('DELETE FROM Deps');
$pdo->exec('UPDATE Users SET uBal=0.000001 WHERE uID=1');
financialAssert($checker->run($pdo)['checks']['user_total']['count']===1,'detect single stored minor unit');
$pdo->exec('UPDATE Users SET uBal=0 WHERE uID=1');
$pdo->exec('INSERT INTO Wallets (wuID,wcID,wBal) VALUES (1,1,-1)');
financialAssert($checker->run($pdo)['checks']['negative_wallet']['count']===1,'negative bucket');
$pdo->exec('DELETE FROM Wallets');
$db->insert('Opers',['ouID'=>1,'ocID'=>1,'oOper'=>'CASHIN','oState'=>3,'oSum'=>1,'oBatch'=>'PRIVATE_BATCH_SECRET','oParams'=>json_encode(['password'=>'PRIVATE_CALLBACK_SECRET','uid2'=>2])]);
$db->insert('Opers',['ouID'=>1,'ocID'=>1,'oOper'=>'CASHIN','oState'=>3,'oSum'=>1,'oBatch'=>'PRIVATE_BATCH_SECRET']);
$report=$checker->run($pdo);
financialAssert($report['checks']['duplicate_gateway_batch']['count']===1,'duplicate batch detection');
$snapshot=(new FinancialAuditSnapshot())->capture($pdo);
financialAssert(!str_contains(json_encode([$snapshot,$report]),'PRIVATE_'),'no raw callback or batch export');
financialAssert(!str_contains(json_encode($snapshot),'fixture-1@'),'no identity export');
financialAssert($snapshot['tables']['Opers'][0]['oBatch']===$snapshot['tables']['Opers'][1]['oBatch'],'hashed batch equality preserved');
$pdo->exec('DELETE FROM Opers');
$db->insert('Opers',['oID'=>42,'ouID'=>1,'ocID'=>1,'oOper'=>'TR','oState'=>3,'oSum'=>1,'oBatch'=>'legacy-pair','oParams'=>'a:1:{s:4:"uid2";i:2;}']);
$db->insert('Opers',['oID'=>43,'ouID'=>2,'ocID'=>1,'oOper'=>'TRIN','oState'=>3,'oSum'=>1,'oBatch'=>'legacy-pair','oParams'=>'a:1:{s:3:"oid";i:42;}']);
$report=$checker->run($pdo);
financialAssert($report['checks']['paired_params_unreadable']['count']===2,'legacy pair explicitly unreadable');
financialAssert($report['checks']['paired_source']['count']===0 && $report['checks']['paired_child']['count']===0,'unreadable pair is not labeled orphaned');
financialAssert($checker->run($pdo,0)['checks']['paired_params_unreadable']['sample']===[],'zero sample option');
$pdo->exec('RENAME TABLE Wallets TO HiddenWallets');
try { $checker->run($pdo); throw new LogicException('Missing table accepted'); } catch (RuntimeException) { $assertions++; }
$pdo->exec('RENAME TABLE HiddenWallets TO Wallets');
financialAssert(!$pdo->inTransaction(),'snapshot closed after failure');
$pdo->exec('START TRANSACTION READ ONLY');
try { $pdo->exec('UPDATE Users SET uBal=1'); throw new LogicException('Read-only write accepted'); } catch (PDOException) { $assertions++; }
$pdo->exec('ROLLBACK');

sort($gaps);
$expected=json_decode(file_get_contents(__DIR__.'/fixtures/financial_known_gaps.json'),true,32,JSON_THROW_ON_ERROR);
financialAssert($gaps===array_values(array_diff(array_keys($expected), ['F02','F03','F04','F05','F06','F07','F08','F09','F12'])),'known gap catalog matches reproducible findings');
echo json_encode(['status'=>'characterized','assertions'=>$assertions,'known_unmet_invariants'=>$gaps],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
