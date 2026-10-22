<?php

declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require __DIR__.'/fixtures/financial_runtime.php';
putenv('APP_DATA_KEY=synthetic-inbound-intent-key');

use HScript\Finance\CallbackValidationException;
use HScript\Finance\DepositCheckout;
use HScript\Finance\GatewaySettlement;
use HScript\Payment\PaymentManager;
use HScript\Security\SecretCipher;

function inboundSigned(int $id, bool $pending=false): array
{
    global $db;
    $body=http_build_query(['merchant'=>'fixture-merchant','ipn_version'=>'1.0','ipn_id'=>'synthetic-contract-ipn','ipn_mode'=>'hmac','ipn_type'=>'simple','invoice'=>(string)$id,
        'txn_id'=>'fixture-inbound-fee-'.$id,'amount1'=>'100.00','currency1'=>'BTC','status'=>$pending?'0':'100']);
    return (new PaymentManager($db))->handleCallback('CP',['_raw_body'=>$body,'_server'=>['HTTP_HMAC'=>hash_hmac('sha512',$body,'fixture-key')]],
        ['id'=>'fixture-merchant','key'=>'fixture-key']);
}
if (($argv[1] ?? '')==='worker') {
    $_currs=$db->fetchIDRows($db->select('Currs'),false,'cID');
    $_cfg=['UI_NumDec'=>2,'Const_IntCurr'=>0]; $_GS['client_ip']='127.0.0.1'; $_onload=[];
    echo json_encode(GatewaySettlement::settle($db,'CP',2,inboundSigned((int)$argv[2]))->data,JSON_THROW_ON_ERROR);
    exit;
}
function inboundState(): string
{
    global $pdo;
    return hash('sha256',financialFingerprint().serialize($pdo->query('SELECT * FROM IdempotencyKeys ORDER BY ikID')->fetchAll())
        .serialize($pdo->query('SELECT * FROM Jobs ORDER BY jID')->fetchAll()));
}
function inboundReject(callable $work, string $reason): void
{
    $before=inboundState();
    try { $work(); throw new LogicException('Inbound contract accepted '.$reason); }
    catch (CallbackValidationException $error) { financialAssert($error->reason===$reason,'fixed inbound rejection '.$reason.', got '.$error->reason); }
    financialAssert(inboundState()===$before,'Rejected inbound request changes no claims/money/history/jobs');
}
function inboundSetup(array $fees=[]): int
{
    global $db,$_currs;
    financialReset(); $salt=time();
    $db->update('Currs',array_replace(['cCID'=>'CP','cCurrID'=>'BTC','cNumDec'=>2,'cCASHINMode'=>2,'cMTS'=>timeToStamp($salt),
        'cParamsSCI'=>encodeArrayToStr(['id'=>'fixture-merchant','key'=>'fixture-key'],'2CP'.$salt,2)],$fees),'','cID=?d',[2]);
    $_currs=$db->fetchIDRows($db->select('Currs'),false,'cID');
    return opOperCreate(1,'CASHIN',2,100);
}
function inboundForm(array $operation): array
{
    return ['url'=>'https://fixture.invalid/pay','merchant'=>'fixture-merchant','amountf'=>$operation['oSum']];
}
function inboundSnapshot(string $namespace): array
{
    global $pdo;
    $row=$pdo->query("SELECT ikID,ikResponse FROM IdempotencyKeys WHERE ikNamespace=".$pdo->quote($namespace))->fetch();
    return [$row,json_decode($row['ikResponse'],true,32,JSON_THROW_ON_ERROR)];
}
function inboundReplace(array $row,array $response): void
{
    global $db;
    $db->update('IdempotencyKeys',['ikResponse'=>json_encode($response,JSON_THROW_ON_ERROR)],'','ikID=?d',[$row['ikID']]);
}
function inboundSaveForm(int $id,array $form): void
{
    global $db,$_cfg;
    $params=strToArray(financialValue("SELECT oParams FROM Opers WHERE oID=$id"));
    $params['checkout']=SecretCipher::encrypt(json_encode($form,JSON_THROW_ON_ERROR),hash('sha256','checkout:'.$id.':1:'.($_cfg['Const_Salt'] ?? '')));
    $db->update('Opers',['oParams'=>arrayToStr($params)],'','oID=?d',[$id]);
}

