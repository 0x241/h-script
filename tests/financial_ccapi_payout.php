<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/fixtures/financial_runtime.php';
use HScript\Finance\{CcapiPayout,WithdrawalDispatcher,CallbackValidationException,IdempotencyConflict,FinancialOperationException};
use HScript\Payment\Provider\{ProviderConnectionSchema,ProviderRepository,ProviderService};
use HScript\Payment\Routing\{PaymentRouteSchema,TokenCatalogSchema,TokenCatalog};
putenv('APP_DATA_KEY=synthetic-ccapi-out-data-key');
$_GS=['client_ip'=>'127.0.0.1','https'=>false,'domain'=>'fixture.invalid','root_dir'=>''];
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,$e::class.': '.$e->getMessage().' at '.basename($e->getFile()).':'.$e->getLine().PHP_EOL.$e->getTraceAsString().PHP_EOL);exit(1);});
function outFail(callable $fn,string $label):void{try{$fn();}catch(RuntimeException|CallbackValidationException){financialAssert(true,$label);return;}throw new LogicException('Accepted '.$label);}
function outRaw(int $id,array $change=[]):string{
 global $db;$original=CcapiPayout::original($db,$id);$p=$original['contract'];$a=$original['arguments'];
 $event=array_replace(['cryptocurrencyapi.net'=>'3','chain'=>$p['chain'],'currency'=>$p['route']['wire']??$p['asset'],'type'=>'out','date'=>(string)time(),
  'from'=>$a['from'],'to'=>$a['to'],'token'=>$a['token']??'','tokenContract'=>$p['route']['contract']??'','amount'=>$p['amount'],'fee'=>'0.000001',
  'txid'=>str_repeat('a',64),'pos'=>'0','confirmation'=>'2','label'=>(string)$id,'id'=>(string)(100000+$id)],$change);
 ksort($event,SORT_STRING);$event['sign']=sha1(implode(':',array_values($event)).':'.md5($original['config']['v3_key']));return json_encode($event,JSON_THROW_ON_ERROR);
}
function outReceive(int $id,string $raw):array{global $db;$p=CcapiPayout::original($db,$id)['contract'];return CcapiPayout::receive($db,$id,$raw,$p['public'],$p['key_version']);}
if(($argv[1]??'')==='worker'){
 $_cfg=['UI_NumDec'=>6,'Const_IntCurr'=>0,'Bal_RateBTC'=>'30000'];$_currs=$db->fetchIDRows($db->select('Currs'),false,'cID');$_onload=[];
 $id=(int)$argv[2];echo json_encode(outReceive($id,$argv[3]),JSON_THROW_ON_ERROR);exit;
}
financialReset();
// Older migrations are not replayed backwards over the fully installed schema.
if(!$pdo->query("SHOW COLUMNS FROM Currs LIKE 'cTokenRoute'")->fetch()){ProviderConnectionSchema::ensure($db);PaymentRouteSchema::ensure($db);TokenCatalogSchema::ensure($db);}
$repo=new ProviderRepository($db);$service=new ProviderService($db,static fn()=>[]);
$pid=$service->save(90,'CCAPI',['form_complete'=>'1','profile_id'=>'0','revision'=>'0','credentials'=>['v3_key'=>'synthetic-ccapi-out-secret'],'currencies'=>['ETH','TRX'],'enabled'=>'1']);
$native=array_column($repo->connections($pid),null,'cCurrID');$eth=(int)$native['ETH']['cID'];$trx=(int)$native['TRX']['cID'];
$source='0x'.str_repeat('1',40);$to='0x'.str_repeat('2',40);
$pdo->prepare('INSERT INTO ProviderNetworkWallets(pnProviderID,pnNetwork,pnForwardTo,pnPayoutFrom) VALUES(?,?,?,?)')->execute([$pid,'ethereum','',$source]);
$pdo->prepare('INSERT INTO ProviderNetworkWallets(pnProviderID,pnNetwork,pnForwardTo,pnPayoutFrom) VALUES(?,?,?,?)')->execute([$pid,'tron','','T'.str_repeat('1',33)]);
foreach([$eth,$trx]as$cid)$pdo->exec("UPDATE Currs SET cDisabled=0,cCASHOUTMode=2,cNumDec=6 WHERE cID=$cid");
$route=TokenCatalog::route('ethereum','DAI','0x'.str_repeat('3',40));
$token=$db->insert('Currs',['cCID'=>'CCT','cCurrID'=>'DAI','cName'=>'Token fixture','cCurr'=>'DAI','cNumDec'=>6,'cProviderID'=>$pid,'cProviderKeyVersion'=>1,'cProviderSelected'=>1,
 'cAssetKey'=>TokenCatalog::assetKey($route),'cTokenRoute'=>json_encode($route,JSON_THROW_ON_ERROR),'cPaymentRoutes'=>json_encode([$route['code']]),'cDisabled'=>0,'cCASHOUTMode'=>2]);
