<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/fixtures/financial_runtime.php';

use HScript\Finance\GatewaySettlement;
use HScript\Finance\IdempotencyConflict;
use HScript\Finance\WithdrawalDispatcher;
use HScript\Finance\RequestDecimal;
use HScript\Finance\DepositCheckout;

if (($argv[1] ?? '') === 'worker') {
    $_currs = $db->fetchIDRows($db->select('Currs'), false, 'cID');
    $_cfg = ['UI_NumDec'=>2,'Const_IntCurr'=>0]; $_GS['client_ip']='127.0.0.1'; $_onload=[];
    $request = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
    if ($request['kind']==='transfer') {
        $result=(new HScript\Finance\IdempotencyStore($db))->execute('fixture:transfer', $request['key'], $request['from'], HScript\Finance\IdempotencyRequest::fingerprint($request), static function () use ($request) {
            $id=HScript\Finance\FinancialOperationException::check(opOperCreate($request['from'],'TR',1,1,['uid2'=>$request['to']],'',true));
            return new HScript\Finance\IdempotencyResult(['id'=>$id],201,$id);
        });
        echo json_encode($result->data,JSON_THROW_ON_ERROR); exit;
    }
    $result = match ($request['kind']) {
        'accrual' => opDepoCharge($request['deposit']),
        'callback' => GatewaySettlement::settle($db, 'CP', 2, $request['callback'])->data,
        'confirm' => opOperConfirm(1, $request['id'], [], true),
        default => throw new LogicException('Unknown fixture worker'),
    };
    echo json_encode($result, JSON_THROW_ON_ERROR);
    exit;
}

function concurrentFinancial(array $request, int $workers = 12, bool $alternate = false): array
{
    $processes = [];
    for ($i = 0; $i < $workers; $i++) {
        if ($alternate) $request=['kind'=>'transfer','key'=>'paired-request','from'=>$i % 2 + 1,'to'=>2 - $i % 2];
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', json_encode($request, JSON_THROW_ON_ERROR)], [1=>['pipe','w'],2=>['pipe','w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start fixture worker');
        $processes[] = [$process, $pipes];
    }
    $results = [];
    foreach ($processes as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) throw new RuntimeException('Fixture worker failed: ' . $error . $output);
        $results[] = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
    }
    return $results;
}

foreach (['10','010.00','1e1',10.0] as $value) financialAssert(RequestDecimal::normalize($value)==='10','decimal equivalent request');
foreach (['NaN','1e99','1e-9',true,[], '-1','1000000000000'] as $value) {
    try { RequestDecimal::normalize($value); throw new LogicException('Invalid request accepted'); }
    catch (InvalidArgumentException) { $assertions++; }
}
financialReset(); opChangeBalance(1,1,100,0,0);
$before = financialFingerprint();
$pdo->beginTransaction();
financialAssert(opChangeBalance(1,1,-10,0,10)===true && $pdo->inTransaction(),'money participates without committing caller');
$pdo->rollBack();
financialAssert(financialFingerprint()===$before,'outer rollback restores money and history');
$id=opOperCreate(1,'CASHOUT',1,10,[],'',false,true);
$results=concurrentFinancial(['kind'=>'confirm','id'=>$id]);
financialAssert(count(array_filter($results,static fn($r)=>$r===true))===12,'all confirmation retries succeed');
financialWallet(1,1,'90.000000','0.000000','10.000000');

financialReset(); opChangeBalance(1,1,100,0,0); opChangeBalance(2,1,100,0,0);
$results=concurrentFinancial([],12,true);
financialAssert(count(array_unique(array_column($results,'id')))===2,'opposite transfers retain one source per owner');
financialAssert((int)financialValue('SELECT COUNT(*) FROM Opers')===4,'opposite transfers have exactly two pairs');
financialWallet(1,1,'100.000000'); financialWallet(2,1,'100.000000');

financialReset(); opChangeBalance(1,1,100,0,0);
$_currs[2]['cEXIn']=10; $db->update('Currs',['cEXIn'=>10],'','cID=?d',[2]);
$id=opOperCreate(1,'EX',1,10,['cid2'=>2]); opOperConfirm(1,$id);
$before=financialFingerprint(); $db->rejectExchangeChild=true;
financialAssert(opOperComplete(1,$id)==='db_error','exchange child failure propagates');
financialAssert(financialFingerprint()===$before,'failed exchange restores parent and both wallets');
$db->rejectExchangeChild=false;
financialAssert(opOperComplete(1,$id)===true && opOperComplete(1,$id)===true,'exchange retries commit one pair');
financialAssert((int)financialValue("SELECT COUNT(*) FROM Opers WHERE oOper='EXIN'")===1,'one exchange child');

