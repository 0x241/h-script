<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/fixtures/financial_runtime.php';

use HScript\Finance\{FinancialOperationException,FinancialReconciler,IdempotencyRequest,IdempotencyResult,IdempotencyStore};

/** Real SQL faults: either reject the statement or leave the selected row unchanged. */
function stateFault(int $id, int $state, string $mode = 'no_op'): void
{
    global $pdo;
    $pdo->exec('DROP TRIGGER IF EXISTS fixture_state_fault');
    $columns=$pdo->query('SHOW COLUMNS FROM Opers')->fetchAll(PDO::FETCH_COLUMN);
    $body=match($mode) {
        'throw'=>"SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic state write failure';",
        'state_only'=>'SET NEW.oState=OLD.oState;',
        'metadata_only'=>'SET NEW.oBatch=OLD.oBatch,NEW.oParams2=OLD.oParams2;',
        default=>'SET '.implode(',',array_map(static fn($c)=>"NEW.`$c`=OLD.`$c`",$columns)).';',
    };
    $pdo->exec("CREATE TRIGGER fixture_state_fault BEFORE UPDATE ON Opers FOR EACH ROW BEGIN IF ($id=0 OR OLD.oID=$id) AND NEW.oState=$state THEN $body END IF; END");
}

function stateFingerprint(): string
{
    global $pdo;
    $all=[];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $rows=array_map('serialize',$pdo->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC));
        sort($rows);$all[$table]=$rows;
    }
    return hash('sha256',serialize($all));
}

function stateReconciled(int $auditRows): void
{
    global $pdo;
    $before=stateFingerprint();$report=(new FinancialReconciler())->run($pdo,0);
    financialAssert($before===stateFingerprint(),'reconciliation is strictly read-only');
    foreach ($report['checks'] as $id=>$check) {
        // Track the exact expected F01 delta; no blanket finding exclusion.
        $expected=$id==='balance_history_incomplete' ? $auditRows : 0;
        financialAssert($check['count']===$expected,'exact reconciliation findings: '.$id);
    }
}

foreach (['no_op','throw','state_only'] as $mode) {
    $throws=$mode==='throw';
    foreach (['confirm','complete','cancel','reject'] as $action) {
        foreach (['CASHOUT','TR','EX'] as $kind) {
            financialReset();
            $pdo->exec('UPDATE Currs SET cNumDec=2');
            financialAssert(opChangeBalance(1,1,'100',0,0)===true,'seed available money');
            $oid=opOperCreate(1,$kind,1,'25',['uid2'=>2,'cid2'=>2],'',false,true);
            financialAssert(is_int($oid),'create operation before fault');
            if ($action!=='confirm') financialAssert(opOperConfirm(1,$oid,[],true)===true,'initial reservation');
            $audits=$action==='confirm' ? 1 : 2;
            stateReconciled($audits);$before=stateFingerprint();
            $target=match($action) {'confirm'=>2,'complete'=>3,'cancel'=>5,'reject'=>4};
            stateFault($oid,$target,$mode);
            try {
                $result=match($action) {
                    'confirm'=>opOperConfirm(1,$oid,[],true),
                    'complete'=>$kind==='CASHOUT' ? opOperComplete(1,$oid,['date'=>time(),'batch'=>'fixture-state-'.$oid],true) : opOperComplete(1,$oid),
                    default=>opOperCancel(1,$oid,[],$action==='reject'),
                };
                financialAssert(!$throws && $result==='db_error','unpersisted state must report db_error: '.$action.'/'.$kind);
            } catch (PDOException $e) {
                financialAssert($throws && $e->getCode()==='45000','real SQL rejection propagated');
            } finally { $pdo->exec('DROP TRIGGER fixture_state_fault'); }
            financialAssert($before===stateFingerprint(),'fault preserves all tables: '.$action.'/'.$kind);
            stateReconciled($audits);
            $retry=match($action) {
                'confirm'=>opOperConfirm(1,$oid,[],true),
                'complete'=>$kind==='CASHOUT' ? opOperComplete(1,$oid,['date'=>time(),'batch'=>'fixture-state-'.$oid],true) : opOperComplete(1,$oid),
                default=>opOperCancel(1,$oid,[],$action==='reject'),
            };
            financialAssert($retry===true,'same operation can retry after fault');
            $delta=$action==='complete' && $kind!=='CASHOUT' ? 2 : 1;
            stateReconciled($audits+$delta);
            financialAssert((int)financialValue("SELECT oState FROM Opers WHERE oID=$oid")===$target,'retry saves intended state');
        }
    }
}

