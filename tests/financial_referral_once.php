<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/fixtures/financial_runtime.php';
require __DIR__.'/fixtures/financial_property_support.php';
use HScript\Database\Connection;
use HScript\Finance\{Decimal,FinancialOperationException,IdempotencyConflict,IdempotencyStore,IdempotencyRequest,IdempotencyResult,ReferralSourceEvent};

final class ReferralRaceConnection extends Connection
{
    public bool $barrier=false;
    private bool $gated=false;
    public function query($query,$values=null)
    {
        if($this->barrier&&!$this->gated&&(str_contains($query,'FOR UPDATE')&&str_contains($query,'FROM Opers WHERE oID='))) {
            $this->gated=true;fwrite(STDOUT,"gate\n");fflush(STDOUT);
            if(trim((string)fgets(STDIN))!=='go')throw new RuntimeException('Synthetic source barrier not released');
        }
        return parent::query($query,$values);
    }
}
function referralRequest(array $request): mixed
{
    global $db;
    if($request['action']==='replay'){onRefSysEvent($request['event'],$request['uid'],$request['params']);return true;}
    try{
        $result=(new IdempotencyStore($db))->execute('fixture:referral',$request['key'],$request['uid'],IdempotencyRequest::fingerprint(['oid'=>$request['id']]),static function()use($request):IdempotencyResult{
            FinancialOperationException::check(opOperComplete($request['uid'],$request['id'],[],true));
            return new IdempotencyResult(['completed'=>true],200,$request['id']);
        });return $result->data['completed'];
    }catch(FinancialOperationException $e){return $e->getMessage();}
}
if(($argv[1]??'')==='worker'){
    ini_set('display_errors','stderr');$r=json_decode($argv[2],true,64,JSON_THROW_ON_ERROR);$env=json_decode($argv[3],true,64,JSON_THROW_ON_ERROR);
    $db=new ReferralRaceConnection();if(!$db->open(getenv('DB_HOST'),'fixture',getenv('DB_USER'),getenv('DB_PASSWORD')))throw new RuntimeException('Synthetic worker unavailable');$pdo=$db->link;
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL '.$r['isolation']);$_cfg=$env['cfg'];$_GS=$env['gs'];$_onload=['RefSys'=>true];$_currs=$db->fetchIDRows($db->select('Currs'),false,'cID');$db->barrier=$r['gate'];
    fwrite(STDOUT,"ready\n");fflush(STDOUT);if(trim((string)fgets(STDIN))!=='start')exit(2);if(!$r['gate']){fwrite(STDOUT,"attempt\n");fflush(STDOUT);}
    echo json_encode(['value'=>referralRequest($r),'transaction_open'=>$pdo->inTransaction()],JSON_THROW_ON_ERROR)."\n";exit;
}
function referralLine($pipe):string
{
    $read=[$pipe];$write=$except=[];if(stream_select($read,$write,$except,30)!==1)throw new RuntimeException('Synthetic source worker timeout');$line=fgets($pipe);if($line===false)throw new RuntimeException('Synthetic source worker exited');return $line;
}
function referralWorkers(array $requests):array
{
    global $_cfg,$_GS;$workers=[];
    try{
        foreach($requests as $r){$p=proc_open([PHP_BINARY,__FILE__,'worker',json_encode($r,JSON_THROW_ON_ERROR),json_encode(['cfg'=>$_cfg,'gs'=>$_GS],JSON_THROW_ON_ERROR)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($p))throw new RuntimeException('Synthetic worker unavailable');$workers[]=[$p,$pipes,$r];}
        foreach($workers as [$p,$pipes])financialAssert(referralLine($pipes[1])==="ready\n",'schema bootstrap before owner transactions');
        foreach($workers as [$p,$pipes]){fwrite($pipes[0],"start\n");fflush($pipes[0]);}
        foreach($workers as [$p,$pipes,$r])financialAssert(referralLine($pipes[1])===($r['gate']?"gate\n":"attempt\n"),'actual source SQL or replay attempted');
        foreach($workers as [$p,$pipes,$r]){if($r['gate']){fwrite($pipes[0],"go\n");fflush($pipes[0]);}fclose($pipes[0]);}
        $out=[];foreach($workers as [$p,$pipes]){$line=referralLine($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);financialAssert($code===0&&$err==='','fresh actual source worker exit: '.$err.$line);$v=json_decode($line,true,64,JSON_THROW_ON_ERROR);financialAssert(!$v['transaction_open'],'source worker releases owner transaction');$out[]=$v['value'];}$workers=[];return $out;
    }finally{foreach($workers as [$p,$pipes])if(is_resource($p)){proc_terminate($p,9);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($p);}}
}
function referralSetup():void
{
    global $_cfg,$_onload;financialReset();propertyScale(2);$_cfg['Ref__Perc']=[5,2];$_cfg['Ref__DPerc']=[5,2];$_cfg['Ref__PPerc']=[3,1];$_onload=['RefSys'=>true];
}
function referralPending(int $uid=1,int $cid=1,string $amount='100',array $params=[]):int
{
    $id=opOperCreate($uid,'CASHIN',$cid,$amount,$params,'',false,true);financialAssert(is_int($id),'actual pending source');financialAssert(opOperConfirm($uid,$id,['date'=>time(),'batch'=>'referral-source-'.$id],true)===true,'actual confirmed source');return $id;
}
function referralReceipt(string $namespace,int $uid):array
{
    global $pdo;$stmt=$pdo->prepare('SELECT * FROM IdempotencyKeys WHERE ikNamespace=? AND ikOwnerID=?');$stmt->execute([$namespace,$uid]);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);financialAssert(count($rows)===1&&$rows[0]['ikState']==='completed','one durable typed source receipt');$data=json_decode($rows[0]['ikResponse'],true,64,JSON_THROW_ON_ERROR)['data'];$ids=$data['operation_ids'];
    financialAssert(count($ids)===count(array_unique($ids)),'original reward IDs unique');foreach($ids as $id)financialAssert((int)financialValue("SELECT COUNT(*) FROM Opers WHERE oID=".(int)$id." AND oOper='REF' AND oState=3")===1,'receipt ID is real completed reward');return $rows[0];
}
function referralUnbound(string $event,int $uid,array $params):void
{
    $before=propertyFingerprint();try{onRefSysEvent($event,$uid,$params);throw new LogicException('Unknown legacy source guessed');}catch(FinancialOperationException $e){financialAssert($e->getMessage()==='ref_source_unbound','first binding needs actual source transition');}financialAssert(propertyFingerprint()===$before,'unknown legacy source refusal writes no table');
}
if(($argv[1]??'')==='legacy-produce'){
    referralSetup();$id=referralPending();financialAssert(opOperComplete(1,$id,[],true)===true,'old genuine source emits original rewards');financialWallet(2,1,'5.000000');financialWallet(3,1,'2.000000');financialAssert((int)financialValue('SELECT COUNT(*) FROM IdempotencyKeys')===0,'old source has no binding');propertyReconcile(3);echo "Legacy referral fixture produced by installed old handler.\n";exit;
}
if(($argv[1]??'')==='legacy-consume'){
    $_cfg['UI_NumDec']=2;$_cfg['Ref__Perc']=[100,100];$id=(int)financialValue("SELECT oID FROM Opers WHERE oOper='CASHIN'");$params=['oid'=>$id,'oper'=>'CASHIN','cid'=>1,'sum'=>'100'];$before=propertyFingerprint();
    referralUnbound('Oper',1,$params);$pdo->beginTransaction();$pdo->exec("UPDATE AddInfo SET aName='caller sentinel' WHERE auID=1");$sentinel=propertyFingerprint();referralUnbound('Oper',1,$params);financialAssert($pdo->inTransaction()&&propertyFingerprint()===$sentinel,'caller transaction does not grant fresh legacy source authority');$pdo->rollBack();financialAssert(propertyFingerprint()===$before,'genuine old history remains byte-for-byte unchanged');propertyReconcile(3);echo "Legacy referral cutover refuses guessed binding and preserves every historical row.\n";exit;
}

