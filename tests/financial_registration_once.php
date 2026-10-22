<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/fixtures/financial_runtime.php';
require __DIR__.'/fixtures/financial_property_support.php';
require dirname(__DIR__).'/module/account/register/lib.php';
require dirname(__DIR__).'/module/balance/onload.php';
use HScript\Database\Connection;
use HScript\Finance\FinancialOperationException;

/** Observe the real current account lock; do not require a blocked worker to reach a second gate. */
final class RegistrationRaceConnection extends Connection
{
    public bool $barrier=false;
    private bool $gated=false;
    public function query($query,$values=null)
    {
        if ($this->barrier && !$this->gated && str_contains($query,'FROM Users LEFT JOIN AddInfo') && str_contains($query,'FOR UPDATE')) {
            $this->gated=true;fwrite(STDOUT,"gate\n");fflush(STDOUT);
            if (trim((string)fgets(STDIN))!=='go') throw new RuntimeException('Synthetic activation barrier not released');
        }
        return parent::query($query,$values);
    }
}
if (($argv[1]??'')==='worker') {
    ini_set('display_errors','stderr');$request=json_decode($argv[2],true,64,JSON_THROW_ON_ERROR);$environment=json_decode($argv[3],true,64,JSON_THROW_ON_ERROR);
    $db=new RegistrationRaceConnection();if(!$db->open(getenv('DB_HOST'),'fixture',getenv('DB_USER'),getenv('DB_PASSWORD'))) throw new RuntimeException('Synthetic worker unavailable');
    $db->barrier=$request['gate'];$pdo=$db->link;$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL '.$request['isolation']);$_cfg=$environment['cfg'];$_GS=$environment['gs'];$_onload=['Balance'=>true];$_currs=$db->fetchIDRows($db->select('Currs'),false,'cID');
    fwrite(STDOUT,"ready\n");fflush(STDOUT);if(trim((string)fgets(STDIN))!=='start')exit(2);
    if(!$db->barrier){fwrite(STDOUT,"attempt\n");fflush(STDOUT);}$result=opRegisterComplete($request['uid']);echo json_encode(['value'=>$result,'transaction_open'=>$pdo->inTransaction()],JSON_THROW_ON_ERROR)."\n";exit;
}
function registrationLine($pipe): string
{
    $read=[$pipe];$write=$except=[];if(stream_select($read,$write,$except,30)!==1)throw new RuntimeException('Synthetic registration worker timeout');$line=fgets($pipe);if($line===false)throw new RuntimeException('Synthetic worker exited');return $line;
}
function registrationPair(string $isolation): void
{
    global $_cfg,$_GS;$workers=[];
    try {
        foreach([2,'02']as $index=>$uid){$process=proc_open([PHP_BINARY,__FILE__,'worker',json_encode(['uid'=>$uid,'isolation'=>$isolation,'gate'=>$index===0],JSON_THROW_ON_ERROR),json_encode(['cfg'=>$_cfg,'gs'=>$_GS],JSON_THROW_ON_ERROR)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Synthetic worker unavailable');$workers[]=[$process,$pipes];}
        foreach($workers as [$process,$pipes])financialAssert(registrationLine($pipes[1])==="ready\n",'all registration schema bootstraps finish before activation');
        foreach($workers as [$process,$pipes]){fwrite($pipes[0],"start\n");fflush($pipes[0]);}
        foreach($workers as $index=>[$process,$pipes])financialAssert(registrationLine($pipes[1])===($index===0?"gate\n":"attempt\n"),'actual source current-lock gate or attempt');
        foreach($workers as $index=>[$process,$pipes]){if($index===0){fwrite($pipes[0],"go\n");fflush($pipes[0]);}fclose($pipes[0]);}
        foreach($workers as [$process,$pipes]){$output=registrationLine($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);financialAssert($code===0&&$stderr==='','actual registration worker clean exit: '.$stderr.$output);$result=json_decode($output,true,64,JSON_THROW_ON_ERROR);financialAssert($result['value']===true&&!$result['transaction_open'],'both activation calls finish, each releases financial transaction');}$workers=[];
    }finally{foreach($workers as [$process,$pipes])if(is_resource($process)){proc_terminate($process,9);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($process);}}
}
function registrationSettings(bool $internal=true): void
{
    global $_cfg;$_cfg['Const_IntCurr']=$internal?1:0;$_cfg['Bal_RegBonus']='25';$_cfg['Bal_RateUSD']='1';$_cfg['Bal_RateEUR']='2';$_cfg['Bal_RateBTC']='5';$_cfg['Account_RegLogin']=0;$_cfg['Sec_MinPIN']=4;
}
function registrationWithoutReceipts(): string
{
    global $pdo;$all=[];foreach($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)as $table){if($table==='IdempotencyKeys')continue;$rows=array_map('serialize',$pdo->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC));sort($rows,SORT_STRING);$all[$table]=$rows;}ksort($all);return hash('sha256',serialize($all));
}
function registrationReceipt(int $uid,bool $issued,int $sources): void
{
    global $pdo;$query=$pdo->prepare("SELECT * FROM IdempotencyKeys WHERE ikNamespace='registration:bonus' AND ikOwnerID=?");$query->execute([$uid]);$rows=$query->fetchAll(PDO::FETCH_ASSOC);financialAssert(count($rows)===1&&$rows[0]['ikState']==='completed','one durable completed registration source identity');$data=json_decode($rows[0]['ikResponse'],true,64,JSON_THROW_ON_ERROR)['data'];financialAssert($data['issued']===$issued&&count($data['operation_ids'])===$sources,'receipt stores exact issued decision and original source IDs');
    if($issued){$q=$pdo->prepare("SELECT oID FROM Opers WHERE ouID=? AND oOper='BONUS' AND oState=3 ORDER BY oID");$q->execute([$uid]);financialAssert(array_map('strval',$data['operation_ids'])===array_map('strval',$q->fetchAll(PDO::FETCH_COLUMN)),'receipt exact persisted BONUS IDs, no synthetic ID');financialAssert((string)$rows[0]['ikOperationID']===(string)$data['operation_ids'][0],'primary receipt operation is an actual original BONUS');}
    else financialAssert(($data['reason']??null)==='legacy_bonus_unbound','ambiguous legacy issuance explicitly classified, provenance never invented');
}
// The runner can execute this producer using the genuine prior installed image.
// Its old onBalanceEvent has no registration marker and retains actual SQL rows.
if(($argv[1]??'')==='legacy-produce'){
    financialReset();propertyScale(2);registrationSettings();onBalanceEvent('RegComplete',2,[]);financialWallet(2,1,'25.000000');financialAssert((int)financialValue('SELECT COUNT(*) FROM IdempotencyKeys')===0,'genuine pre-marker emitter');propertyReconcile(1);echo "Legacy registration fixture produced by installed old handler.\n";exit;
}
if(($argv[1]??'')==='legacy-consume'){
    $_cfg['UI_NumDec']=2;$_currs=$db->fetchIDRows($db->select('Currs'),false,'cID');registrationSettings();$_cfg['Bal_RegBonus']='100';$before=registrationWithoutReceipts();propertyReconcile(1);financialAssert(onBalanceEvent('RegComplete',2,[])===true,'new event safely handles genuine old issuance');financialAssert(registrationWithoutReceipts()===$before,'legacy money/history/queue/config unchanged, no guessed second bonus');registrationReceipt(2,false,0);propertyReconcile(1);echo "Legacy registration cutover preserved every historical row.\n";exit;
}
$scenarios=0;
foreach(['REPEATABLE READ','READ COMMITTED']as $isolation)foreach(['internal','converted','partial_supported']as $family){
    financialReset();propertyScale(2);registrationSettings($family==='internal');if($family==='partial_supported')$_cfg['Bal_RateBTC']='bad';$db->update('Users',['uState'=>0,'uRef'=>0],'','uID=2');propertyReconcile(0);registrationPair($isolation);
    $expected=$family==='internal'?[1=>'25.000000']:($family==='converted'?[1=>'25.000000',2=>'12.500000',3=>'5.000000']:[1=>'25.000000',2=>'12.500000']);
    financialAssert((int)financialValue("SELECT COUNT(*) FROM Opers WHERE oOper='BONUS' AND oState=3")===count($expected),'one original issuance per supported currency, no duplicate activation bonus');foreach($expected as $cid=>$amount)financialWallet(2,$cid,$amount);registrationReceipt(2,true,count($expected));propertyReconcile(count($expected));
    // The current account transition preserves one PIN and registration history.
    // Financial event retries below must change no table.
    financialAssert((int)financialValue("SELECT COUNT(*) FROM Hist WHERE hOper='REG'")===1,'one current activation and original PIN/history');$before=propertyFingerprint();
    $_cfg['Bal_RegBonus']='100';$_cfg['Bal_RateBTC']='1';$_cfg['Const_IntCurr']=$family==='internal'?0:1;
    foreach([2,'2','02','+2','2.0']as $uid){financialAssert(onBalanceEvent('RegComplete',$uid,[])===true&&propertyFingerprint()===$before,'normalized owner and changed bonus/currency/rate configuration cannot pay again');propertyReconcile(count($expected));}
    $scenarios++;
}
// Separate accounts get separate identities, while unrelated event kinds have
// no claim or financial effect.
financialReset();propertyScale(2);registrationSettings();onBalanceEvent('RegComplete',2,[]);onBalanceEvent('RegComplete',3,[]);financialWallet(2,1,'25.000000');financialWallet(3,1,'25.000000');registrationReceipt(2,true,1);registrationReceipt(3,true,1);propertyReconcile(2);$before=propertyFingerprint();onBalanceEvent('OtherEvent',2,[]);financialAssert(propertyFingerprint()===$before,'unrelated event has no row effects');$scenarios++;
// Existing unbound Bonus rows are ambiguous; no automatic backfill or new money.
financialReset();propertyScale(2);registrationSettings();financialAssert(is_int(opOperCreate(2,'BONUS',1,'25',[],'Bonus',true,true)),'old emitter exact actual financial primitive');$before=registrationWithoutReceipts();onBalanceEvent('RegComplete',2,[]);registrationReceipt(2,false,0);financialAssert(registrationWithoutReceipts()===$before,'legacy ambiguity guard preserves all money/history/config/queue rows');propertyReconcile(1);$scenarios++;
// A different manual bonus memo is a separate intentional source, not a guessed
// previous registration issuance.
financialReset();propertyScale(2);registrationSettings();opOperCreate(2,'BONUS',1,'7',[],'Separate manual award',true,true);onBalanceEvent('RegComplete',2,[]);financialWallet(2,1,'32.000000');$snapshot=json_decode((string)financialValue("SELECT ikResponse FROM IdempotencyKeys WHERE ikNamespace='registration:bonus'"),true);financialAssert(count($snapshot['data']['operation_ids'])===1&&$snapshot['data']['issued']===true,'registration receipt excludes unrelated manual source');propertyReconcile(2);$scenarios++;
// Real late SQL error occurs after the earlier currency/notification writes.
foreach([false,true]as $nested){
    financialReset();propertyScale(2);registrationSettings(false);$jobQueue=new HScript\Queue\JobQueue($db);FinancialFixtureView::$queueMail=true;$pdo->exec("CREATE TRIGGER RegistrationLateFailure BEFORE INSERT ON Opers FOR EACH ROW BEGIN IF NEW.oOper='BONUS' AND NEW.ocID=3 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated late registration bonus failure'; END IF; END");
    if($nested){$pdo->beginTransaction();$pdo->exec("UPDATE AddInfo SET aName='retained caller' WHERE auID=1");}$before=propertyFingerprint();
    try{onBalanceEvent('RegComplete',2,[]);throw new RuntimeException('Genuine late registration fault absent');}catch(PDOException $e){financialAssert($e->getCode()==='45000'&&(int)$e->errorInfo[1]===1644,'genuine late SQL45000, no simulated result');}
    financialAssert(propertyFingerprint()===$before&&$pdo->inTransaction()===$nested,'all bonus currencies/claim/Jobs rollback together, caller ownership retained');if($nested)$pdo->rollBack();propertyReconcile(0);$pdo->exec('DROP TRIGGER RegistrationLateFailure');
    financialAssert(onBalanceEvent('RegComplete',2,[])===true,'same registration can retry after uncommitted late failure');registrationReceipt(2,true,3);financialAssert((int)financialValue('SELECT COUNT(*) FROM Jobs')===3,'only committed BONUS notifications queued, no worker runs');propertyReconcile(3);$scenarios++;
}
// Outer rollback removes its own bonus and child receipt without poisoning a
// subsequent registration; commit retains precisely that original issuance.
foreach([false,true]as $commit){financialReset();propertyScale(2);registrationSettings();$before=propertyFingerprint();$pdo->beginTransaction();$pdo->exec("UPDATE AddInfo SET aName='owned caller' WHERE auID=1");financialAssert(onBalanceEvent('RegComplete',2,[])===true&&$pdo->inTransaction(),'participating registration event keeps parent ownership');registrationReceipt(2,true,1);if($commit)$pdo->commit();else$pdo->rollBack();if(!$commit)financialAssert(propertyFingerprint()===$before,'parent rollback removes child claim and every financial effect');propertyReconcile($commit?1:0);onBalanceEvent('RegComplete',2,[]);registrationReceipt(2,true,1);financialWallet(2,1,'25.000000');propertyReconcile(1);$scenarios++;}
// authLoadCurrencies omits disabled currencies; a missing internal currency
// keeps the old unsupported-currency skip instead of aborting activation.
financialReset();propertyScale(2);registrationSettings();unset($_currs[1]);$before=propertyFingerprint();
financialAssert(onBalanceEvent('RegComplete',2,[])===true,'unavailable internal currency skips registration bonus');
financialAssert(propertyFingerprint()===$before,'unsupported internal currency creates no money/claim/Jobs');propertyReconcile(0);$scenarios++;
echo json_encode(['status'=>'passed','assertions'=>$assertions,'scenarios'=>$scenarios,'actual_activation_race'=>true,'source_activation_atomic'=>true,'legacy_history_never_rewritten'=>true,'exact_reconciliation'=>true,'no_notification_worker'=>true],JSON_THROW_ON_ERROR)."\n";
