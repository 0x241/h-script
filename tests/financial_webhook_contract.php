<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/fixtures/financial_runtime.php';
require __DIR__.'/fixtures/currency_archive_checks.php';

use HScript\Finance\CallbackAmount;
use HScript\Finance\CallbackContract;
use HScript\Finance\CallbackValidationException;
use HScript\Finance\DepositCheckout;
use HScript\Finance\GatewaySettlement;
use HScript\Finance\WithdrawalDispatcher;
use HScript\Payment\PaymentManager;

function contractState(): string
{
    global $pdo;
    return hash('sha256', financialFingerprint().serialize($pdo->query('SELECT * FROM IdempotencyKeys ORDER BY ikID')->fetchAll()).serialize($pdo->query('SELECT * FROM Jobs ORDER BY jID')->fetchAll()));
}
function contractReject(callable $call, string $reason): void
{
    $before=contractState();
    try { $call(); throw new LogicException('Callback contract accepted '.$reason); }
    catch (CallbackValidationException $e) { financialAssert($e->reason===$reason,'fixed callback rejection reason '.$reason.', got '.$e->reason); }
    financialAssert(contractState()===$before,'rejection changes no operations/buckets/history/claims/jobs');
}
function contractCurrency(int $id, string $gateway, string $asset, array $sci, int $scale=2): void
{
    global $db,$_currs;
    $salt=time();
    $db->update('Currs',['cCID'=>$gateway,'cCurrID'=>$asset,'cNumDec'=>$scale,'cCASHINMode'=>2,'cMTS'=>timeToStamp($salt),
        'cParamsSCI'=>encodeArrayToStr($sci,$id.$gateway.$salt,2)],'','cID=?d',[$id]);
    if ($gateway==='WM') $db->update('Currs',['cParams'=>encodeArrayToStr(['acc'=>'Z123456789012'],$id.$gateway.$salt)],'','cID=?d',[$id]);
    $_currs=$db->fetchIDRows($db->select('Currs'),false,'cID');
}
function contractInvoice(int $id): void
{
    global $db;
    putenv('APP_DATA_KEY=synthetic-contract-checkout-key');
    DepositCheckout::prepare($db,1,$id,static function(array $operation):array {
        $memo=strToArray($operation['oParams2'])['memo'];
        return $operation['cCID']==='WM'
            ? ['url'=>'https://fixture.invalid/wm','LMI_PAYEE_PURSE'=>'Z123456789012','LMI_PAYMENT_AMOUNT'=>$operation['oSum'],'LMI_PAYMENT_DESC_BASE64'=>base64_encode($memo)]
            : ['url'=>'https://fixture.invalid/yoo','receiver'=>'410111222333444','sum'=>$operation['oSum']];
    });
}
function contractCoin(int $id, array $changes=[]): array
{
    global $db;
    $fields=array_replace(['merchant'=>'fixture-merchant','ipn_version'=>'1.0','ipn_id'=>'synthetic-contract-ipn','ipn_mode'=>'hmac','ipn_type'=>'simple','invoice'=>(string)$id,
        'txn_id'=>'fixture-contract-coin','amount1'=>'100.00','currency1'=>'BTC','status'=>'100'],$changes);
    $body=http_build_query($fields);
    return (new PaymentManager($db))->handleCallback('CP',['_raw_body'=>$body,'_server'=>['HTTP_HMAC'=>hash_hmac('sha512',$body,'fixture-key')]],['id'=>'fixture-merchant','key'=>'fixture-key']);
}
function contractYoo(int $id, int $date, array $changes=[]): array
{
    global $db;
    $fields=array_replace(['notification_type'=>'p2p-incoming','operation_id'=>'fixture-contract-yoo','amount'=>'49.75','withdraw_amount'=>'50.00',
        'currency'=>'643','datetime'=>gmdate('Y-m-d\TH:i:s\Z',$date),'sender'=>'fixture-yoo-sender','codepro'=>'false','label'=>(string)$id,'unaccepted'=>'false'],$changes);
    ksort($fields,SORT_STRING);
    $fields['sign']=hash_hmac('sha256',http_build_query($fields,'','&',PHP_QUERY_RFC3986),'fixture-yoo-key');
    return (new PaymentManager($db))->handleCallback('YM',['_raw_body'=>http_build_query($fields)],['key'=>'fixture-yoo-key','acc'=>'410111222333444']);
}
function contractWebMoney(int $id, array $changes=[]): array
{
    global $db;
    $fields=array_replace(['LMI_PAYEE_PURSE'=>'Z123456789012','LMI_PAYMENT_AMOUNT'=>'50.00','LMI_PAYMENT_NO'=>(string)$id,
        'LMI_MODE'=>'0','LMI_SYS_INVS_NO'=>'120','LMI_SYS_TRANS_NO'=>'45678','LMI_SYS_TRANS_DATE'=>gmdate('Ymd H:i:s'),
        'LMI_PAYER_PURSE'=>'Z987654321012','LMI_PAYER_WM'=>'123456789012',
        'LMI_PAYMENT_DESC'=>strToArray(financialValue("SELECT oParams2 FROM Opers WHERE oID=$id"))['memo']],$changes);
    $parts=[$fields['LMI_PAYEE_PURSE'],$fields['LMI_PAYMENT_AMOUNT']];
    if (isset($fields['LMI_HOLD'])) $parts[]=$fields['LMI_HOLD'];
    foreach (['LMI_PAYMENT_NO','LMI_MODE','LMI_SYS_INVS_NO','LMI_SYS_TRANS_NO','LMI_SYS_TRANS_DATE'] as $field) $parts[]=$fields[$field];
    array_push($parts,'fixture-webmoney-key',$fields['LMI_PAYER_PURSE'],$fields['LMI_PAYER_WM']);
    $fields['LMI_HASH2']=strtoupper(hash('sha256',implode(';',$parts)));
    return (new PaymentManager($db))->handleCallback('WM',['_raw_body'=>http_build_query($fields)],['key'=>'fixture-webmoney-key','timezone'=>'UTC']);
}