$cases=json_decode(file_get_contents(__DIR__.'/fixtures/financial_referral_cases.json'),true,64,JSON_THROW_ON_ERROR);if($cases['format']!=='hscript-referral-properties-v1')throw new RuntimeException('Unsupported referral fixture');$scenarios=0;$generated=0;
foreach($cases['cases']as $case){
    referralSetup();propertyScale($case['scale']);$_cfg['Ref__Perc']=$case['rates'];$cid=$case['cid'];
    foreach($case['parents']as $uid=>$parent){if($uid>3){$db->insert('Users',['uID'=>$uid,'uLogin'=>'fixture-'.$uid,'uMail'=>'fixture-'.$uid.'@fixture.invalid','uState'=>1]);$db->insert('AddInfo',['auID'=>$uid]);}$db->update('Users',['uRef'=>$parent,'uState'=>$uid===1||$case['active'][$uid]?1:0],'','uID=?d',[$uid]);if(isset($case['overrides'][$uid]))$db->update('AddInfo',['aPerc'=>implode(HS2_NL,$case['overrides'][$uid])],'','auID=?d',[$uid]);}
    $_currs[$cid]['cCASHINComis']=$case['fee_percent'];propertyReconcile(0);$id=referralPending(1,$cid,$case['raw']);$before=propertyFingerprint();
    try{$value=opOperComplete(1,$id,[],true);financialAssert($case['error']===null&&$value===true,'independent graph expects committed source');}catch(FinancialOperationException $e){financialAssert($e->getMessage()===$case['error'],'independent graph predicts cycle');financialAssert(propertyFingerprint()===$before,'cyclic source plus earlier rewards/claim rollback');propertyReconcile(0);$generated++;$scenarios++;continue;}
    financialWallet(1,$cid,$case['net']);$actual=$pdo->query("SELECT ouID,oSum FROM Opers WHERE oOper='REF' ORDER BY ouID")->fetchAll(PDO::FETCH_KEY_PAIR);financialAssert($actual===$case['payouts'],'Python exact recipient/issuance map');
    foreach($case['payouts']as $uid=>$sum)financialWallet((int)$uid,$cid,$sum);financialAssert(financialValue('SELECT oSum FROM Opers WHERE oID='.$id)===$case['gross']&&financialValue('SELECT oComis FROM Opers WHERE oID='.$id)===$case['fee'],'independent stored source gross/fee');
    financialAssert(Decimal::of(financialValue('SELECT SUM(wBal+wLock+wOut) FROM Wallets WHERE wcID='.$cid))->compareTo(Decimal::of($case['net'])->plus(Decimal::of($case['issuance'])))===0,'net external source plus declared referral issuance conserved');$receipt=referralReceipt('referral:cashin',1);$original=json_decode($receipt['ikResponse'],true,64,JSON_THROW_ON_ERROR)['data'];financialAssert($original['event']==='Oper'&&$original['source_id']===$id&&$original['period']===0&&$original['currency_id']===$cid&&Decimal::of($original['basis'])->compareTo(Decimal::of($case['net']))===0,'immutable source identity/currency/basis retained with original reward IDs');propertyReconcile(1+count($case['payouts']));
    $params=['oid'=>$id,'oper'=>'CASHIN','cid'=>$cid,'sum'=>$case['net']];$_cfg['Ref__Perc']=[100,100];$before=propertyFingerprint();onRefSysEvent('Oper','01',$params);financialAssert(propertyFingerprint()===$before,'bound generated event replay keeps original config-independent IDs');propertyReconcile(1+count($case['payouts']));$generated++;$scenarios++;
}

