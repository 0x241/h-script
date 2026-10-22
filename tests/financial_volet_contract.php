<?php

declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require __DIR__.'/fixtures/financial_runtime.php';
putenv('APP_DATA_KEY=synthetic-volet-financial-key');

use HScript\Finance\CallbackValidationException;
use HScript\Finance\DepositCheckout;
use HScript\Finance\GatewaySettlement;
use HScript\Finance\IdempotencyConflict;
use HScript\Finance\CallbackContract;
use HScript\Finance\IdempotencyStore;
use HScript\Finance\IdempotencyResult;
use HScript\Finance\IdempotencyRequest;
use HScript\Finance\VoletContract;
use HScript\Payment\Gateways\AdvCash;
use HScript\Payment\PaymentManager;
use HScript\Security\SecretCipher;

final class FinancialVolet extends AdvCash
{
    protected function merchantRequest(array $config,string $method,array $arguments): array
    {
        $GLOBALS['voletFinancialCalls'][]=[$method,$arguments];
        return $GLOBALS[$method==='findPaymentByOrderId' ? 'voletFinancialOrder' : 'voletFinancialTransaction'];
    }
}
function voletFinancialState(): string
{
    global $pdo;
    return hash('sha256',financialFingerprint().serialize($pdo->query('SELECT * FROM IdempotencyKeys ORDER BY ikID')->fetchAll())
        .serialize($pdo->query('SELECT * FROM Jobs ORDER BY jID')->fetchAll()));
}
function voletFinancialReject(callable $call,string $reason): void
{
    $before=voletFinancialState();
    try {
        $result=$call();
        financialAssert(is_array($result) && !$result['correct'] && $result['reason']===$reason,'fixed Volet rejection '.$reason);
    } catch (CallbackValidationException $error) { financialAssert($error->reason===$reason,'fixed Volet validation '.$reason.', got '.$error->reason); }
    catch (IdempotencyConflict) { financialAssert($reason==='conflict','expected durable Volet identity conflict'); }
    catch (RuntimeException $error) { financialAssert($error->getMessage()===$reason,'fixed Volet runtime '.$reason); }
    financialAssert(voletFinancialState()===$before,'Rejected Volet input changes no money/operation/history/registry/job');
}
function voletFinancialSigned(array $fields): string
{
    $keys=['ac_transfer','ac_start_date','ac_sci_name','ac_src_wallet','ac_dest_wallet','ac_order_id','ac_amount','ac_merchant_currency'];
    $fields['ac_hash']=hash('sha256',implode(':',array_map(static fn($key)=>$fields[$key],$keys)).':'.$GLOBALS['voletFinancialSci']['key']);
    return http_build_query($fields,'','&',PHP_QUERY_RFC3986);
}
function voletFinancialSetup(string $gateway='AC'): array
{
    global $db,$_currs;
    financialReset(); $GLOBALS['voletFinancialCalls']=[];
    $fixture=json_decode(file_get_contents(__DIR__.'/fixtures/volet_sci.json'),true,32,JSON_THROW_ON_ERROR);
    $GLOBALS['voletFinancialSci']=$fixture['sci']; $GLOBALS['voletFinancialApi']=$fixture['api'];
    $asset=VoletContract::CURRENCIES[$gateway];$wireScale=VoletContract::wireScale($asset);
    if ($gateway!=='AC') { $fixture['api']['acc']=$asset.'123456789012';$fixture['callback']['ac_dest_wallet']=$fixture['api']['acc'];$fixture['callback']['ac_merchant_currency']=$asset;
        $fixture['order']['transactionCurrency']=$asset;$fixture['transaction']['currency']=$asset;$fixture['transaction']['currencyIncome']=$asset;$fixture['transaction']['walletDestId']=$fixture['api']['acc']; }
    $GLOBALS['voletFinancialApi']=$fixture['api'];
    $now=time();
    $db->update('Currs',['cCID'=>$gateway,'cCurrID'=>$asset,'cNumDec'=>$wireScale,'cCASHINMode'=>2,'cMTS'=>timeToStamp($now),
        'cParamsSCI'=>encodeArrayToStr($fixture['sci'],'1'.$gateway.$now,2),'cParamsAPI'=>encodeArrayToStr($fixture['api'],'1'.$gateway.$now,3)],'','cID=?d',[1]);
    $_currs=$db->fetchIDRows($db->select('Currs'),false,'cID');
    $db->insert('Wallets',['wuID'=>1,'wcID'=>1,'wBal'=>'0','wLock'=>'0','wOut'=>'0']);
    $id=opOperCreate(1,'CASHIN',1,'1.23');
    $manager=new PaymentManager($db);
    $manager->register($gateway,new FinancialVolet($gateway,$manager->definitions($gateway),[],$db));
    $public=DepositCheckout::prepare($db,1,$id,fn()=>$manager->processDeposit($gateway,[
        'sci'=>$fixture['sci'],'api'=>$fixture['api'],'sum'=>financialValue("SELECT oSum FROM Opers WHERE oID=$id"),
        'tag'=>$id,'callback_scale'=>$wireScale,'memo'=>'Synthetic Volet invoice',
        'callback_fee_policy'=>$db->fetch1Row($db->query('SELECT Opers.*,Currs.* FROM Opers JOIN Currs ON cID=ocID WHERE oID=?d',[$id])),
    ]));
    $transaction='235f9d0b-b48f-462c-9949-'.str_pad((string)$id,12,'0',STR_PAD_LEFT);
    $fields=array_replace($fixture['callback'],['ac_transfer'=>$transaction,'ac_order_id'=>(string)$id,'ac_start_date'=>gmdate('Y-m-d H:i:s',$now)]);
    $GLOBALS['voletFinancialOrder']=array_replace($fixture['order'],['orderId'=>(string)$id,'transactionId'=>$transaction,
        'paymentCreated'=>gmdate('Y-m-d\TH:i:s\Z',$now),'transactionCreated'=>gmdate('Y-m-d\TH:i:s\Z',$now),'transactionCompleted'=>gmdate('Y-m-d\TH:i:s\Z',$now)]);
    $GLOBALS['voletFinancialTransaction']=array_replace($fixture['transaction'],['id'=>$transaction,'orderId'=>(string)$id,
        'startTime'=>gmdate('Y-m-d\TH:i:s\Z',$now),'updatedTime'=>gmdate('Y-m-d\TH:i:s\Z',$now)]);
    $before=voletFinancialState();
    $verified=$manager->handleCallback($gateway,['_raw_body'=>voletFinancialSigned($fields)],$fixture['sci']);
    financialAssert($verified['correct'] && $verified['outcome']==='verified','Authenticated order and SCI transaction verified');
    financialAssert(voletFinancialState()===$before,'API verification is read-only and creates no durable identity');
    return [$id,$verified,$public,$manager,$fields];
}
function voletFinancialSaveForm(int $id,array $form): void
{
    global $db,$_cfg;
    $params=strToArray(financialValue("SELECT oParams FROM Opers WHERE oID=$id"));
    $params['checkout']=SecretCipher::encrypt(json_encode($form,JSON_THROW_ON_ERROR),hash('sha256','checkout:'.$id.':1:'.($_cfg['Const_Salt'] ?? '')));
    $db->update('Opers',['oParams'=>arrayToStr($params)],'','oID=?d',[$id]);
}