financialReset(); contractCurrency(2,'CP','BTC',['id'=>'fixture-merchant','key'=>'fixture-key']);
$id=opOperCreate(1,'CASHIN',2,100);
foreach (['90.00'=>'callback_amount_mismatch','101.00'=>'callback_amount_mismatch','100.001'=>'callback_amount_precision'] as $amount=>$reason)
    contractReject(fn()=>GatewaySettlement::settle($db,'CP',2,contractCoin($id,['amount1'=>(string)$amount])),$reason);
$valid=contractCoin($id);
contractReject(fn()=>GatewaySettlement::settle($db,'CP',2,array_replace($valid,['curr'=>'ETH'])),'callback_currency_mismatch');
contractReject(fn()=>GatewaySettlement::settle($db,'CPE',2,$valid),'callback_gateway_mismatch');
contractReject(fn()=>GatewaySettlement::settle($db,'CP',2,array_replace($valid,['accto'=>'fixture-other-merchant'])),'callback_destination_mismatch');
financialAssert(GatewaySettlement::settle($db,'CP',2,$valid)->data===['settled'=>true],'correct signed amount settles after rejected attempts');
financialWallet(1,2,'100.000000');
financialAssert(financialValue("SELECT oSum FROM Opers WHERE oID=$id")==='100.000000','callback cannot substitute original requested amount');
financialAssert(GatewaySettlement::settle($db,'CP',2,$valid)->replayed,'exact authenticated replay retains the one effect');
financialArchivedReplay('CP',2,$valid);
contractReject(fn()=>GatewaySettlement::settle($db,'CP',2,array_replace($valid,['accto'=>'fixture-other-merchant'])),'callback_destination_mismatch');
contractReject(fn()=>GatewaySettlement::settle($db,'CP',2,array_replace($valid,['curr'=>'ETH'])),'callback_currency_mismatch');
foreach ([0,2,6,8] as $scale) {
    financialAssert(CallbackAmount::atScale('1.00000000',$scale)==='1'.($scale?'.'.str_repeat('0',$scale):''),'decimal scale '.$scale);
}
foreach (['1e2','+100',' 100','0','-1','NaN','9999999999999',100.0] as $invalid) {
    try { CallbackAmount::atScale($invalid,2); throw new LogicException('Invalid callback amount accepted'); }
    catch (CallbackValidationException) { $assertions++; }
}
financialAssert(CallbackAmount::atScale('0.00000100',8)==='0.00000100','eight-place comparison preserves minor units');