// Empty/rounded-zero policy and absent sponsor still bind the real source once.
foreach(['disabled','zero','no-sponsor'] as $policy){
    referralSetup();if($policy==='disabled')$_cfg['Ref__Perc']=[];elseif($policy==='zero')$_cfg['Ref__Perc']=['0.0000000001'];else $pdo->exec('UPDATE Users SET uRef=0 WHERE uID=1');
    $id=referralPending();financialAssert(opOperComplete(1,$id,[],true)===true,'source with no issued rewards completes');$row=referralReceipt('referral:cashin',1);$data=json_decode($row['ikResponse'],true,64,JSON_THROW_ON_ERROR)['data'];financialAssert($data['operation_ids']===[],'empty issuance original receipt');propertyReconcile(1);
    $before=propertyFingerprint();$_cfg['Ref__Perc']=[100,100];onRefSysEvent('Oper',1,['oid'=>$id,'oper'=>'CASHIN','cid'=>1,'sum'=>'100']);financialAssert(propertyFingerprint()===$before,'later positive policy does not issue a second source reward');propertyReconcile(1);$scenarios++;
}
// Tier mode has a single recipient but still cannot pay the source user itself.
referralSetup();$pdo->exec('UPDATE Users SET uRef=1 WHERE uID=1');$_cfg['Ref_Type']=1;$_cfg['Ref__Perc']=['0-5'];$id=referralPending();$before=propertyFingerprint();
try{opOperComplete(1,$id,[],true);throw new LogicException('Tier self-cycle accepted');}catch(FinancialOperationException $e){financialAssert($e->getMessage()==='ref_cycle','tier self-cycle refused');}
financialAssert(propertyFingerprint()===$before,'tier self-cycle rolls back source and receipt');propertyReconcile(0);$scenarios++;

