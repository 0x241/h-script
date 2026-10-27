<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/fixtures/financial_runtime.php';
use HScript\Payment\{CoinPaymentsNewClient,PaymentManager,CallbackCapabilities};
use HScript\Payment\Provider\{CoinPaymentsNewSchema,CoinPaymentsNewService,ProviderConnectionSchema,ProviderNetworkSchema,ProviderCatalogSchema,PaykassaAssetSchema,ProviderRepository,ProviderService,ProviderException,CurrencyPresentation};
use HScript\Update\{SchemaStateRepository,MigrationRunner,MigrationLoader};
putenv('APP_DATA_KEY=synthetic-coinnew-key');
set_exception_handler(static function(Throwable$e):void{fwrite(STDERR,$e::class.': '.$e->getMessage().' at '.basename($e->getFile()).':'.$e->getLine()."\n");exit(1);});
financialReset();
if(!$pdo->query("SHOW COLUMNS FROM Currs LIKE 'cTokenRoute'")->fetch()){
    ProviderConnectionSchema::ensure($db);ProviderNetworkSchema::ensure($db);
    HScript\Payment\Routing\PaymentRouteSchema::ensure($db);HScript\Payment\Routing\TokenCatalogSchema::ensure($db);
}
PaykassaAssetSchema::ensure($db);ProviderCatalogSchema::ensure($db);
foreach(['SchemaState','SchemaMigrations','UpdateRuns']as$t)$pdo->exec('CREATE TABLE IF NOT EXISTS '.$t.' ('.$_dbstru[$t].') ENGINE=InnoDB');
$state=new SchemaStateRepository($db);$state->setCurrentVersion('1.0.14');$runner=new MigrationRunner($db,$state,new MigrationLoader(dirname(__DIR__).'/migrations/versioned'));