// Freeze before provider I/O, then preserve the agreed fee through policy edits.
foreach ([
    [[], '0.000000','100.000000'],
    [['cCASHINComis'=>10], '10.000000','90.000000'],
    [['cCASHINComis'=>1,'cCASHINComisMin'=>7], '7.000000','93.000000'],
    [['cCASHINComis'=>10,'cCASHINComisMax'=>3], '3.000000','97.000000'],
] as [$fees,$commission,$net]) {
    $id=inboundSetup($fees); $calls=0;
    $public=DepositCheckout::prepare($db,1,$id,static function (array $operation) use (&$calls,$commission): array {
        global $pdo;
        $calls++;
        financialAssert(!$pdo->inTransaction(),'Provider I/O runs after committed inbound intent');
        [$row,$response]=inboundSnapshot('invoice:attempt');
        financialAssert($response['data']['contract']['commission']===HScript\Finance\RequestDecimal::normalize($commission,true),'Durable original fee exists before provider I/O');
        financialAssert($operation['oComis']===$commission,'Provider request receives the locked original operation');
        return inboundForm($operation);
    });
    financialAssert(!isset($public['inbound_intent']),'Public checkout excludes internal money proof');
    $saved=DepositCheckout::storedForm($db->fetch1Row($db->select('Opers','*','oID=?d',[$id])));
    [$attempt,$response]=inboundSnapshot('invoice:attempt');
    financialAssert($saved['inbound_intent']===$response['data']['contract'],'Protected checkout agrees with durable pre-I/O intent');
    $verified=inboundSigned($id);
    $db->update('Currs',['cCASHINComis'=>20,'cCASHINComisMin'=>50,'cCASHINComisMax'=>60,'cCASHINMin'=>200,'cCASHINMax'=>50,'cCASHINMode'=>0],'','cID=?d',[2]);
    $_currs[2]['cCASHINComis']=99; $_currs[2]['cNumDec']=6;
    financialAssert(DepositCheckout::prepare($db,1,$id,static fn()=>throw new LogicException('Invoice resend'))===$public,'Policy edits preserve saved checkout without resending');
    financialAssert(GatewaySettlement::settle($db,'CP',2,$verified)->data===['settled'=>true],'Existing invoice settles its agreed fee despite later policy/range edits');
    financialWallet(1,2,$net);
    financialAssert(financialValue("SELECT oSum FROM Opers WHERE oID=$id")==='100.000000' && financialValue("SELECT oComis FROM Opers WHERE oID=$id")===$commission,'No automatic gross/commission replacement');
    financialAssert(!isset(strToArray(financialValue("SELECT oParams2 FROM Opers WHERE oID=$id"))['sum']),'Automated receipt sum is excluded from legacy fee recalculation');
    $before=inboundState();
    financialAssert(GatewaySettlement::settle($db,'CP',2,$verified)->replayed && inboundState()===$before,'Completed exact replay remains stable after policy changes');
    financialAssert($calls===1,'Exactly one provider invoice request');
}

// An admin operation edit after checkout, unlike a policy edit, needs review.
foreach (['oComis'=>'20','ouID'=>2] as $field=>$value) {
    $id=inboundSetup(); DepositCheckout::prepare($db,1,$id,'inboundForm'); $verified=inboundSigned($id);
    $db->update('Opers',[$field=>$value],'','oID=?d',[$id]);
    inboundReject(fn()=>GatewaySettlement::settle($db,'CP',2,$verified),$field==='ouID'?'callback_checkout_unavailable':'callback_inbound_intent_mismatch');
}