financialReset(); $_cfg['Const_IntCurr']=1; $_currs[2]['cCASHINMode']=2;
$db->update('Currs',['cCASHINMode'=>2],'','cID=?d',[2]);
$id=opOperCreate(1,'CASHIN',2,20); $before=financialFingerprint(); $db->rejectExchangeChild=true;
$automatic=['correct'=>true,'authenticated'=>true,'outcome'=>'verified','profile_key_hash'=>hash('sha256','fixture-key'),'profile_timezone'=>'','tag'=>$id,'batch'=>'synthetic-auto-exchange','sum'=>'20','curr'=>'EUR','accto'=>'fixture-merchant'];
try { GatewaySettlement::settle($db,'CP',2,$automatic); throw new LogicException('Incomplete automatic exchange accepted'); }
catch (HScript\Finance\FinancialOperationException $e) { financialAssert($e->getMessage()==='db_error','automatic exchange failure propagates'); }
financialAssert(financialFingerprint()===$before,'automatic exchange failure rolls back cashin credit');
$db->rejectExchangeChild=false;
financialAssert(GatewaySettlement::settle($db,'CP',2,$automatic)->data===['settled'=>true],'automatic exchange can settle after failure');
financialAssert((int)financialValue("SELECT COUNT(*) FROM Opers WHERE oOper='EXIN'")===1,'callback creates one automatic exchange child');

financialReset(); $_cfg['Const_IntCurr']=1; opChangeBalance(1,2,20,0,0);
$id=opOperCreate(1,'CASHOUT',2,20); opOperConfirm(1,$id);
$before=financialFingerprint(); $db->rejectExchangeChild=true;
financialAssert(opOperCancel(1,$id)==='db_error','cancel automatic exchange failure propagates');
financialAssert(financialFingerprint()===$before,'failed cancellation restores operation and both wallets');
financialWallet(1,2,'0.000000','0.000000','20.000000');
$db->rejectExchangeChild=false;
financialAssert(opOperCancel(1,$id)===true,'failed cancellation remains retryable');
financialWallet(1,2,'0.000000'); financialWallet(1,1,'40.000000');
financialAssert((int)financialValue("SELECT COUNT(*) FROM Opers WHERE oOper='EXIN'")===1,'cancel creates one automatic exchange child');
financialAssert((int)financialValue("SELECT oState FROM Opers WHERE oID=$id")===5,'cancel state commits with conversion');

financialReset(); opChangeBalance(1,1,100,0,0);
$did=opDepoCreate(1,1,100,0,1); $deposit=financialDepo($did);
$results=concurrentFinancial(['kind'=>'accrual','deposit'=>$deposit]);
financialAssert(count(array_filter($results,static fn($r)=>$r===true))===12,'concurrent accrual boundary replays');
financialAssert(financialValue("SELECT dNPer FROM Deps WHERE dID=$did")===1,'one charged period');
financialWallet(1,1,'10.000000','100.000000');
financialAssert(opDepoCharge($deposit)===true,'stale accrual snapshot replays after workers exit');
financialWallet(1,1,'10.000000','100.000000');

financialReset(); $_currs[2]['cCASHINMode']=2;
$db->update('Currs',['cCASHINMode'=>2],'','cID=?d',[2]);
$id=opOperCreate(1,'CASHIN',2,25);
$callback=['correct'=>true,'authenticated'=>true,'outcome'=>'pending','profile_key_hash'=>hash('sha256','fixture-key'),'profile_timezone'=>'','tag'=>$id,'batch'=>'synthetic-tx-1','sum'=>'25.00','curr'=>'EUR','accto'=>'fixture-merchant','accfrom'=>'fixture-sender','wait'=>true];
financialAssert(GatewaySettlement::settle($db,'CP',2,$callback)->data===['settled'=>false],'pending callback commits no credit');
financialAssert(GatewaySettlement::settle($db,'CP',2,$callback)->replayed,'pending callback replays');
financialAssert((int)financialValue('SELECT COUNT(*) FROM Wallets WHERE wuID=1')===0,'pending has no wallet effect');
$callback['wait']=false; $callback['outcome']='verified';
$results=concurrentFinancial(['kind'=>'callback','callback'=>$callback]);
financialAssert(count(array_filter($results,static fn($r)=>$r===['settled'=>true]))===12,'concurrent settlement callbacks return original result');
financialWallet(1,2,'25.000000');
financialAssert(GatewaySettlement::settle($db,'CP',2,$callback)->replayed,'settled callback survives worker restart');
$other=opOperCreate(2,'CASHIN',2,25); $changed=$callback; $changed['tag']=$other;
try { GatewaySettlement::settle($db,'CP',2,$changed); throw new LogicException('Batch redirected'); } catch (IdempotencyConflict) { $assertions++; }
$changed=$callback; $changed['sum']='26';
try { GatewaySettlement::settle($db,'CP',2,$changed); throw new LogicException('Amount changed'); } catch (IdempotencyConflict) { $assertions++; }
financialWallet(1,2,'25.000000');