$_currs=$db->fetchIDRows($db->select('Currs'),false,'cID');
function outCreate(int $cid,string $destination=''):int{
 global $db,$pdo,$to;$target=$destination?:$to;
 financialAssert(opChangeBalance(1,$cid,0,0,'10')===true,'reserve original gross');
 return $db->insert('Opers',['ouID'=>1,'ocID'=>$cid,'oOper'=>'CASHOUT','oState'=>2,'oSum'=>'10','oComis'=>'1','oCTS'=>timeToStamp(),'oParams2'=>arrayToStr(['acc'=>$target])]);
}
$calls=[];$wire=[];$responseChange=[];$timeout=false;$early=false;
$transport=static function(string $chain,string $method,array $config,array $args)use(&$calls,&$wire,&$responseChange,&$timeout,&$early,$route,$db):array{
 financialAssert(!$db->link->inTransaction(),'all provider I/O outside SQL transaction');$calls[]=$method;
 if($method==='tokens')return ['tokens'=>[$route]];
 if($method==='send'){
  $id=(int)$args['label'];$wire[$args['uniqID']]=$args;
  financialAssert($args['amount']==='9.000000','send exact original net');
  financialAssert(WithdrawalDispatcher::wasAttempted($db,$id),'attempt committed before send');
  if($early){try{outReceive($id,outRaw($id));throw new LogicException('Early finality');}catch(CallbackValidationException $e){financialAssert($e->reason==='ccapi_payout_ack_pending','early terminal saved without settlement');}}
  if($timeout)throw new RuntimeException('synthetic_timeout');
  return ['queue_id'=>(string)(100000+$id)];
 }
 if($method==='status'){
  $a=$wire[$args['uniqID']];return array_replace(['id'=>(string)(100000+(int)$a['label']),'from'=>$a['from'],'to'=>$a['to'],'token'=>$a['token']??'',
   'amount'=>$a['amount'],'fee'=>'0.000001','label'=>$a['label'],'state'=>'7','result'=>'','txid'=>str_repeat('a',64)],$responseChange);
 }
 throw new LogicException('Unexpected method');
};
$id=outCreate($eth);financialAssert(WithdrawalDispatcher::dispatch($db,$id,$transport)===['pending'=>true],'queue id does not complete payout');financialWallet(1,$eth,'0.000000','0.000000','10.000000');
financialAssert(opOperCancel(1,$id)==='payout_reconciliation_required','ordinary cancellation fenced');
financialAssert(opOperComplete(-1,$id,['date'=>time(),'batch'=>'fake','acc'=>$to],true)==='ccapi_payout_reconciliation_required','manual receipt cannot complete attempted payout');
$before=financialFingerprint();outFail(static fn()=>CcapiPayout::saveReceipt($db,$id,['date'=>timeToStamp(),'batch'=>'fake','acc'=>$to]),'receipt editor fenced');financialAssert(financialFingerprint()===$before,'rejected receipt edit changes no rows');
$raw=outRaw($id,['confirmation'=>'0']);financialAssert(outReceive($id,$raw)===['pending'=>true],'pending notification keeps reservation');
financialWallet(1,$eth,'0.000000','0.000000','10.000000');
foreach(['amount'=>'9.000001','id'=>'1','from'=>'0x'.str_repeat('4',40),'to'=>'0x'.str_repeat('5',40),'chain'=>'bsc','token'=>'DAI','tokenContract'=>$route['contract']]as$field=>$value){$before=financialFingerprint();outFail(static fn()=>outReceive($id,outRaw($id,[$field=>$value])),'signed mismatch '.$field);financialAssert(financialFingerprint()===$before,'mismatch preserves all monetary/registry rows');}
$raw=outRaw($id,['date'=>(string)(stampToTime(CcapiPayout::original($db,$id)['contract']['created'])+1)]);$db->rejectWalletUpdate=true;$before=financialFingerprint();outFail(static fn()=>outReceive($id,$raw),'wallet failure');financialAssert(financialFingerprint()===$before,'wallet failure rolls back permanent receipt and operation');$db->rejectWalletUpdate=false;
$pdo->exec("CREATE TRIGGER ccapi_out_late_failure BEFORE INSERT ON Hist FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic late history failure'");
$before=financialFingerprint();try{outFail(static fn()=>outReceive($id,$raw),'late history failure');financialAssert(financialFingerprint()===$before,'late SQL failure rolls back wallet, reservation, operation and permanent claims');}finally{$pdo->exec('DROP TRIGGER ccapi_out_late_failure');}
financialAssert(outReceive($id,$raw)===['completed'=>true],'signed confirmed payout completes');financialWallet(1,$eth,'0.000000');
$before=financialFingerprint();financialAssert(outReceive($id,$raw)===['completed'=>true] && financialFingerprint()===$before,'exact callback replay has no effect');
financialAssert(CcapiPayout::reconcile($db,$id,$transport)===['completed'=>true] && financialFingerprint()===$before,'signed out followed by status shortcut requires retained proof');
$before=financialFingerprint();outFail(static fn()=>outReceive($id,outRaw($id,['txid'=>str_repeat('b',64)])),'changed final transaction');financialAssert(financialFingerprint()===$before,'changed transaction cannot create receipt');
$query=$pdo->prepare("SELECT ikID,ikResponse FROM IdempotencyKeys WHERE ikNamespace='ccapi:payout:receipt' AND ikOperationID=?");$query->execute([$id]);$receipt=$query->fetch();$pdo->prepare('DELETE FROM IdempotencyKeys WHERE ikID=?')->execute([$receipt['ikID']]);outFail(static fn()=>CcapiPayout::reconcile($db,$id,$transport),'state3 without permanent receipt');
// Restore the exact test receipt, never reconstruct production monetary proof.
$pdo->prepare("INSERT INTO IdempotencyKeys(ikID,ikNamespace,ikKeyHash,ikFingerprint,ikClaimToken,ikOwnerID,ikOperationID,ikState,ikResponse,ikCreatedAt,ikExpiresAt) VALUES(?,?,?,?,?,0,?,'completed',?,1,0)")
 ->execute([$receipt['ikID'],'ccapi:payout:receipt',hash('sha256',"0\0".$id),HScript\Finance\IdempotencyRequest::fingerprint(['operation'=>$id,'queue'=>(string)(100000+$id),'txid'=>str_repeat('a',64)]),str_repeat('a',32),$id,$receipt['ikResponse']]);