financialReset(); contractCurrency(2,'CP','BTC',['id'=>'fixture-merchant','key'=>'fixture-key'],8);
$id=opOperCreate(1,'CASHIN',2,'0.000001');
contractReject(fn()=>GatewaySettlement::settle($db,'CP',2,contractCoin($id,['amount1'=>'0.00000101'])),'callback_amount_mismatch');
financialAssert(GatewaySettlement::settle($db,'CP',2,contractCoin($id,['amount1'=>'0.00000100']))->data===['settled'=>true],'exact stored crypto amount settles');
financialWallet(1,2,'0.000001');

// Receiver identity is taken from the saved invoice, not mutable configuration.
financialReset(); contractCurrency(2,'CP','BTC',['id'=>'fixture-merchant','key'=>'fixture-key']);
putenv('APP_DATA_KEY=synthetic-contract-checkout-key');
$id=opOperCreate(1,'CASHIN',2,100);
DepositCheckout::prepare($db,1,$id,fn()=>['url'=>'https://fixture.invalid/pay','merchant'=>'fixture-merchant','amountf'=>'100.00']);
$db->update('Opers',['oSum'=>'99'],'','oID=?d',[$id]);
contractReject(fn()=>GatewaySettlement::settle($db,'CP',2,contractCoin($id,['amount1'=>'99.00'])),'callback_inbound_intent_mismatch');
$db->update('Opers',['oSum'=>'100'],'','oID=?d',[$id]);
contractReject(fn()=>GatewaySettlement::settle($db,'CP',2,array_replace(contractCoin($id),['accto'=>'fixture-other-merchant'])),'callback_destination_mismatch');
$invoice=contractCoin($id);
financialAssert(GatewaySettlement::settle($db,'CP',2,$invoice)->data===['settled'=>true],'saved invoice receiver binding');
financialWallet(1,2,'100.000000');

// A pre-existing identity without contract proof cannot authorize a new effect.
financialReset(); contractCurrency(2,'CP','BTC',['id'=>'fixture-merchant','key'=>'fixture-key']);
$id=opOperCreate(1,'CASHIN',2,100); $old=contractCoin($id,['amount1'=>'90.00']);
$fingerprint=HScript\Finance\IdempotencyRequest::fingerprint(['operation_id'=>$id,'currency_id'=>2,'amount'=>'90','direction'=>'in','sender_hash'=>hash('sha256','')]);
(new HScript\Finance\IdempotencyStore($db))->execute('gateway:cp:currency:2:identity',hash('sha256',$old['batch']),0,$fingerprint,fn()=>new HScript\Finance\IdempotencyResult(['bound'=>true],200,$id));
contractReject(fn()=>GatewaySettlement::settle($db,'CP',2,$old),'callback_inbound_binding_missing');