financialReset(); $_cfg+=['Ref__Perc'=>'5','Ref_Type'=>0]; $_onload=['RefSys'=>1];
$jobQueue=new HScript\Queue\JobQueue($db);
$id=opOperCreate(1,'CASHIN',2,20);
FinancialFixtureView::$queueMail=true; $db->abortEmailTransactionOnce=true;
$notificationCallback=['correct'=>true,'authenticated'=>true,'outcome'=>'verified','profile_key_hash'=>hash('sha256','fixture-key'),'profile_timezone'=>'','tag'=>$id,'batch'=>'synthetic-mail-deadlock','sum'=>'20','curr'=>'EUR','accto'=>'fixture-merchant'];
financialAssert(GatewaySettlement::settle($db,'CP',2,$notificationCallback)->data===['settled'=>true],'notification deadlock retries entire callback');
financialAssert(!$db->abortEmailTransactionOnce,'notification fault exercised');
financialAssert((int)financialValue("SELECT COUNT(*) FROM Opers WHERE oOper='REF'")===1,'notification deadlock never commits orphaned referral');
financialWallet(1,2,'20.000000'); financialWallet(2,2,'1.000000');
financialAssert((int)financialValue("SELECT COUNT(*) FROM Jobs WHERE jType='email'")===2,'one source and one referral notification');
financialAssert(GatewaySettlement::settle($db,'CP',2,$notificationCallback)->replayed,'callback still replays after notification recovery');
financialAssert((int)financialValue("SELECT COUNT(*) FROM Opers WHERE oOper='REF'")===1,'notification recovery replay cannot duplicate referral');
financialAssert((int)financialValue("SELECT COUNT(*) FROM Jobs WHERE jType='email'")===2,'notification recovery replay cannot duplicate jobs');
FinancialFixtureView::$queueMail=false;

financialReset(); opChangeBalance(1,2,100,0,0);
$failed=opOperCreate(1,'CASHOUT',2,5); opOperConfirm(1,$failed); $before=financialFingerprint(); $db->rejectPayoutJob=true;
try { opOperComplete(1,$failed); throw new LogicException('Missing outbox accepted'); }
catch (HScript\Finance\FinancialOperationException $e) { financialAssert($e->getMessage()==='payout_queue_unavailable','outbox insert failure propagates'); }
financialAssert(financialFingerprint()===$before,'missing outbox leaves reserved operation retryable');
financialAssert((int)financialValue("SELECT COUNT(*) FROM IdempotencyKeys WHERE ikNamespace='payout:queue'")===0,'missing outbox rolls back its marker');
$db->rejectPayoutJob=false; opOperCancel(1,$failed);
$id=opOperCreate(1,'CASHOUT',2,20);
$destination=strToArray(financialValue("SELECT oParams2 FROM Opers WHERE oID=$id")); $destination['acc']='fixture-payout-destination';
$db->update('Opers',['oParams2'=>arrayToStr($destination)],'','oID=?d',[$id]);
financialAssert(opOperConfirm(1,$id)===true && opOperComplete(1,$id)===true,'payout reservation and enqueue');
financialAssert(opOperComplete(1,$id)===true,'repeated processing does not add job');
financialAssert(financialValue("SELECT COUNT(*) FROM Jobs WHERE jType='financial.withdraw'")===1,'one payout job');
$calls=0;
$send=static function (array $payload) use (&$calls): array { $calls++; return ['batch'=>'synthetic-payout-1']; };
financialAssert(WithdrawalDispatcher::dispatch($db,$id,$send)===['completed'=>true],'fake provider payout settles');
financialAssert(WithdrawalDispatcher::dispatch($db,$id,$send)===['completed'=>true],'completed payout retry is stable');
financialAssert($calls===1,'provider contacted once');
financialWallet(1,2,'80.000000');