// Completion must retain the exact paid row and durable receipt shape.
$savedBatch=financialValue('SELECT oBatch FROM Opers WHERE oID='.$id);
$pdo->prepare('UPDATE Opers SET oBatch=? WHERE oID=?')->execute(['tampered',$id]);outFail(static fn()=>CcapiPayout::reconcile($db,$id,$transport),'state3 batch mismatch');$pdo->prepare('UPDATE Opers SET oBatch=? WHERE oID=?')->execute([$savedBatch,$id]);
$second=outCreate($eth);CcapiPayout::dispatch($db,$second,$transport);$sent=count(array_filter($calls,static fn($m)=>$m==='send'));
financialAssert(CcapiPayout::reconcile($db,$second,$transport)===['completed'=>true],'native status6/7 completes exact original intent');
$before=financialFingerprint();financialAssert(outReceive($second,outRaw($second,['pos'=>'3']))===['completed'=>true] && financialFingerprint()===$before,'late signed out after native status has no false conflict');
financialAssert(count(array_filter($calls,static fn($m)=>$m==='send'))===$sent,'reconcile never sends');
$third=outCreate($token);CcapiPayout::dispatch($db,$third,$transport);financialAssert(CcapiPayout::reconcile($db,$third,$transport)===['pending'=>true],'token status has insufficient contract authority');financialWallet(1,$token,'0.000000','0.000000','10.000000');
outFail(static fn()=>outReceive($third,outRaw($third,['tokenContract'=>'0x'.str_repeat('4',40)])),'wrong token contract');financialAssert(outReceive($third,outRaw($third))===['completed'=>true],'exact signed token out completes');financialWallet(1,$token,'0.000000');
$timeout=true;$early=true;$fourth=outCreate($token);try{CcapiPayout::dispatch($db,$fourth,$transport);throw new LogicException('Timeout accepted');}catch(RuntimeException $e){if($e instanceof LogicException)throw $e;financialAssert(true,'lost send response');}$timeout=$early=false;
$sent=count(array_filter($calls,static fn($m)=>$m==='send'));financialAssert(CcapiPayout::dispatch($db,$fourth,$transport)===['completed'=>true],'status recovers ack then exact encrypted early token event');financialAssert(count(array_filter($calls,static fn($m)=>$m==='send'))===$sent,'timeout/restart recovery does not resend');financialWallet(1,$token,'0.000000');
$invalid=outCreate($eth);$domain=$_GS['domain'];$_GS['domain']=str_repeat('a',60).'.'.str_repeat('b',60).'.'.str_repeat('c',60).'.fixture.invalid';$before=financialFingerprint();$count=count($calls);
outFail(static fn()=>CcapiPayout::dispatch($db,$invalid,$transport),'overlong callback rejected before attempt');financialAssert(financialFingerprint()===$before && count($calls)===$count,'invalid transport arguments create no attempt and no I/O');$_GS['domain']=$domain;
$pdo->prepare('UPDATE ProviderNetworkWallets SET pnPayoutFrom=? WHERE pnProviderID=? AND pnNetwork=?')->execute(['',$pid,'ethereum']);
outFail(static fn()=>CcapiPayout::dispatch($db,$invalid,$transport),'source must be explicit');financialAssert(!WithdrawalDispatcher::wasAttempted($db,$invalid),'missing source never creates external attempt');
$pdo->prepare('UPDATE ProviderNetworkWallets SET pnPayoutFrom=? WHERE pnProviderID=? AND pnNetwork=?')->execute([$source,$pid,'ethereum']);
$pdo->prepare('UPDATE Opers SET oParams2=? WHERE oID=?')->execute([arrayToStr(['acc'=>$to,'tag'=>'123']),$invalid]);outFail(static fn()=>CcapiPayout::dispatch($db,$invalid,$transport),'destination tag unsupported before I/O');financialAssert(!WithdrawalDispatcher::wasAttempted($db,$invalid),'tag rejection creates no attempt');
financialAssert(opOperCancel(1,$invalid)===true && opChangeBalance(1,$eth,'-10',0,0)===true,'unused reservation can be cancelled normally');financialWallet(1,$eth,'0.000000');
$mismatch=outCreate($token);$count=count($calls);
foreach(['cCurrID'=>'USDT','cAssetKey'=>'asset:DAI'] as$field=>$value){$old=financialValue('SELECT '.$field.' FROM Currs WHERE cID='.$token);$pdo->prepare('UPDATE Currs SET '.$field.'=? WHERE cID=?')->execute([$value,$token]);$before=financialFingerprint();outFail(static fn()=>CcapiPayout::dispatch($db,$mismatch,$transport),'original token tuple mismatch '.$field);financialAssert(count($calls)===$count && financialFingerprint()===$before,'mismatched token metadata sends nothing and leaves no attempt');$pdo->prepare('UPDATE Currs SET '.$field.'=? WHERE cID=?')->execute([$old,$token]);}
financialAssert(opOperCancel(1,$mismatch)===true && opChangeBalance(1,$token,'-10',0,0)===true,'mismatched asset remains cancelable');
$invalidToken=outCreate($token);$wrongCatalog=static fn()=>['tokens'=>[array_replace($route,['contract'=>'0x'.str_repeat('9',40)])]];
outFail(static fn()=>CcapiPayout::dispatch($db,$invalidToken,$wrongCatalog),'fresh catalog must match original token contract');financialAssert(!WithdrawalDispatcher::wasAttempted($db,$invalidToken),'mapping mismatch occurs before any send attempt');
financialAssert(opOperCancel(1,$invalidToken)===true && opChangeBalance(1,$token,'-10',0,0)===true,'unused token reservation remains cancelable');financialWallet(1,$token,'0.000000');
$lost=outCreate($eth);CcapiPayout::dispatch($db,$lost,$transport);$capsule=financialValue('SELECT oParams FROM Opers WHERE oID='.$lost);
$pdo->prepare("DELETE FROM IdempotencyKeys WHERE ikNamespace='payout:attempt' AND ikOperationID=?")->execute([$lost]);$count=count($calls);$before=financialFingerprint();
outFail(static fn()=>CcapiPayout::dispatch($db,$lost,$transport),'partial restore cannot recreate send');financialAssert(count($calls)===$count && financialFingerprint()===$before && financialValue('SELECT oParams FROM Opers WHERE oID='.$lost)===$capsule,'missing attempt preserves capsule and never contacts provider');
financialAssert(opOperCancel(1,$lost)==='payout_reconciliation_required','orphaned attempt still fences refund from surviving committed capsule');
financialAssert(opOperComplete(-1,$lost,['date'=>time(),'batch'=>'fake','acc'=>$to],true)==='ccapi_payout_reconciliation_required','orphaned attempt still fences manual completion');
$pdo->prepare('UPDATE Opers SET oParams=? WHERE oID=?')->execute(['',$lost]);
financialAssert(WithdrawalDispatcher::wasAttempted($db,$lost) && opOperCancel(1,$lost)==='payout_reconciliation_required','permanent footprint alone fences refund after partial restore');
financialAssert(opOperComplete(-1,$lost,['date'=>time(),'batch'=>'fake','acc'=>$to],true)==='ccapi_payout_reconciliation_required','permanent footprint alone fences manual completion');
$pdo->prepare('UPDATE Opers SET oParams=? WHERE oID=?')->execute([$capsule,$lost]);
// An unresolved issued payout remains reserved for explicit reconciliation.
financialWallet(1,$eth,'0.000000','0.000000','10.000000');
$race=outCreate($eth);CcapiPayout::dispatch($db,$race,$transport);$raw=outRaw($race);$children=[];
for($i=0;$i<6;$i++){$pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'worker',(string)$race,$raw],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);$children[]=[$process,$pipes];}
foreach($children as[$process,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);financialAssert(proc_close($process)===0,'concurrent receiver '.$err);financialAssert(json_decode($out,true,8,JSON_THROW_ON_ERROR)===['completed'=>true],'concurrent response');}financialWallet(1,$eth,'0.000000','0.000000','10.000000');
$changed=outCreate($eth);CcapiPayout::dispatch($db,$changed,$transport);
$pdo->exec("UPDATE ProviderNetworkWallets SET pnPayoutFrom='0x".str_repeat('9',40)."' WHERE pnProviderID=$pid AND pnNetwork='ethereum'");
$pdo->exec("UPDATE Currs SET cDisabled=1,cCASHOUTMode=0 WHERE cID=$eth");$pdo->exec("UPDATE PaymentProviders SET ppState='retired',ppSlot=NULL WHERE ppID=$pid");
financialAssert(outReceive($changed,outRaw($changed))===['completed'=>true],'issued payout retains original source/key after disable and retirement');financialWallet(1,$eth,'0.000000','0.000000','10.000000');
financialAssert(!str_contains((string)financialValue("SELECT GROUP_CONCAT(ikResponse) FROM IdempotencyKeys"),'synthetic-ccapi-out-secret'),'no plaintext key in durable snapshots');
echo "CCAPI outgoing SQL: $assertions assertions passed\n";