[$id,$verified,$public,$manager,$fields]=voletFinancialSetup();
$operation=$db->fetch1Row($db->select('Opers','*','oID=?d',[$id]));
$saved=DepositCheckout::storedForm($operation);
financialAssert($saved['volet_api_secret']===$GLOBALS['voletFinancialApi']['apipass'] && $saved['volet_wallet']===$verified['accto'],'Encrypted intent includes explicit API credentials and receiver');
financialAssert(array_filter(array_keys($public),static fn($key)=>str_starts_with($key,'volet_'))===[],'Public checkout strips all internal intent fields');
financialAssert($public['ac_amount']==='1.23' && $public['ac_currency']==='USD' && $public['ac_ps']==='VOLET','Public request freezes full USD wallet payment');
financialAssert(DepositCheckout::prepare($db,1,$id,fn()=>throw new LogicException('duplicate checkout'))===$public,'Checkout retry returns original form');
financialAssert(count($GLOBALS['voletFinancialCalls'])===2,'Only API19 followed by API13 was invoked');
foreach (['sum'=>'1.24','sum2'=>'1.22','curr'=>'EUR','accto'=>'USD999999999999','volet_proof_hash'=>hash('sha256','changed'),'signed_at'=>$verified['signed_at']+1] as $field=>$value)
    voletFinancialReject(fn()=>GatewaySettlement::settle($db,'AC',1,array_replace($verified,[$field=>$value])),match($field) {
        'sum','sum2'=>'callback_amount_mismatch','curr'=>'callback_currency_mismatch','accto'=>'callback_destination_mismatch',default=>'callback_volet_proof_mismatch'});
