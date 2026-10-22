<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/fixtures/financial_runtime.php';
require __DIR__.'/fixtures/financial_property_support.php';
use HScript\Database\Connection;
use HScript\Finance\{FinancialOperationException,IdempotencyStore,IdempotencyRequest,IdempotencyResult};

/** Observe the real group lock after the ordinary owner read established A's view. */
final class CurrentDepositConnection extends Connection
{
    private bool $gated=false;
    public function query($query,$params=null)
    {
        if (!$this->gated && str_contains($query,'SELECT uID FROM Users WHERE uID=?d FOR UPDATE')) {
            $this->gated=true;fwrite(STDOUT,"gate\n");fflush(STDOUT);
            if (trim((string)fgets(STDIN))!=='go') throw new RuntimeException('Synthetic current-deposit gate missing');
        }
        return parent::query($query,$params);
    }
}

if (($argv[1]??'')==='worker') {
    ini_set('display_errors','stderr');$r=json_decode($argv[2],true,64,JSON_THROW_ON_ERROR);$env=json_decode($argv[3],true,64,JSON_THROW_ON_ERROR);
    $db=new CurrentDepositConnection();
    if (!$db->open(getenv('DB_HOST'),'fixture',getenv('DB_USER'),getenv('DB_PASSWORD'))) throw new RuntimeException('Synthetic worker unavailable');
    $pdo=$db->link;$_cfg=$env['cfg'];$_GS=array_replace($_GS,$env['gs']);$_onload=[];
    $_currs=$db->fetchIDRows($db->select('Currs'),false,'cID');
    fwrite(STDOUT,"ready\n");fflush(STDOUT);if(trim((string)fgets(STDIN))!=='start')exit(2);
    try {
        $result=(new IdempotencyStore($db))->execute('fixture:deposit-current',$r['key'],1,IdempotencyRequest::fingerprint($r),static function()use($r):IdempotencyResult{
            $id=FinancialOperationException::check(opDepoCreate(1,1,'100',$r['compound'],$r['plan']));
            return new IdempotencyResult(['deposit_id'=>$id]);
        });$value=$result->data['deposit_id'];
    } catch(FinancialOperationException $e){$value=$e->getMessage();}
    echo json_encode(['value'=>$value,'transaction_open'=>$pdo->inTransaction()],JSON_THROW_ON_ERROR)."\n";exit;
}

function currentLine($pipe):string
{
    $read=[$pipe];$write=$except=[];
    if(stream_select($read,$write,$except,30)!==1)throw new RuntimeException('Synthetic current-deposit timeout');
    $line=fgets($pipe);if($line===false)throw new RuntimeException('Synthetic worker exited');return $line;
}
function currentStart(array $r):array
{
    global $_cfg,$_GS;
    $proc=proc_open([PHP_BINARY,__FILE__,'worker',json_encode($r,JSON_THROW_ON_ERROR),json_encode(['cfg'=>$_cfg,'gs'=>$_GS],JSON_THROW_ON_ERROR)],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($proc))throw new RuntimeException('Synthetic worker unavailable');
    try{
        financialAssert(currentLine($pipes[1])==="ready\n",'schema bootstrap precedes worker transaction');
        fwrite($pipes[0],"start\n");fflush($pipes[0]);financialAssert(currentLine($pipes[1])==="gate\n",'real old owner snapshot precedes group lock');
        return [$proc,$pipes];
    }catch(Throwable $e){currentAbort([$proc,$pipes]);throw $e;}
}
function currentAbort(array $worker):void
{
    [$proc,$pipes]=$worker;if(is_resource($proc)){proc_terminate($proc,9);foreach($pipes as $p)if(is_resource($p))fclose($p);proc_close($proc);}
}
function currentFinish(array $worker):mixed
{
    [$proc,$pipes]=$worker;
    try{
        fwrite($pipes[0],"go\n");fflush($pipes[0]);fclose($pipes[0]);$out=currentLine($pipes[1]);$err=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($proc);$proc=null;
        financialAssert($code===0&&$err==='','real worker exits without warnings: '.$err.$out);
        $result=json_decode($out,true,64,JSON_THROW_ON_ERROR);financialAssert(!$result['transaction_open'],'worker releases owning transaction');return $result['value'];
    }finally{currentAbort([$proc,$pipes]);}
}
function currentSecond():Connection
{
    $connection=new Connection();if(!$connection->open(getenv('DB_HOST'),'fixture',getenv('DB_USER'),getenv('DB_PASSWORD')))throw new RuntimeException('Synthetic second connection unavailable');return $connection;
}
function currentOn(Connection $connection,callable $work):mixed
{
    global $db,$pdo;$previous=$db;$previousPDO=$pdo;$db=$connection;$pdo=$connection->link;
    try{return $work();}finally{$db=$previous;$pdo=$previousPDO;}
}

