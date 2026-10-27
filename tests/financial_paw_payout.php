<?php

declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/fixtures/financial_runtime.php';
use HScript\Finance\{PawPayout,WithdrawalDispatcher,FinancialOperationException,IdempotencyRequest};
use HScript\Payment\Provider\{ProviderConnectionSchema,PawNativeSchema,PawPayoutSchema,PawProviderRepository};
use HScript\Security\SecretCipher;
putenv('APP_DATA_KEY=synthetic-paw-payout-data-key');
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,$e::class.': '.$e->getMessage().' at '.basename($e->getFile()).':'.$e->getLine().PHP_EOL.$e->getTraceAsString().PHP_EOL);exit(1);});
function pawOutReject(callable $fn,string $label):void{try{$fn();}catch(RuntimeException|InvalidArgumentException){financialAssert(true,$label);return;}throw new LogicException('Unexpected '.$label);}
financialReset();
if(!$pdo->query("SHOW COLUMNS FROM Currs LIKE 'cProviderKeyVersion'")->fetch())ProviderConnectionSchema::ensure($db);
if(!$pdo->query("SHOW COLUMNS FROM Currs LIKE 'cTokenRoute'")->fetch()){HScript\Payment\Routing\PaymentRouteSchema::ensure($db);HScript\Payment\Routing\TokenCatalogSchema::ensure($db);}
PawNativeSchema::ensure($db);
$drop=str_contains((string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION),'MariaDB')?'DROP CONSTRAINT':'DROP CHECK';
$pdo->exec('ALTER TABLE Currs ADD CONSTRAINT chk_paw_native CHECK (1=1)');
$ddl=$pdo->query('SHOW CREATE TABLE Currs')->fetch(PDO::FETCH_NUM)[1];$before=financialFingerprint();
pawOutReject(static fn()=>PawPayoutSchema::ensure($db),'unknown successor guard');
financialAssert($ddl===$pdo->query('SHOW CREATE TABLE Currs')->fetch(PDO::FETCH_NUM)[1] && financialFingerprint()===$before,'incompatible guard refused before DDL or financial writes');
$pdo->exec('ALTER TABLE Currs '.$drop.' chk_paw_native');
if($drop==='DROP CONSTRAINT') {
    $pdo->exec('SET check_constraint_checks=0');pawOutReject(static fn()=>PawPayoutSchema::ensure($db),'MariaDB disabled checks');$pdo->exec('SET check_constraint_checks=1');
}
foreach(['SchemaState','SchemaMigrations','UpdateRuns']as$t)$pdo->exec('CREATE TABLE IF NOT EXISTS '.$t.' ('.$_dbstru[$t].') ENGINE=InnoDB');
$state=new HScript\Update\SchemaStateRepository($db);$state->setCurrentVersion('1.0.12');
$runner=new HScript\Update\MigrationRunner($db,$state,new HScript\Update\MigrationLoader(dirname(__DIR__).'/migrations/versioned'));
pawOutReject(static fn()=>$runner->run('1.0.13',false),'outgoing migration requires verified backup');
$before=financialFingerprint();financialAssert($runner->run('1.0.13',true)===['202610241200_paw_payouts'],'one explicit ordered outgoing migration');
financialAssert(financialFingerprint()===$before && $state->currentVersion()==='1.0.13','capability migration preserves all financial rows');
PawPayoutSchema::ensure($db);PawNativeSchema::ensure($db);financialAssert(PawPayoutSchema::ready($pdo,true),'upgrade resumable and predecessor never reapplied');
$public=str_repeat('9',32);$key='synthetic_paw_live_key';
$db->insert('PaymentProviders',['ppPublicID'=>$public,'ppKind'=>'PAW','ppSlot'=>'PAW','ppEnvironment'=>'live','ppState'=>'enabled','ppValidationState'=>'valid','ppCurrentKeyVersion'=>1,'ppCreatedAt'=>time(),'ppUpdatedAt'=>time()]);$pid=(int)$pdo->lastInsertId();
$db->insert('PaymentProviderCredentials',['ppcProviderID'=>$pid,'ppcVersion'=>1,'ppcEnvironment'=>'live','ppcEncryptedKey'=>SecretCipher::encrypt($key,PawProviderRepository::context($public,1)),'ppcCreatedAt'=>time()]);
$tron='TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';$eth='0x'.str_repeat('a',40);$now=time();
$cids=[];
foreach(['USD','EUR']as$fiat){$cid=$db->insert('Currs',['cCID'=>'PAW','cCurrID'=>$fiat,'cAssetKey'=>'asset:'.$fiat,'cProviderID'=>$pid,'cProviderSelected'=>1,'cName'=>'Synthetic PAW '.$fiat,'cNumDec'=>2,'cDisabled'=>0,'cCASHINMode'=>2,'cCASHOUTMode'=>2,'cCASHOUTComisMin'=>'1','cEXMode'=>2]);$cids[$fiat]=$cid;$db->insert('Wallets',['wcID'=>$cid,'wuID'=>1,'wBal'=>'1000','wParams'=>encodeArrayToStr(['usdt_tron'=>$tron,'usdc_eth'=>$eth],$cid.'1'.$now),'wMTS'=>timeToStamp($now)]);}
$_currs=$db->fetchIDRows($db->select('Currs'),false,'cID');
$pdo->exec('UPDATE Users SET uBal=2000 WHERE uID=1');
function pawOutCreate(int $cid,string $asset='usdt_tron'):int{global $db; $id=FinancialOperationException::check(opOperCreate(1,'CASHOUT',$cid,'10',['payout_asset'=>$asset]));FinancialOperationException::check(opOperConfirm(1,$id));FinancialOperationException::check(opOperComplete(1,$id));return $id;}
$sends=0;$gets=0;$orders=[];$statusChange=[];
$transport=static function($method,$key,$arg)use(&$sends,&$gets,&$orders,&$statusChange){
 global $db;financialAssert(!$db->link->inTransaction(),'PAW network I/O outside every SQL transaction');
 if($method==='assets')return [['asset'=>'usdt_tron','symbol'=>'USDT','chain'=>'tron','enabled'=>true,'type'=>'token'],['asset'=>'usdc_eth','symbol'=>'USDC','chain'=>'eth','enabled'=>true,'type'=>'token']];
 if($method==='create'){$sends++;$order=substr(hash('sha256',$arg['fields']['ref']),0,24);$orders[$order]=$arg['fields'];return ['order_id'=>$order];}
 if($method==='status'){$gets++;$f=$orders[$arg];return array_replace(['order_id'=>$arg,'ref'=>$f['ref'],'currency'=>$f['asset'],'address'=>$f['asset']==='usdc_eth'?strtolower($f['address']):$f['address'],'fee_bearer'=>'merchant','status'=>'success','amount'=>'11.123456','fiat_amount_usd'=>'11.12','debit_usd'=>'12.12','fee'=>'1','fee_usd'=>'1','energy_fee_usd'=>'0','created_at'=>(string)time(),'processed_at'=>(string)time(),'txid'=>str_repeat('a',64),'tx_hashes'=>null,'voided'=>false,'voided_at'=>null,'voided_reason'=>null],$statusChange);}
 throw new LogicException('Unexpected transport');
};
// Before the attempt every invalid target is rejected atomically.
foreach(['','usdt_eth','usdc_ethereum']as$bad){$before=financialFingerprint();financialAssert(is_string(opOperCreate(1,'CASHOUT',$cids['USD'],'10',['payout_asset'=>$bad])) && financialFingerprint()===$before,'unsupported target cannot reserve');}
$id=pawOutCreate($cids['EUR'],'usdc_eth');$before=financialFingerprint();
financialAssert(PawPayout::dispatch($db,$id,$transport)===['pending'=>true] && $sends===1,'201 acknowledges pending exactly once');
financialAssert((int)financialValue('SELECT oState FROM Opers WHERE oID='.$id)===2,'ack keeps reservation');
$original=PawPayout::original($db,$id);financialAssert($original['fields']['amount']==='9.00' && $original['fields']['fiat_currency']==='EUR','provider converts original EUR net directly');
financialAssert(opOperCancel(1,$id)==='payout_reconciliation_required','cancel fenced after POST');
pawOutReject(static fn()=>PawPayout::saveReceipt($db,$id,['batch'=>'forged']),'receipt edit fenced');
financialAssert(opOperComplete(-1,$id,['date'=>time(),'batch'=>'forged'])==='paw_payout_reconciliation_required','manual completion needs proof');
$dest=strToArray(financialValue('SELECT oParams2 FROM Opers WHERE oID='.$id));$dest['txid']='stale';$dest['crypto_amount']='999';$db->update('Opers',['oParams2'=>arrayToStr($dest)],'','oID=?d',[$id]);
$pdo->exec("CREATE TRIGGER paw_out_fail BEFORE UPDATE ON Wallets FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic payout settlement failure'");
try{pawOutReject(static fn()=>PawPayout::reconcile($db,$id,$transport),'late SQL failure aborts settlement');}finally{$pdo->exec('DROP TRIGGER paw_out_fail');}
financialAssert((int)financialValue('SELECT oState FROM Opers WHERE oID='.$id)===2 && (int)financialValue("SELECT COUNT(*) FROM IdempotencyKeys WHERE ikNamespace='paw:payout:receipt' AND ikOperationID=".$id)===0,'receipt and settlement roll back together');
$pdo->exec("DELETE FROM IdempotencyKeys WHERE ikNamespace='paw:payout:check' AND ikOperationID=".$id);
financialAssert(PawPayout::reconcile($db,$id,$transport)===['completed'=>true],'known-id success completes original EUR request despite different USD equivalent');
$dest=strToArray(financialValue('SELECT oParams2 FROM Opers WHERE oID='.$id));financialAssert($dest['txid']===str_repeat('a',64) && $dest['crypto_amount']==='11.123456','validated receipt replaces stale metadata');
financialAssert(financialValue('SELECT oSum FROM Opers WHERE oID='.$id)==='10.000000' && financialValue('SELECT oComis FROM Opers WHERE oID='.$id)==='1.000000','provider FX never rewrites original gross or local fee');
$before=financialFingerprint();financialAssert(PawPayout::dispatch($db,$id,$transport)===['completed'=>true] && $sends===1 && financialFingerprint()===$before,'job replay preserves all money and never resends');
$pdo->prepare("DELETE FROM IdempotencyKeys WHERE ikNamespace='paw:payout:receipt' AND ikOperationID=?")->execute([$id]);pawOutReject(static fn()=>PawPayout::reconcile($db,$id,$transport),'state3 without receipt not trusted');
// Lost acknowledgment remains reconciliation, even beyond provider dedupe duration.
$lost=pawOutCreate($cids['USD']);$lostCalls=0;
$lostTransport=static function($method,$key,$arg)use(&$lostCalls,$transport){if($method==='create'){$lostCalls++;throw new RuntimeException('Synthetic lost response');}return $transport($method,$key,$arg);};
pawOutReject(static fn()=>PawPayout::dispatch($db,$lost,$lostTransport),'lost create response');
foreach([1,2]as$retry)pawOutReject(static fn()=>PawPayout::dispatch($db,$lost,$lostTransport),'unknown ID never adopted/resubmitted');
financialAssert($lostCalls===1 && opOperCancel(1,$lost)==='payout_reconciliation_required','lost ack keeps reservation permanently fenced');
$pdo->prepare("DELETE FROM IdempotencyKeys WHERE ikNamespace='payout:attempt' AND ikOperationID=?")->execute([$lost]);pawOutReject(static fn()=>PawPayout::dispatch($db,$lost,$lostTransport),'orphan capsule never becomes fresh send');
financialAssert(opOperCancel(1,$lost)==='payout_reconciliation_required','orphan capsule cannot refund');
// TRON uses its original case-sensitive address; status may share a batch txid.
$tronId=pawOutCreate($cids['USD']);PawPayout::dispatch($db,$tronId,$transport);
financialAssert(PawPayout::reconcile($db,$tronId,$transport)===['completed'=>true],'TRON exact-case success completes independently of shared chain transaction');
$proof=strToArray(financialValue('SELECT oParams2 FROM Opers WHERE oID='.$tronId));
financialAssert($proof['acc']===$tron && $proof['payout_asset']==='usdt_tron','selected network and recipient retained');
foreach(['pending','failed','cancelled']as$state) {
    $pending=pawOutCreate($cids['USD']);PawPayout::dispatch($db,$pending,$transport);$statusChange=['status'=>$state];
    $walletBefore=$pdo->query('SELECT wuID,wcID,wBal,wLock,wOut FROM Wallets ORDER BY wuID,wcID')->fetchAll();
    financialAssert(PawPayout::reconcile($db,$pending,$transport)===['pending'=>true]
        && $walletBefore===$pdo->query('SELECT wuID,wcID,wBal,wLock,wOut FROM Wallets ORDER BY wuID,wcID')->fetchAll(),'provider '.$state.' never releases/refunds reservation');
    $sentBefore=$sends;PawPayout::dispatch($db,$pending,$transport);financialAssert($sends===$sentBefore,'provider '.$state.' never triggers resend');
}
$statusChange=[];
// A provider order belongs globally to one original request, never another cID.
$acceptedOrder=$pdo->query("SELECT ikResponse FROM IdempotencyKeys WHERE ikNamespace='paw:payout:accepted' AND ikOperationID=".$tronId)->fetchColumn();
$reuse=json_decode($acceptedOrder,true)['data']['order_id'];$another=pawOutCreate($cids['EUR'],'usdc_eth');
$reusedTransport=static function($method,$key,$arg)use($transport,$reuse){return $method==='create'?['order_id'=>$reuse]:$transport($method,$key,$arg);};
pawOutReject(static fn()=>PawPayout::dispatch($db,$another,$reusedTransport),'cross-currency provider order reuse');
financialAssert((int)financialValue("SELECT COUNT(*) FROM IdempotencyKeys WHERE ikNamespace='paw:payout:accepted' AND ikOperationID=".$another)===0 && opOperCancel(1,$another)==='payout_reconciliation_required','conflicting acknowledgment cannot complete/refund second operation');
// Current enable flags and saved wallet addresses cannot rewrite acknowledged work.
$retained=pawOutCreate($cids['EUR'],'usdc_eth');PawPayout::dispatch($db,$retained,$transport);
$pdo->prepare('UPDATE Currs SET cDisabled=1,cCASHOUTMode=0 WHERE cID=?')->execute([$cids['EUR']]);
financialAssert(PawPayout::reconcile($db,$retained,$transport)===['completed'=>true],'retained original provider contract completes after local mode disable');
$pdo->prepare('UPDATE Currs SET cDisabled=0,cCASHOUTMode=2 WHERE cID=?')->execute([$cids['EUR']]);
// Ordinary exchange is not a payout, including a sandbox-only PAW balance.
$pdo->prepare('UPDATE Currs SET cCASHOUTMode=0 WHERE cID=?')->execute([$cids['USD']]);$_currs=$db->fetchIDRows($db->select('Currs'),false,'cID');$_cfg['Bal_RateUSD']='1';$_cfg['Const_IntCurr']=0;
$db->insert('Wallets',['wcID'=>1,'wuID'=>1,'wBal'=>'100']);$pdo->exec('UPDATE Users SET uBal=uBal+100 WHERE uID=1');
financialAssert(is_int(opOperCreate(1,'EX',1,'10',['cid2'=>$cids['USD']])),'ordinary EX works without payout asset or mode');
// Sandbox is rejected before a permanent attempt or any provider send.
$pdo->prepare("UPDATE PaymentProviders SET ppSlot=NULL,ppState='retired' WHERE ppID=?")->execute([$pid]);
$sandboxPublic=str_repeat('8',32);
$sandboxPid=$db->insert('PaymentProviders',['ppPublicID'=>$sandboxPublic,'ppKind'=>'PAW','ppSlot'=>'PAW','ppEnvironment'=>'sandbox','ppState'=>'enabled','ppValidationState'=>'valid','ppCurrentKeyVersion'=>1,'ppCreatedAt'=>time(),'ppUpdatedAt'=>time()]);
$db->insert('PaymentProviderCredentials',['ppcProviderID'=>$sandboxPid,'ppcVersion'=>1,'ppcEnvironment'=>'sandbox','ppcEncryptedKey'=>SecretCipher::encrypt('sk_sandbox_synthetic',PawProviderRepository::context($sandboxPublic,1)),'ppcCreatedAt'=>time()]);
$sandboxCid=$db->insert('Currs',['cCID'=>'PAW','cCurrID'=>'USD','cAssetKey'=>'asset:USD','cProviderID'=>$sandboxPid,'cProviderSelected'=>1,'cName'=>'Sandbox refusal fixture','cNumDec'=>2,'cDisabled'=>0,'cCASHINMode'=>2,'cCASHOUTMode'=>2]);
$_currs=$db->fetchIDRows($db->select('Currs'),false,'cID');$before=financialFingerprint();
financialAssert(opOperCreate(1,'CASHOUT',$sandboxCid,'10',['payout_asset'=>'usdt_tron'])==='paw_payout_sandbox_unsupported' && financialFingerprint()===$before,'sandbox withdrawal refuses before reservation');
echo "PAW payout SQL: $assertions checks passed\n";
