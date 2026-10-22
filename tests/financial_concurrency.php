<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/fixtures/financial_runtime.php';
require __DIR__.'/fixtures/financial_property_support.php';
use HScript\Database\Connection;
use HScript\Finance\{FinancialOperationException,GatewaySettlement,IdempotencyStore,IdempotencyResult,IdempotencyRequest};

/** The barrier observes real lock statements; it never replaces SQL or financial results. */
final class ConcurrentFinancialConnection extends Connection
{
    public string $point='none';
    public bool $reached=false;
    public function query($query,$ph_values=null)
    {
        $user=str_contains($query,'SELECT uID FROM Users WHERE uID=?d FOR UPDATE')
            || str_contains($query,'SELECT uID,uBal FROM Users WHERE uID=?d FOR UPDATE');
        $gate=!$this->reached && $user && $this->point!=='none';
        if ($gate && $this->point==='before_user') $this->barrier();
        $result=parent::query($query,$ph_values);
        if ($gate && $this->point==='after_user') $this->barrier();
        return $result;
    }
    private function barrier(): void
    {
        $this->reached=true;fwrite(STDOUT,"gate\n");fflush(STDOUT);
        if (trim((string)fgets(STDIN))!=='go') throw new RuntimeException('Synthetic barrier not released');
    }
}

function concurrentWork(array $r): mixed
{
    global $db;
    if ($r['kind']==='callback') return GatewaySettlement::settle($db,'CP',2,$r['callback'])->data;
    if ($r['kind']==='charge') return opDepoCharge($r['deposit']);
    $result=(new IdempotencyStore($db))->execute('fixture:concurrency',$r['key'],$r['uid'],IdempotencyRequest::fingerprint(array_diff_key($r,['point'=>true])),static function()use($r):IdempotencyResult {
        $id=null;
        if (in_array($r['kind'],['withdraw','transfer'],true)) {
            $id=FinancialOperationException::check(opOperCreate($r['uid'],$r['kind']==='withdraw'?'CASHOUT':'TR',1,$r['amount'],['uid2'=>$r['target']??0],$r['key'],false,true));
            FinancialOperationException::check(opOperConfirm($r['uid'],$id,[],true));
            FinancialOperationException::check(opOperComplete($r['uid'],$id,$r['kind']==='withdraw'?['date'=>time(),'batch'=>'fixture-race-'.$id,'sum'=>$r['amount']]:[],true));
            $value=true;
        } else $value=match($r['kind']) {
            'add'=>opDepoAdd($r['uid'],1,$r['amount'],$r['did']),
            'sub'=>opDepoSub($r['uid'],1,$r['amount'],$r['did']),
            'cancel'=>opOperCancel($r['uid'],$r['id']),
            'complete'=>opOperComplete($r['uid'],$r['id']),
            default=>throw new LogicException('Unknown synthetic concurrent operation'),
        };
        FinancialOperationException::check($value);
        return new IdempotencyResult(['result'=>$value],200,$id);
    });
    return $result->data['result'];
}

if (($argv[1]??'')==='worker') {
    ini_set('display_errors','stderr');
    $r=json_decode($argv[2],true,64,JSON_THROW_ON_ERROR);
    $env=json_decode($argv[3],true,64,JSON_THROW_ON_ERROR);
    $db=new ConcurrentFinancialConnection();
    if (!$db->open(getenv('DB_HOST'),'fixture',getenv('DB_USER'),getenv('DB_PASSWORD'))) throw new RuntimeException('Synthetic worker connection failed');
    $pdo=$db->link;$db->point=$r['point']??'none';$_cfg=$env['cfg'];$_GS=$env['gs'];$_onload=[];
    $_currs=$db->fetchIDRows($db->select('Currs'),false,'cID');
    // Every worker finishes test-only schema bootstrap before any transaction begins.
    fwrite(STDOUT,"ready\n");fflush(STDOUT);
    if (trim((string)fgets(STDIN))!=='start') exit(2);
    if ($db->point==='none') {fwrite(STDOUT,"attempt\n");fflush(STDOUT);}
    try {$value=concurrentWork($r);}
    catch (FinancialOperationException $e) {$value=$e->getMessage();}
    echo json_encode(['value'=>$value,'gated'=>$db->reached,'transaction_open'=>$pdo->inTransaction()],JSON_THROW_ON_ERROR)."\n";
    exit;
}