$scenarios=0;
foreach(['max_count','one_group','hidden_group','completed_prerequisite','hidden_policy','range_policy','compound_policy'] as $case){
    financialReset();propertyScale(2);$pdo->exec('UPDATE Plans SET pGroup=7,pClComis=0');
    $_cfg['Depo_OneFromGroup']=in_array($case,['one_group','hidden_group'],true)?1:0;
    if($case==='max_count')$pdo->exec('UPDATE Plans SET pMaxCount=1');
    if(in_array($case,['hidden_group','completed_prerequisite'],true)){
        $plan=$db->fetch1Row($db->select('Plans','*','pID=1'));$plan['pID']=2;
        if($case==='hidden_group')$plan['pHidden']=1;else{$plan['pGroup']=8;$plan['pGroupReq']=7;}
        financialAssert(is_int($db->insert('Plans',$plan)),'second synthetic plan');
    }
    financialAssert(opChangeBalance(1,1,'400',0,0)===true,'seed opening funds');$audit=1;
    if($case==='completed_prerequisite'){financialAssert(is_int($prior=opDepoCreate(1,1,'100',0,1)),'initial prerequisite');$audit=3;}
    propertyReconcile($audit);
    $worker=currentStart(['key'=>'current-'.$case,'plan'=>$case==='completed_prerequisite'?2:1,'compound'=>$case==='compound_policy'?50:0]);
    try{
        if($case==='completed_prerequisite'){financialAssert(opDepoSub(1,1,'100',$prior)===true,'B closes prerequisite across A old view');$audit=5;}
        elseif(in_array($case,['max_count','one_group','hidden_group'],true)){
            financialAssert(is_int(opDepoCreate(1,1,'100',0,$case==='hidden_group'?2:1,$case==='hidden_group')),'B creates real occupied principal');$audit=3;
        }else $pdo->exec(match($case){'hidden_policy'=>'UPDATE Plans SET pHidden=1','range_policy'=>'UPDATE Plans SET pMax=50','compound_policy'=>'UPDATE Plans SET pCompndMax=20'});
        propertyReconcile($audit);$committedB=propertyFingerprint();$result=currentFinish($worker);$worker=[];
    }finally{if($worker)currentAbort($worker);}
    if($case==='completed_prerequisite'){
        financialAssert(is_int($result),'current closed-group prerequisite enables opening');$audit+=2;financialWallet(1,1,'300.000000','100.000000');
        financialAssert((int)financialValue('SELECT COUNT(*) FROM Deps WHERE dState=1')===1,'one new active deposit after closure');
        financialAssert((int)financialValue('SELECT COUNT(*) FROM IdempotencyKeys')===1,'one completed opening receipt');
    }else{
        financialAssert($result===($case==='compound_policy'?'compnd_wrong':'plan_wrong'),'current policy refuses stale opening: '.$case);
        financialAssert(propertyFingerprint()===$committedB,'refusal preserves EVERY committed B row and no claim');
        financialWallet(1,1,$audit===3?'300.000000':'400.000000',$audit===3?'100.000000':'0.000000');
        financialAssert((int)financialValue('SELECT COUNT(*) FROM IdempotencyKeys')===0,'refused opening receipt rolls back');
    }
    propertyReconcile($audit);$scenarios++;
}