// A second real SQL session changes sponsor policy after the caller snapshot.
foreach(['REPEATABLE READ','READ COMMITTED'] as $isolation)foreach(['inactive','activated','cycle','override','tier-count','tier-closed-principal','tier-active-principal'] as $policy){
    referralSetup();if($policy==='activated')$pdo->exec('UPDATE Users SET uState=0 WHERE uID=2');$id=referralPending();
    if(str_starts_with($policy,'tier-')){$_cfg['Ref_Type']=match($policy){'tier-count'=>1,'tier-closed-principal'=>3,default=>4};$_cfg['Ref__Perc']=$policy==='tier-count'?['0-1','1-7']:['0-1','100-7'];}
    propertyReconcile(0);$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL '.$isolation);$pdo->beginTransaction();$pdo->query('SELECT * FROM Users LEFT JOIN AddInfo ON auID=uID')->fetchAll();$pdo->query('SELECT * FROM Deps')->fetchAll();
    $other=new Connection();financialAssert($other->open(getenv('DB_HOST'),'fixture',getenv('DB_USER'),getenv('DB_PASSWORD')),'independent real policy session');$b=$other->link;
    match($policy){
        'inactive'=>$b->exec('UPDATE Users SET uState=0 WHERE uID=2'),
        'activated'=>$b->exec('UPDATE Users SET uState=1 WHERE uID=2'),
        'cycle'=>$b->exec('UPDATE Users SET uRef=1 WHERE uID=2'),
        'override'=>$b->exec("UPDATE AddInfo SET aPerc='0' WHERE auID=2"),
        'tier-count','tier-closed-principal'=>$b->exec('INSERT INTO Deps(dID,duID,dcID,dpID,dState,dZ0,dZD,dNTS) VALUES(500,1,1,1,3,100,100,0)'),
        'tier-active-principal'=>null,
    };
    if($policy==='tier-active-principal'){$b->beginTransaction();$b->exec('INSERT INTO Deps(dID,duID,dcID,dpID,dState,dZ0,dZD,dNTS) VALUES(500,2,1,1,1,100,100,20261031000000)');$b->exec('INSERT INTO Wallets(wuID,wcID,wBal,wLock) VALUES(2,1,0,100)');$b->exec('UPDATE Users SET uBal=100 WHERE uID=2');$b->commit();}
    // Capture committed B on its own connection; A's ordinary RR reads stay old.
    $saved=$pdo;$pdo=$b;$before=propertyFingerprint();$pdo=$saved;
    if($policy==='cycle'){
        try{opOperComplete(1,$id,[],true);throw new LogicException('Current sponsor cycle missed');}catch(FinancialOperationException $e){financialAssert($e->getMessage()==='ref_cycle','current changed ancestry cycle refused');}
        financialAssert($pdo->inTransaction(),'cyclic source preserves parent ownership');$pdo->rollBack();financialAssert(propertyFingerprint()===$before,'source rollback preserves B current committed policy and every row');propertyReconcile(0);
    }else{
        financialAssert(opOperComplete(1,$id,[],true)===true&&$pdo->inTransaction(),'actual source retains caller ownership after current policy reads');$pdo->commit();financialWallet(1,1,'100.000000');
        if(str_starts_with($policy,'tier-')){financialWallet(2,1,'7.000000',$policy==='tier-active-principal'?'100.000000':'0.000000');propertyReconcile(2);}
        elseif($policy==='activated'){financialWallet(2,1,'5.000000');financialWallet(3,1,'2.000000');propertyReconcile(3);}
        else{financialAssert((int)financialValue('SELECT COUNT(*) FROM Wallets WHERE wuID=2')===0,'current inactive or zero override receives nothing');financialWallet(3,1,'2.000000');propertyReconcile(2);}
        referralReceipt('referral:cashin',1);$before=propertyFingerprint();onRefSysEvent('Oper',1,['oid'=>$id,'oper'=>'CASHIN','cid'=>1,'sum'=>'100']);financialAssert(propertyFingerprint()===$before,'bound result retains current-policy original rewards');propertyReconcile($policy==='activated'?3:2);
    }
    $scenarios++;
}
$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');