function stateUsePDO(PDO $connection): void
{
    global $db,$pdo;
    (new ReflectionProperty(HScript\Database\Connection::class,'pdo'))->setValue($db,$connection);
    $db->link=$pdo=$connection;
}
function stateNewPDO(bool $foundRows=false): PDO
{
    return new PDO('mysql:host=database;dbname=fixture;charset=utf8mb4',getenv('DB_USER'),getenv('DB_PASSWORD'),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES=>false,PDO::MYSQL_ATTR_FOUND_ROWS=>$foundRows]);
}
// Receipt postconditions must hold for changed rows and matched rows alike.
foreach ([false,true] as $foundRows) {
    stateUsePDO(stateNewPDO($foundRows));
    financialReset();$pdo->exec('UPDATE Currs SET cNumDec=2');
    $oid=opOperCreate(1,'CASHIN',1,'25',[],'',false,true);
    $params=['date'=>time(),'batch'=>'fixture-repeat-confirm','sum'=>'25.00'];
    financialAssert(opOperConfirm(1,$oid,$params,true)===true,'CASHIN first confirmation');
    $equal=false;
    for ($attempt=0;$attempt<3 && !$equal;$attempt++) {
        $stamp=timeToStamp();$pdo->exec('UPDATE Opers SET oTS='.$stamp.' WHERE oID='.$oid);
        $before=stateFingerprint();stateFault($oid,2);
        $result=opOperConfirm(1,$oid,$params,true);
        $pdo->exec('DROP TRIGGER fixture_state_fault');
        financialAssert(stateFingerprint()===$before,'equal confirmation does not change tables');
        // Crossing a second changes oTS: that is a different write and must fail.
        financialAssert($result===true || ($result==='db_error' && timeToStamp()!==$stamp),'only a clock boundary may make the equal test differ');
        $equal=$result===true;
    }
    financialAssert($equal,'equal locked CASHIN confirmation is a valid no-op');
    foreach (['no_op','metadata_only'] as $mode) {
        $pdo->exec('UPDATE Opers SET oTS=0 WHERE oID='.$oid);
        stateReconciled(0);$before=stateFingerprint();stateFault($oid,2,$mode);
        $changed=$params;$changed['batch']='fixture-different-confirm';
        try {
            financialAssert(opOperConfirm(1,$oid,$changed,true)==='db_error','unsaved receipt must fail regardless of affected-row semantics');
        } finally { $pdo->exec('DROP TRIGGER fixture_state_fault'); }
        financialAssert(stateFingerprint()===$before,'unpersisted receipt change has no effects');stateReconciled(0);
    }
    financialAssert(opOperConfirm(1,$oid,$changed,true)===true,'receipt change retries after fault');stateReconciled(0);
    financialAssert(financialValue('SELECT oBatch FROM Opers WHERE oID='.$oid)==='?fixture-different-confirm','retry saves changed receipt');
}
stateUsePDO(stateNewPDO());

// A failed late state write must also restore the deposit and its parent/child operations.
foreach (['no_op','throw','state_only'] as $mode) {
    foreach (['add','sub','close'] as $action) {
        financialReset();$pdo->exec('UPDATE Currs SET cNumDec=2');
        financialAssert(opChangeBalance(1,1,'100',0,0)===true,'deposit fault seed');
        $did=opDepoCreate(1,1,'50',0,1);financialAssert(is_int($did),'deposit before late fault');
        stateReconciled(3);$before=stateFingerprint();stateFault(0,3,$mode);
        try {
            $result=match($action) {'add'=>opDepoAdd(1,1,'10',$did),'sub'=>opDepoSub(1,1,'10',$did),default=>opDepoClose(1,1,$did)};
            financialAssert($mode!=='throw' && $result==='db_error','deposit late state refusal: '.$action);
        } catch (PDOException $e) { financialAssert($mode==='throw' && $e->getCode()==='45000','deposit SQL rejection propagated'); }
        finally { $pdo->exec('DROP TRIGGER fixture_state_fault'); }
        financialAssert($before===stateFingerprint(),'deposit late failure restores all tables');stateReconciled(3);
        $retry=match($action) {'add'=>opDepoAdd(1,1,'10',$did),'sub'=>opDepoSub(1,1,'10',$did),default=>opDepoClose(1,1,$did)};
        financialAssert($action==='close' ? is_int($retry) : $retry===true,'deposit can retry after late failure');stateReconciled(5);
    }
}