// Accepted pending identity freezes time/receiver, but money is re-read at final.
financialReset(); contractCurrency(2,'CP','BTC',['id'=>'fixture-merchant','key'=>'fixture-key']);
$id=opOperCreate(1,'CASHIN',2,100); $pending=contractCoin($id,['status'=>'0']);
financialAssert(GatewaySettlement::settle($db,'CP',2,$pending)->data===['settled'=>false],'pending has no financial credit');
$db->update('Opers',['oSum'=>'99'],'','oID=?d',[$id]);
contractReject(fn()=>GatewaySettlement::settle($db,'CP',2,contractCoin($id)),'callback_amount_mismatch');
$db->update('Opers',['oSum'=>'100'],'','oID=?d',[$id]);
$db->update('Currs',['cNumDec'=>3],'','cID=?d',[2]); $_currs[2]['cNumDec']=3;
contractReject(fn()=>GatewaySettlement::settle($db,'CP',2,contractCoin($id)),'callback_precision_configuration_changed');
$db->update('Currs',['cNumDec'=>2],'','cID=?d',[2]); $_currs[2]['cNumDec']=2;
financialAssert(GatewaySettlement::settle($db,'CP',2,contractCoin($id))->data===['settled'=>true],'original amount restored after pending conflict');
financialWallet(1,2,'100.000000');

// The arithmetic context must use the locked DB scale, not stale auth globals.
financialReset(); contractCurrency(2,'CP','BTC',['id'=>'fixture-merchant','key'=>'fixture-key'],6);
$id=opOperCreate(1,'CASHIN',2,'100.123456');
financialAssert(GatewaySettlement::settle($db,'CP',2,contractCoin($id,['amount1'=>'100.123456','status'=>'0']))->data===['settled'=>false],'precise pending amount');
$_currs[2]['cNumDec']=2;
financialAssert(GatewaySettlement::settle($db,'CP',2,contractCoin($id,['amount1'=>'100.123456']))->data===['settled'=>true],'stale global scale refreshed before financial effects');
financialWallet(1,2,'100.123456');
financialAssert(financialValue("SELECT oSum FROM Opers WHERE oID=$id")==='100.123456','stale request context cannot truncate the original amount');

financialReset(); contractCurrency(3,'YM','RUB',['acc'=>'410111222333444','key'=>'fixture-yoo-key']);
$now=time(); $id=opOperCreate(1,'CASHIN',3,50); contractInvoice($id);
$db->update('Opers',['oCTS'=>timeToStamp($now-172800)],'','oID=?d',[$id]);
foreach ([$now-CallbackContract::YOO_MAX_AGE-1,$now+CallbackContract::CLOCK_SKEW+1] as $date)
    contractReject(fn()=>GatewaySettlement::settle($db,'YM',3,contractYoo($id,$date),$now),'callback_time_outside_window');
$db->update('Opers',['oCTS'=>timeToStamp($now)],'','oID=?d',[$id]);
contractReject(fn()=>GatewaySettlement::settle($db,'YM',3,contractYoo($id,$now-301),$now),'callback_time_outside_window');
$pending=contractYoo($id,$now,['unaccepted'=>'true']);
financialAssert(GatewaySettlement::settle($db,'YM',3,$pending,$now)->data===['settled'=>false],'YooMoney held payment is pending');
$final=contractYoo($id,$now);
contractReject(fn()=>GatewaySettlement::settle($db,'YM',3,contractYoo($id,$now+1),$now),'callback_time_conflict');
contractReject(fn()=>GatewaySettlement::settle($db,'YM',3,array_replace($final,['accto'=>'fixture-other-receiver']),$now),'callback_destination_mismatch');
financialAssert(GatewaySettlement::settle($db,'YM',3,$final,$now+172800)->data===['settled'=>true],'known pending transaction can finish after initial age window');
financialAssert(GatewaySettlement::settle($db,'YM',3,$final,$now+259200)->replayed,'known final replay remains stable after window');
financialArchivedReplay('YM',3,$final);
financialWallet(1,3,'50.000000');
foreach ($pdo->query("SELECT ikResponse FROM IdempotencyKeys WHERE ikNamespace LIKE '%:identity'")->fetchAll(PDO::FETCH_COLUMN) as $snapshot)
    foreach (['410111222333444','fixture-yoo-sender','fixture-yoo-key',$final['batch']] as $private)
        financialAssert(!str_contains($snapshot,$private),'contract proof excludes private provider fields');