// An existing caller's RR snapshot can precede a committed mutation. Helpers
// must use current rows while preserving caller ownership and committed B data.
foreach(['add','sub','admin_close','plan','compound_policy','closed'] as $case)foreach([false,true] as $commit){
    financialReset();propertyScale(6);$pdo->exec('UPDATE Plans SET pClComis=0');opChangeBalance(1,1,'400.000005',0,0);
    $did=opDepoCreate(1,1,'100.000001',$case==='compound_policy'?10:50,1);financialAssert(is_int($did),'nested initial deposit');$audit=3;
    if(in_array($case,['plan','compound_policy'],true)){
        $plan=$db->fetch1Row($db->select('Plans','*','pID=1'));$plan['pID']=2;
        if($case==='plan'){$plan['pMin']='100';$pdo->exec('UPDATE Deps SET dZC=90.000001');}else $plan['pCompndMax']=20;
        $db->insert('Plans',$plan);
    }
    propertyReconcile($audit);$second=currentSecond();$pdo->beginTransaction();
    financialAssert(opReadUser(1)['uID']==1,'caller establishes old view');$pdo->query('SELECT * FROM Deps')->fetchAll();
    $db->update('AddInfo',['aCountry'=>'current-'.$case],'','auID=1');
    currentOn($second,static function()use($case,$did,&$audit):void{
        $result=match($case){'compound_policy'=>opDepoChangePlan(1,2,$did),'closed'=>opDepoSub(1,1,'100.000001',$did),default=>opDepoAdd(1,1,'25.000003',$did)};
        financialAssert(!is_string($result),'B real mutation committed before nested current read');if($case!=='compound_policy')$audit+=2;
        propertyReconcile($audit);
    });
    $B=currentOn($second,static fn()=>propertyFingerprint());$before=propertyFingerprint();
    $result=match($case){'add'=>opDepoAdd(1,1,'20.000002',$did),'sub'=>opDepoSub(1,1,'20.000002',$did),'admin_close'=>opDepoClose(1,1,$did),
        'plan'=>opDepoChangePlan(1,2,$did),'compound_policy'=>opDepoChangeCompnd(1,$did,50),'closed'=>opDepoAdd(1,1,'20',$did)};
    financialAssert($pdo->inTransaction()&&financialValue('SELECT aCountry FROM AddInfo WHERE auID=1')==='current-'.$case,'helper preserves caller transaction/sentinel');
    $failure=in_array($case,['compound_policy','closed'],true);
    if($failure){financialAssert($result===($case==='closed'?'depo_wrong':'compnd_wrong'),'nested current state/plan refuses');financialAssert(propertyFingerprint()===$before,'failed nested unit preserves all caller-visible rows');}
    else financialAssert(!is_string($result),'nested mutation succeeds against current principal');
    financialAssert(currentOn($second,static fn()=>propertyFingerprint())===$B,'A effects remain uncommitted and B rows preserved');
    if($commit){
        $pdo->commit();
        [$available,$locked]=match($case){'add'=>['254.999999','145.000006'],'sub'=>['295.000003','105.000002'],'admin_close','closed'=>['400.000005','0.000000'],
            'plan'=>['275.000001','125.000004'],'compound_policy'=>['300.000004','100.000001']};
        financialWallet(1,1,$available,$locked);
        if(in_array($case,['add','sub','admin_close'],true))$audit+=2;
        if($case==='plan')financialAssert(financialValue('SELECT dZC FROM Deps')==='25.000004'&&(int)financialValue('SELECT dpID FROM Deps')===2,'cap uses current principal and selected plan');
        if($case==='admin_close')financialAssert(financialValue('SELECT dZD FROM Deps')==='0.000000'&&(int)financialValue('SELECT dState FROM Deps')===3,'admin closes entire current principal');
        financialAssert(financialValue('SELECT aCountry FROM AddInfo WHERE auID=1')==='current-'.$case,'caller commit includes sentinel');
    }else{
        $pdo->rollBack();financialAssert(propertyFingerprint()===$B,'caller rollback removes only A effects and retains entire committed B');
    }
    propertyReconcile($audit);$scenarios++;
}

// Read-only UI keeps its ordinary snapshot; only the explicit writer mode reads
// current policy. Neither a request-global configuration nor rates are refreshed.
financialReset();propertyScale(2);$pdo->exec('UPDATE Plans SET pMaxCount=1');opChangeBalance(1,1,'400',0,0);propertyReconcile(1);
$second=currentSecond();$pdo->beginTransaction();$initial=opDepoGetPlanList(1,false,false,1);
currentOn($second,static fn()=>opDepoCreate(1,1,'100',0,1));$B=currentOn($second,static fn()=>propertyFingerprint());
financialAssert(opDepoGetPlanList(1,false,false,1)===$initial,'UI default retains readonly snapshot semantics');
opDepoLockGroup(1,1);$current=opDepoGetPlanList(1,false,false,1,0,true);
financialAssert((int)$current[1]['DCNT']===1&&!empty($current[1]['Disabled']),'writer count sees committed occupancy');
$pdo->rollBack();financialAssert(propertyFingerprint()===$B,'current policy reads perform no writes');propertyReconcile(3);
$before=propertyFingerprint();$refused=false;try{opDepoGetPlanList(1,false,false,1,0,true);}catch(LogicException){$refused=true;}
financialAssert($refused&&propertyFingerprint()===$before,'current policy requires an owning transaction');$scenarios+=2;

echo json_encode(['status'=>'passed','assertions'=>$assertions,'scenarios'=>$scenarios,'actual_PHP_worker'=>true,'current_policy_subqueries'=>true,'nested_caller_boundaries'=>12,'known_audit_limitation'=>'F01'],JSON_THROW_ON_ERROR)."\n";