foreach (['ac_transaction_status'=>'REFUNDED','ac_fee'=>'999.99','ac_merchant_amount'=>'0.01','ac_buyer_email'=>'changed@fixture.invalid'] as $field=>$value) {
    $again=$manager->handleCallback('AC',['_raw_body'=>voletFinancialSigned(array_replace($fields,[$field=>$value]))],$GLOBALS['voletFinancialSci']);
    financialAssert($again===$verified,'Unsigned SCI field is not a status, money or sender authority');
}
financialAssert(GatewaySettlement::settle($db,'AC',1,$verified)->data===['settled'=>true],'One exact USD credit committed');
financialWallet(1,1,'1.230000');
financialAssert(GatewaySettlement::settle($db,'AC',1,$verified)->replayed,'Completed exact replay returns original result');
financialAssert((int)financialValue("SELECT COUNT(*) FROM Hist WHERE hOper='BAL'")===1,'Single BAL credit event');
foreach (['sum'=>'1.24','accto'=>'USD999999999999','volet_proof_hash'=>hash('sha256','changed'),'signed_at'=>$verified['signed_at']+1,'accfrom'=>'USD999999999999'] as $field=>$value)
    voletFinancialReject(fn()=>GatewaySettlement::settle($db,'AC',1,array_replace($verified,[$field=>$value])),match($field) {
        'sum','accfrom'=>'conflict','signed_at'=>'callback_time_conflict','accto'=>'callback_destination_mismatch',default=>'callback_volet_proof_mismatch'});
$before=voletFinancialState();
$normalizedFields=array_replace($fields,['ac_amount'=>'1.23000000']);
$normalized=$manager->handleCallback('AC',['_raw_body'=>voletFinancialSigned($normalizedFields)],$GLOBALS['voletFinancialSci']);
financialAssert($normalized===$verified && GatewaySettlement::settle($db,'AC',1,$normalized)->replayed,'Extra trailing zeros preserve normalized proof and replay');
financialAssert(voletFinancialState()===$before,'Normalized replay changes no rows');
foreach (['volet_api_secret','volet_profile','volet_proof_hash','volet_fee_policy_hash','ac_buyer_email','ac_transaction_status','ac_fee','ac_merchant_amount'] as $field)
    financialAssert(!isset(strToArray(financialValue("SELECT oParams2 FROM Opers WHERE oID=$id"))[$field]),'Private/unsigned metadata absent from receipt');
$identity=$pdo->query("SELECT ikID,ikResponse FROM IdempotencyKeys WHERE ikNamespace='gateway:ac:currency:1:identity'")->fetch();
$proof=json_decode($identity['ikResponse'],true,32,JSON_THROW_ON_ERROR);
foreach ([$saved['volet_api_secret'],$saved['volet_api_email'],$saved['volet_wallet'],$verified['accfrom'],$GLOBALS['voletFinancialSci']['key']] as $private)
    financialAssert(!str_contains(serialize($proof),$private),'Durable proof contains digests, not credentials/source/merchant/receiver');
foreach (['volet_profile','volet_proof_hash','volet_fee_policy_hash','contract'] as $missing) {
    $legacy=$proof;
    if ($missing==='contract') unset($legacy['data']['contract']); else unset($legacy['data']['contract'][$missing]);
    $db->update('IdempotencyKeys',['ikResponse'=>json_encode($legacy,JSON_THROW_ON_ERROR)],'','ikID=?d',[$identity['ikID']]);
    voletFinancialReject(fn()=>GatewaySettlement::settle($db,'AC',1,$verified),'callback_volet_binding_missing');
    voletFinancialReject(fn()=>GatewaySettlement::settle($db,'AC',1,array_replace($verified,['volet_proof_hash'=>hash('sha256','changed')])),'callback_volet_binding_missing');
}
$db->update('IdempotencyKeys',['ikResponse'=>$identity['ikResponse']],'','ikID=?d',[$identity['ikID']]);