// Actual distinct request receipts, shared source completion, and fresh process replays.
foreach(['REPEATABLE READ','READ COMMITTED']as $isolation)foreach([false,true]as $distinct){
    referralSetup();$a=referralPending();$b=$distinct?referralPending():$a;$base=['action'=>'complete','uid'=>1,'params'=>[],'isolation'=>$isolation];$out=referralWorkers([$base+['id'=>$a,'key'=>'first','gate'=>true],$base+['id'=>$b,'key'=>'second','gate'=>false]]);
    if($distinct)financialAssert($out===[true,true],'distinct sources each issue once');else financialAssert(count(array_filter($out,static fn($v)=>$v===true))>=1&&count(array_filter($out,static fn($v)=>$v===true||is_string($v)))===2,'same actual source keeps stable completion/no duplicate effect');
    $n=$distinct?2:1;financialWallet(1,1,$distinct?'200.000000':'100.000000');financialWallet(2,1,$distinct?'10.000000':'5.000000');financialWallet(3,1,$distinct?'4.000000':'2.000000');financialAssert((int)financialValue("SELECT COUNT(*) FROM IdempotencyKeys WHERE ikNamespace='referral:cashin'")===$n,'one reward receipt per completed source');propertyReconcile(3*$n);
    $params=['oid'=>$a,'oper'=>'CASHIN','cid'=>1,'sum'=>'100'];$_cfg['Ref__Perc']=[50,50];$before=propertyFingerprint();$replay=['action'=>'replay','event'=>'Oper','uid'=>1,'params'=>$params,'isolation'=>$isolation,'gate'=>false];financialAssert(referralWorkers([$replay,$replay])===[true,true],'fresh concurrent bound event retries');financialAssert(propertyFingerprint()===$before,'fresh retries no tables/receipt/Jobs changes');propertyReconcile(3*$n);$scenarios++;
}

// Typed identities remain independent, including successive deposit boundaries.
referralSetup();financialAssert(opChangeBalance(1,1,'200',0,0)===true,'backed seed');propertyReconcile(1);$did=opDepoCreate(1,1,'100',0,1);financialAssert(is_int($did),'actual deposit source');referralReceipt('referral:deposit',1);propertyReconcile(5);$d=financialDepo($did);financialAssert(opDepoCharge($d)===true,'actual accrual period1');referralReceipt('referral:accrual',1);propertyReconcile(8);
$before=propertyFingerprint();$_cfg['Ref__DPerc']=[100,100];$_cfg['Ref__PPerc']=[100,100];onRefSysEvent('DepoCreate',1,['did'=>$did,'cid'=>1,'sum'=>'100']);onRefSysEvent('DepoCharge',1,['did'=>$did,'cid'=>1,'sum'=>'10','nper'=>1]);financialAssert(propertyFingerprint()===$before,'deposit and period1 retain original receipts after policy drift');propertyReconcile(8);
$_cfg['Ref__PPerc']=[3,1];financialAssert(opDepoCharge(financialDepo($did))===true,'actual terminal period2');financialAssert((int)financialValue("SELECT COUNT(*) FROM IdempotencyKeys WHERE ikNamespace='referral:accrual'")===2,'two independent period identities');financialWallet(2,1,'5.600000');financialWallet(3,1,'2.200000');propertyReconcile(11);$before=propertyFingerprint();onRefSysEvent('DepoCharge',1,['did'=>$did,'cid'=>1,'sum'=>'10.0','nper'=>1]);financialAssert(propertyFingerprint()===$before,'old period receipt replays after current period advances');propertyReconcile(11);$scenarios++;