// Only accepted incoming profiles enter callback settlement. Manual and
// synchronous payout recovery stays in WithdrawalDispatcher below.
financialReset(); opChangeBalance(1,2,100,0,0);
$id=opOperCreate(1,'CASHOUT',2,20); opOperConfirm(1,$id); opOperComplete(1,$id);
$out=['correct'=>true,'authenticated'=>true,'outcome'=>'verified','outbound'=>true,'tag'=>$id,
    'batch'=>'fixture-contract-payout','sum'=>'20','curr'=>'EUR','accto'=>'fixture-payout-destination'];
contractReject(fn()=>GatewaySettlement::settle($db,'CP',2,$out),'automatic_callback_unsupported');
financialWallet(1,2,'80.000000','0.000000','20.000000');

// A synchronous provider response obeys the same immutable payout contract.
financialReset(); opChangeBalance(1,2,100,0,0);
$id=opOperCreate(1,'CASHOUT',2,20); opOperConfirm(1,$id);
$params=strToArray(financialValue("SELECT oParams2 FROM Opers WHERE oID=$id")); $params['acc']='fixture-sync-payout';
$db->update('Opers',['oParams2'=>arrayToStr($params)],'','oID=?d',[$id]); opOperComplete(1,$id);
$calls=0;
try {
    WithdrawalDispatcher::dispatch($db,$id,function(array $payload) use($id,&$calls,$db):array {
        $calls++; financialAssert($payload['sum']==='20.00','provider receives exact decimal net');
        $db->update('Opers',['oComis'=>'1'],'','oID=?d',[$id]);
        return ['batch'=>'fixture-sync-payout-batch'];
    });
    throw new LogicException('Changed reservation completed after provider send');
} catch (CallbackValidationException $e) { financialAssert($e->reason==='callback_amount_mismatch','sync response rejects changed local amount'); }
financialWallet(1,2,'80.000000','0.000000','20.000000');
try { WithdrawalDispatcher::dispatch($db,$id,fn()=>throw new LogicException('Payout resent')); }
catch (RuntimeException $e) { financialAssert($e->getMessage()==='payout_reconciliation_required','unknown local/provider divergence requires reconciliation'); }
financialAssert($calls===1,'reservation divergence never resends the provider payment');

financialReset(); opChangeBalance(1,2,100,0,0);
$id=opOperCreate(1,'CASHOUT',2,20); opOperConfirm(1,$id);
$params=strToArray(financialValue("SELECT oParams2 FROM Opers WHERE oID=$id")); $params['acc']='fixture-sync-payout'; $params['sum']='19';
$db->update('Opers',['oParams2'=>arrayToStr($params)],'','oID=?d',[$id]); opOperComplete(1,$id);
financialAssert(WithdrawalDispatcher::dispatch($db,$id,function(array $payload):array {
    financialAssert($payload['sum']==='20.00' && !isset($payload['to']['sum']),'editable receipt amount cannot steer automated payout');
    return ['batch'=>'fixture-sync-payout-success'];
})===['completed'=>true],'valid sync response completes the immutable reserve');
financialWallet(1,2,'80.000000');
financialAssert(financialValue("SELECT oSum FROM Opers WHERE oID=$id")==='20.000000','sync result cannot replace gross from editable receipt');