// A later authenticated refund cannot ACK a cached credit or reverse it itself.
foreach ([['order','paymentStatus','REFUNDED'],['order','transactionStatus','REFUNDED'],['transaction','status','REFUNDED']] as [$record,$field,$value]) {
    $key=$record==='order'?'voletFinancialOrder':'voletFinancialTransaction'; $old=$GLOBALS[$key][$field]; $GLOBALS[$key][$field]=$value;
    voletFinancialReject(fn()=>$manager->handleCallback('AC',['_raw_body'=>voletFinancialSigned($fields)],$GLOBALS['voletFinancialSci']),$record==='order'?'volet_payment_not_completed':'volet_transaction_contract_unsupported');
    $GLOBALS[$key][$field]=$old;
}
financialWallet(1,1,'1.230000');

// Current credentials cannot redirect a frozen checkout, even after completion.
foreach (['name'=>'other@fixture.invalid','api'=>'Changed API','apipass'=>'changed-api-secret','acc'=>'USD999999999999'] as $field=>$value) {
    $row=$db->fetch1Row($db->select('Currs','*','cID=?d',[1]));
    $key='1AC'.stampToTime($row['cMTS']);
    $changed=$GLOBALS['voletFinancialApi']; $changed[$field]=$value;
    $db->update('Currs',['cParamsAPI'=>encodeArrayToStr($changed,$key,3)],'','cID=?d',[1]);
    $calls=count($GLOBALS['voletFinancialCalls']);
    voletFinancialReject(fn()=>$manager->handleCallback('AC',['_raw_body'=>voletFinancialSigned($fields)],$GLOBALS['voletFinancialSci']),$field==='name'?'volet_api_configuration_required':'callback_merchant_configuration_changed');
    financialAssert(count($GLOBALS['voletFinancialCalls'])===$calls,'Changed API configuration performs no provider I/O');
    $db->update('Currs',['cParamsAPI'=>$row['cParamsAPI']],'','cID=?d',[1]);
}
// Missing profile does not use SCI unsigned COMPLETED as a pending authority.
$row=$db->fetch1Row($db->select('Currs','*','cID=?d',[1]));
$db->update('Currs',['cParamsAPI'=>''],'','cID=?d',[1]);
voletFinancialReject(fn()=>$manager->handleCallback('AC',['_raw_body'=>voletFinancialSigned($fields)],$GLOBALS['voletFinancialSci']),'volet_api_configuration_required');
$db->update('Currs',['cParamsAPI'=>$row['cParamsAPI']],'','cID=?d',[1]);

// Old, malformed or altered saved checkout cannot use mutable receipt fallback.
foreach (['volet_profile','volet_api_secret','volet_wallet','ac_sci_name','volet_scale','volet_local_fee_percent','volet_local_fee_min','volet_local_fee_max','volet_local_fee_commission'] as $missing) {
    $bad=$saved; unset($bad[$missing]); voletFinancialSaveForm($id,$bad);
    if ($missing==='volet_profile' || str_starts_with($missing,'volet_local_fee_'))
        voletFinancialReject(fn()=>DepositCheckout::prepare($db,1,$id,fn()=>throw new LogicException('old invoice retry')),'callback_invoice_unavailable');
    voletFinancialReject(fn()=>$manager->handleCallback('AC',['_raw_body'=>voletFinancialSigned($fields)],$GLOBALS['voletFinancialSci']),'callback_invoice_unavailable');
}
voletFinancialSaveForm($id,$saved);
$params=strToArray(financialValue("SELECT oParams FROM Opers WHERE oID=$id")); $oldParams=$params;
unset($params['checkout']); $db->update('Opers',['oParams'=>arrayToStr($params)],'','oID=?d',[$id]);
voletFinancialReject(fn()=>$manager->handleCallback('AC',['_raw_body'=>voletFinancialSigned($fields)],$GLOBALS['voletFinancialSci']),'callback_invoice_unavailable');
$params=$oldParams; $params['checkout']='not-encrypted'; $db->update('Opers',['oParams'=>arrayToStr($params)],'','oID=?d',[$id]);
voletFinancialReject(fn()=>$manager->handleCallback('AC',['_raw_body'=>voletFinancialSigned($fields)],$GLOBALS['voletFinancialSci']),'callback_checkout_unavailable');
$db->update('Opers',['oParams'=>arrayToStr($oldParams)],'','oID=?d',[$id]);