$ddl=static function()use($pdo):string{$result=[];foreach(['Currs','PaymentProviders','PaymentProviderCredentials','PaymentGatewayCatalog']as$t)$result[$t]=$pdo->query('SHOW CREATE TABLE '.$t)->fetch(PDO::FETCH_NUM)[1];return hash('sha256',serialize($result));};
$drop=str_contains((string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION),'MariaDB')?'DROP CONSTRAINT':'DROP CHECK';
$refuse=static function()use($db,$ddl):void{$before=$ddl();try{CoinPaymentsNewSchema::ensure($db);throw new LogicException('Incompatible predecessor accepted');}catch(RuntimeException$e){if($e instanceof LogicException)throw$e;financialAssert($ddl()===$before,'incompatible predecessor refused before any DDL');}};
$pdo->exec("ALTER TABLE Currs ADD CONSTRAINT chk_coinnew_configuration_only CHECK(cCID<>'CPN')");$refuse();$pdo->exec('ALTER TABLE Currs '.$drop.' chk_coinnew_configuration_only');
$pdo->exec('ALTER TABLE Currs DROP INDEX uq_currency_provider_fiat, ADD UNIQUE KEY uq_currency_provider_fiat(cProviderID,cCurrID)');$refuse();$pdo->exec('ALTER TABLE Currs DROP INDEX uq_currency_provider_fiat, ADD UNIQUE KEY uq_currency_provider_fiat(cProviderID,cAssetKey)');
$pdo->exec('ALTER TABLE PaymentProviders '.$drop.' chk_provider_kind');$refuse();HScript\Payment\Provider\PaymentProviderSchema::check($pdo,'PaymentProviders','chk_provider_kind',"ppKind IN ('PAW','CCAPI','VOLET','PAYKASSA','STRIPE')",true);
if(str_contains((string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION),'MariaDB')){$pdo->exec('SET check_constraint_checks=0');try{$refuse();}finally{$pdo->exec('SET check_constraint_checks=1');}}
$money=financialFingerprint();
try{$runner->run('1.0.15',false);throw new LogicException('No backup');}catch(RuntimeException$e){if($e instanceof LogicException)throw$e;financialAssert(financialFingerprint()===$money&&$state->currentVersion()==='1.0.14','backup refusal preserves original data');}
financialAssert($runner->run('1.0.15',true)===['202610251300_coinpayments_new_catalog'],'explicit NEW catalog migration');
financialAssert(CoinPaymentsNewSchema::ready($pdo)&&PaykassaAssetSchema::ready($pdo)&&financialFingerprint()===$money,'new guard retains previous payment capabilities and all balances');
CoinPaymentsNewSchema::ensure($db);financialAssert(financialFingerprint()===$money,'resumable configuration migration');
$entry=static fn($id,$symbol,$type='crypto',$status='active')=>['id'=>$id,'symbol'=>$symbol,'name'=>$symbol.' network','type'=>$type,'status'=>$status,'decimalPlaces'=>18,'capabilities'=>['payments'],'requiredConfirmations'=>3,'isEnabledForPayment'=>true];
$raw=json_encode([$entry('4','ETH'),$entry('4:0x'.str_repeat('1',40),'USDT.ERC20','token'),$entry('9','TRX'),$entry('9:T'.str_repeat('1',33),'USDT.TRC20','token'),$entry('1002','LTCT'),$entry('36','XRP','crypto','underMaintenance')],JSON_THROW_ON_ERROR);
$catalog=CoinPaymentsNewClient::parseCatalog($raw);financialAssert(count($catalog)===6,'all payment-capable native and token assets discovered');
financialAssert(!CoinPaymentsNewService::selectable($catalog[1002],'A')&&CoinPaymentsNewService::selectable($catalog[1002],'SANDBOX'),'LTCT excluded from production instance selection');
financialAssert(!CoinPaymentsNewService::selectable($catalog[36],'A'),'maintenance visible but not selectable');
foreach(['[{"id":"4","id":"9"}]','{}','[],"ignored":1',substr($raw,0,-1).','.$raw[1].']']as$bad){try{CoinPaymentsNewClient::parseCatalog($bad);throw new LogicException('Ambiguous catalog accepted');}catch(ProviderException$e){financialAssert($e->reason==='catalog_unavailable','ambiguous and malformed catalogs fail closed');}}
$coinMoney=static function()use($pdo):string{$out=[];foreach(['Users','Wallets','Opers','Deps','Hist','Plans']as$t){$rows=array_map('serialize',$pdo->query('SELECT * FROM '.$t)->fetchAll(PDO::FETCH_ASSOC));sort($rows);$out[$t]=$rows;}return hash('sha256',serialize($out));};
$money=$coinMoney();$calls=0;$fetches=0;
HScript\Payment\Provider\CurrencyArchiveSchema::ensure($db);$service=new CoinPaymentsNewService($db,static function($c)use(&$calls,$pdo):void{financialAssert(!$pdo->inTransaction(),'auth read precedes locks');$calls++;},static function($instance)use(&$fetches,$pdo,$catalog):array{financialAssert(!$pdo->inTransaction(),'catalog read precedes locks');$fetches++;return$catalog;});
$input=['profile_id'=>'0','revision'=>'0','form_complete'=>'1','enabled'=>'1','credentials'=>['instance'=>'A','client_id'=>'00000000-0000-0000-0000-000000000123','client_secret'=>'synthetic-new-secret'],'assets'=>['4:0x'.str_repeat('1',40),'9:T'.str_repeat('1',33)]];
$reject=static function(callable$f,string$reason):void{try{$f();throw new LogicException('Expected '.$reason);}catch(ProviderException$e){financialAssert($e->reason===$reason,'reject '.$reason);}};
$reject(static fn()=>$service->save(89,$input),'forbidden');financialAssert($calls===0,'authorization before network reads');
$bad=$input;unset($bad['form_complete']);$reject(static fn()=>$service->save(90,$bad),'input_invalid');financialAssert($calls===0,'truncated forms rejected before network reads');
$bad=$input;$bad['assets']=['36'];$reject(static fn()=>$service->save(90,$bad),'asset_unavailable');
$bad=$input;$bad['assets']=['unknown'];$reject(static fn()=>$service->save(90,$bad),'asset_unavailable');
$pid=$service->save(90,$input);$repo=new ProviderRepository($db);$profile=$repo->find($pid);$rows=$repo->connections($pid);
financialAssert(count($rows)===2&&$profile['ppKind']==='COINNEW','only selected assets added to NEW profile');
financialAssert($repo->credentials($profile,1)===$input['credentials'],'shared credentials encrypted with original version context');
financialAssert($rows[0]['cAssetKey']!==$rows[1]['cAssetKey']&&$rows[0]['cTokenRoute']!==$rows[1]['cTokenRoute'],'same token family on two networks has independent exact identities');
foreach($rows as$row){
    financialAssert((int)$row['cDisabled']===1&&(int)$row['cHidden']===1,'new currency unavailable to users');
    foreach(CoinPaymentsNewSchema::MODES as$mode){financialAssert((int)$row[$mode]===0,'configuration only '.$mode);try{$pdo->exec('UPDATE Currs SET '.$mode.'=1 WHERE cID='.(int)$row['cID']);throw new LogicException('Mode enabled');}catch(PDOException){financialAssert(true,'SQL rejects '.$mode);}}
    foreach(['cDisabled'=>0,'cHidden'=>0]as$field=>$value){try{$pdo->exec('UPDATE Currs SET '.$field.'='.$value.' WHERE cID='.(int)$row['cID']);throw new LogicException('Visible currency');}catch(PDOException){financialAssert(true,'SQL rejects '.$field);}}
    $asset=CoinPaymentsNewService::asset($row);financialAssert($row['cAssetKey']===CoinPaymentsNewClient::assetKey('A',$asset['id']),'full id plus instance binds asset');
}
$manager=new PaymentManager($db);financialAssert(!CallbackCapabilities::allows('CPN')&&isset($manager->definitions()['CPN']),'no NEW automatic callback authority');
financialAssert(($manager->processDeposit('CPN',[])['error']??null)==='automatic_payment_unsupported'&&($manager->processWithdrawal('CPN',[])['result']??null)==='automatic_payment_unsupported','no NEW invoice or payout execution');
$bad=$input;$bad['profile_id']=(string)$pid;$bad['revision']='1';$bad['credentials']['instance']='B';$before=$calls;$reject(static fn()=>$service->save(90,$bad),'connection_identity_changed');financialAssert($calls===$before,'no cross-instance fallback or secret transmission');
$input['profile_id']=(string)$pid;$input['revision']='1';$firstIds=array_column($rows,'cID');$service->save(90,$input);
financialAssert(array_column($repo->connections($pid),'cID')===$firstIds&&$coinMoney()===$money,'resave preserves currency identities and every financial row');
$row=$rows[0];$db->insert('Wallets',['wuID'=>1,'wcID'=>$row['cID'],'wBal'=>'0','wLock'=>'0','wOut'=>'0']);
$oldOperation=$db->insert('Opers',['ouID'=>1,'ocID'=>$row['cID'],'oOper'=>'CASHIN','oState'=>0,'oSum'=>'3.25']);
$beforeArchive=$coinMoney();$reject(static fn()=>(new ProviderService($db))->disable(90,'COINNEW',$pid,2,true),'currency_archive_operations');$pdo->exec('UPDATE Opers SET oState=4 WHERE oID='.$oldOperation);$beforeArchive=$coinMoney();(new ProviderService($db))->disable(90,'COINNEW',$pid,2,true);
$display=CurrencyPresentation::annotate($db,$repo->connections($pid));financialAssert($display[0]['currency_status']==='retired'&&str_contains($display[0]['cName'],'#'.$row['cID']),'archive is distinguishable without adapter identity changes');
financialAssert(CurrencyPresentation::annotate($db,$display)===$display,'archive annotation is idempotent');
$originalLanguage=$_GS['lang']??'';$localized=$display;$localized[0]['currency_original_name']='{!ru!}Старый кошелёк{!en!}Original wallet{!!}';
foreach(['ru'=>'Старый кошелёк','en'=>'Original wallet']as$lang=>$name){$_GS['lang']=$lang;$shown=CurrencyPresentation::annotate($db,$localized);financialAssert(str_contains($shown[0]['cName'],$name)&&str_contains($shown[0]['cName'],'#'.$row['cID']),'localized archive suffix remains visible '.$lang);}$_GS['lang']=$originalLanguage;

financialAssert($coinMoney()===$beforeArchive,'archive is read-only for money/history/ids');
$input['profile_id']='0';$input['revision']='0';$new=$service->save(90,$input);$newRows=$repo->connections($new);
financialAssert($new!==$pid&&array_intersect($firstIds,array_column($newRows,'cID'))===[],'reconnection never reuses archived ledger identities');
financialAssert((int)financialValue('SELECT ocID FROM Opers WHERE oID='.(int)$oldOperation)===(int)$row['cID']&&$coinMoney()===$beforeArchive,'old invoice and all historical references stay with archive');
$oldKeys=$repo->credentials($repo->find($pid),1);financialAssert($oldKeys===$input['credentials'],'retired original credentials retained');
echo "CoinPayments NEW configuration tests passed: $assertions assertions.\n";