// Genuine late server faults, source plus all rewards, queue records and claims atomic.
foreach(['reward','receipt']as $fault)foreach([false,true]as $nested){
    referralSetup();$id=referralPending();$jobQueue=new HScript\Queue\JobQueue($db);FinancialFixtureView::$queueMail=true;
    $sql=$fault==='reward'?"CREATE TRIGGER ReferralLateFault BEFORE INSERT ON Opers FOR EACH ROW BEGIN IF NEW.oOper='REF' AND NEW.ouID=3 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated late referral failure'; END IF; END":"CREATE TRIGGER ReferralLateFault BEFORE UPDATE ON IdempotencyKeys FOR EACH ROW BEGIN IF NEW.ikNamespace='referral:cashin' AND NEW.ikState='completed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated late referral receipt failure'; END IF; END";$pdo->exec($sql);
    if($nested){$pdo->beginTransaction();$pdo->exec("UPDATE AddInfo SET aName='caller retained' WHERE auID=1");}$before=propertyFingerprint();try{opOperComplete(1,$id,[],true);throw new LogicException('Real late SQL failure absent');}catch(PDOException $e){financialAssert($e->getCode()==='45000'&&(int)$e->errorInfo[1]===1644,'real server45000');}financialAssert($pdo->inTransaction()===$nested&&propertyFingerprint()===$before,'parent ownership retained, source/rewards/Jobs/child claim all rolled back');if($nested)$pdo->rollBack();propertyReconcile(0);$pdo->exec('DROP TRIGGER ReferralLateFault');
    // Context was removed by finally: even a caller TX cannot turn the failed source into an unrelated reward emission.
    $pdo->beginTransaction();referralUnbound('Oper',1,['oid'=>$id,'oper'=>'CASHIN','cid'=>1,'sum'=>'100']);$pdo->rollBack();propertyReconcile(0);financialAssert(opOperComplete(1,$id,[],true)===true,'actual same source retry after rollback');financialAssert((int)financialValue('SELECT COUNT(*) FROM Jobs')===3,'only committed source and reward mails queued');referralReceipt('referral:cashin',1);propertyReconcile(3);$scenarios++;
}
foreach(['create','charge']as $event){referralSetup();opChangeBalance(1,1,'200',0,0);if($event==='charge'){$_onload=[];$did=opDepoCreate(1,1,'100',0,1);$_onload=['RefSys'=>true];}$pdo->exec('UPDATE Users SET uRef=2 WHERE uID=3');$_cfg[$event==='create'?'Ref__DPerc':'Ref__PPerc']=[5,2,1];$before=propertyFingerprint();$value=$event==='create'?opDepoCreate(1,1,'100',0,1):opDepoCharge(financialDepo($did));financialAssert($value==='ref_cycle'&&propertyFingerprint()===$before,'cyclic '.$event.' refuses entire source');propertyReconcile($event==='create'?1:3);$scenarios++;}
foreach([false,true]as $commit){referralSetup();$id=referralPending();$before=propertyFingerprint();$pdo->beginTransaction();$pdo->exec("UPDATE AddInfo SET aName='caller-owned' WHERE auID=1");financialAssert(opOperComplete(1,$id,[],true)===true&&$pdo->inTransaction(),'source child keeps caller ownership');if($commit)$pdo->commit();else$pdo->rollBack();if(!$commit){financialAssert(propertyFingerprint()===$before,'owner rollback removes source child and reward marker');financialAssert(opOperComplete(1,$id,[],true)===true,'actual source retries after caller rollback');}propertyReconcile(3);$scenarios++;}
// A same source key with changed financial basis is a conflict, never a second payout.
$before=propertyFingerprint();try{onRefSysEvent('Oper',1,['oid'=>$id,'oper'=>'CASHIN','cid'=>1,'sum'=>'101']);throw new LogicException('Changed source accepted');}catch(IdempotencyConflict){financialAssert(propertyFingerprint()===$before,'changed basis conflict no effects');}propertyReconcile(3);
echo json_encode(['status'=>'passed','assertions'=>$assertions,'scenarios'=>$scenarios,'generated_graphs'=>$generated,'actual_php_workers'=>true,'real_source_context'=>true,'exact_reconciliation'=>true,'legacy_history_unchanged'=>true,'no_external_dispatch'=>true],JSON_THROW_ON_ERROR)."\n";
