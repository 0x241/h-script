<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/fixtures/financial_runtime.php';
use HScript\Payment\Provider\{ProviderService,ProviderRepository,ProviderException,CurrencyArchive,CurrencyArchiveSchema,CurrencySelection,LegacyProviderService,PaykassaNetworks};
use HScript\Payment\Routing\TokenCatalogService;
use HScript\Database\TransactionScope;
set_exception_handler(static function(Throwable$e):void{fwrite(STDERR,$e::class.': '.$e->getMessage().' at '.basename($e->getFile()).':'.$e->getLine()."\n");exit(1);});
financialReset();CurrencyArchiveSchema::ensure($db);putenv('APP_DATA_KEY=synthetic-explicit-activation-key');
$service=new ProviderService($db,static fn()=>[]);$repo=new ProviderRepository($db);
$get=static function(int$id)use($pdo):array{$q=$pdo->prepare('SELECT * FROM Currs WHERE cID=?');$q->execute([$id]);return$q->fetch(PDO::FETCH_ASSOC);};
$snapshot=static function()use($pdo):string{$all=[];foreach($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)as$table){$rows=array_map('serialize',$pdo->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC));sort($rows);$all[$table]=$rows;}return hash('sha256',serialize($all));};
$reject=static function(callable$f,string$reason)use($snapshot):void{$before=$snapshot();try{$f();throw new LogicException('Expected '.$reason);}catch(ProviderException$e){financialAssert($e->reason===$reason,'reject '.$reason);financialAssert($snapshot()===$before,'rejected activation leaves complete database unchanged');}};
$input=static function(string$kind,int$pid,array$selected,array$extra=[])use($repo):array{$credentials=$kind==='PAYKASSA'&&$pid?['merchant_id'=>'100','api_id'=>'200']:[];$result=array_replace(['profile_id'=>(string)$pid,'revision'=>(string)($pid?$repo->find($pid)['ppRevision']:0),'credentials'=>$credentials,'currencies'=>$selected,'enabled'=>'1','form_complete'=>'1'],$extra);$result['credentials']=array_replace($credentials,$extra['credentials']??[]);return$result;};
$pid=$service->save(90,'CCAPI',$input('CCAPI',0,['ETH','TRX'],['credentials'=>['v3_key'=>'synthetic-activation-secret']]));
$rows=array_column($repo->connections($pid),null,'cCurrID');$eth=(int)$rows['ETH']['cID'];$trx=(int)$rows['TRX']['cID'];
financialAssert((int)$get($eth)['cDisabled']===1,'no activation intent preserves prior service behavior');
$service->save(90,'CCAPI',$input('CCAPI',$pid,['ETH','TRX']));financialAssert((int)$get($eth)['cDisabled']===1,'no-scope resave does not activate');
$old=$get($trx);$service->save(90,'CCAPI',$input('CCAPI',$pid,['ETH','TRX'],['activation_scope'=>['ETH']]));$testEth=$get($eth);$testTrx=$get($trx);if((int)$testEth['cDisabled']!==0||$testTrx!==$old)fwrite(STDERR,json_encode(['eth_disabled'=>$testEth['cDisabled'],'trx_changed_fields'=>array_keys(array_diff_assoc($testTrx,$old))]).PHP_EOL);financialAssert((int)$testEth['cDisabled']===0&&$testTrx===$old,'explicit visible native only activates intended row');
foreach([['UNKNOWN'],['ETH','ETH']]as$scope)$reject(static fn()=>$service->save(90,'CCAPI',$input('CCAPI',$pid,['ETH','TRX'],['activation_scope'=>$scope])),'input_invalid');
$reject(static fn()=>CurrencySelection::scope(['activation_scope'=>['TRX']],['ETH','TRX'],['ETH']),'input_invalid');
$reject(static fn()=>$service->save(90,'CCAPI',$input('CCAPI',$pid,['ETH'],['activation_scope'=>['ETH'],'form_complete'=>'0'])),'input_invalid');
$stale=$input('CCAPI',$pid,['ETH','TRX'],['activation_scope'=>['ETH']]);$service->save(90,'CCAPI',$input('CCAPI',$pid,['ETH','TRX']));$reject(static fn()=>$service->save(90,'CCAPI',$stale),'configuration_changed');
// Restoration keeps the original identity, amounts and proof domains; modes stay zero.
$pdo->prepare('INSERT INTO Wallets(wuID,wcID,wBal,wLock,wOut) VALUES(1,?,12.345678,0,0)')->execute([$eth]);
$before=$get($eth);$service->save(90,'CCAPI',$input('CCAPI',$pid,['TRX']));$service->save(90,'CCAPI',$input('CCAPI',$pid,['ETH','TRX'],['activation_scope'=>['ETH']]));$after=$get($eth);
financialAssert($after['cArchivedAt']===null&&(int)$after['cDisabled']===0&&(int)$after['cHidden']===0,'restored original row enabled and visible');
foreach(['cID','cCID','cMTS','cParams','cParamsSCI','cParamsAPI','cProviderKeyVersion']as$f)financialAssert($after[$f]===$before[$f],'activation retains original '.$f);
foreach(CurrencyArchiveSchema::MODES as$m)financialAssert((int)$after['c'.$m.'Mode']===0,'restored mode remains zero '.$m);
financialAssert((string)$pdo->query('SELECT wBal FROM Wallets WHERE wuID=1 AND wcID='.$eth)->fetchColumn()==='12.345678','no balance conversion or transfer');
$pdo->exec('UPDATE Currs SET cDisabled=1,cCASHOUTMode=2 WHERE cID='.$eth);
$reject(static fn()=>$service->save(90,'CCAPI',$input('CCAPI',$pid,['ETH','TRX'],['activation_scope'=>['ETH']])),'ccapi_payout_source_required');
(new TokenCatalogService($db))->saveWallets(90,$pid,(int)$repo->find($pid)['ppRevision'],'ethereum','','0x'.str_repeat('3',40));
$service->save(90,'CCAPI',$input('CCAPI',$pid,['ETH','TRX'],['activation_scope'=>['ETH']]));financialAssert((int)$get($eth)['cDisabled']===0&&(int)$get($eth)['cCASHOUTMode']===2,'valid stored payout policy resumes without changing mode');
$service->save(90,'CCAPI',$input('CCAPI',$pid,['ETH','TRX'],['activation_scope'=>['ETH'],'enabled'=>'0']));financialAssert((int)$get($eth)['cDisabled']===1&&(int)$get($eth)['cCASHOUTMode']===0,'gateway off overrides visible activation');
// PayKassa keeps hidden token selection byte exact and never enables unsupported token payouts.
$pk=$service->save(90,'PAYKASSA',$input('PAYKASSA',0,['BTC','PKBT'],['credentials'=>['merchant_id'=>'100','merchant_secret'=>'synthetic-sci','api_id'=>'200','api_secret'=>'synthetic-api']]));
$pkRows=array_column($repo->connections($pk),null,'cCID');$btc=(int)$pkRows['PKB']['cID'];$token=(int)$pkRows['PKBT']['cID'];$hidden=$get($token);
$service->save(90,'PAYKASSA',$input('PAYKASSA',$pk,['BTC','PKBT'],['activation_scope'=>['BTC']]));financialAssert((int)$get($btc)['cDisabled']===0&&$get($token)===$hidden,'PayKassa hidden token not activated by native-page save');
$service->save(90,'PAYKASSA',$input('PAYKASSA',$pk,['BTC','PKBT'],['activation_scope'=>['PKBT']]));financialAssert((int)$get($token)['cDisabled']===0,'PayKassa explicit token activation');
$pdo->exec('UPDATE Currs SET cDisabled=1,cCASHOUTMode=2 WHERE cID='.$token);$reject(static fn()=>$service->save(90,'PAYKASSA',$input('PAYKASSA',$pk,['BTC','PKBT'],['activation_scope'=>['PKBT']])),'automatic_withdrawal_unsupported');$pdo->exec('UPDATE Currs SET cCASHOUTMode=0 WHERE cID='.$token);
// A late activation failure rolls back earlier activations and a proposed new credential version.
$pdo->exec('UPDATE Currs SET cDisabled=1 WHERE cProviderID='.$pk);
$pdo->exec("CREATE TRIGGER activation_fault BEFORE UPDATE ON Currs FOR EACH ROW BEGIN IF OLD.cID=$token AND OLD.cDisabled=1 AND NEW.cDisabled=0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic activation failure'; END IF; END");$before=$snapshot();
try{$service->save(90,'PAYKASSA',$input('PAYKASSA',$pk,['BTC','PKBT'],['activation_scope'=>['BTC','PKBT'],'credentials'=>['api_secret'=>'synthetic-proposed-key']]));throw new LogicException('Late trigger missed');}catch(PDOException){financialAssert($before===$snapshot(),'late activation failure rolls back entire profile, keys and rows');}finally{$pdo->exec('DROP TRIGGER activation_fault');}
financialAssert(PaykassaNetworks::native('PKBN')==='binancecoin'&&PaykassaNetworks::token('PKBT')==='bsc','system29 and BSC system31 stay distinct');
financialAssert(PaykassaNetworks::token('PKTU')==='shared'&&isset(PaykassaNetworks::visible('shared')['USDT'])&&isset(PaykassaNetworks::visible('tron')['USDT'])&&isset(PaykassaNetworks::visible('ethereum')['USDT']),'legacy shared USDT retains its original group on TRON navigation');
financialAssert(PaykassaNetworks::navigation('PKB')===null&&PaykassaNetworks::navigation('PKDG')===null&&PaykassaNetworks::navigation('PKBN')==='bsc','token-free native links hidden and BSC navigation grouped without changing native identity');
$reject(static fn()=>PaykassaNetworks::visible('forged'),'input_invalid');
// Legacy group activation cannot silently select or enable multiple historical accounts.
$pdo->exec("UPDATE Currs SET cCID='*' WHERE cID=1");$legacy=static function(array$assets,array$extra=[])use($db):array{return array_replace(['assets'=>$assets,'form_complete'=>'1','revision'=>LegacyProviderService::revision(LegacyProviderService::rows($db,'BANKWIRE'))],$extra);};
LegacyProviderService::select($db,90,'BANKWIRE',$legacy(['USD'],['activation_scope'=>['USD']]));$usd=$pdo->query("SELECT * FROM Currs WHERE cCID='BW' AND cCurrID='USD'")->fetch(PDO::FETCH_ASSOC);financialAssert((int)$usd['cDisabled']===0&&(int)$usd['cHidden']===0,'single legacy account activated');
$db->insert('Currs',['cCID'=>'BW','cCurrID'=>'USD','cDisabled'=>1,'cHidden'=>1,'cName'=>'Other original account']);$reject(static fn()=>LegacyProviderService::select($db,90,'BANKWIRE',$legacy(['USD'],['activation_scope'=>['USD']])),'activation_ambiguous');
echo "Currency activation tests passed: $assertions assertions.\n";