// Current fee cannot be substituted between pending and final stages.
foreach ([false,true] as $checkout) {
    $id=inboundSetup(['cCASHINComis'=>10]);
    if ($checkout) DepositCheckout::prepare($db,1,$id,'inboundForm');
    financialAssert(GatewaySettlement::settle($db,'CP',2,inboundSigned($id,true))->data===['settled'=>false],'Pending signed identity has no credit');
    $db->update('Opers',['oComis'=>'20'],'','oID=?d',[$id]);
    inboundReject(fn()=>GatewaySettlement::settle($db,'CP',2,inboundSigned($id)),'callback_inbound_intent_mismatch');
    $db->update('Opers',['oComis'=>'10'],'','oID=?d',[$id]);
    financialAssert(GatewaySettlement::settle($db,'CP',2,inboundSigned($id))->data===['settled'=>true],'Restored signed original commission completes once');
    financialWallet(1,2,'90.000000');
    financialAssert(GatewaySettlement::settle($db,'CP',2,inboundSigned($id))->replayed,'Restart-safe signed original commission');
}

// No-checkout profile freezes only when its first signed identity is accepted.
$id=inboundSetup(); $db->update('Opers',['oComis'=>'7'],'','oID=?d',[$id]);
$receipt=strToArray(financialValue("SELECT oParams2 FROM Opers WHERE oID=$id")); $receipt['sum']='80';
$db->update('Opers',['oParams2'=>arrayToStr($receipt)],'','oID=?d',[$id]);
$db->update('Currs',['cCASHINComis'=>20],'','cID=?d',[2]);
financialAssert(GatewaySettlement::settle($db,'CP',2,inboundSigned($id))->data===['settled'=>true],'Configured no-checkout uses original locked operation at first signed acceptance, not receipt/policy');
financialWallet(1,2,'93.000000');

// A post-provider operation edit rejects result save and never resends.
foreach (['oComis'=>'20','oSum'=>'99'] as $field=>$value) {
    $id=inboundSetup(); $calls=0;
    try {
        DepositCheckout::prepare($db,1,$id,static function (array $operation) use ($id,$field,$value,&$calls): array {
            global $db; $calls++; $db->update('Opers',[$field=>$value],'','oID=?d',[$id]); return inboundForm($operation);
        });
        throw new LogicException('Changed post-I/O money accepted');
    } catch (CallbackValidationException $error) { financialAssert($error->reason==='callback_inbound_intent_mismatch','Post-I/O current operation change refuses saving invoice'); }
    financialAssert((int)financialValue("SELECT COUNT(*) FROM IdempotencyKeys WHERE ikNamespace='invoice:result'")===0,'Failed invoice-result claim rolls back');
    financialAssert(!isset(strToArray(financialValue("SELECT oParams FROM Opers WHERE oID=$id"))['checkout']),'Changed invoice not published');
    try { DepositCheckout::prepare($db,1,$id,static function () use (&$calls): array { $calls++; return []; }); throw new LogicException('Ambiguous invoice resend'); }
    catch (RuntimeException $error) { financialAssert($error->getMessage()==='invoice_reconciliation_required','Unknown external invoice response requires reconciliation'); }
    financialAssert($calls===1,'Post-I/O divergence never resends provider request');
    if ($field==='oComis') inboundReject(fn()=>GatewaySettlement::settle($db,'CP',2,inboundSigned($id)),'callback_invoice_intent_missing');
}

// Retry must re-read operation money but must not take the current fee policy.
$id=inboundSetup(); DepositCheckout::prepare($db,1,$id,'inboundForm');
$db->update('Opers',['oComis'=>'1'],'','oID=?d',[$id]);
inboundReject(fn()=>DepositCheckout::prepare($db,1,$id,static fn()=>throw new LogicException('Invoice resend')),'callback_inbound_intent_mismatch');
$db->update('Opers',['oComis'=>'0'],'','oID=?d',[$id]);