/** Faults wrap actual PDO transaction calls, never an environment-controlled production hook. */
final class StateFaultPDO extends PDO
{
    public ?string $fault=null;
    public function beginTransaction(): bool {
        if ($this->fault==='begin') { $this->fault=null;throw new RuntimeException('synthetic before begin'); }
        return parent::beginTransaction();
    }
    public function commit(): bool {
        $fault=$this->fault;$this->fault=null;
        if ($fault==='commit_before') throw new RuntimeException('synthetic before commit');
        $result=parent::commit();
        if ($fault==='commit_after') throw new RuntimeException('synthetic committed response loss');
        return $result;
    }
}
$faultPDO=new StateFaultPDO('mysql:host=database;dbname=fixture;charset=utf8mb4',getenv('DB_USER'),getenv('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
stateUsePDO($faultPDO);
foreach (['begin','commit_before','commit_after'] as $point) {
    stateUsePDO($faultPDO);
    financialReset();$pdo->exec('UPDATE Currs SET cNumDec=2');
    financialAssert(opChangeBalance(1,1,'100',0,0)===true,'transaction fault seed');
    stateReconciled(1);$before=stateFingerprint();$calls=0;
    $work=static function () use (&$calls): IdempotencyResult {
        $calls++;$oid=FinancialOperationException::check(opOperCreate(1,'TR',1,'25',['uid2'=>2],'',true));
        return new IdempotencyResult(['id'=>$oid],201,$oid);
    };
    $store=new IdempotencyStore($db);$hash=IdempotencyRequest::fingerprint(['kind'=>'TR','amount'=>'25']);
    $faultPDO->fault=$point;
    try { $store->execute('fixture:state-recovery','transaction-fault',1,$hash,$work);throw new LogicException('Missing transaction fault'); }
    catch (RuntimeException $e) { financialAssert(str_starts_with($e->getMessage(),'synthetic '),'transaction fault reached'); }
    financialAssert(!$pdo->inTransaction(),'transaction owner cleans up after fault');stateReconciled($point==='commit_after'?4:1);
    if ($point!=='commit_after') financialAssert(stateFingerprint()===$before,'uncommitted fault has no effects');
    else {
        financialWallet(1,1,'75.000000');financialWallet(2,1,'25.000000');
        financialAssert((int)financialValue('SELECT COUNT(*) FROM Opers')===2,'committed response loss keeps one pair');
    }
    stateUsePDO(stateNewPDO());$store=new IdempotencyStore($db);
    $retry=$store->execute('fixture:state-recovery','transaction-fault',1,$hash,$work);
    financialAssert($retry->status===201 && $retry->replayed===($point==='commit_after'),'retry reflects commit outcome');
    financialAssert($calls===($point==='commit_before'?2:1),'retry only runs uncommitted work');
    financialWallet(1,1,'75.000000');financialWallet(2,1,'25.000000');stateReconciled(4);
    $after=stateFingerprint();$again=$store->execute('fixture:state-recovery','transaction-fault',1,$hash,$work);
    financialAssert($again->replayed && $again->data===$retry->data && $after===stateFingerprint(),'original committed response replays exactly');
}
echo json_encode(['status'=>'passed','assertions'=>$assertions,'state_fault_scenarios'=>45,'receipt_faults'=>4,'transaction_faults'=>3,
    'known_audit_limitation'=>'F01'],JSON_THROW_ON_ERROR)."\n";
