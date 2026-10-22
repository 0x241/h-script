<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/fixtures/financial_runtime.php';
require __DIR__.'/fixtures/financial_property_support.php';
require dirname(__DIR__).'/module/account/register/lib.php';
require dirname(__DIR__).'/module/balance/onload.php';
function recoverySetup(bool $confirm):?int
{
    global $db,$pdo,$_cfg,$_onload,$jobQueue;
    financialReset();propertyScale(2);$_cfg['Bal_RegBonus']='25';$_cfg['Bal_RateUSD']='1';$_cfg['Bal_RateBTC']='5';$_cfg['Const_IntCurr']=0;$_cfg['Sec_MinPIN']=4;$_cfg['Account_RegLogin']=0;$_cfg['Confirm_Expire']=0;$_cfg['Confirm_DifIP']=0;$_onload=['Balance'=>true];
    $db->update('Users',['uState'=>0,'uRef'=>0],'','uID=2');$jobQueue=new HScript\Queue\JobQueue($db);FinancialFixtureView::$queueMail=true;
    if(!$confirm)return null;
    return $db->insert('Hist',['hOper'=>'CONFIRM','huID'=>2,'hTS'=>timeToStamp(),'hMemo'=>'synthetic-code','hParams'=>arrayToStr(['oper'=>'REG','module'=>'account/register','mname'=>'register','mail'=>'verified@fixture.invalid'])]);
}
$scenarios=0;
foreach([false,true]as $confirm)foreach([false,true]as $nested){
    $hid=recoverySetup($confirm);$pdo->exec("CREATE TRIGGER RecoveryLateBonus BEFORE INSERT ON Opers FOR EACH ROW BEGIN IF NEW.oOper='BONUS' AND NEW.ocID=3 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated activation late bonus failure'; END IF; END");
    if($nested){$pdo->beginTransaction();$pdo->exec("UPDATE AddInfo SET aName='retained caller' WHERE auID=1");}$before=propertyFingerprint();
    try{$confirm?opRegisterConfirmCode($hid):opRegisterComplete(2);throw new LogicException('Real source failure absent');}catch(PDOException $e){financialAssert($e->getCode()==='45000'&&(int)$e->errorInfo[1]===1644,'genuine source late45000');}
    financialAssert(propertyFingerprint()===$before&&$pdo->inTransaction()===$nested,'entire code/activation/PIN/REG/mail/money/receipt rollback and caller retained');if($nested)$pdo->rollBack();propertyReconcile(0);$pdo->exec('DROP TRIGGER RecoveryLateBonus');
    financialAssert(($confirm?opRegisterConfirmCode($hid):opRegisterComplete(2))===true,'normal source retry succeeds after rollback');financialWallet(2,1,'25.000000');financialWallet(2,2,'12.500000');financialWallet(2,3,'5.000000');
    financialAssert((int)financialValue("SELECT COUNT(*) FROM Hist WHERE hOper='REG'")===1,'one activation history');financialAssert((int)financialValue('SELECT COUNT(*) FROM Jobs')===($confirm?5:4),'only committed source/bonus notification jobs');propertyReconcile(3);
    $before=propertyFingerprint();financialAssert(($confirm?opRegisterConfirmCode($hid):opRegisterComplete(2))===($confirm?'code_used':true),'stable direct activation/rejected used code');financialAssert(propertyFingerprint()===$before,'source retry never resets PIN or notifications/money/receipt');propertyReconcile(3);$scenarios++;
}
// The last confirmation write fails after all financial work; nothing may escape.
$hid=recoverySetup(true);$pdo->exec("CREATE TRIGGER RecoveryLateConfirm BEFORE UPDATE ON Hist FOR EACH ROW BEGIN IF NEW.hOper='CONFIRM' AND NEW.hTag=1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated final confirmation failure'; END IF; END");$before=propertyFingerprint();
try{opRegisterConfirmCode($hid);throw new LogicException('Final confirmation failure absent');}catch(PDOException $e){financialAssert($e->getCode()==='45000','actual final confirmation45000');}financialAssert(propertyFingerprint()===$before&&!$pdo->inTransaction(),'final code failure rolls back all completed child effects');propertyReconcile(0);$pdo->exec('DROP TRIGGER RecoveryLateConfirm');financialAssert(opRegisterConfirmCode($hid)===true,'normal confirmation can retry final-write fault');propertyReconcile(3);$scenarios++;
foreach(['used','expired','ip','blocked','wrong-module']as $kind){$hid=recoverySetup(true);if($kind==='used')$pdo->exec('UPDATE Hist SET hTag=1 WHERE hID='.$hid);if($kind==='expired'){$_cfg['Confirm_Expire']=1;$pdo->exec('UPDATE Hist SET hTS=20000101000000 WHERE hID='.$hid);}if($kind==='ip'){$_cfg['Confirm_DifIP']=1;$pdo->exec("UPDATE Hist SET hIP='192.0.2.3' WHERE hID=".$hid);}if($kind==='blocked')$pdo->exec('UPDATE Users SET uState=3 WHERE uID=2');if($kind==='wrong-module')$db->update('Hist',['hParams'=>arrayToStr(['oper'=>'REG','module'=>'account/login','mname'=>'login'])],'','hID=?d',[$hid]);$expected=['used'=>'code_used','expired'=>'code_expired','ip'=>'dif_ip','blocked'=>'not_active','wrong-module'=>'oper_wrong'][$kind];$before=propertyFingerprint();financialAssert(opRegisterConfirmCode($hid)===$expected,'current source guard '.$kind);financialAssert(propertyFingerprint()===$before,'source refusal entire tables unchanged');propertyReconcile(0);$scenarios++;}
// A nested owner with automatic login configured must commit before session/redirect effects.
foreach([false,true]as $confirm){$hid=recoverySetup($confirm);$_cfg['Account_RegLogin']=1;$before=propertyFingerprint();$pdo->beginTransaction();$result=$confirm?opRegisterConfirm(2,['mail'=>'verified@fixture.invalid']):opRegisterComplete(2);financialAssert($result===true&&$pdo->inTransaction(),'nested source defers automatic login and redirect to its owner');$pdo->rollBack();financialAssert(propertyFingerprint()===$before,'nested login configuration preserves complete rollback');propertyReconcile(0);$scenarios++;}
// Affected-row counts alone do not prove the intended source fields persisted.
// Genuine triggers retain one field while another changes, so UPDATE reports success.
foreach(['pin','state_zero_bonus','confirmation']as $lost)foreach([false,true]as $nested){
    $hid=recoverySetup(true);if($lost==='state_zero_bonus')$_cfg['Bal_RegBonus']='0';
    $sql=$lost==='confirmation'?"CREATE TRIGGER RecoveryLostField BEFORE UPDATE ON Hist FOR EACH ROW BEGIN IF NEW.hOper='CONFIRM' AND NEW.hTag=1 THEN SET NEW.hTag=OLD.hTag,NEW.hIP='192.0.2.60'; END IF; END":("CREATE TRIGGER RecoveryLostField BEFORE UPDATE ON Users FOR EACH ROW BEGIN IF NEW.uID=2 AND NEW.uState=1 THEN SET ".($lost==='pin'?'NEW.uPIN=OLD.uPIN':'NEW.uState=OLD.uState')."; END IF; END");$pdo->exec($sql);
    if($nested){$pdo->beginTransaction();$pdo->exec("UPDATE AddInfo SET aName='owned field sentinel' WHERE auID=1");}$before=propertyFingerprint();
    financialAssert(opRegisterConfirmCode($hid)==='db_error','physical persisted source postcondition '.$lost);
    financialAssert(propertyFingerprint()===$before&&$pdo->inTransaction()===$nested,'lost intended field rolls back code/PIN/activation/REG/mail/bonus/receipt and preserves caller');if($nested)$pdo->rollBack();propertyReconcile(0);$pdo->exec('DROP TRIGGER RecoveryLostField');
    financialAssert(opRegisterConfirmCode($hid)===true,'normal source retries after lost-field refusal');financialAssert((int)financialValue('SELECT hTag FROM Hist WHERE hID='.$hid)===1&&(int)financialValue('SELECT uState FROM Users WHERE uID=2')===1&&strlen((string)financialValue('SELECT uPIN FROM Users WHERE uID=2'))>0,'actual activation/PIN/consumption saved');propertyReconcile($lost==='state_zero_bonus'?0:3);$scenarios++;
}
echo json_encode(['status'=>'passed','assertions'=>$assertions,'scenarios'=>$scenarios,'source_activation_atomic'=>true,'real_SQL_faults'=>true,'whole_source_atomic'=>true,'no_external_delivery'=>true],JSON_THROW_ON_ERROR)."\n";
