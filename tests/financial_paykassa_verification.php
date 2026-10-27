<?php

declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require __DIR__.'/fixtures/financial_runtime.php';
putenv('APP_DATA_KEY=synthetic-paykassa-verification-key');

use HScript\Finance\CallbackValidationException;
use HScript\Finance\DepositCheckout;
use HScript\Finance\GatewaySettlement;
use HScript\Finance\PaykassaVerification;
use HScript\Payment\Gateways\Paykassa;
use HScript\Payment\PaymentManager;

final class VerificationPaykassa extends Paykassa
{
    public static string $wire='';
    public static string $mutation='';
    protected function sciRequest(array $config,array $fields): array
    {
        global $pdo,$db;
        financialAssert(!$pdo->inTransaction(),'Paykassa transport has no open financial/quota transaction');
        $create=$fields['func']==='sci_create_order_get_data';
        $id=$create ? (int)$fields['order_id'] : (int)explode('-',$fields['private_hash'])[1];
        if (!$create) {
            file_put_contents(self::$wire,"confirm:$id\n",FILE_APPEND|LOCK_EX);
            usleep(100000); // Let concurrent first requests exercise the in-flight reservation.
        }
        $asset=['PKB'=>'BTC','PKE'=>'ETH','PKL'=>'LTC'][$this->id];
        $system=['PKB'=>'BitCoin','PKE'=>'Ethereum','PKL'=>'Litecoin'][$this->id];
        $hash='verification_invoice_'.$id;
        $address='syntheticOriginalAddress'.str_pad((string)$id,12,'0',STR_PAD_LEFT);
        $data=$create ? ['invoice'=>(string)(500000+$id),'order_id'=>(string)$id,'wallet'=>$address,
            'amount'=>'100.000000','currency'=>$asset,'system'=>$system,
            'url'=>'https://crypto.paykassa.pro/sci/index.php?hash='.$hash,'tag'=>false]
            : ['shop_id'=>'122','order_id'=>(string)$id,'transaction'=>(string)(500000+$id),
                'amount'=>'100.000000','currency'=>$asset,'system'=>$system,'address'=>$address,
                'tag'=>false,'hash'=>$hash,'partial'=>'no'];
        if (!$create && self::$mutation==='amount') $data['amount']='99.000000';
        if (!$create && self::$mutation==='configuration') verificationConfig(['key'=>'changed-after-remote']);
        return ['error'=>false,'data'=>$data];
    }
}
function verificationConfig(array $changes): void
{
    global $db;
    $currency=$db->fetch1Row($db->select('Currs','*','cID=?d',[2]));
    opDecodeCurrParams($currency,$pay,$sci,$api);
    $db->update('Currs',['cParamsSCI'=>encodeArrayToStr(array_replace($sci,$changes),
        '2'.$currency['cCID'].stampToTime($currency['cMTS']),2)],'','cID=?d',[2]);
}
function verificationManager(string $gateway='PKB'): PaymentManager
{
    global $db;
    $manager=new PaymentManager($db);
    $manager->register($gateway,new VerificationPaykassa($gateway,$manager->definitions($gateway),[],$db));
    return $manager;
}
function verificationSetup(string $gateway='PKB'): void
{
    global $db,$_currs;
    financialReset(); VerificationPaykassa::$mutation=''; putenv('PAYKASSA_VERIFICATION_BUDGET_PER_MINUTE');
    file_put_contents(VerificationPaykassa::$wire,'');
    $salt=time();
    $db->update('Currs',['cCID'=>$gateway,'cCurrID'=>['PKB'=>'BTC','PKE'=>'ETH','PKL'=>'LTC'][$gateway],
        'cNumDec'=>6,'cCASHINMode'=>2,'cCASHINComis'=>10,'cMTS'=>timeToStamp($salt),
        'cParamsSCI'=>encodeArrayToStr(['id'=>'122','key'=>'synthetic-verification-secret'],'2'.$gateway.$salt,2)],'','cID=?d',[2]);
    $_currs=$db->fetchIDRows($db->select('Currs'),false,'cID');
}
function verificationInvoice(string $gateway='PKB'): int
{
    global $db;
    $id=opOperCreate(1,'CASHIN',2,'100');
    $manager=verificationManager($gateway);
    DepositCheckout::prepare($db,1,$id,static function(array $operation) use($manager,$gateway): array {
        opDecodeCurrParams($operation,$pay,$sci,$api);
        return $manager->processDeposit($gateway,['sci'=>$sci,'sum'=>$operation['oSum'],'tag'=>$operation['oID']]);
    });
    return $id;
}
function verificationCallback(int $id,string $suffix='good',string $gateway='PKB'): array
{
    global $db;
    $currency=$db->fetch1Row($db->select('Currs','*','cID=?d',[2]));
    opDecodeCurrParams($currency,$pay,$sci,$api);
    return verificationManager($gateway)->handleCallback($gateway,['_raw_body'=>http_build_query([
        'private_hash'=>'fixture-'.$id.'-'.$suffix,'order_id'=>(string)$id,
        'currency'=>$currency['cCurrID'],'system'=>['PKB'=>'BitCoin','PKE'=>'Ethereum','PKL'=>'Litecoin'][$gateway],
    ])],$sci);
}
function verificationCalls(): int { return count(file(VerificationPaykassa::$wire,FILE_IGNORE_NEW_LINES) ?: []); }
function verificationMoney(): string
{
    global $pdo;

    $rows=[];
    foreach(['Users','Wallets','Opers','Hist','Deps','Jobs'] as $table) $rows[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll();
    $rows['financial_keys']=$pdo->query("SELECT * FROM IdempotencyKeys WHERE ikNamespace NOT LIKE 'verification:paykassa:%' ORDER BY ikID")->fetchAll();
    return hash('sha256',serialize($rows));
}
function verificationReject(callable $work,string $reason): void
{
    $before=verificationMoney();
    try { $work(); throw new LogicException('Paykassa verification accepted '.$reason); }
    catch (CallbackValidationException $error) { financialAssert($error->reason===$reason,'Preflight reason '.$reason.', got '.$error->reason); }
    catch (RuntimeException $error) { financialAssert($error->getMessage()===$reason,'Quota reason '.$reason.', got '.$error->getMessage()); }
    financialAssert(verificationMoney()===$before,'Verification refusal changes no financial operation/claim/history/job');
}
function verificationOrderData(int $id,callable $change): void
{
    global $pdo;
    $row=$pdo->query("SELECT ikID,ikResponse FROM IdempotencyKeys WHERE ikNamespace='verification:paykassa:order' AND ikOperationID=$id")->fetch();
    $statement=$pdo->prepare('UPDATE IdempotencyKeys SET ikResponse=? WHERE ikID=?');
    $statement->execute([json_encode($change(json_decode($row['ikResponse'],true,32,JSON_THROW_ON_ERROR)),JSON_THROW_ON_ERROR),$row['ikID']]);
}
if (($argv[1] ?? '')==='worker') {
    $_cfg=['UI_NumDec'=>2,'Const_IntCurr'=>0]; $_GS['client_ip']='127.0.0.1'; $_onload=[];
    $_currs=$db->fetchIDRows($db->select('Currs'),false,'cID'); VerificationPaykassa::$wire=$argv[3];
    for($attempt=0;;++$attempt) {
        try { $result=GatewaySettlement::settle($db,'PKB',2,verificationCallback((int)$argv[2])); break; }
        catch(RuntimeException $error) {
            if ($error->getMessage()!=='paykassa_verification_throttled' || $attempt>=59) throw $error;
            usleep(100000);
        }
    }
    echo json_encode($result->data,JSON_THROW_ON_ERROR); exit;
}
VerificationPaykassa::$wire=tempnam(sys_get_temp_dir(),'pk-verification-wire-');
chmod(VerificationPaykassa::$wire,0600);
try {
    foreach(['PKB','PKE','PKL'] as $gateway) {
        verificationSetup($gateway); $id=verificationInvoice($gateway);
        $verified=verificationCallback($id,'good',$gateway);
        financialAssert(verificationCalls()===1 && $verified['outcome']==='verified','One original credentialed confirmation '.$gateway);
        $db->update('Currs',['cCASHINComis'=>20,'cCASHINComisMin'=>50,'cCASHINComisMax'=>60,'cCASHINMode'=>0],'','cID=?d',[2]);
        GatewaySettlement::settle($db,$gateway,2,$verified); financialWallet(1,2,'90.000000');
        financialAssert(GatewaySettlement::settle($db,$gateway,2,verificationCallback($id,'good',$gateway))->replayed,'Authenticated token cache replays original credit');
        financialAssert(verificationCalls()===1,'Exact retry performs no remote work');
        verificationReject(fn()=>verificationCallback($id,'forged',$gateway),'paykassa_verification_throttled');
        financialAssert(verificationCalls()===1,'Another token cannot use an invoice-wide ACK cache');
        $receipt=strToArray(financialValue("SELECT oParams2 FROM Opers WHERE oID=$id"));
        financialAssert(!isset($receipt['profile_key_hash'],$receipt['profile_timezone'])
            && !array_intersect_key($receipt,array_flip(['profile_key_hash','profile_timezone','inbound_profile'])),'Private profile omitted from receipt');
        verificationConfig(['key'=>'rotated-key']);
        verificationReject(fn()=>verificationCallback($id,'good',$gateway),'callback_profile_configuration_changed');
        financialAssert(verificationCalls()===1,'Key rotation refuses cached ACK before remote work');
    }
    // Local original proof is mandatory before spending merchant credentials.
    verificationSetup(); $id=verificationInvoice();
    verificationReject(fn()=>verificationCallback(999999),'callback_order_unavailable');
    $empty=opOperCreate(1,'CASHIN',2,100);
    verificationReject(fn()=>verificationCallback($empty),'callback_profile_binding_missing');
    $db->update('Opers',['oComis'=>20],'','oID=?d',[$id]);
    verificationReject(fn()=>verificationCallback($id),'callback_inbound_intent_mismatch');
    financialAssert(verificationCalls()===0,'Missing/nonexistent/edited original invoice causes zero credentialed requests');
    // Errors consume one bounded slot, without treating the invoice as authenticated.
    foreach(['amount','configuration'] as $mutation) {
        verificationSetup(); $id=verificationInvoice(); VerificationPaykassa::$mutation=$mutation;
        verificationReject(fn()=>verificationCallback($id),$mutation==='amount'?'callback_paykassa_original_mismatch':'callback_profile_configuration_changed');
        financialAssert(verificationCalls()===1,'Remote mutation attempted exactly once');
        $data=json_decode(financialValue("SELECT ikResponse FROM IdempotencyKeys WHERE ikNamespace='verification:paykassa:order'"),true,32,JSON_THROW_ON_ERROR);
        financialAssert($data['token_hash']==='' && $data['verified_at']===0,'Unvalidated remote response never becomes token cache authority');
    }
    // Default quota ten per minute and a separate thirty-second invoice cooldown.
    verificationSetup(); $ids=[];
    for($i=0;$i<11;++$i) $ids[]=verificationInvoice();
    for($i=0;$i<10;++$i) verificationCallback($ids[$i]);
    // Fix only the synthetic window to avoid a wall-clock minute-boundary flake.
    $pdo->exec("UPDATE IdempotencyKeys SET ikResponse='".json_encode(['version'=>1,'window'=>intdiv(time(),60)*60,'count'=>10],JSON_THROW_ON_ERROR)."' WHERE ikNamespace='verification:paykassa:merchant'");
    verificationReject(fn()=>verificationCallback($ids[10]),'paykassa_verification_throttled');
    financialAssert(verificationCalls()===10,'Merchant budget stops eleventh remote request');
    financialAssert((int)financialValue("SELECT COUNT(*) FROM IdempotencyKeys WHERE ikNamespace='verification:paykassa:merchant'")===1,'Fixed one account counter across tokens/minutes');
    financialAssert((int)financialValue("SELECT COUNT(*) FROM IdempotencyKeys WHERE ikNamespace='verification:paykassa:order'")===10,'Rejected quota does not retain attacker token/order claims');
    verificationOrderData($ids[0],fn($data)=>array_replace($data,['last'=>time()-31]));
    $pdo->exec("UPDATE IdempotencyKeys SET ikResponse='".json_encode(['version'=>1,'window'=>0,'count'=>10],JSON_THROW_ON_ERROR)."' WHERE ikNamespace='verification:paykassa:merchant'");
    verificationCallback($ids[0],'new'); financialAssert(verificationCalls()===11,'Expired cooldown/window permits fresh read-only verification');
    $serialized=serialize($pdo->query("SELECT ikResponse FROM IdempotencyKeys WHERE ikNamespace LIKE 'verification:paykassa:%'")->fetchAll());
    foreach(['private_hash','synthetic-verification-secret','syntheticOriginalAddress','fixture-'] as $raw)
        financialAssert(!str_contains($serialized,$raw),'Quota/cache contains no raw token/address/credentials');
    verificationOrderData($ids[0],fn($data)=>array_replace($data,['token_hash'=>'INVALID']));
    verificationReject(fn()=>verificationCallback($ids[0]),'paykassa_verification_unavailable');
    foreach(['0','1001','unlimited'] as $budget) {
        putenv('PAYKASSA_VERIFICATION_BUDGET_PER_MINUTE='.$budget);
        verificationReject(fn()=>verificationCallback($ids[1]),'paykassa_verification_policy_invalid');
    }
    putenv('PAYKASSA_VERIFICATION_BUDGET_PER_MINUTE=100');
    verificationSetup(); putenv('PAYKASSA_VERIFICATION_BUDGET_PER_MINUTE=100'); $id=verificationInvoice(); verificationCallback($id);
    $pdo->exec("UPDATE IdempotencyKeys SET ikExpiresAt=".(time()-1)." WHERE ikNamespace='verification:paykassa:order'");
    verificationCallback($id); financialAssert(verificationCalls()===2,'Expired cached token requires fresh bounded verification');
    financialAssert((int)financialValue("SELECT COUNT(*) FROM IdempotencyKeys WHERE ikNamespace='verification:paykassa:order'")===1,'Expired row removed/replaced without permanent token namespaces');
    // Archive fences intent→claim races and bounds an abandoned verification lease.
    verificationSetup();$id=verificationInvoice();$verified=verificationCallback($id);GatewaySettlement::settle($db,'PKB',2,$verified);
    HScript\Payment\Provider\CurrencyArchiveSchema::ensure($db);
    $config=['id'=>'122','key'=>'synthetic-verification-secret'];$context=PaykassaVerification::intent($db,'PKB',$id,$config);
    verificationOrderData($id,static fn($data)=>array_replace($data,['last'=>time()-PaykassaVerification::COOLDOWN-1]));
    $unverified=PaykassaVerification::claim($db,$context,'synthetic-unverified-token');financialAssert(!$unverified['cached'],'verification lease begins before I/O');
    $archive=static function()use($db,$pdo):void{HScript\Database\TransactionScope::run($pdo,static fn()=>HScript\Payment\Provider\CurrencyArchive::archive($db,$db->fetch1Row($db->query('SELECT * FROM Currs WHERE cID=2 FOR UPDATE'))));};
    try{$archive();throw new LogicException('Active verification archived');}catch(HScript\Payment\Provider\ProviderException$e){financialAssert($e->reason==='currency_archive_invoice','active bounded verification blocks archive');}
    verificationOrderData($id,static fn($data)=>array_replace($data,['last'=>time()-PaykassaVerification::LEASE-1]));$archive();
    $snapshot=static function()use($pdo):string{$out=[];foreach(['Currs','Opers','Wallets','Hist','Jobs','IdempotencyKeys']as$t)$out[$t]=$pdo->query('SELECT * FROM '.$t.' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);return hash('sha256',serialize($out));};
    $before=$snapshot();$calls=verificationCalls();financialAssert(verificationCallback($id)['correct']&&verificationCalls()===$calls&&$snapshot()===$before,'expired abandoned lease retains old authenticated digest read-only');
    try{PaykassaVerification::claim($db,$context,'synthetic-new-forged-token');throw new LogicException('Stale prearchive context claimed');}catch(CallbackValidationException$e){financialAssert($e->reason==='archived_callback_reconciliation_required'&&$before===$snapshot()&&verificationCalls()===$calls,'intent→claim barrier rechecks current archive before any mutation or I/O');}
    try{PaykassaVerification::complete($db,$context,$unverified,$verified);throw new LogicException('Late verification wrote archive');}catch(CallbackValidationException$e){financialAssert($before===$snapshot(),'late expired/archived response cannot complete old claim');}
    // Twelve first requests eventually converge, then a fresh process has zero IO.
    verificationSetup(); $id=verificationInvoice(); $workers=[];
    for($i=0;$i<12;++$i) {
        $process=proc_open([PHP_BINARY,__FILE__,'worker',(string)$id,VerificationPaykassa::$wire],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start verification worker');
        $workers[]=[$process,$pipes];
    }
    foreach($workers as [$process,$pipes]) {
        $output=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        financialAssert(proc_close($process)===0,'Concurrent verification eventually succeeds: '.$error.$output);
        financialAssert(json_decode($output,true,32,JSON_THROW_ON_ERROR)===['settled'=>true],'Concurrent original reply stable');
    }
    financialAssert(verificationCalls()===1,'Twelve first requests make one remote confirmation');
    financialWallet(1,2,'90.000000');
    financialAssert((int)financialValue("SELECT COUNT(*) FROM Hist WHERE hOper='BAL'")===1,'Twelve first requests credit once');
    $before=verificationMoney();
    $process=proc_open([PHP_BINARY,__FILE__,'worker',(string)$id,VerificationPaykassa::$wire],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $output=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    financialAssert(proc_close($process)===0 && json_decode($output,true,32,JSON_THROW_ON_ERROR)===['settled'=>true],'Fresh process exact retry succeeds: '.$error);
    financialAssert(verificationCalls()===1 && verificationMoney()===$before,'Fresh process cache replay preserves one effect and zero IO');
    echo 'Financial Paykassa verification passed ('.$assertions." checks, 12 concurrent workers).\n";
} finally { unlink(VerificationPaykassa::$wire); }