// WebMoney's unsigned prerequest checks local intent without a financial claim.
financialReset(); contractCurrency(3,'WM','USD',['key'=>'fixture-webmoney-key','timezone'=>'UTC']);
$salt=stampToTime(financialValue('SELECT cMTS FROM Currs WHERE cID=3'));
$db->update('Currs',['cParams'=>encodeArrayToStr(['acc'=>'Z123456789012'],'3WM'.$salt)],'','cID=?d',[3]);
$_currs[3]=$db->fetch1Row($db->select('Currs','*','cID=?d',[3]));
$id=opOperCreate(1,'CASHIN',3,50); contractInvoice($id);
$memo=strToArray(financialValue("SELECT oParams2 FROM Opers WHERE oID=$id"))['memo'];
$preFields=['LMI_PREREQUEST'=>'1','LMI_PAYEE_PURSE'=>'Z123456789012','LMI_PAYMENT_AMOUNT'=>'50.00','LMI_PAYMENT_NO'=>(string)$id,'LMI_MODE'=>'0','LMI_PAYMENT_DESC'=>$memo];
$pre=(new PaymentManager($db))->handleCallback('WM',['_raw_body'=>http_build_query($preFields)],['key'=>'fixture-webmoney-key','timezone'=>'UTC']);
$before=contractState(); GatewaySettlement::preflight($db,'WM',3,$pre);
financialAssert(contractState()===$before,'valid WebMoney prerequest writes no operation/wallet/history/claim/job');
contractReject(fn()=>GatewaySettlement::preflight($db,'WM',3,array_replace($pre,['sum'=>'49.00'])),'callback_amount_mismatch');
contractReject(fn()=>GatewaySettlement::preflight($db,'WM',3,array_replace($pre,['accto'=>'Z111111111111'])),'callback_destination_mismatch');
contractReject(fn()=>GatewaySettlement::preflight($db,'WM',3,array_replace($pre,['tag'=>'999999'])),'callback_order_unavailable');
contractReject(fn()=>GatewaySettlement::preflight($db,'WM',3,array_replace($pre,['authenticated'=>true])),'callback_prerequest_invalid');
contractReject(fn()=>GatewaySettlement::preflight($db,'WM',3,array_replace($pre,['description_hash'=>hash('sha256','changed')])),'callback_description_mismatch');
$wm=contractWebMoney($id);
contractReject(fn()=>GatewaySettlement::settle($db,'WM',3,contractWebMoney($id,['LMI_PAYMENT_DESC'=>'changed'])),'callback_description_mismatch');
contractReject(fn()=>GatewaySettlement::settle($db,'WM',3,contractWebMoney($id,['LMI_PAYMENT_AMOUNT'=>'49.00'])),'callback_amount_mismatch');
contractReject(fn()=>GatewaySettlement::settle($db,'WM',3,contractWebMoney($id,['LMI_PAYEE_PURSE'=>'Z111111111111'])),'callback_destination_mismatch');
financialAssert(GatewaySettlement::settle($db,'WM',3,$wm)->data===['settled'=>true],'signed WebMoney HASH2 completes exact original money');
financialWallet(1,3,'50.000000');
contractReject(fn()=>GatewaySettlement::preflight($db,'WM',3,$pre),'callback_order_unavailable');
contractReject(fn()=>GatewaySettlement::settle($db,'WM',3,array_replace($wm,['signed_at'=>$wm['signed_at']+1])),'callback_time_conflict');
financialAssert(GatewaySettlement::settle($db,'WM',3,$wm)->replayed,'WebMoney exact signed callback replay is stable');
financialArchivedReplay('WM',3,$wm);
contractReject(fn()=>GatewaySettlement::settle($db,'WM',3,array_replace($wm,['description_hash'=>hash('sha256','changed')])),'callback_description_mismatch');
$identity=$pdo->query("SELECT ikID,ikResponse FROM IdempotencyKeys WHERE ikNamespace='gateway:wm:currency:3:identity'")->fetch();
foreach (['description_hash','contract'] as $missingProof) {
    $snapshot=json_decode($identity['ikResponse'],true,32,JSON_THROW_ON_ERROR);
    if ($missingProof==='contract') unset($snapshot['data']['contract']);
    else unset($snapshot['data']['contract']['description_hash']);
    $db->update('IdempotencyKeys',['ikResponse'=>json_encode($snapshot,JSON_THROW_ON_ERROR)],'','ikID=?d',[$identity['ikID']]);
    contractReject(fn()=>GatewaySettlement::settle($db,'WM',3,$wm),'callback_description_binding_missing');
    contractReject(fn()=>GatewaySettlement::settle($db,'WM',3,array_replace($wm,['description_hash'=>hash('sha256','changed')])),'callback_description_binding_missing');
}

