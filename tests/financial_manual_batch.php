<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/fixtures/financial_runtime.php';
require __DIR__.'/fixtures/financial_property_support.php';
use HScript\Database\Connection;
use HScript\Finance\{FinancialOperationException,IdempotencyStore,IdempotencyRequest,IdempotencyResult};

/** Observes the actual SQL boundary without replacing database or financial results. */
final class BatchRaceConnection extends Connection
{
    public bool $gated=false;
    public bool $barrier=false;
    public function query($query,$values=null)
    {
        if ($this->barrier && !$this->gated && (str_contains($query,'SELECT cID FROM Currs WHERE cID=?d FOR UPDATE')
            || str_contains($query,'SELECT uID,uBal FROM Users WHERE uID=?d FOR UPDATE'))) {
            $this->gated=true;fwrite(STDOUT,"gate\n");fflush(STDOUT);
            if (trim((string)fgets(STDIN))!=='go') throw new RuntimeException('Synthetic batch barrier not released');
        }
        return parent::query($query,$values);
    }
}
function batchWork(array $request): mixed
{
    global $db;
    try {
        $receipt=(new IdempotencyStore($db))->execute('fixture:batch',$request['key'],$request['uid'],IdempotencyRequest::fingerprint(array_diff_key($request,['gate'=>true,'isolation'=>true])),static function()use($request):IdempotencyResult {
            FinancialOperationException::check(opOperComplete($request['uid'],$request['id'],$request['params'],true));
            return new IdempotencyResult(['completed'=>true],200,$request['id']);
        });
        return $receipt->data['completed'];
    } catch (FinancialOperationException $error) { return $error->getMessage(); }
}
if (($argv[1]??'')==='worker') {
    ini_set('display_errors','stderr');$request=json_decode($argv[2],true,64,JSON_THROW_ON_ERROR);$env=json_decode($argv[3],true,64,JSON_THROW_ON_ERROR);
    $db=new BatchRaceConnection();if (!$db->open(getenv('DB_HOST'),'fixture',getenv('DB_USER'),getenv('DB_PASSWORD'))) throw new RuntimeException('Synthetic worker unavailable');
    $pdo=$db->link;$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL '.$request['isolation']);
    $_cfg=$env['cfg'];$_GS=$env['gs'];$_onload=[];$_currs=$db->fetchIDRows($db->select('Currs'),false,'cID');$db->barrier=$request['gate'];
    fwrite(STDOUT,"ready\n");fflush(STDOUT);if (trim((string)fgets(STDIN))!=='start') exit(2);
    if (!$db->barrier) {fwrite(STDOUT,"attempt\n");fflush(STDOUT);}
    $value=batchWork($request);
    echo json_encode(['value'=>$value,'gated'=>$db->gated,'transaction_open'=>$pdo->inTransaction()],JSON_THROW_ON_ERROR)."\n";exit;
}
function batchLine($pipe): string
{
    $read=[$pipe];$write=$except=[];if(stream_select($read,$write,$except,30)!==1) throw new RuntimeException('Synthetic batch result timeout');
    $line=fgets($pipe);if($line===false)throw new RuntimeException('Synthetic batch worker exited');return $line;
}
function batchWorkers(array $requests, ?callable $beforeStart=null): array
{
    global $_cfg,$_GS;$workers=[];
    try {
        foreach ($requests as $request) {
            $process=proc_open([PHP_BINARY,__FILE__,'worker',json_encode($request,JSON_THROW_ON_ERROR),json_encode(['cfg'=>$_cfg,'gs'=>$_GS],JSON_THROW_ON_ERROR)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if(!is_resource($process))throw new RuntimeException('Synthetic worker unavailable');$workers[]=[$process,$pipes,$request];
        }
        foreach($workers as [$process,$pipes])financialAssert(batchLine($pipes[1])==="ready\n",'all worker schema bootstraps complete before financial transactions');
        if ($beforeStart) $beforeStart();
        foreach($workers as [$process,$pipes]){fwrite($pipes[0],"start\n");fflush($pipes[0]);}
        foreach($workers as [$process,$pipes,$request])financialAssert(batchLine($pipes[1])===($request['gate']?"gate\n":"attempt\n"),'both workers reach genuine batch/wallet SQL');
        foreach($workers as [$process,$pipes,$request]){if($request['gate']){fwrite($pipes[0],"go\n");fflush($pipes[0]);}fclose($pipes[0]);}
        $values=[];foreach($workers as [$process,$pipes,$request]){
            $output=batchLine($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
            financialAssert($code===0&&$stderr==='','actual batch worker exits without warnings: '.$stderr.$output);$result=json_decode($output,true,64,JSON_THROW_ON_ERROR);
            financialAssert(!$result['transaction_open'],'each worker releases transaction');financialAssert($result['gated']===$request['gate'],'actual gate reached exactly when requested');$values[]=$result['value'];
        }$workers=[];return $values;
    } finally {foreach($workers as [$process,$pipes])if(is_resource($process)){proc_terminate($process,9);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($process);}}
}
function batchPrepare(int $uid,int $cid,string $oper,string $batch,int &$audit): array
{
    global $db;
    if (!financialValue("SELECT COUNT(*) FROM Wallets WHERE wuID=$uid AND wcID=$cid"))$db->insert('Wallets',['wuID'=>$uid,'wcID'=>$cid]);
    if($oper==='CASHOUT'){financialAssert(opChangeBalance($uid,$cid,'100',0,0)===true,'seed backed outgoing funds');$audit++;}
    $id=opOperCreate($uid,$oper,$cid,'25',[],'Synthetic batch',false,true);financialAssert(is_int($id),'real distinct pending source');
    financialAssert(opOperConfirm($uid,$id,$oper==='CASHIN'?['date'=>time(),'batch'=>$batch]:[],true)===true,'real pending confirmation');
    if($oper==='CASHOUT')$audit++;
    return ['uid'=>$uid,'id'=>$id,'params'=>$oper==='CASHOUT'?['date'=>time(),'batch'=>$batch]:[],'oper'=>$oper,'cid'=>$cid];
}
function batchControl(): string
{
    global $pdo;$rows=[];foreach(['Cfg','AddInfo','Currs','Plans','PaymentProviders','PaymentProviderCredentials','Jobs']as $table){$all=array_map('serialize',$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC));sort($all,SORT_STRING);$rows[$table]=$all;}return hash('sha256',serialize($rows));
}
$scenarios=0;
foreach(['REPEATABLE READ','READ COMMITTED']as $isolation)foreach(['in_same','in_distinct','out_distinct','mixed','different_currency','case_collision','different_batch']as $family){
    financialReset();propertyScale(2);$audit=0;$requests=[];
    foreach([0,1]as $i){$uid=$family==='in_same'?1:$i+1;$cid=$family==='different_currency'?$i+1:1;
        $oper=$family==='out_distinct'||($family==='mixed'&&$i===1)?'CASHOUT':'CASHIN';
        $batch=$family==='case_collision'?($i?'Synthetic-ID':'synthetic-id'):($family==='different_batch'?'synthetic-'.$i:'synthetic-shared');
        $request=batchPrepare($uid,$cid,$oper,$batch,$audit);$requests[]=array_replace($request,['key'=>'batch-'.$scenarios.'-'.$i,'isolation'=>$isolation,'gate'=>$i===0]);
    }
    // Hold A at the real mutex boundary and start B's actual request. B may
    // legitimately block earlier; do not require it to reach a second barrier.
    propertyReconcile($audit);$control=batchControl();$values=batchWorkers($requests);$both=in_array($family,['different_currency','different_batch'],true);
    $sorted=$values;usort($sorted,static fn($a,$b)=>strcmp(json_encode($a),json_encode($b)));$expected=$both?[true,true]:['batch_exists',true];
    financialAssert($sorted===$expected,'one logical currency/batch settles once, scheduler independent: '.$family.' '.$isolation.' '.json_encode($values));
    financialAssert((int)financialValue('SELECT COUNT(*) FROM Opers WHERE oState=3')===($both?2:1),'exact completed source count');
    financialAssert((int)financialValue('SELECT COUNT(*) FROM IdempotencyKeys')===($both?2:1),'rejected request leaves no completed/pending receipt');
    $wallets=[];foreach($requests as $i=>$request){$k=$request['uid'].':'.$request['cid'];$wallets[$k]??=['uid'=>$request['uid'],'cid'=>$request['cid'],'available'=>0,'outgoing'=>0];
        if($request['oper']==='CASHOUT'){$wallets[$k]['available']+=75;$wallets[$k]['outgoing']+=25;}
        if($values[$i]===true){if($request['oper']==='CASHIN')$wallets[$k]['available']+=25;else$wallets[$k]['outgoing']-=25;}
    }
    foreach($wallets as $w)financialWallet($w['uid'],$w['cid'],(string)$w['available'].'.000000','0.000000',(string)$w['outgoing'].'.000000');
    $audit+=$both?2:1;propertyReconcile($audit);financialAssert(batchControl()===$control,'manual batch settlement changes no unrelated control/provider/queue row');
    foreach($requests as $i=>$request){$before=propertyFingerprint();$replay=batchWorkers([array_replace($request,['gate'=>false])]);financialAssert($replay===[$values[$i]]&&propertyFingerprint()===$before,'fresh-process exact success/refusal retry adds no row or monetary effect');propertyReconcile($audit);}
    $scenarios++;
}
// A caller's old RR view predates another operation's completed batch. Current
// validation refuses both confirmation and completion and retains caller effects.
foreach(['confirm','complete']as $action){
    financialReset();propertyScale(2);$audit=0;$a=batchPrepare(1,1,'CASHIN','synthetic-current',$audit);$b=batchPrepare(2,1,'CASHIN','synthetic-current',$audit);propertyReconcile(0);
    $req=array_replace($b,['key'=>'nested-batch-'.$action,'isolation'=>'REPEATABLE READ','gate'=>false]);financialAssert(batchWorkers([$req],static function()use($pdo){$pdo->beginTransaction();$pdo->query('SELECT COUNT(*) FROM Opers WHERE oState=3')->fetchColumn();$pdo->exec("UPDATE AddInfo SET aName='caller retained' WHERE auID=3");})===[true],'B actual batch completion after A snapshot');
    $result=$action==='confirm'?opOperConfirm(1,$a['id'],[],true):opOperComplete(1,$a['id'],[],true);
    financialAssert($result==='batch_exists'&&$pdo->inTransaction(),'current batch refusal retains nested caller transaction');financialAssert(financialValue("SELECT aName FROM AddInfo WHERE auID=3")==='caller retained','nested caller sentinel retained');
    $pdo->rollBack();financialAssert((int)financialValue('SELECT COUNT(*) FROM Opers WHERE oState=3')===1,'caller rollback preserves actual committed B source');financialWallet(2,1,'25.000000');propertyReconcile(1);$scenarios++;
}
// Genuine late database failure must free the currency mutex and roll back all
// local rows and receipt; another pending source can subsequently settle it.
financialReset();propertyScale(2);$audit=0;$a=batchPrepare(1,1,'CASHIN','synthetic-late',$audit);$b=batchPrepare(2,1,'CASHIN','synthetic-late',$audit);propertyReconcile(0);$before=propertyFingerprint();
$pdo->exec('CREATE TRIGGER BatchLateFailure BEFORE UPDATE ON Opers FOR EACH ROW BEGIN IF NEW.oID='.$a['id']." AND NEW.oState=3 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated batch late failure'; END IF; END");
try{batchWork(array_replace($a,['key'=>'batch-late','isolation'=>'REPEATABLE READ','gate'=>false]));throw new RuntimeException('Late SQL failure absent');}catch(PDOException $e){financialAssert($e->getCode()==='45000'&&(int)$e->errorInfo[1]===1644,'real SQL failure after wallet/history work');}
financialAssert(!$pdo->inTransaction()&&propertyFingerprint()===$before,'late financial SQL failure rolls back every source/history/receipt/queue field');propertyReconcile(0);$pdo->exec('DROP TRIGGER BatchLateFailure');
financialAssert(batchWorkers([array_replace($b,['key'=>'batch-after-fault','isolation'=>'READ COMMITTED','gate'=>false])])===[true],'fresh process can use released mutex after genuine failure');propertyReconcile(1);$scenarios++;
echo json_encode(['status'=>'passed','assertions'=>$assertions,'scenarios'=>$scenarios,'actual_PHP_workers'=>true,'isolation_levels'=>['REPEATABLE READ','READ COMMITTED'],'exact_reconciliation'=>true,'no_external_dispatch'=>true],JSON_THROW_ON_ERROR)."\n";
