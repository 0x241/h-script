<?php

declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require __DIR__.'/fixtures/financial_runtime.php';
putenv('APP_DATA_KEY=synthetic-inbound-profile-key');

use HScript\Finance\CallbackValidationException;
use HScript\Finance\DepositCheckout;
use HScript\Finance\GatewaySettlement;
use HScript\Payment\PaymentManager;
use HScript\Security\SecretCipher;

function profileOperation(int $id): array
{
    global $db;
    return $db->fetch1Row($db->query('SELECT Opers.*,Currs.* FROM Opers JOIN Currs ON cID=ocID WHERE oID=?d',[$id]));
}
function profileSetup(string $gateway): int
{
    global $db,$_currs;
    financialReset(); $salt=time();
    $sci=['key'=>'synthetic-original-profile-secret'];
    if ($gateway==='WM') $sci['timezone']='UTC';
    elseif ($gateway==='CP') $sci['id']='fixture-merchant';
    else $sci['acc']='410111222333444';
    $db->update('Currs',['cCID'=>$gateway,'cCurrID'=>in_array($gateway,['YM','YMC'],true)?'RUB':($gateway==='WM'?'USD':'BTC'),
        'cNumDec'=>2,'cCASHINMode'=>2,'cCASHINComis'=>10,'cMTS'=>timeToStamp($salt),
        'cParamsSCI'=>encodeArrayToStr($sci,'2'.$gateway.$salt,2),
        'cParams'=>encodeArrayToStr(['acc'=>'Z123456789012'],'2'.$gateway.$salt)],'','cID=?d',[2]);
    $_currs=$db->fetchIDRows($db->select('Currs'),false,'cID');
    return opOperCreate(1,'CASHIN',2,100,[],'Synthetic original description');
}
function profileForm(array $operation): array
{
    global $db;
    opDecodeCurrParams($operation,$pay,$sci,$api);
    return (new PaymentManager($db))->processDeposit($operation['cCID'],['pay'=>$pay,'sci'=>$sci,
        'sum'=>$operation['oSum'],'tag'=>$operation['oID'],'memo'=>strToArray($operation['oParams2'])['memo'],
        'url_ok'=>'https://fixture.invalid/operation','url_fail'=>'https://fixture.invalid/fail','url_callback'=>'https://fixture.invalid/status']);
}
function profileConfig(int $id,array $changes,?string $purse=null): void
{
    global $db;
    $operation=profileOperation($id); opDecodeCurrParams($operation,$pay,$sci,$api);
    $values=['cParamsSCI'=>encodeArrayToStr(array_replace($sci,$changes),'2'.$operation['cCID'].stampToTime($operation['cMTS']),2)];
    if ($purse!==null) $values['cParams']=encodeArrayToStr(['acc'=>$purse],'2'.$operation['cCID'].stampToTime($operation['cMTS']));
    $db->update('Currs',$values,'','cID=?d',[2]);
}
function profileCallback(int $id): array
{
    global $db;
    $operation=profileOperation($id); opDecodeCurrParams($operation,$pay,$sci,$api);
    if ($operation['cCID']==='CP') {
        $body=http_build_query(['ipn_version'=>'1.0','ipn_id'=>'synthetic-profile-event','ipn_mode'=>'hmac','ipn_type'=>'simple',
            'merchant'=>$sci['id'],'invoice'=>(string)$id,'txn_id'=>'fixture-profile-transaction','amount1'=>'100.00','currency1'=>'BTC','status'=>'100']);
        $request=['_raw_body'=>$body,'_server'=>['HTTP_HMAC'=>hash_hmac('sha512',$body,$sci['key'])]];
    } elseif ($operation['cCID']==='WM') {
        $wire=(new DateTimeImmutable('@'.stampToTime($operation['oCTS'])))->setTimezone(new DateTimeZone($sci['timezone']))->format('Ymd H:i:s');
        $fields=['LMI_PAYEE_PURSE'=>'Z123456789012','LMI_PAYMENT_AMOUNT'=>'100.00','LMI_PAYMENT_NO'=>(string)$id,
            'LMI_MODE'=>'0','LMI_SYS_INVS_NO'=>'120','LMI_SYS_TRANS_NO'=>'45678','LMI_SYS_TRANS_DATE'=>$wire,
            'LMI_PAYER_PURSE'=>'Z987654321012','LMI_PAYER_WM'=>'123456789012','LMI_PAYMENT_DESC'=>'Synthetic original description'];
        $parts=[$fields['LMI_PAYEE_PURSE'],$fields['LMI_PAYMENT_AMOUNT'],$fields['LMI_PAYMENT_NO'],'0','120','45678',$wire,
            $sci['key'],$fields['LMI_PAYER_PURSE'],$fields['LMI_PAYER_WM']];
        $fields['LMI_HASH2']=strtoupper(hash('sha256',implode(';',$parts))); $request=['_raw_body'=>http_build_query($fields)];
    } else {
        $fields=['notification_type'=>$operation['cCID']==='YM'?'p2p-incoming':'card-incoming','operation_id'=>'fixture-profile-transaction',
            'amount'=>'99.50','withdraw_amount'=>'100.00','currency'=>'643','datetime'=>gmdate('Y-m-d\TH:i:s\Z',stampToTime($operation['oCTS'])),
            'sender'=>'fixture-sender','codepro'=>'false','label'=>(string)$id,'unaccepted'=>'false'];
        ksort($fields,SORT_STRING); $fields['sign']=hash_hmac('sha256',http_build_query($fields,'','&',PHP_QUERY_RFC3986),$sci['key']);
        $request=['_raw_body'=>http_build_query($fields)];
    }
    return (new PaymentManager($db))->handleCallback($operation['cCID'],$request,$sci);
}
function profileState(): string
{
    global $pdo;
    return hash('sha256',financialFingerprint().serialize($pdo->query('SELECT * FROM IdempotencyKeys ORDER BY ikID')->fetchAll()).serialize($pdo->query('SELECT * FROM Jobs ORDER BY jID')->fetchAll()));
}
function profileReject(callable $call,string $reason): void
{
    $before=profileState();
    try { $call(); throw new LogicException('Original profile accepted '.$reason); }
    catch(CallbackValidationException $error) { financialAssert($error->reason===$reason,'Profile reason '.$reason.', got '.$error->reason); }
    financialAssert(profileState()===$before,'Original profile refusal changes no financial/configuration/claim/job');
}
function profileSettle(int $id): mixed
{
    global $db;
    return GatewaySettlement::settle($db,profileOperation($id)['cCID'],2,profileCallback($id));
}
foreach(['YM','YMC','WM','CP'] as $gateway) {
    $id=profileSetup($gateway);
    if ($gateway!=='CP') profileReject(fn()=>profileSettle($id),'callback_profile_binding_missing');
    $public=DepositCheckout::prepare($db,1,$id,'profileForm');
    financialAssert(!isset($public['inbound_profile']) && !str_contains(serialize($public),'synthetic-original-profile-secret'),'Browser excludes profile/key for '.$gateway);
    $form=DepositCheckout::storedForm(profileOperation($id));
    financialAssert($form['inbound_profile']['gateway']===$gateway,'Encrypted profile records actual original gateway');
    profileConfig($id,['key'=>'rotated-profile-secret']);
    profileReject(fn()=>profileSettle($id),'callback_profile_configuration_changed');
    profileReject(fn()=>DepositCheckout::prepare($db,1,$id,fn()=>throw new LogicException('Invoice resent')),'callback_profile_configuration_changed');
    profileConfig($id,['key'=>'synthetic-original-profile-secret']);
    if ($gateway==='WM') {
        profileConfig($id,['timezone'=>'Europe/Berlin']);
        profileReject(fn()=>profileSettle($id),'callback_profile_configuration_changed');
        profileConfig($id,['timezone'=>'UTC'],'Z111111111111');
        profileReject(fn()=>profileSettle($id),'callback_profile_configuration_changed');
        profileConfig($id,[],'Z123456789012');
    } else {
        $field=$gateway==='CP'?'id':'acc'; $original=$gateway==='CP'?'fixture-merchant':'410111222333444';
        profileConfig($id,[$field=>$gateway==='CP'?'changed-merchant':'410199999999999']);
        profileReject(fn()=>profileSettle($id),'callback_profile_configuration_changed'); profileConfig($id,[$field=>$original]);
    }
    $db->update('Currs',['cCASHINComis'=>99,'cCASHINMode'=>0],'','cID=?d',[2]);
    financialAssert(profileSettle($id)->data===['settled'=>true],'Restored original profile credits original net'); financialWallet(1,2,'90.000000');
    $receipt=strToArray(financialValue("SELECT oParams2 FROM Opers WHERE oID=$id"));
    financialAssert(!array_intersect_key($receipt,array_flip(['profile_key_hash','profile_timezone','inbound_profile'])),'Receipt excludes private profile metadata');
    $before=profileState(); financialAssert(profileSettle($id)->replayed && profileState()===$before,'Original completed replay stays exact');
    profileConfig($id,['key'=>'rotated-after-credit']);
    profileReject(fn()=>profileSettle($id),'callback_profile_configuration_changed');
    profileConfig($id,['key'=>'synthetic-original-profile-secret']);
    $row=$pdo->query("SELECT ikID,ikResponse FROM IdempotencyKeys WHERE ikNamespace='gateway:".strtolower($gateway).":currency:2:identity'")->fetch();
    $legacy=json_decode($row['ikResponse'],true,32,JSON_THROW_ON_ERROR); unset($legacy['data']['contract']['inbound_profile']);
    $db->update('IdempotencyKeys',['ikResponse'=>json_encode($legacy,JSON_THROW_ON_ERROR)],'','ikID=?d',[$row['ikID']]);
    profileReject(fn()=>profileSettle($id),'callback_profile_binding_missing');
    $db->update('IdempotencyKeys',['ikResponse'=>$row['ikResponse']],'','ikID=?d',[$row['ikID']]);
    $attempt=$pdo->query("SELECT ikID,ikResponse FROM IdempotencyKeys WHERE ikNamespace='invoice:attempt'")->fetch();
    $missing=json_decode($attempt['ikResponse'],true,32,JSON_THROW_ON_ERROR); unset($missing['data']['profile']);
    $db->update('IdempotencyKeys',['ikResponse'=>json_encode($missing,JSON_THROW_ON_ERROR)],'','ikID=?d',[$attempt['ikID']]);
    profileReject(fn()=>profileSettle($id),'callback_profile_binding_missing');
    $db->update('IdempotencyKeys',['ikResponse'=>$attempt['ikResponse']],'','ikID=?d',[$attempt['ikID']]);
    $params=strToArray(profileOperation($id)['oParams']); unset($form['inbound_profile']);
    $params['checkout']=SecretCipher::encrypt(json_encode($form,JSON_THROW_ON_ERROR),hash('sha256','checkout:'.$id.':1:'.($_cfg['Const_Salt'] ?? '')));
    $db->update('Opers',['oParams'=>arrayToStr($params)],'','oID=?d',[$id]);
    profileReject(fn()=>profileSettle($id),'callback_profile_binding_missing');
    // A response arriving after current SCI changes cannot publish the invoice.
    $id=profileSetup($gateway); $calls=0;
    try {
        DepositCheckout::prepare($db,1,$id,static function(array $operation) use($id,&$calls):array {
            ++$calls; $form=profileForm($operation); profileConfig($id,['key'=>'changed-during-checkout']); return $form;
        }); throw new LogicException('Post-I/O profile drift published');
    } catch(CallbackValidationException $error) { financialAssert($error->reason==='callback_profile_configuration_changed','Pre-I/O SCI remains original result authority'); }
    financialAssert(DepositCheckout::storedForm(profileOperation($id))===null,'Changed profile invoice never saved');
    try { DepositCheckout::prepare($db,1,$id,fn()=>throw new LogicException('Provider resent')); }
    catch(RuntimeException $error) { financialAssert($error->getMessage()==='invoice_reconciliation_required','Ambiguous checkout cannot resend'); }
    financialAssert($calls===1,'Exactly one checkout attempt across profile drift/retry');
}
echo 'Financial inbound profile passed ('.$assertions." checks).\n";
