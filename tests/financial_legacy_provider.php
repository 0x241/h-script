<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/fixtures/financial_runtime.php';
use HScript\Payment\Provider\{LegacyProviderService as L,ProviderException};
use HScript\Database\TransactionScope;
set_exception_handler(static function(Throwable$e):void{fwrite(STDERR,$e::class.': '.$e->getMessage().' at '.basename($e->getFile()).':'.$e->getLine()."\n");exit(1);});
if(($argv[1]??'')==='worker'){
    $request=json_decode($argv[2],true,32,JSON_THROW_ON_ERROR);fwrite(STDOUT,"ready\n");fflush(STDOUT);if(trim((string)fgets(STDIN))!=='go')exit(2);
    if($request['action']==='add')$result=L::add($db,90,'BANKWIRE',['assets'=>$request['assets'],'form_complete'=>'1']);
    else $result=TransactionScope::run($pdo,static function()use($db,$pdo,$request):bool{L::lock($db,'BW');$q=$pdo->prepare('SELECT * FROM Currs WHERE cID=? FOR UPDATE');$q->execute([$request['id']]);$row=$q->fetch(PDO::FETCH_ASSOC);if(L::enabledConflict($db,$row))return false;$pdo->prepare('UPDATE Currs SET cDisabled=0 WHERE cID=?')->execute([$row['cID']]);return true;});
    echo json_encode($result,JSON_THROW_ON_ERROR)."\n";exit;
}
function legacyRace(array $requests):array{
    $children=[];foreach($requests as$r){$proc=proc_open([PHP_BINARY,__FILE__,'worker',json_encode($r,JSON_THROW_ON_ERROR)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);$children[]=[$proc,$pipes];}
    foreach($children as[$proc,$pipes])financialAssert(fgets($pipes[1])==="ready\n",'independent workers ready');
    foreach($children as[$proc,$pipes]){fwrite($pipes[0],"go\n");fclose($pipes[0]);}
    $result=[];foreach($children as[$proc,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);financialAssert(proc_close($proc)===0&&$err==='','worker completes: '.$err);$result[]=json_decode($out,true,32,JSON_THROW_ON_ERROR);}return $result;
}
financialReset();
$money=static function()use($pdo):string{$data=[];foreach(['Users','Wallets','Opers','Hist','Deps','Plans','Cfg','Jobs','IdempotencyKeys','PaymentProviders','PaymentProviderCredentials']as$t){$rows=array_map('serialize',$pdo->query('SELECT * FROM '.$t)->fetchAll(PDO::FETCH_ASSOC));sort($rows);$data[$t]=$rows;}return hash('sha256',serialize($data));};
$before=$money();$old=$pdo->query('SELECT * FROM Currs ORDER BY cID')->fetchAll(PDO::FETCH_ASSOC);
$reject=static function(callable$f,string$reason):void{try{$f();throw new LogicException('Expected '.$reason);}catch(ProviderException$e){financialAssert($e->reason===$reason,'reject '.$reason);}};
$reject(static fn()=>L::add($db,89,'BANKWIRE',['assets'=>['EUR'],'form_complete'=>'1']),'forbidden');
foreach([['assets'=>['BTC'],'form_complete'=>'1'],['assets'=>['EUR','EUR'],'form_complete'=>'1'],['assets'=>['EUR']],['assets'=>[],'form_complete'=>'1'],['assets'=>['EUR'],'form_complete'=>'1','cCID'=>'CP'],['assets'=>['EUR'],'form_complete'=>'1','other_btn'=>'1']]as$bad)$reject(static fn()=>L::add($db,90,'BANKWIRE',$bad),'input_invalid');
financialAssert($old===$pdo->query('SELECT * FROM Currs ORDER BY cID')->fetchAll(PDO::FETCH_ASSOC)&&$before===$money(),'invalid selections have zero writes');
$pdo->exec("UPDATE PaymentGatewayCatalog SET pgAvailable=0 WHERE pgCode='BANKWIRE'");$reject(static fn()=>L::add($db,90,'BANKWIRE',['assets'=>['EUR'],'form_complete'=>'1']),'provider_unavailable');$pdo->exec("UPDATE PaymentGatewayCatalog SET pgAvailable=1 WHERE pgCode='BANKWIRE'");
legacyRace([['action'=>'add','assets'=>['EUR','RUB']],['action'=>'add','assets'=>['RUB','GBP']]]);
foreach(['EUR','RUB','GBP']as$code)financialAssert((int)financialValue("SELECT COUNT(*) FROM Currs WHERE cCID='BW' AND cCurrID=".$pdo->quote($code))===1,'one row per overlapping fiat '.$code);
financialAssert(L::add($db,90,'BANKWIRE',['assets'=>['EUR','RUB'],'form_complete'=>'1'])===[],'exact retry is no-op');
foreach(['CAD'=>'cad','CHF'=>'CHF ']as$canonical=>$stored){
    $id=$db->insert('Currs',['cCID'=>'BW','cCurrID'=>$stored,'cDisabled'=>1,'cHidden'=>1]);$snapshot=$pdo->query('SELECT * FROM Currs WHERE cID='.(int)$id)->fetch(PDO::FETCH_ASSOC);
    $match=(int)financialValue("SELECT COUNT(*) FROM Currs WHERE cCID='BW' AND cCurrID=".$pdo->quote($canonical))>0;
    $added=L::add($db,90,'BANKWIRE',['assets'=>[$canonical],'form_complete'=>'1']);
    financialAssert(count($added)===($match?0:1)&&$snapshot===$pdo->query('SELECT * FROM Currs WHERE cID='.(int)$id)->fetch(PDO::FETCH_ASSOC),'actual SQL collation/PAD occupancy without canonical predecessor');
}
$rows=$pdo->query("SELECT * FROM Currs WHERE cCID='BW' AND cCurrID IN ('EUR','RUB','GBP')")->fetchAll(PDO::FETCH_ASSOC);
foreach($rows as$r){financialAssert($r['cProviderID']===null&&(int)$r['cDisabled']===1&&(int)$r['cHidden']===1&&(int)$r['cNumDec']===2,'new rows disabled/hidden exact scale');foreach(['CASHIN','CASHOUT','EX','TR','BUY','SELL','BUY2','SELL2','GIVE','TAKE']as$mode)financialAssert((int)$r['c'.$mode.'Mode']===0,'explicit zero mode '.$mode);}
$currents=$pdo->query('SELECT * FROM Currs ORDER BY cID')->fetchAll(PDO::FETCH_ASSOC);$pdo->exec("CREATE TRIGGER legacy_add_fault BEFORE INSERT ON Currs FOR EACH ROW BEGIN IF NEW.cCID='BW' AND NEW.cCurrID='SGD' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic late legacy insert failure'; END IF; END");
try{L::add($db,90,'BANKWIRE',['assets'=>['NZD','SGD'],'form_complete'=>'1']);throw new LogicException('Expected late SQL failure');}catch(PDOException){financialAssert($currents===$pdo->query('SELECT * FROM Currs ORDER BY cID')->fetchAll(PDO::FETCH_ASSOC)&&$money()===$before,'later insert failure rolls back all earlier rows');}finally{$pdo->exec('DROP TRIGGER legacy_add_fault');}
$ids=$pdo->query("SELECT cCurrID,cID FROM Currs WHERE cCID='BW' AND cCurrID IN ('EUR','RUB')")->fetchAll(PDO::FETCH_KEY_PAIR);
foreach($ids as$id)TransactionScope::run($pdo,static function()use($db,$pdo,$id):void{L::lock($db,'BW');$q=$pdo->prepare('SELECT * FROM Currs WHERE cID=? FOR UPDATE');$q->execute([$id]);financialAssert(!L::enabledConflict($db,$q->fetch(PDO::FETCH_ASSOC)),'different fiat may enable');$pdo->prepare('UPDATE Currs SET cDisabled=0 WHERE cID=?')->execute([$id]);});
$duplicate=$db->insert('Currs',['cCID'=>'BW','cCurrID'=>'EUR','cDisabled'=>1]);$pdo->exec("UPDATE Currs SET cDisabled=1 WHERE cCID='BW' AND cCurrID='EUR'");$race=legacyRace([['action'=>'enable','id'=>$ids['EUR']],['action'=>'enable','id'=>$duplicate]]);sort($race);financialAssert($race===[false,true],'only one duplicate-fiat enable wins');
financialAssert($money()===$before,'configuration changes do not alter money or keys');
financialAssert($old===$pdo->query('SELECT * FROM Currs WHERE cID IN (1,2,3) ORDER BY cID')->fetchAll(PDO::FETCH_ASSOC),'existing currency identities/encryption remain exact');
foreach(['YOOMONEY'=>['YM','YMC'],'WEBMONEY'=>['USD'],'SBERBANK'=>['RUB']]as$kind=>$assets){$added=L::add($db,90,$kind,['assets'=>$assets,'form_complete'=>'1']);financialAssert(count($added)===count($assets),'fixed protocol variants separate '.$kind);}
$_cfg['Bal_RateRUB']='1';$eur=(int)$ids['RUB'];$currency=$db->fetch1Row($db->select('Currs','*','cID=?d',[$eur]));$currency['cCASHINMode']=1;$db->update('Currs',['cDisabled'=>0,'cCASHINMode'=>1],'','cID=?d',[$eur]);$_currs=$db->fetchIDRows($db->select('Currs'),false,'cID');
$details=['iban'=>'DE89370400440532013000','bname'=>'Synthetic recipient bank','cname'=>'Synthetic beneficiary'];$salt=time();$cipher=encodeArrayToStr($details,$eur.'1'.$salt);$db->insert('Wallets',['wuID'=>1,'wcID'=>$eur,'wParams'=>$cipher,'wMTS'=>timeToStamp($salt)]);
$oid=opOperCreate(1,'CASHIN',$eur,'2.25',[],'',false,true);financialAssert(is_int($oid),'manual fiat invoice created');financialAssert(opOperConfirm(1,$oid,['date'=>time(),'batch'=>'synthetic-bank-receipt','acc'=>'different-payer'],true)===true,'manual trusted statement confirmation');financialAssert(opOperComplete(1,$oid,[],true)===true,'manual bank credit completes');
$wallet=$db->fetch1Row($db->select('Wallets','*','wuID=1 AND wcID=?d',[$eur]));financialAssert($wallet['wParams']===$cipher&&opDecodeUserCurrParams($wallet)===$details,'CASHIN never replaces IBAN-only bank recipient details');financialAssert($wallet['wBal']==='2.250000','manual credit exact selected fiat');$after=financialFingerprint();financialAssert(opOperComplete(1,$oid,[],true)===true&&financialFingerprint()===$after,'manual bank replay no additional effect');
echo 'Legacy provider SQL: '.$assertions." checks passed\n";