$id=opOperCreate(1,'CASHOUT',2,5); opOperConfirm(1,$id); opOperComplete(1,$id);
try { WithdrawalDispatcher::dispatch($db,$id); throw new LogicException('Missing payout configuration accepted'); }
catch (HScript\Finance\FinancialOperationException $e) { financialAssert($e->getMessage()==='payout_configuration_missing','payout preflight rejects absent API configuration'); }
financialAssert(!WithdrawalDispatcher::wasAttempted($db,$id),'configuration failure does not reserve an external attempt');
financialAssert(opOperCancel(1,$id)===true,'never attempted payout may be cancelled');
financialWallet(1,2,'80.000000');

$id=opOperCreate(1,'CASHOUT',2,10); opOperConfirm(1,$id); opOperComplete(1,$id);
$destination=strToArray(financialValue("SELECT oParams2 FROM Opers WHERE oID=$id")); $destination['acc']='fixture-outbound-destination';
$db->update('Opers',['oParams2'=>arrayToStr($destination)],'','oID=?d',[$id]);
$send=static function (array $payload) use (&$calls): array { $calls++; throw new RuntimeException('synthetic timeout'); };
try { WithdrawalDispatcher::dispatch($db,$id,$send); } catch (RuntimeException) { $assertions++; }
try { WithdrawalDispatcher::dispatch($db,$id,$send); } catch (RuntimeException) { $assertions++; }
financialAssert($calls===2,'unknown response never resends');
financialAssert(opOperCancel(1,$id)==='payout_reconciliation_required','ambiguous payout cannot refund reserve');
financialWallet(1,2,'70.000000','0.000000','10.000000');
// No accepted provider profile authenticates an asynchronous payout callback.
$before=financialFingerprint();
$outbound=['correct'=>true,'authenticated'=>true,'outcome'=>'verified','outbound'=>true,'tag'=>$id,'batch'=>'fixture-resolved-payout','sum'=>'10','curr'=>'EUR','accto'=>'fixture-outbound-destination'];
try { GatewaySettlement::settle($db,'CP',2,$outbound); throw new LogicException('Unsupported automatic payout completed'); }
catch (HScript\Finance\CallbackValidationException $e) { financialAssert($e->reason==='automatic_callback_unsupported','unreviewed payout callback rejected'); }
financialAssert(financialFingerprint()===$before,'unsupported callback preserves ambiguous reserve/history');
financialWallet(1,2,'70.000000','0.000000','10.000000');

financialReset(); $_currs[2]['cCASHINMode']=2;
$id=opOperCreate(1,'CASHIN',2,25); $calls=0;
putenv('APP_DATA_KEY=synthetic-protected-checkout-key');
$create=static function () use (&$calls): array { $calls++; return ['url'=>'https://fixture.invalid/checkout','signature'=>'synthetic-private-signature','psysorder'=>'synthetic-invoice','merchant'=>'fixture-merchant','amountf'=>'25.00']; };
$form=DepositCheckout::prepare($db,1,$id,$create);
financialAssert(DepositCheckout::prepare($db,1,$id,$create)===$form && $calls===1,'checkout retry never creates another provider invoice');
financialAssert(!str_contains((string)financialValue("SELECT oParams FROM Opers WHERE oID=$id"),'synthetic-private-signature'),'checkout form encrypted outside safe snapshot');
financialAssert(!str_contains((string)financialValue("SELECT GROUP_CONCAT(ikResponse) FROM IdempotencyKeys"),'synthetic-private-signature'),'checkout credentials never enter response snapshots');
try { DepositCheckout::prepare($db,2,$id,$create); throw new LogicException('Cross owner checkout'); }
catch (HScript\Finance\FinancialOperationException) { $assertions++; }

$id=opOperCreate(1,'CASHIN',2,10); $calls=0;
putenv('APP_DATA_KEY');
try { DepositCheckout::prepare($db,1,$id,static function () use (&$calls): array { $calls++; return []; }); throw new LogicException('Unprotected checkout accepted'); }
catch (HScript\Finance\FinancialOperationException $e) { financialAssert($e->getMessage()==='invoice_configuration_missing','invoice encryption preflight'); }
financialAssert($calls===0,'configuration failure never contacts invoice provider');
putenv('APP_DATA_KEY=synthetic-protected-checkout-key');
$timeout=static function () use (&$calls): array { $calls++; throw new RuntimeException('synthetic invoice timeout'); };
try { DepositCheckout::prepare($db,1,$id,$timeout); } catch (RuntimeException) { $assertions++; }
try { DepositCheckout::prepare($db,1,$id,$timeout); } catch (RuntimeException) { $assertions++; }
financialAssert($calls===1,'unknown invoice response is never retried externally');
financialAssert(financialValue("SELECT COUNT(*) FROM IdempotencyKeys WHERE ikState='pending'")===0,'no stranded claims');
echo 'Financial phase-2 domain tests passed (' . $assertions . " checks).\n";