// A currency precision edit before first acceptance cannot silently round money.
[$id,$verified,$public,$manager,$fields]=voletFinancialSetup();
$db->update('Currs',['cNumDec'=>3],'','cID=?d',[1]);
voletFinancialReject(fn()=>$manager->handleCallback('AC',['_raw_body'=>voletFinancialSigned($fields)],$GLOBALS['voletFinancialSci']),'callback_precision_configuration_changed');
$db->update('Currs',['cNumDec'=>2],'','cID=?d',[1]);
$db->update('Opers',['oSum'=>'1.24'],'','oID=?d',[$id]);
voletFinancialReject(fn()=>$manager->handleCallback('AC',['_raw_body'=>voletFinancialSigned($fields)],$GLOBALS['voletFinancialSci']),'callback_amount_mismatch');
$db->update('Opers',['oSum'=>'1.23'],'','oID=?d',[$id]);

// Skew is a local first-acceptance policy; exact accepted proof has no max age.
voletFinancialReject(fn()=>GatewaySettlement::settle($db,'AC',1,array_replace($verified,['signed_at'=>time()+301])),'callback_time_outside_window');
financialAssert(GatewaySettlement::settle($db,'AC',1,$verified)->data===['settled'=>true],'Validated immutable intent still settles');
financialAssert(GatewaySettlement::settle($db,'AC',1,$verified,time()+86400*30)->replayed,'Late completed replay does not expire accepted proof');
financialWallet(1,1,'1.230000');

// A changed local commission cannot change a previously frozen exact credit.
foreach (['cCASHINComis'=>'20','cCASHINComisMin'=>'0.01','cCASHINComisMax'=>'5','oComis'=>'0.23'] as $field=>$value) {
    [$id,$verified,$public,$manager,$fields]=voletFinancialSetup();
    $isOperation=$field==='oComis'; $table=$isOperation?'Opers':'Currs'; $idField=$isOperation?'oID':'cID'; $rowId=$isOperation?$id:1;
    $old=financialValue("SELECT $field FROM $table WHERE $idField=$rowId");
    $db->update($table,[$field=>$value],'',"$idField=?d",[$rowId]);
    $calls=count($GLOBALS['voletFinancialCalls']);
    voletFinancialReject(fn()=>$manager->handleCallback('AC',['_raw_body'=>voletFinancialSigned($fields)],$GLOBALS['voletFinancialSci']),'callback_local_fee_configuration_changed');
    financialAssert(count($GLOBALS['voletFinancialCalls'])===$calls,'Changed local fee rejected before API lookup');
    voletFinancialReject(fn()=>DepositCheckout::prepare($db,1,$id,fn()=>throw new LogicException('duplicate invoice')),'callback_local_fee_configuration_changed');
    voletFinancialReject(fn()=>GatewaySettlement::settle($db,'AC',1,$verified),$isOperation?'callback_inbound_intent_mismatch':'callback_local_fee_configuration_changed');
    financialWallet(1,1,'0.000000');
    $db->update($table,[$field=>$old],'',"$idField=?d",[$rowId]);
    financialAssert(DepositCheckout::prepare($db,1,$id,fn()=>throw new LogicException('duplicate invoice'))===$public,'Restored original policy returns frozen checkout');
    financialAssert(GatewaySettlement::settle($db,'AC',1,$verified)->data===['settled'=>true],'Restored fee policy permits one exact credit');
    financialAssert(GatewaySettlement::settle($db,'AC',1,$verified)->replayed,'Restored exact retry does not repeat credit');
    financialWallet(1,1,'1.230000');
    financialAssert((int)financialValue("SELECT COUNT(*) FROM Hist WHERE hOper='BAL'")===1,'Restored fee policy credits once');
}

