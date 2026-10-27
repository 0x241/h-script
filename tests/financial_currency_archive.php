<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/fixtures/financial_runtime.php';
use HScript\Database\TransactionScope;
use HScript\Payment\Provider\{CurrencyArchive as A,CurrencyArchiveSchema as S,LegacyProviderService as L,ProviderException,ProviderCatalogSchema};
use HScript\Update\{SchemaStateRepository,MigrationRunner,MigrationLoader};
set_exception_handler(static function(Throwable$e):void{fwrite(STDERR,$e::class.': '.$e->getMessage().' / '.($e->getPrevious()?->getMessage()??'').' at '.basename($e->getFile()).':'.$e->getLine()."\n");exit(1);});
financialReset();ProviderCatalogSchema::ensure($db);
foreach(['SchemaState','SchemaMigrations','UpdateRuns']as$t)$pdo->exec('CREATE TABLE IF NOT EXISTS '.$t.' ('.$_dbstru[$t].') ENGINE=InnoDB');
$state=new SchemaStateRepository($db);$state->setCurrentVersion('1.0.15');$runner=new MigrationRunner($db,$state,new MigrationLoader(dirname(__DIR__).'/migrations/versioned'));
$drop=str_contains((string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION),'MariaDB')?'DROP CONSTRAINT':'DROP CHECK';
if((int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME='chk_currency_archived'")->fetchColumn())$pdo->exec('ALTER TABLE Currs '.$drop.' chk_currency_archived');
$pdo->exec('ALTER TABLE Currs DROP COLUMN cArchivedAt');
$legacy=$pdo->query('SELECT * FROM Currs ORDER BY cID')->fetchAll(PDO::FETCH_ASSOC);
try{$runner->run('1.0.16',false);throw new LogicException('Missing backup accepted');}catch(RuntimeException$e){if($e instanceof LogicException)throw$e;financialAssert(!S::ready($pdo)&&$state->currentVersion()==='1.0.15','explicit verified backup required');}
financialAssert($runner->run('1.0.16',true)===['202610261200_currency_archive'],'ordered explicit archive migration');
$after=$pdo->query('SELECT * FROM Currs ORDER BY cID')->fetchAll(PDO::FETCH_ASSOC);foreach($after as&$r){financialAssert($r['cArchivedAt']===null,'no speculative archive backfill');unset($r['cArchivedAt']);}unset($r);financialAssert($after===$legacy,'migration preserves all pre-existing currency data');S::ensure($db);financialAssert(S::ready($pdo),'repeat DDL verification');
$reject=static function(callable$f,string$reason):void{try{$f();throw new LogicException('Expected '.$reason);}catch(ProviderException$e){financialAssert($e->reason===$reason,'reject '.$reason);}};
$money=static function()use($pdo):string{$out=[];foreach(['Users','Wallets','Opers','Deps','Hist','Jobs','IdempotencyKeys']as$t)$out[$t]=$pdo->query('SELECT * FROM '.$t.' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);return hash('sha256',serialize($out));};
$archive=static function(int$id)use($db):void{TransactionScope::run($db->link,static function()use($db,$id):void{$c=$db->fetch1Row($db->query('SELECT * FROM Currs WHERE cID=?d FOR UPDATE',[$id]));A::archive($db,$c);});};
$get=static fn(int$id):array=>$db->fetch1Row($db->select('Currs','*','cID=?d',[$id]));
$select=static fn(array$assets,array$extra=[]):array=>array_replace(['assets'=>$assets,'form_complete'=>'1','revision'=>L::revision(L::rows($db,'BANKWIRE'))],$extra);
$reject(static fn()=>L::select($db,89,'BANKWIRE',$select(['USD'])),'forbidden');
$reject(static fn()=>L::select($db,90,'BANKWIRE',$select(['USD'],['form_complete'=>'0'])),'input_invalid');
$reject(static fn()=>L::select($db,90,'BANKWIRE',$select(['BTC'])),'input_invalid');
// Real legacy account fields retain their original encryption context across membership changes.
$pdo->exec("UPDATE Currs SET cCID='*' WHERE cID=1");$db->insert('Wallets',['wuID'=>1,'wcID'=>2,'wBal'=>'0','wLock'=>'0','wOut'=>'0']);
$row=$get(2);$pdo->prepare('UPDATE Currs SET cCID=?,cCurrID=?,cName=?,cMTS=?,cParams=?,cParamsSCI=?,cParamsAPI=? WHERE cID=2')->execute(['BW','EUR','Bank EUR','20261026101010','original-pay','original-sci','original-api']);
$stale=$select(['EUR']);$pdo->exec('UPDATE Wallets SET wBal=0.000001 WHERE wuID=1 AND wcID=2');$before=$money();$original=$get(2);L::select($db,90,'BANKWIRE',$select([]));$archived=$get(2);
financialAssert($money()===$before&&$archived['cArchivedAt']!==null,'archive preserves all money/history');
$operational=$_currs;$holdings=HScript\Finance\ArchivedHoldings::forUser($db,1);financialAssert(count($holdings)===1&&$holdings[0]['wBal']==='0.000001'&&$_currs===$operational,'archived dust remains visible read-only without operational catalog mutation');financialAssert(HScript\Finance\ArchivedHoldings::forUser($db,2)===[]&&HScript\Finance\ArchivedHoldings::forUser($db,0)===[],'no other owner holdings leak');financialAssert(!array_intersect(['cParams','cParamsSCI','cParamsAPI','wParams','cProviderKeyVersion'],array_keys($holdings[0])),'readonly projection exposes no addresses or credentials');
foreach(['cID','cCID','cCurrID','cMTS','cParams','cParamsSCI','cParamsAPI','cProviderID','cProviderKeyVersion']as$f)financialAssert(($original[$f]??null)===($archived[$f]??null),'immutable original '.$f);
foreach(S::MODES as$mode)financialAssert((int)$archived['c'.$mode.'Mode']===0,'archive closes '.$mode);
foreach(['cDisabled'=>0,'cHidden'=>0,'cProviderSelected'=>1,'cCASHINMode'=>2,'cEXMode'=>1]as$f=>$v){try{$pdo->exec('UPDATE Currs SET '.$f.'='.$v.' WHERE cID=2');throw new LogicException('Guard bypass');}catch(PDOException){financialAssert($get(2)===$archived,'SQL archive guard '.$f);}}
$reject(static fn()=>L::select($db,90,'BANKWIRE',$stale),'configuration_changed');
financialAssert(opOperCreate(1,'CASHIN',2,'1')==='currency_archived','stale global create denied');
financialAssert(opOperCreateInfo(1,'BONUS',2,'1')==='currency_archived','stale info writer denied');
financialAssert(opDepoCreate(1,2,'10',1)==='currency_archived','stale deposit writer denied');
financialAssert(opOperCreate(1,'EX',1,'1',['cid2'=>2])==='currency_archived','exchange target guard');
financialAssert($money()===$before,'stale writers leave no claims/jobs/financial rows');
L::select($db,90,'BANKWIRE',$select(['EUR']));$restored=$get(2);financialAssert($restored['cArchivedAt']===null&&(int)$restored['cDisabled']===1&&(int)$restored['cHidden']===1,'same ID restored disabled');
foreach(['cMTS','cParams','cParamsSCI','cParamsAPI']as$f)financialAssert($restored[$f]===$original[$f],'restore original ciphertext context '.$f);
$revision=$select(['EUR']);L::select($db,90,'BANKWIRE',$revision);financialAssert($get(2)===$restored&&$money()===$before,'repeat selection no financial effect or duplicate row');
$pending=$db->insert('Opers',['ouID'=>1,'ocID'=>2,'oOper'=>'CASHIN','oState'=>0,'oSum'=>'1']);$reject(static fn()=>$archive(2),'currency_archive_operations');$pdo->exec('DELETE FROM Opers WHERE oID='.$pending);
$pdo->exec('UPDATE Wallets SET wLock=1 WHERE wuID=1 AND wcID=2');$reject(static fn()=>$archive(2),'currency_archive_reservations');$pdo->exec('UPDATE Wallets SET wLock=0 WHERE wuID=1 AND wcID=2');
$dep=$db->insert('Deps',['duID'=>1,'dcID'=>2,'dpID'=>1,'dState'=>1]);$reject(static fn()=>$archive(2),'currency_archive_deposits');$pdo->exec('DELETE FROM Deps WHERE dID='.$dep);
$ex=$db->insert('Opers',['ouID'=>1,'ocID'=>1,'oOper'=>'EX','oState'=>0,'oSum'=>'1','oParams'=>arrayToStr(['cid2'=>2])]);$reject(static fn()=>$archive(2),'currency_archive_operations');$pdo->exec('DELETE FROM Opers WHERE oID='.$ex);
$closed=$db->insert('Opers',['ouID'=>1,'ocID'=>2,'oOper'=>'CASHIN','oState'=>4,'oSum'=>'1']);
(new HScript\Finance\IdempotencyStore($db))->execute('invoice:attempt',(string)$closed,1,str_repeat('b',64),static fn()=>new HScript\Finance\IdempotencyResult(['started'=>true],202,$closed));
$reject(static fn()=>$archive(2),'currency_archive_invoice');$pdo->exec("UPDATE Opers SET oState=3 WHERE oID=$closed");$before=$money();$archive(2);financialAssert($before===$money(),'archive never manufactures settlement proof or modifies closed operations');
TransactionScope::run($pdo,static fn()=>A::restore($db,$get(2)));
// Late SQL failure must roll back an earlier archive from this same form.
L::select($db,90,'BANKWIRE',$select(['EUR','USD']));$all=L::rows($db,'BANKWIRE');$before=$money();$saved=$pdo->query('SELECT * FROM Currs ORDER BY cID')->fetchAll(PDO::FETCH_ASSOC);
$pdo->exec("CREATE TRIGGER currency_archive_fault BEFORE UPDATE ON Currs FOR EACH ROW BEGIN IF NEW.cArchivedAt IS NOT NULL AND OLD.cCurrID='USD' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic archive failure'; END IF; END");
try{L::select($db,90,'BANKWIRE',$select([]));throw new LogicException('Late failure missed');}catch(PDOException){financialAssert($pdo->query('SELECT * FROM Currs ORDER BY cID')->fetchAll(PDO::FETCH_ASSOC)===$saved&&$before===$money(),'whole selection rolls back after late failure');}finally{$pdo->exec('DROP TRIGGER currency_archive_fault');}
// Independent real connection holds money/currency locks while the other owner fails atomically.
$other=new HScript\Database\Connection();if(!$other->open(getenv('DB_HOST'),'fixture',getenv('DB_USER'),getenv('DB_PASSWORD')))throw new RuntimeException('Second fixture connection');
$other->link->beginTransaction();$other->query('SELECT wuID FROM Wallets WHERE wuID=1 AND wcID=2 FOR UPDATE');$before=$money();$saved=$pdo->query('SELECT * FROM Currs ORDER BY cID')->fetchAll(PDO::FETCH_ASSOC);$started=microtime(true);
try{$archive(2);throw new LogicException('Busy wallet accepted');}catch(PDOException$e){financialAssert(in_array((int)($e->errorInfo[1]??0),[1205,1213,3572],true)&&microtime(true)-$started<3&&!$pdo->inTransaction(),'archive current blocker NOWAIT and owning rollback');}
financialAssert($saved===$pdo->query('SELECT * FROM Currs ORDER BY cID')->fetchAll(PDO::FETCH_ASSOC)&&$money()===$before,'busy archive has zero partial effects');$other->link->rollBack();
$pdo->beginTransaction();$pdo->query('SELECT cID FROM Currs WHERE cID=2 FOR UPDATE');$before=$money();$started=microtime(true);
$oldDb=$GLOBALS['db'];$GLOBALS['db']=$other;
try{TransactionScope::run($other->link,static function()use($other):void{$other->query('SELECT uID FROM Users WHERE uID=1 FOR UPDATE');$other->query('UPDATE Users SET uBal=uBal+1 WHERE uID=1');$result=opOperCreate(1,'CASHIN',2,'1');if(is_string($result))throw new RuntimeException($result);});throw new LogicException('Writer waited on currency');}catch(PDOException$e){financialAssert(in_array((int)($e->errorInfo[1]??0),[1205,1213,3572],true)&&microtime(true)-$started<3&&!$other->link->inTransaction(),'nested money owner NOWAIT rollback without lock cycle');}finally{$GLOBALS['db']=$oldDb;$pdo->rollBack();}
financialAssert($money()===$before,'nested-owner failure rolls back every wallet/user/operation/job/claim');
// After the conflicting owner is gone a fresh complete operation succeeds.
$_currs=$db->fetchIDRows($db->select('Currs'),false,'cID');$id=opOperCreateInfo(1,'BONUS',2,'1');financialAssert(is_int($id)&&$id>0&&!$pdo->inTransaction(),'fresh retry outside failed transaction succeeds');
// Repeatable-read stale snapshot never authorizes a first writer after archive committed.
$other->link->beginTransaction();$other->query('SELECT cArchivedAt FROM Currs WHERE cID=2');$archive(2);financialAssert(A::writable($other,[2])===false,'current archive check defeats stale RR snapshot');$other->link->rollBack();
financialAssert(!HScript\Finance\ArchivedReplay::inactive(['cProviderID'=>1,'cProviderSelected'=>0]),'historical inactive profile is not speculative archive');
foreach(['label=1&label=2','label=0','label=01','label[]=1','label=2147483648']as$raw){try{HScript\Finance\ArchivedReplay::operationTag('YM',$raw);throw new LogicException('Ambiguous route accepted');}catch(InvalidArgumentException){financialAssert(true,'strict exact-order routing');}}
financialAssert(HScript\Finance\ArchivedReplay::operationTag('CP','invoice=123&item_number=999')===123,'CoinPayments original invoice domain');
// A legacy checkbox represents all historical accounts of the same tuple.
$duplicate=$db->insert('Currs',['cCID'=>'BW','cCurrID'=>'EUR','cName'=>'Other original EUR','cDisabled'=>1,'cHidden'=>1,'cParamsAPI'=>'other-original-cipher']);
$db->insert('Wallets',['wuID'=>1,'wcID'=>$duplicate,'wBal'=>'2.000001','wLock'=>'0','wOut'=>'0']);
L::select($db,90,'BANKWIRE',$select([]));$funds=$money();L::select($db,90,'BANKWIRE',$select(['EUR']));
foreach([2,$duplicate]as$cid){$row=$get($cid);financialAssert($row['cArchivedAt']===null&&(int)$row['cDisabled']===1&&(int)$row['cHidden']===1,'group restores every original ID disabled');}
financialAssert($get(2)['cParamsAPI']===$original['cParamsAPI']&&$get($duplicate)['cParamsAPI']==='other-original-cipher'&&$money()===$funds,'duplicate account balances/ciphers never merge or move');
echo "Currency archive tests passed: $assertions assertions.\n";