// Legacy, malformed, negative, hiddenfraction and noncanonical attempt proofs
// cannot be inferred from a currently readable encrypted invoice.
[$row,$response]=inboundSnapshot('invoice:attempt');
foreach ([null,[],['commission'=>1.0],['commission'=>'0e0'],['commission'=>' 0'],['commission'=>'+0'],['commission'=>'-1'],
    ['commission'=>'0.001'],['commission'=>'101'],['gross'=>'100'],['scale'=>'2'],['owner_id'=>0],['version'=>0]] as $change) {
    $changed=$response;
    if ($change===null) unset($changed['data']['contract']); elseif ($change===[]) $changed['data']['contract']=[];
    else $changed['data']['contract']=array_replace($changed['data']['contract'],$change);
    inboundReplace($row,$changed);
    inboundReject(fn()=>GatewaySettlement::settle($db,'CP',2,inboundSigned($id)),'callback_invoice_intent_missing');
    inboundReject(fn()=>DepositCheckout::prepare($db,1,$id,static fn()=>throw new LogicException('Invoice resend')),'callback_invoice_intent_missing');
}
inboundReplace($row,$response);
$form=DepositCheckout::storedForm($db->fetch1Row($db->select('Opers','*','oID=?d',[$id])));
$old=$form; unset($old['inbound_intent']); inboundSaveForm($id,$old);
inboundReject(fn()=>GatewaySettlement::settle($db,'CP',2,inboundSigned($id)),'callback_invoice_intent_missing');
inboundSaveForm($id,$form);
$db->delete('IdempotencyKeys','ikID=?d',[$row['ikID']]);
inboundReject(fn()=>GatewaySettlement::settle($db,'CP',2,inboundSigned($id)),'callback_invoice_intent_missing');
inboundReject(fn()=>DepositCheckout::prepare($db,1,$id,static fn()=>throw new LogicException('Historical invoice resend')),'callback_invoice_intent_missing');

// Old accepted identities cannot acknowledge even a cached completed credit.
foreach ([true,false] as $pending) {
    $id=inboundSetup(); GatewaySettlement::settle($db,'CP',2,inboundSigned($id,$pending));
    [$row,$response]=inboundSnapshot('gateway:cp:currency:2:identity');
    foreach ([null,['commission'=>1.0],['commission'=>'0e0'],['commission'=>'0.001'],['commission'=>'101'],['operation_id'=>$id+1],['asset'=>'ETH']] as $change) {
        $changed=$response;
        if ($change===null) unset($changed['data']['contract']['inbound_intent']);
        else $changed['data']['contract']['inbound_intent']=array_replace($changed['data']['contract']['inbound_intent'],$change);
        inboundReplace($row,$changed);
        inboundReject(fn()=>GatewaySettlement::settle($db,'CP',2,inboundSigned($id)),($change['asset'] ?? '')==='ETH'?'callback_currency_mismatch':'callback_inbound_binding_missing');
    }
    inboundReplace($row,$response);
    financialAssert(GatewaySettlement::settle($db,'CP',2,inboundSigned($id))->data===['settled'=>true],'Restored original identity never substitutes fee');
    financialWallet(1,2,'100.000000');
}

// Concurrent authenticated callbacks and fresh worker replay commit one net.
$id=inboundSetup(['cCASHINComis'=>10]); DepositCheckout::prepare($db,1,$id,'inboundForm');
$db->update('Currs',['cCASHINComis'=>20],'','cID=?d',[2]);
$processes=[];
for ($i=0;$i<12;$i++) {
    $process=proc_open([PHP_BINARY,__FILE__,'worker',(string)$id],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start inbound worker');
    $processes[]=[$process,$pipes];
}
foreach ($processes as [$process,$pipes]) {
    $output=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    financialAssert(proc_close($process)===0,'Concurrent inbound worker succeeds: '.$error.$output);
    financialAssert(json_decode($output,true,32,JSON_THROW_ON_ERROR)===['settled'=>true],'Concurrent reply is stable');
}
financialWallet(1,2,'90.000000');
financialAssert((int)financialValue("SELECT COUNT(*) FROM Hist WHERE hOper='BAL'")===1,'Exactly one financial credit under concurrency');
financialAssert(financialValue("SELECT oComis FROM Opers WHERE oID=$id")==='10.000000','Concurrent workers preserve original fee');
financialAssert(GatewaySettlement::settle($db,'CP',2,inboundSigned($id))->replayed,'Callback result survives worker process restart');
echo 'Financial inbound intent tests passed ('.$assertions." checks, 12 concurrent workers).\n";