// Simulate interruption after durable identity, before the separate money stage.
[$id,$verified,$public,$manager,$fields]=voletFinancialSetup();
$fingerprint=IdempotencyRequest::fingerprint(['operation_id'=>$id,'currency_id'=>1,'amount'=>'1.23','direction'=>'in','sender_hash'=>hash('sha256',$verified['accfrom'])]);
(new IdempotencyStore($db))->execute('gateway:ac:currency:1:identity',hash('sha256',$verified['batch']),0,$fingerprint,static function() use($db,$id,$verified): IdempotencyResult {
    $locked=$db->fetch1Row($db->query('SELECT Opers.*,Currs.* FROM Opers JOIN Currs ON cID=ocID WHERE oID=?d FOR UPDATE',[$id]));
    return new IdempotencyResult(['bound'=>true,'contract'=>CallbackContract::validate($db,'AC',$locked,$verified,time())],200,$id);
});
$db->update('Currs',['cCASHINComis'=>'20'],'','cID=?d',[1]);
voletFinancialReject(fn()=>GatewaySettlement::settle($db,'AC',1,$verified),'callback_local_fee_configuration_changed');
financialAssert((int)financialValue("SELECT COUNT(*) FROM IdempotencyKeys WHERE ikNamespace='gateway:ac:currency:1:settled'")===0,'Fee drift after identity creates no completed money stage');
financialWallet(1,1,'0.000000');
$db->update('Currs',['cCASHINComis'=>'0'],'','cID=?d',[1]);
// A stale request-global policy must be replaced with the locked accepted row.
$_currs[1]['cCASHINComis']='20'; $_currs[1]['cCASHINComisMin']='0.25';
financialAssert(GatewaySettlement::settle($db,'AC',1,$verified)->data===['settled'=>true],'Restored locked policy ignores stale request globals');
financialWallet(1,1,'1.230000');
financialAssert((int)financialValue("SELECT COUNT(*) FROM Hist WHERE hOper='BAL'")===1,'Interrupted identity recovery still credits once');

// Freeze and re-check even a currently harmless maximum; no inferred changes.
$row=$db->fetch1Row($db->select('Currs','*','cID=?d',[1]));
$badPolicy=$row; $badPolicy['oComis']='0'; $badPolicy['cCASHINComisMax']='5';
financialAssert(VoletContract::localFeePolicy($badPolicy)['max']==='5','Nonnegative maximum is supported only as frozen metadata');
$before=voletFinancialState();
$result=$manager->processDeposit('AC',['sci'=>$GLOBALS['voletFinancialSci'],'api'=>$GLOBALS['voletFinancialApi'],'sum'=>'1.23','tag'=>$id,'callback_scale'=>2,'callback_fee_policy'=>array_replace($badPolicy,['cCASHINComis'=>'20'])]);
financialAssert(($result['error'] ?? '')==='volet_local_fee_contract_unsupported' && voletFinancialState()===$before,'Nonzero fee checkout is explicitly unsupported and read-only');
// Every new settlement currency is independently bound, including a full SQL
// credit, immutable replay and rejection of cross-currency/provider-fee evidence.
foreach (VoletContract::CURRENCIES as $gateway=>$asset) {
    if ($gateway==='AC') continue;
    [$id,$verified,$public,$manager,$fields]=voletFinancialSetup($gateway);
    financialAssert($public['ac_currency']===$asset && $verified['curr']===$asset,'New SCI currency is signed and authenticated');
    financialAssert($verified['volet_profile']!==VoletContract::PROFILE,'New currency never reuses USD v1 profile');
    financialAssert(GatewaySettlement::settle($db,$gateway,1,$verified)->data===['settled'=>true],'Exact new Volet currency credit');
    financialWallet(1,1,'1.230000');$before=voletFinancialState();
    financialAssert(GatewaySettlement::settle($db,$gateway,1,$verified)->replayed && voletFinancialState()===$before,'New currency replay is immutable');
    voletFinancialReject(fn()=>GatewaySettlement::settle($db,$gateway,1,array_replace($verified,['curr'=>'USD'])),'callback_currency_mismatch');
    $GLOBALS['voletFinancialTransaction']['fullCommission']='0.01';
    voletFinancialReject(fn()=>$manager->handleCallback($gateway,['_raw_body'=>voletFinancialSigned($fields)],$GLOBALS['voletFinancialSci']),'volet_fee_contract_unsupported');
}
echo "Financial Volet AC/USD fee-zero contract passed ($assertions checks).\n";
