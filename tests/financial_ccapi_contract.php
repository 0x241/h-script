<?php

declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require __DIR__.'/fixtures/financial_runtime.php';
require __DIR__.'/fixtures/currency_archive_checks.php';
putenv('APP_DATA_KEY=synthetic-ccapi-contract-key');
use HScript\Finance\CallbackValidationException;
use HScript\Finance\DepositCheckout;
use HScript\Finance\GatewaySettlement;
use HScript\Finance\IdempotencyConflict;
use HScript\Payment\Gateways\CryptoCurrencyApi;
use HScript\Payment\PaymentManager;
use HScript\Security\SecretCipher;

final class FinancialNativeGateway extends CryptoCurrencyApi
{
    public static int $calls=0;
    protected function apiRequest(string $chain,string $method,array $config,array $arguments=[]): array {
        global $pdo;
        self::$calls++;
        financialAssert(!$pdo->inTransaction(),'Native give runs after committed invoice intent');
        financialAssert($method==='give' && $arguments['uniqID']===HScript\Payment\CcapiV3Client::requestId($arguments['statusURL'],$arguments['label']),'Native give one operation/uniqID');
        financialAssert((int)financialValue("SELECT COUNT(*) FROM IdempotencyKeys WHERE ikNamespace='invoice:attempt' AND ikState='completed' AND ikOperationID=".$arguments['label'])===1,'Original financial intent committed before give');
        return ['address'=>$GLOBALS['ccapiGiveAddress']??ccapiTestAddress($chain)];
    }
}
function ccapiTestAddress(string $chain): string { return match($chain) {'ripple'=>'rHb9CJAWyB4rj91VRWn96DkukG4bwdtyTh','ton'=>'0:'.str_repeat('a',64),default=>'bc1q'.str_repeat('q',38)}; }
function ccapiOperation(int $id): array { global $db; return $db->fetch1Row($db->query('SELECT Opers.*,Currs.* FROM Opers JOIN Currs ON cID=ocID WHERE oID=?d',[$id])); }
function ccapiSetup(string $gateway='CCAB',array $fees=['cCASHINComis'=>10]): int {
    global $db,$_currs;
    financialReset(); FinancialNativeGateway::$calls=0;unset($GLOBALS['ccapiGiveAddress']);
    $salt=time(); $asset=CryptoCurrencyApi::NATIVE[$gateway][1];
    $db->update('Currs',array_replace(['cCID'=>$gateway,'cCurrID'=>$asset,'cNumDec'=>6,'cCASHINMode'=>2,'cMTS'=>timeToStamp($salt),
        'cParamsSCI'=>encodeArrayToStr(['protocol'=>'3','v3_key'=>'synthetic-v3-key'],'2'.$gateway.$salt,2)],$fees),'','cID=?d',[2]);
    $_currs=$db->fetchIDRows($db->select('Currs'),false,'cID');
    return opOperCreate(1,'CASHIN',2,100);
}
function ccapiCreate(array $operation): array {
    global $db;
    opDecodeCurrParams($operation,$pay,$sci,$api);
    return (new FinancialNativeGateway($operation['cCID'],[$operation['cCID'],$operation['cCurrID']],[],$db))->processDeposit([
        'sci'=>$sci,'tag'=>$operation['oID'],'sum'=>$operation['oSum'],'callback_scale'=>(int)$operation['cNumDec'],
        'url_callback'=>'https://fixture.invalid/status','url_ok'=>'https://fixture.invalid/operation?id='.$operation['oID'].'&check']);
}
function ccapiRaw(int $id,array $changes=[]): string {
    $operation=ccapiOperation($id); [$chain,$asset]=CryptoCurrencyApi::NATIVE[$operation['cCID']];
    opDecodeCurrParams($operation,$pay,$sci,$api);
    $payload=array_replace(['cryptocurrencyapi.net'=>3,'chain'=>$chain,'currency'=>$asset,'type'=>'in','date'=>stampToTime($operation['oCTS']),
        'from'=>null,'to'=>ccapiTestAddress($chain),'token'=>null,'tokenContract'=>null,'amount'=>'100.000000','fee'=>'0.00000001',
        'txid'=>str_pad(dechex($id),64,'a',STR_PAD_LEFT),'pos'=>0,'confirmation'=>2,'label'=>(string)$id],$changes);
    unset($payload['sign']); ksort($payload,SORT_STRING);
    $payload['sign']=sha1(implode(':',array_map(static fn($v)=>(string)$v,array_values($payload))).':'.md5($sci['v3_key']));
    return json_encode($payload,JSON_THROW_ON_ERROR);
}
function ccapiVerified(int $id,array $changes=[]): array {
    global $db;
    $operation=ccapiOperation($id); opDecodeCurrParams($operation,$pay,$sci,$api);
    return (new PaymentManager($db))->handleCallback($operation['cCID'],['_raw_body'=>ccapiRaw($id,$changes)],$sci);
}
function ccapiSettle(int $id,array $changes=[]): mixed { global $db; return GatewaySettlement::settle($db,ccapiOperation($id)['cCID'],2,ccapiVerified($id,$changes)); }
function ccapiState(): string {
    global $pdo;
    return hash('sha256',financialFingerprint().serialize($pdo->query('SELECT * FROM IdempotencyKeys ORDER BY ikID')->fetchAll()).serialize($pdo->query('SELECT * FROM Jobs ORDER BY jID')->fetchAll()));
}
function ccapiReject(callable $work,string $reason): void {
    $before=ccapiState();
    try { $work(); throw new LogicException('Native contract accepted '.$reason); }
    catch (CallbackValidationException|IdempotencyConflict $error) {
        $actual=$error instanceof CallbackValidationException?$error->reason:$error->getMessage();
        financialAssert($actual===$reason,'Fixed native rejection '.$reason.', got '.$actual);
    }
    financialAssert(ccapiState()===$before,'Native refusal creates no money/claim/job/history');
}
function ccapiSaveForm(int $id,array $form): void {
    global $db,$_cfg;
    $params=strToArray(ccapiOperation($id)['oParams']);
    $params['checkout']=SecretCipher::encrypt(json_encode($form,JSON_THROW_ON_ERROR),hash('sha256','checkout:'.$id.':1:'.($_cfg['Const_Salt'] ?? '')));
    $db->update('Opers',['oParams'=>arrayToStr($params)],'','oID=?d',[$id]);
}
function ccapiConfig(int $id,array $changes): void {
    global $db;
    $operation=ccapiOperation($id); opDecodeCurrParams($operation,$pay,$sci,$api);
    $db->update('Currs',['cParamsSCI'=>encodeArrayToStr(array_replace($sci,$changes),'2'.$operation['cCID'].stampToTime($operation['cMTS']),2)],'','cID=?d',[2]);
}
if (($argv[1] ?? '')==='worker') {
    $_currs=$db->fetchIDRows($db->select('Currs'),false,'cID'); $_cfg=['UI_NumDec'=>2,'Const_IntCurr'=>0]; $_GS['client_ip']='127.0.0.1'; $_onload=[];
    echo json_encode(ccapiSettle((int)$argv[2])->data,JSON_THROW_ON_ERROR); exit;
}
// Canonical receiver equivalence must survive the entire identity/settlement
// path, not just standalone address comparison or contract validation.
$ton=json_decode(file_get_contents(__DIR__.'/fixtures/ccapi_address_vectors.json'),true,16,JSON_THROW_ON_ERROR);
foreach ([['CCAO',$ton['ton_raw'],$ton['ton_bounceable']],['CCAO',$ton['ton_nonbounceable'],$ton['ton_raw']],
    ['CCAH','bitcoincash:q'.str_repeat('q',41),'q'.str_repeat('q',41)],['CCAH','q'.str_repeat('q',41),'bitcoincash:q'.str_repeat('q',41)]] as [$gateway,$give,$callback]) {
    $id=ccapiSetup($gateway);$GLOBALS['ccapiGiveAddress']=$give;DepositCheckout::prepare($db,1,$id,'ccapiCreate');
    financialAssert(ccapiSettle($id,['to'=>$callback,'confirmation'=>0])->data===['settled'=>false],'Canonical receiver pending accepted');
    financialAssert(ccapiSettle($id,['to'=>$callback,'confirmation'=>1])->replayed,'Canonical receiver pending retry');
    financialAssert(ccapiSettle($id,['to'=>$callback])->data===['settled'=>true],'Canonical receiver first final settlement');
    financialWallet(1,2,'90.000000');$after=ccapiState();
    financialAssert(ccapiSettle($id,['to'=>$callback])->replayed && ccapiState()===$after,'Canonical receiver completed replay is immutable');
}
// All twelve native profiles retain their agreed local fee and original receiver.
foreach (CryptoCurrencyApi::NATIVE as $gateway=>[$chain,$asset]) {
    $id=ccapiSetup($gateway);
    financialAssert(DepositCheckout::nativeStored($db,1,$id)===null,'No readonly invoice before pay');
    $public=DepositCheckout::prepare($db,1,$id,'ccapiCreate');
    financialAssert(array_keys($public)===['url','formkind','payment_address','payment_asset','payment_amount'],'Public invoice contains only intended form fields');
    financialAssert($public['payment_asset']===$asset && $public['payment_amount']==='100.000000','Native invoice exact original gross/asset');
    $db->update('Currs',['cCASHINComis'=>20,'cCASHINComisMin'=>50,'cCASHINComisMax'=>60,'cCASHINMin'=>200,'cCASHINMax'=>50,'cCASHINMode'=>0],'','cID=?d',[2]);
    $_currs[2]['cNumDec']=2; $_currs[2]['cCASHINComis']=99;
    financialAssert(DepositCheckout::nativeStored($db,1,$id)===$public,'Readonly reload preserves invoice after policy/range changes');
    financialAssert(DepositCheckout::prepare($db,1,$id,static fn()=>throw new LogicException('Invoice resend'))===$public,'Retry preserves original native invoice');
    financialAssert(ccapiSettle($id,['confirmation'=>0])->data===['settled'=>false],'Native zero confirmations pending');
    financialAssert(ccapiSettle($id,['confirmation'=>1])->replayed,'Native first confirmation pending replay');
    financialAssert((int)financialValue("SELECT COUNT(*) FROM Hist WHERE hOper='BAL'")===0,'Pending does not credit');
    financialAssert(ccapiSettle($id)->data===['settled'=>true],'Native final settles');
    financialWallet(1,2,'90.000000');
    financialAssert(financialValue("SELECT oComis FROM Opers WHERE oID=$id")==='10.000000','Network fee/current policy does not replace local original fee');
    financialAssert(financialValue("SELECT oBatch FROM Opers WHERE oID=$id")===HScript\Finance\CcapiV3Contract::batchAlias(ccapiVerified($id)['batch']),'Legacy bounded alias derives from full txid and position zero');
    $before=ccapiState(); financialAssert(ccapiSettle($id)->replayed && ccapiState()===$before,'Native completed exact replay unchanged');
    financialArchivedReplay(ccapiOperation($id)['cCID'],2,ccapiVerified($id));
    ccapiReject(fn()=>ccapiSettle($id,['pos'=>1]),'callback_invoice_identity_mismatch');
    financialAssert(FinancialNativeGateway::$calls===1,'One give call, including readonly reopen/pending/replay');
    $receipt=strToArray(financialValue("SELECT oParams2 FROM Opers WHERE oID=$id"));
    financialAssert(!isset($receipt['sum']) && !array_filter(array_keys($receipt),static fn($field)=>str_starts_with($field,'ccapi_')),'Legacy receipt excludes sum/private native evidence');
}
// Original configured minimum/maximum fee remains agreed after policy edits.
foreach ([['cCASHINComis'=>1,'cCASHINComisMin'=>7,'net'=>'93.000000'],['cCASHINComis'=>10,'cCASHINComisMax'=>3,'net'=>'97.000000'],['cCASHINComis'=>0,'net'=>'100.000000']] as $fees) {
    $net=$fees['net']; unset($fees['net']); $id=ccapiSetup('CCAB',$fees); DepositCheckout::prepare($db,1,$id,'ccapiCreate');
    $db->update('Currs',['cCASHINComis'=>99],'','cID=?d',[2]); ccapiSettle($id); financialWallet(1,2,$net);
}
// Unsafe original-operation and signed receiver/time/chain drift fails closed.
foreach (['oSum'=>'99','oComis'=>'20','cNumDec'=>5] as $field=>$value) {
    $id=ccapiSetup(); DepositCheckout::prepare($db,1,$id,'ccapiCreate');
    $db->update(str_starts_with($field,'c')?'Currs':'Opers',[$field=>$value],'',str_starts_with($field,'c')?'cID=?d':'oID=?d',[str_starts_with($field,'c')?2:$id]);
    ccapiReject(fn()=>ccapiSettle($id),$field==='oSum'?'callback_amount_mismatch':($field==='cNumDec'?'callback_precision_configuration_changed':'callback_inbound_intent_mismatch'));
}
$id=ccapiSetup(); DepositCheckout::prepare($db,1,$id,'ccapiCreate');
foreach ([['to'=>str_repeat('a',40),'reason'=>'callback_destination_mismatch'],['date'=>time()+1000,'reason'=>'callback_time_outside_window'],['date'=>time()-1000,'reason'=>'callback_time_outside_window']] as $mutation) {
    $reason=$mutation['reason']; unset($mutation['reason']); ccapiReject(fn()=>ccapiSettle($id,$mutation),$reason);
}
// Pending binds fee/date/receiver/pos: no later signed event may reinterpret it.
ccapiSettle($id,['confirmation'=>0]);
ccapiReject(fn()=>ccapiSettle($id,['fee'=>'0.01']),'callback_ccapi_proof_mismatch');
ccapiReject(fn()=>ccapiSettle($id,['date'=>stampToTime(ccapiOperation($id)['oCTS'])+1]),'callback_ccapi_proof_mismatch');
ccapiReject(fn()=>ccapiSettle($id,['to'=>str_repeat('a',40)]),'callback_ccapi_proof_mismatch');
ccapiReject(fn()=>ccapiSettle($id,['pos'=>1]),'callback_invoice_identity_mismatch');
$db->update('Opers',['oComis'=>20],'','oID=?d',[$id]);
ccapiReject(fn()=>ccapiSettle($id),'callback_inbound_intent_mismatch');
$db->update('Opers',['oComis'=>10],'','oID=?d',[$id]); ccapiSettle($id); financialWallet(1,2,'90.000000');
// Key rotation is never backfilled, including previously completed replies.
ccapiConfig($id,['v3_key'=>'rotated-v3-key']);
ccapiReject(fn()=>ccapiSettle($id),'callback_ccapi_proof_mismatch');
ccapiConfig($id,['v3_key'=>'synthetic-v3-key']); financialAssert(ccapiSettle($id)->replayed,'Restored exact native key accepts replay');
// A key/protocol change after provider I/O refuses publication and cannot resend.
foreach ([['v3_key'=>'rotated-v3-key'],['protocol'=>'2']] as $change) {
    $id=ccapiSetup();
    try { DepositCheckout::prepare($db,1,$id,static function(array $operation) use($id,$change):array { $form=ccapiCreate($operation); ccapiConfig($id,$change); return $form; }); throw new LogicException('Changed post-I/O config accepted'); }
    catch (CallbackValidationException $error) { financialAssert($error->reason==='callback_merchant_configuration_changed','Post-I/O SCI must match original key/protocol'); }
    financialAssert((int)financialValue("SELECT COUNT(*) FROM IdempotencyKeys WHERE ikNamespace='invoice:result'")===0 && DepositCheckout::storedForm(ccapiOperation($id))===null,'Divergent post-I/O checkout not stored');
    try { DepositCheckout::prepare($db,1,$id,'ccapiCreate'); throw new LogicException('Unknown give resent'); }
    catch (RuntimeException $error) { financialAssert($error->getMessage()==='invoice_reconciliation_required','Ambiguous give requires reconciliation'); }
    financialAssert(FinancialNativeGateway::$calls===1,'No post-I/O give resend');
}
// Readonly/retry cannot expose public fields detached from original private proof.
$id=ccapiSetup(); DepositCheckout::prepare($db,1,$id,'ccapiCreate'); $form=DepositCheckout::storedForm(ccapiOperation($id));
foreach (['payment_address'=>str_repeat('a',40),'payment_amount'=>'99.000000','payment_asset'=>'LTC','formkind'=>'form','ccapi_address'=>str_repeat('a',40),'ccapi_profile'=>'legacy'] as $field=>$value) {
    $changed=$form; $changed[$field]=$value; ccapiSaveForm($id,$changed);
    $reason=$field==='ccapi_profile'?'callback_invoice_unavailable':'callback_invoice_identity_mismatch';
    ccapiReject(fn()=>DepositCheckout::nativeStored($db,1,$id),$reason);
    ccapiReject(fn()=>DepositCheckout::prepare($db,1,$id,static fn()=>throw new LogicException('Invoice resend')),$reason);
    ccapiReject(fn()=>ccapiSettle($id),$reason);
}
ccapiSaveForm($id,$form); ccapiSettle($id);
// Old/malformed identities cannot acknowledge a cached completed native effect.
$row=$pdo->query("SELECT ikID,ikResponse FROM IdempotencyKeys WHERE ikNamespace='gateway:ccab:currency:2:identity'")->fetch();
$response=json_decode($row['ikResponse'],true,32,JSON_THROW_ON_ERROR);
foreach (['ccapi_profile','ccapi_invoice_hash','ccapi_batch_hash','ccapi_event_hash','ccapi_key_hash','ccapi_position','ccapi_network_fee'] as $field) {
    $changed=$response; unset($changed['data']['contract'][$field]);
    $db->update('IdempotencyKeys',['ikResponse'=>json_encode($changed,JSON_THROW_ON_ERROR)],'','ikID=?d',[$row['ikID']]);
    ccapiReject(fn()=>ccapiSettle($id),in_array($field,['ccapi_profile','ccapi_invoice_hash','ccapi_batch_hash'],true)?'callback_ccapi_binding_missing':'callback_ccapi_proof_mismatch');
}
$db->update('IdempotencyKeys',['ikResponse'=>$row['ikResponse']],'','ikID=?d',[$row['ikID']]);
$legacy=$form; foreach(array_keys($legacy) as $field) if(str_starts_with($field,'ccapi_')) unset($legacy[$field]); ccapiSaveForm($id,$legacy);
ccapiReject(fn()=>ccapiSettle($id),'callback_invoice_unavailable');
ccapiSaveForm($id,$form); $db->delete('IdempotencyKeys',"ikNamespace='invoice:attempt'");
ccapiReject(fn()=>ccapiSettle($id),'callback_invoice_intent_missing');
// Configured no-checkout and readable legacy URL-only checkout cannot be upgraded.
$id=ccapiSetup(); ccapiReject(fn()=>ccapiSettle($id),'callback_invoice_unavailable');
ccapiSaveForm($id,['url'=>'https://fixture.invalid/old-address']);
ccapiReject(fn()=>ccapiSettle($id),'callback_invoice_intent_missing');
ccapiReject(fn()=>DepositCheckout::prepare($db,1,$id,'ccapiCreate'),'callback_invoice_intent_missing');
// Alias collisions cannot supply full event authority, even before stage work.
$id=ccapiSetup(); DepositCheckout::prepare($db,1,$id,'ccapiCreate'); ccapiSettle($id,['confirmation'=>0]);
$changedTx=str_repeat('b',64); $changedBatch=$changedTx.'#0';
$db->update('Opers',['oBatch'=>'?'.HScript\Finance\CcapiV3Contract::batchAlias($changedBatch)],'','oID=?d',[$id]);
ccapiReject(fn()=>ccapiSettle($id,['txid'=>$changedTx]),'callback_invoice_identity_mismatch');
$db->update('Opers',['oState'=>0,'oBatch'=>''],'','oID=?d',[$id]);
ccapiReject(fn()=>ccapiSettle($id,['txid'=>$changedTx]),'callback_invoice_identity_mismatch');
$db->update('Opers',['oState'=>2,'oBatch'=>'?'.HScript\Finance\CcapiV3Contract::batchAlias(ccapiVerified($id)['batch'])],'','oID=?d',[$id]);
ccapiSettle($id); financialWallet(1,2,'90.000000');
// Another completed synthetic operation occupies the exact alias. The full
// registry identity remains new; the legacy uniqueness check refuses credit.
$id=ccapiSetup(); DepositCheckout::prepare($db,1,$id,'ccapiCreate');
$alias=HScript\Finance\CcapiV3Contract::batchAlias(ccapiVerified($id)['batch']);
$collision=$db->insert('Opers',['ouID'=>2,'oOper'=>'CASHIN','ocID'=>2,'oSum'=>100,'oState'=>3,'oBatch'=>$alias]);
try { ccapiSettle($id); throw new LogicException('Collision credited'); }
catch (HScript\Finance\FinancialOperationException $error) { financialAssert($error->getMessage()==='batch_exists','Alias collision refuses legacy credit'); }
financialAssert((int)financialValue("SELECT COUNT(*) FROM Hist WHERE hOper='BAL'")===0 && (int)financialValue("SELECT COUNT(*) FROM IdempotencyKeys WHERE ikNamespace='gateway:ccab:currency:2:settled'")===0,'Colliding alias creates no money/settled claim');
financialAssert((int)financialValue("SELECT oState FROM Opers WHERE oID=$id")===0,'Colliding alias rolls back invoice stage');
$db->delete('Opers','oID=?d',[$collision]); ccapiSettle($id); financialWallet(1,2,'90.000000');
// Actual SQL rollback/retry and concurrency preserve one financial effect.
$id=ccapiSetup(); DepositCheckout::prepare($db,1,$id,'ccapiCreate');
$db->rejectWalletUpdate=true; $before=ccapiState();
try { ccapiSettle($id); throw new LogicException('Rejected wallet write accepted'); } catch (HScript\Finance\FinancialOperationException) { financialAssert((int)financialValue("SELECT oState FROM Opers WHERE oID=$id")!==3,'Wallet write failure rolls back effect'); }
$db->rejectWalletUpdate=false;
$processes=[];
for($i=0;$i<12;$i++) { $process=proc_open([PHP_BINARY,__FILE__,'worker',(string)$id],[1=>['pipe','w'],2=>['pipe','w']],$pipes); if(!is_resource($process)) throw new RuntimeException('Native worker unavailable'); $processes[]=[$process,$pipes]; }
foreach($processes as [$process,$pipes]) { $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); financialAssert(proc_close($process)===0,'Native concurrent worker: '.$err.$out); financialAssert(json_decode($out,true,32,JSON_THROW_ON_ERROR)===['settled'=>true],'Concurrent native stable response'); }
financialWallet(1,2,'90.000000');
financialAssert((int)financialValue("SELECT COUNT(*) FROM Hist WHERE hOper='BAL'")===1,'Native twelve workers one credit');
financialAssert(ccapiSettle($id)->replayed,'Native result persists across fresh worker processes');
// Distinct invoice events also share wallet/registry locks; none may starve.
$first=ccapiSetup(); $ids=[$first];
for($i=1;$i<12;$i++) $ids[]=opOperCreate(1,'CASHIN',2,100);
foreach($ids as $id) DepositCheckout::prepare($db,1,$id,'ccapiCreate');
$plan=$pdo->query("EXPLAIN SELECT ikResponse FROM IdempotencyKeys FORCE INDEX (ix_idempotency_operation) WHERE ikNamespace='gateway:ccab:currency:2:identity' AND ikOperationID=".$first." AND ikState='completed' FOR UPDATE")->fetch();
financialAssert($plan['key']==='ix_idempotency_operation','Narrow operation index selected for original-event locks');
$processes=[];
foreach($ids as $id) { $process=proc_open([PHP_BINARY,__FILE__,'worker',(string)$id],[1=>['pipe','w'],2=>['pipe','w']],$pipes); if(!is_resource($process)) throw new RuntimeException('Native distinct worker unavailable'); $processes[]=[$process,$pipes]; }
foreach($processes as [$process,$pipes]) { $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); financialAssert(proc_close($process)===0,'Distinct native concurrent worker: '.$err.$out); financialAssert(json_decode($out,true,32,JSON_THROW_ON_ERROR)===['settled'=>true],'Distinct native stable response'); }
financialWallet(1,2,'1080.000000');
financialAssert((int)financialValue("SELECT COUNT(*) FROM Hist WHERE hOper='BAL'")===12,'Twelve distinct invoices commit twelve agreed net credits');
foreach($ids as $id) financialAssert(ccapiSettle($id)->replayed,'Distinct native durable replay');
echo 'Financial CryptoCurrencyAPI v3 tests passed ('.$assertions." checks, 12 same-event and 12 distinct-invoice workers).\n";