function concurrentLine($pipe,string $stage='result'): string
{
    $ready=[$pipe];$write=$except=[];
    if (stream_select($ready,$write,$except,30)!==1) throw new RuntimeException('Synthetic worker timeout at '.$stage);
    $line=fgets($pipe);
    if ($line===false) throw new RuntimeException('Synthetic worker exited before response');
    return $line;
}

function concurrentStart(array $requests,?callable $hold=null): array
{
    global $_cfg,$_GS;
    $workers=[];
    try {
        foreach ($requests as $r) {
            $p=proc_open([PHP_BINARY,__FILE__,'worker',json_encode($r,JSON_THROW_ON_ERROR),json_encode(['cfg'=>$_cfg,'gs'=>$_GS],JSON_THROW_ON_ERROR)],
                [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if (!is_resource($p)) throw new RuntimeException('Synthetic worker unavailable');
            stream_set_timeout($pipes[1],30);$workers[]=['process'=>$p,'pipes'=>$pipes,'request'=>$r];
        }
        foreach ($workers as $w) financialAssert(concurrentLine($w['pipes'][1],'schema-ready')==="ready\n",'all schema bootstraps finish before starting financial transactions');
        if ($hold) $hold();
        $gated=count(array_filter($workers,static fn($w)=>($w['request']['point']??'none')!=='none'))===count($workers);
        if ($gated) {
            // A receipt claim can hold a next-key lock BEFORE its user gate.
            // Start distinct fixture receipts in ascending unique-index order
            // so a later INSERT cannot wait in an earlier claim's lower gap.
            // Every real user gate remains held until concurrentFinish sends go;
            // both transactions still overlap, including the forced deadlock.
            $ordered=$workers;
            $hash=static fn($w)=>hash('sha256',$w['request']['uid']."\0".$w['request']['key']);
            if (count($ordered)>1) {
                foreach ($ordered as $w)
                    if (in_array($w['request']['kind'],['charge','callback'],true)) throw new LogicException('Gated receipt order requires explicit fixture claims');
                financialAssert(count(array_unique(array_map($hash,$ordered)))===count($ordered),'gated fixture receipt keys are distinct');
                usort($ordered,static fn($a,$b)=>strcmp($hash($a),$hash($b)));
            }
            foreach ($ordered as $w) {
                fwrite($w['pipes'][0],"start\n");fflush($w['pipes'][0]);
                financialAssert(concurrentLine($w['pipes'][1],'user-gate')==="gate\n",'actual concurrent boundary reached');
            }
        } else {
            foreach ($workers as $w) {fwrite($w['pipes'][0],"start\n");fflush($w['pipes'][0]);}
            foreach ($workers as $w) financialAssert(concurrentLine($w['pipes'][1],'parallel-attempt')===(($w['request']['point']??'none')==='none'?"attempt\n":"gate\n"),'actual concurrent boundary reached');
        }
        return $workers;
    } catch (Throwable $e) {concurrentAbort($workers);throw $e;}
}
function concurrentAbort(array $workers): void
{
    foreach ($workers as $w) {
        if (is_resource($w['process'])) {proc_terminate($w['process'],9);foreach($w['pipes'] as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($w['process']);}
    }
}
function concurrentFinish(array $workers): array
{
    $results=[];
    try {
        foreach ($workers as $w) {if (($w['request']['point']??'none')!=='none') {fwrite($w['pipes'][0],"go\n");fflush($w['pipes'][0]);}fclose($w['pipes'][0]);}
        foreach ($workers as $w) {
            $out=concurrentLine($w['pipes'][1]);$err=stream_get_contents($w['pipes'][2]);fclose($w['pipes'][1]);fclose($w['pipes'][2]);
            $code=proc_close($w['process']);financialAssert($code===0 && $err==='','real worker exit and stderr: '.$err.$out);
            $r=json_decode($out,true,64,JSON_THROW_ON_ERROR);financialAssert(!$r['transaction_open'],'worker releases its transaction');
            $results[]=$r['value'];
        }
        return $results;
    } finally {concurrentAbort($workers);}
}
function concurrentControl(): string
{
    global $pdo;
    $rows=[];
    foreach(['Cfg','AddInfo','Currs','Plans','PaymentProviders','PaymentProviderCredentials','Jobs'] as $t) {
        $r=array_map('serialize',$pdo->query('SELECT * FROM '.$t)->fetchAll(PDO::FETCH_ASSOC));sort($r,SORT_STRING);$rows[$t]=$r;
    }
    return hash('sha256',serialize($rows));
}
function concurrentClean(int $audit,string $control): void
{
    financialAssert(concurrentControl()===$control,'concurrent financial work changes no unrelated configuration/provider/queue row');
    financialAssert((int)financialValue("SELECT COUNT(*) FROM IdempotencyKeys WHERE ikState<>'completed'")===0,'no stranded concurrent receipt');
    propertyReconcile($audit);
}
function concurrentReplay(array $requests): void
{
    foreach ($requests as $r) {
        $before=propertyFingerprint();$w=concurrentStart([array_replace($r,['point'=>'none'])]);$result=concurrentFinish($w);
        financialAssert(propertyFingerprint()===$before,'fresh-process retry preserves ALL table rows');
    }
}
$scenarios=0;

foreach (['withdraw','transfer'] as $kind) {
    financialReset();propertyScale(2);financialAssert(opChangeBalance(1,1,'100',0,0)===true,'competing source seed');propertyReconcile(1);$control=concurrentControl();
    $requests=[['kind'=>$kind,'key'=>$kind.'-first','uid'=>1,'target'=>2,'amount'=>'80','point'=>'before_user'],['kind'=>$kind,'key'=>$kind.'-second','uid'=>1,'target'=>3,'amount'=>'80','point'=>'before_user']];
    $results=concurrentFinish(concurrentStart($requests));financialAssert(count(array_filter($results,static fn($r)=>$r===true))===1 && count(array_filter($results,static fn($r)=>$r==='low_bal1'))===1,'distinct competing requests cannot overdraft');
    financialWallet(1,1,'20.000000');
    financialAssert((int)financialValue('SELECT COUNT(*) FROM Opers')===($kind==='withdraw'?1:2),'only successful source/child rows committed');
    financialAssert((int)financialValue('SELECT COUNT(*) FROM IdempotencyKeys')===1,'failed request leaves no receipt');
    if ($kind==='transfer') financialAssert(financialValue('SELECT SUM(wBal+wLock+wOut) FROM Wallets')==='100.000000','transfer conservation including losing request');
    concurrentClean($kind==='withdraw'?3:4,$control);concurrentReplay($requests);concurrentClean($kind==='withdraw'?3:4,$control);$scenarios++;
}
// Both source-user locks are held at the barrier: one real deadlock must retry its whole receipt.
financialReset();propertyScale(2);opChangeBalance(1,1,'100',0,0);opChangeBalance(2,1,'100',0,0);propertyReconcile(2);$control=concurrentControl();
$requests=[['kind'=>'transfer','key'=>'left','uid'=>1,'target'=>2,'amount'=>'80','point'=>'after_user'],['kind'=>'transfer','key'=>'right','uid'=>2,'target'=>1,'amount'=>'80','point'=>'after_user']];
financialAssert(concurrentFinish(concurrentStart($requests))===[true,true],'opposite transfers finish after actual lock contention');
financialWallet(1,1,'100.000000');financialWallet(2,1,'100.000000');
financialAssert((int)financialValue('SELECT COUNT(*) FROM Opers')===4 && (int)financialValue('SELECT COUNT(*) FROM IdempotencyKeys')===2,'deadlock retry preserves one pair per request');
concurrentClean(8,$control);concurrentReplay($requests);concurrentClean(8,$control);$scenarios++;

foreach (['two_adds','add_sub','two_subs','two_closes'] as $case) {
    financialReset();propertyScale(2);$db->update('Plans',['pClComis'=>0],'','pID=1');opChangeBalance(1,1,'200',0,0);$did=opDepoCreate(1,1,'100',0,1);propertyReconcile(3);$control=concurrentControl();
    $specs=match($case){'two_adds'=>[['add','20'],['add','30']],'add_sub'=>[['add','20'],['sub','50']],'two_subs'=>[['sub','60'],['sub','60']],'two_closes'=>[['sub','100'],['sub','100']]};
    $requests=[];foreach($specs as $i=>[$kind,$amount])$requests[]=['kind'=>$kind,'key'=>$case.'-'.$i,'uid'=>1,'did'=>$did,'amount'=>$amount,'point'=>'before_user'];
    $results=concurrentFinish(concurrentStart($requests));$success=count(array_filter($results,static fn($v)=>$v===true));
    financialAssert($success===(in_array($case,['two_adds','add_sub'],true)?2:1),'exact concurrent deposit winners');
    [$available,$locked,$state,$ops,$audit]=match($case){'two_adds'=>['50.000000','150.000000',1,3,7],'add_sub'=>['130.000000','70.000000',1,3,7],'two_subs'=>['160.000000','40.000000',1,2,5],'two_closes'=>['200.000000','0.000000',3,2,5]};
    financialWallet(1,1,$available,$locked);financialAssert((int)financialValue('SELECT dState FROM Deps')===$state,'deposit state after competing mutations');
    financialAssert((int)financialValue('SELECT COUNT(*) FROM Opers')===$ops && (int)financialValue('SELECT COUNT(*) FROM IdempotencyKeys')===$success,'exact source and receipt counts for concurrent deposits');
    concurrentClean($audit,$control);concurrentReplay($requests);concurrentClean($audit,$control);$scenarios++;
}

// A really overlapping second SQL session commits after A's owner snapshot,
// before A's group locks. A must price and release the current principal.
foreach ([1,2] as $periods) foreach (['add','sub','close'] as $mutation) {
    financialReset();propertyScale(6);$db->update('Plans',['pNPer'=>$periods,'pClComis'=>0],'','pID=1');
    opChangeBalance(1,1,'400.000005',0,0);$did=opDepoCreate(1,1,'100.000001',0,1);$original=financialDepo($did);propertyReconcile(3);$control=concurrentControl();
    $request=['kind'=>'charge','deposit'=>$original,'uid'=>0,'key'=>'unused','point'=>'before_user'];$workers=concurrentStart([$request]);
    try {
        $result=match($mutation){'add'=>opDepoAdd(1,1,'25.000003',$did),'sub'=>opDepoSub(1,1,'25.000003',$did),'close'=>opDepoSub(1,1,'100.000001',$did)};
        financialAssert($result===true,'second connection commits during suspended accrual');
        propertyReconcile(in_array($mutation,['add','sub','close'],true)?5:4);
        $results=concurrentFinish($workers);$workers=[];
    } finally {concurrentAbort($workers);}
    $terminal=$periods===1;
    if ($mutation==='close') {
        financialAssert($results===['depo_boundary_changed'],'closed deposit refuses stale boundary');financialWallet(1,1,'400.000005');$audit=5;$profit='0.000000';
    } else {
        financialAssert($results===[true],'current locked principal accrual succeeds');
        [$principal,$profit,$available]= $mutation==='add'?['125.000004','12.500000',$terminal?'412.500005':'287.500001']:['74.999998','7.500000',$terminal?'407.500005':'332.500007'];
        financialWallet(1,1,$available,$terminal?'0.000000':$principal);$audit=6;
    }
    financialAssert(financialValue('SELECT dZP FROM Deps')===$profit,'accrual uses actual current principal/profit');
    concurrentClean($audit,$control);$before=propertyFingerprint();
    $replay=concurrentFinish(concurrentStart([array_replace($request,['point'=>'none'])]));financialAssert($replay===$results && propertyFingerprint()===$before,'fresh worker preserves original boundary result or refusal');
    concurrentClean($audit,$control);$scenarios++;
}

foreach ([1,2] as $periods) {
    financialReset();propertyScale(6);$db->update('Plans',['pNPer'=>$periods,'pClComis'=>0],'','pID=1');
    opChangeBalance(1,1,'400.000005',0,0);$did=opDepoCreate(1,1,'100.000001',0,1);$original=financialDepo($did);propertyReconcile(3);$control=concurrentControl();
    $requests=array_fill(0,4,['kind'=>'charge','deposit'=>$original,'uid'=>0,'key'=>'unused','point'=>'none']);
    try {$workers=concurrentStart($requests,static function()use($pdo){$pdo->beginTransaction();$pdo->query('SELECT uID FROM Users WHERE uID=1 FOR UPDATE')->fetchColumn();});$pdo->commit();$results=concurrentFinish($workers);}
    finally {if($pdo->inTransaction())$pdo->rollBack();}
    financialAssert($results===[true,true,true,true],'duplicate concurrent accruals return one original boundary result');
    financialWallet(1,1,$periods===1?'410.000005':'310.000004',$periods===1?'0.000000':'100.000001');
    financialAssert((int)financialValue('SELECT COUNT(*) FROM IdempotencyKeys')===1 && financialValue('SELECT dZP FROM Deps')==='10.000000','one accrual receipt and one profit');
    concurrentClean(4,$control);concurrentReplay($requests);concurrentClean(4,$control);$scenarios++;
}

foreach (['transfer','callback'] as $kind) {
    financialReset();propertyScale(2);$control=concurrentControl();
    if ($kind==='transfer') {opChangeBalance(1,1,'100',0,0);$id=opOperCreate(1,'TR',1,'30',['uid2'=>2],'',false,true);opOperConfirm(1,$id,[],true);$audit=2;}
    else {$id=opOperCreate(1,'CASHIN',2,'30');$audit=0;}
    propertyReconcile($audit);
    $a=['kind'=>$kind==='transfer'?'complete':'callback','uid'=>1,'key'=>'completion','id'=>$id,'point'=>'none'];
    if ($kind==='callback')$a['callback']=['correct'=>true,'authenticated'=>true,'outcome'=>'verified','profile_key_hash'=>hash('sha256','fixture-key'),'profile_timezone'=>'','tag'=>$id,'batch'=>'fixture-concurrent-callback','sum'=>'30','curr'=>'EUR','accto'=>'fixture-merchant'];
    $requests=[$a,['kind'=>'cancel','uid'=>1,'key'=>'cancellation','id'=>$id,'point'=>'none']];
    try {$workers=concurrentStart($requests,static function()use($pdo,$id){$pdo->beginTransaction();$pdo->query('SELECT oID FROM Opers WHERE oID='.$id.' FOR UPDATE')->fetchColumn();});$pdo->commit();$results=concurrentFinish($workers);}
    finally {if($pdo->inTransaction())$pdo->rollBack();}
    $state=(int)financialValue('SELECT oState FROM Opers WHERE oID='.$id);financialAssert(in_array($state,[3,5],true),'only one terminal transition wins');
    if ($kind==='transfer') {
        financialWallet(1,1,$state===3?'70.000000':'100.000000');
        financialAssert((int)financialValue('SELECT COUNT(*) FROM Opers')===($state===3?2:1),'cancel/completion preserves exact paired effects');$audit=$state===3?4:3;
    } else {
        financialAssert((int)financialValue('SELECT COUNT(*) FROM Wallets')===($state===3?1:0),'callback/cancel has at most one credit');
        if ($state===3)financialWallet(1,2,'30.000000');$audit=$state===3?1:0;
    }
    concurrentClean($audit,$control);concurrentReplay($requests);concurrentClean($audit,$control);$scenarios++;
}
echo json_encode(['status'=>'passed','assertions'=>$assertions,'scenarios'=>$scenarios,'actual_php_workers'=>true,'real_SQL_barriers'=>true,'known_audit_limitation'=>'F01','scope'=>'distinct money/deposit requests; forced owner-snapshot accrual races; callback/cancel and completion/cancel'],JSON_THROW_ON_ERROR)."\n";
