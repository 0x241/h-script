<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/fixtures/financial_runtime.php';
putenv('APP_DATA_KEY=synthetic-paykassa-checkout-key');

use HScript\Finance\CallbackValidationException;
use HScript\Finance\DepositCheckout;
use HScript\Finance\GatewaySettlement;
use HScript\Finance\IdempotencyConflict;
use HScript\Payment\Gateways\Paykassa;
use HScript\Payment\PaymentManager;

final class FinancialPaykassa extends Paykassa
{
    protected function sciRequest(array $config, array $fields): array
    {
        $GLOBALS['pkFinancialCalls'][]=$fields;
        return ['error'=>false,'data'=>$GLOBALS[$fields['func']==='sci_confirm_order' ? 'pkFinancialOrder' : 'pkFinancialInvoice']];
    }
}
function pkFinancialState(): string
{
    global $pdo;
    return hash('sha256',financialFingerprint().serialize($pdo->query('SELECT * FROM IdempotencyKeys ORDER BY ikID')->fetchAll())
        .serialize($pdo->query('SELECT * FROM Jobs ORDER BY jID')->fetchAll()));
}
function pkFinancialReject(callable $call, string $reason): void
{
    $before=pkFinancialState();
    try { $call(); throw new LogicException('Paykassa accepted '.$reason); }
    catch (CallbackValidationException $e) { financialAssert($e->reason===$reason,'fixed rejection '.$reason); }
    catch (IdempotencyConflict) { financialAssert($reason==='conflict','expected durable identity conflict'); }
    financialAssert(pkFinancialState()===$before,'Paykassa rejection changes no money/operation/history/registry/job');
}
function pkFinancialSetup(string $gateway='PKB'): array
{
    global $db,$_currs;
    financialReset(); $GLOBALS['pkFinancialCalls']=[];
    $fixture=json_decode(file_get_contents(__DIR__.'/fixtures/paykassa_order.json'),true,32,JSON_THROW_ON_ERROR);
    $asset=['PKB'=>'BTC','PKE'=>'ETH','PKL'=>'LTC'][$gateway];
    $system=['PKB'=>'BitCoin','PKE'=>'Ethereum','PKL'=>'Litecoin'][$gateway];
    $salt=time();
    $db->update('Currs',['cCID'=>$gateway,'cCurrID'=>$asset,'cNumDec'=>6,'cCASHINMode'=>2,'cMTS'=>timeToStamp($salt),
        'cParamsSCI'=>encodeArrayToStr($fixture['config'],'2'.$gateway.$salt,2)],'','cID=?d',[2]);
    $_currs=$db->fetchIDRows($db->select('Currs'),false,'cID');
    $id=opOperCreate(1,'CASHIN',2,'1.23');
    $GLOBALS['pkFinancialInvoice']=array_replace($fixture['created_invoice'],['order_id'=>(string)$id,'currency'=>$asset,'system'=>$system]);
    $GLOBALS['pkFinancialOrder']=array_replace($fixture['verified_order'],['order_id'=>(string)$id,'currency'=>$asset,'system'=>$system]);
    $manager=new PaymentManager($db);
    $manager->register($gateway,new FinancialPaykassa($gateway,$manager->definitions($gateway),[],$db));
    $public=DepositCheckout::prepare($db,1,$id,fn()=>$manager->processDeposit($gateway,[
        'sci'=>$fixture['config'],'sum'=>financialValue("SELECT oSum FROM Opers WHERE oID=$id"),'tag'=>$id,'memo'=>'Synthetic invoice',
    ]));
    $fields=['private_hash'=>'synthetic-private-token','order_id'=>(string)$id,'system'=>$system,'currency'=>$asset];
    $verified=$manager->handleCallback($gateway,['_raw_body'=>http_build_query($fields)],$fixture['config']);
    financialAssert($verified['outcome']==='verified' && $verified['correct'],'credentialed confirmation normalized for '.$gateway);
    return [$gateway,$id,$verified,$public,$manager];
}