// Immutable WM checkout applies to both preflight and signed notification.
financialReset(); contractCurrency(3,'WM','USD',['key'=>'fixture-webmoney-key','timezone'=>'UTC']);
$id=opOperCreate(1,'CASHIN',3,50);
$savedMemo="  Оплата + & = %20 ' тест  ";
DepositCheckout::prepare($db,1,$id,fn()=>['url'=>'https://fixture.invalid/webmoney','LMI_PAYEE_PURSE'=>'Z123456789012','LMI_PAYMENT_AMOUNT'=>'50.00',
    'LMI_PAYMENT_DESC'=>'ignored when BASE64 present','LMI_PAYMENT_DESC_BASE64'=>base64_encode($savedMemo)]);
$pre=array_replace($pre,['tag'=>(string)$id,'description_hash'=>hash('sha256',$savedMemo)]);
$before=contractState(); GatewaySettlement::preflight($db,'WM',3,$pre);
financialAssert(contractState()===$before,'saved WebMoney invoice receiver governs unsigned prerequest');
$db->update('Opers',['oSum'=>'49'],'','oID=?d',[$id]);
contractReject(fn()=>GatewaySettlement::preflight($db,'WM',3,array_replace($pre,['sum'=>'49.00'])),'callback_inbound_intent_mismatch');
contractReject(fn()=>GatewaySettlement::settle($db,'WM',3,contractWebMoney($id,['LMI_PAYMENT_AMOUNT'=>'49.00','LMI_PAYMENT_DESC'=>$savedMemo])),'callback_inbound_intent_mismatch');
$db->update('Opers',['oSum'=>'50'],'','oID=?d',[$id]);
contractReject(fn()=>GatewaySettlement::settle($db,'WM',3,contractWebMoney($id)),'callback_description_mismatch');
financialAssert(GatewaySettlement::settle($db,'WM',3,contractWebMoney($id,['LMI_PAYMENT_DESC'=>$savedMemo]))->data===['settled'=>true],'saved WebMoney invoice completes with original receiver and effective BASE64 description');
financialWallet(1,3,'50.000000');
foreach ($pdo->query("SELECT ikResponse FROM IdempotencyKeys WHERE ikNamespace LIKE 'gateway:wm:%:identity'")->fetchAll(PDO::FETCH_COLUMN) as $snapshot)
    financialAssert(!str_contains(serialize(json_decode($snapshot,true,32,JSON_THROW_ON_ERROR)),$savedMemo),'WebMoney proof stores only description hash');
foreach ([[],['LMI_PAYMENT_DESC'=>'Order','LMI_PAYMENT_DESC_BASE64'=>'%%%'],['LMI_PAYMENT_DESC_BASE64'=>base64_encode("bad\0memo")]] as $descriptionFields) {
    $id=opOperCreate(1,'CASHIN',3,50);
    DepositCheckout::prepare($db,1,$id,fn()=>['url'=>'https://fixture.invalid/webmoney','LMI_PAYEE_PURSE'=>'Z123456789012','LMI_PAYMENT_AMOUNT'=>'50.00']+$descriptionFields);
    $verified=contractWebMoney($id,['LMI_SYS_TRANS_NO'=>'45678'.$id]);
    contractReject(fn()=>GatewaySettlement::preflight($db,'WM',3,array_replace($verified,['outcome'=>'preflight','correct'=>false,'authenticated'=>false])),'callback_invoice_description_unavailable');
    contractReject(fn()=>GatewaySettlement::settle($db,'WM',3,$verified),'callback_invoice_description_unavailable');
}
echo "Financial webhook contract tests passed ($assertions checks).\n";