foreach (['PKB','PKE','PKL'] as $gateway) {
    [$gateway,$id,$verified,$public,$manager]=pkFinancialSetup($gateway);
    financialAssert(array_keys($public)===['url','get','hash'],'browser form contains no private invoice metadata');
    $operation=$db->fetch1Row($db->select('Opers','*','oID=?d',[$id]));
    $saved=DepositCheckout::storedForm($operation);
    financialAssert($saved['paykassa_address']===$verified['accto'] && $saved['paykassa_invoice']===$verified['batch'],'encrypted invoice keeps receiver and provider identity');
    $calls=count($GLOBALS['pkFinancialCalls']);
    financialAssert(DepositCheckout::prepare($db,1,$id,fn()=>throw new LogicException('invoice resend'))===$public,'checkout retry returns original sanitized form');
    financialAssert(count($GLOBALS['pkFinancialCalls'])===$calls,'checkout retry performs no provider call');
    foreach ([
        ['sum','1.24','callback_amount_mismatch'],['curr','WRONG','callback_currency_mismatch'],
        ['accto','another-synthetic-address','callback_destination_mismatch'],['destination_tag','changed','callback_destination_mismatch'],
        ['merchant_id','123','callback_merchant_mismatch'],['provider_invoice_hash',hash('sha256','other'),'callback_invoice_identity_mismatch'],
        ['batch','579206','callback_invoice_identity_mismatch'],
    ] as [$field,$value,$reason]) pkFinancialReject(fn()=>GatewaySettlement::settle($db,$gateway,2,array_replace($verified,[$field=>$value])),$reason);
    financialAssert(GatewaySettlement::settle($db,$gateway,2,$verified)->data===['settled'=>true],'original invoice credits exact original amount');
    financialWallet(1,2,'1.230000');
    financialAssert(GatewaySettlement::settle($db,$gateway,2,$verified)->replayed,'exact provider order replays one credit');
    foreach ([['merchant_id','123','callback_merchant_mismatch'],['provider_invoice_hash',hash('sha256','other'),'callback_invoice_identity_mismatch'],
        ['accto','another-synthetic-address','callback_destination_mismatch'],['destination_tag','changed','callback_destination_mismatch'],['sum','1.24','conflict']]
        as [$field,$value,$reason]) pkFinancialReject(fn()=>GatewaySettlement::settle($db,$gateway,2,array_replace($verified,[$field=>$value])),$reason);
    $receipt=strToArray(financialValue("SELECT oParams2 FROM Opers WHERE oID=$id"));
    financialAssert(array_intersect_key($receipt,array_flip(['merchant_id','provider_invoice_hash','profile_key_hash','profile_timezone']))===[],'internal provider proof is excluded from legacy receipt');
    $row=$pdo->query("SELECT ikID,ikResponse FROM IdempotencyKeys WHERE ikNamespace='gateway:".strtolower($gateway).":currency:2:identity'")->fetch();
    $proof=json_decode($row['ikResponse'],true,32,JSON_THROW_ON_ERROR);
    financialAssert(!str_contains(serialize($proof),$saved['paykassa_address']) && !str_contains(serialize($proof),$saved['hash']),'durable proof contains receiver/link digests only');
    foreach (['merchant_hash','provider_invoice_hash','destination_tag_bound','contract'] as $missing) {
        $legacy=$proof;
        if ($missing==='contract') unset($legacy['data']['contract']); else unset($legacy['data']['contract'][$missing]);
        $db->update('IdempotencyKeys',['ikResponse'=>json_encode($legacy,JSON_THROW_ON_ERROR)],'','ikID=?d',[$row['ikID']]);
        pkFinancialReject(fn()=>GatewaySettlement::settle($db,$gateway,2,$verified),'callback_invoice_binding_missing');
        pkFinancialReject(fn()=>GatewaySettlement::settle($db,$gateway,2,array_replace($verified,['merchant_id'=>'123'])),'callback_invoice_binding_missing');
    }
}

// A historical URL-only checkout cannot reconstruct immutable receiver proof.
[$gateway,$id,$verified]=pkFinancialSetup();
$params=strToArray(financialValue("SELECT oParams FROM Opers WHERE oID=$id")); unset($params['checkout']);
$db->update('Opers',['oParams'=>arrayToStr($params)],'','oID=?d',[$id]);
pkFinancialReject(fn()=>GatewaySettlement::settle($db,$gateway,2,$verified),'callback_invoice_intent_missing');
echo "Financial Paykassa invoice contract passed ($assertions checks).\n";
