<?php

declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/fixtures/financial_runtime.php';
require __DIR__.'/fixtures/currency_archive_checks.php';
use HScript\Finance\{DepositCheckout,GatewaySettlement,CallbackValidationException,IdempotencyConflict};
use HScript\Payment\{PaymentManager,Gateways\PawPayments};
use HScript\Payment\Provider\{PawProviderRepository,PawProviderService,PawValidationClient,ProviderConnectionSchema,ProviderNetworkSchema,PawNativeSchema,PawProviderException};
use HScript\Update\{SchemaStateRepository,MigrationRunner,MigrationLoader};
putenv('APP_DATA_KEY=synthetic-paw-native-data-key');
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,$e::class.': '.$e->getMessage().' at '.basename($e->getFile()).':'.$e->getLine().PHP_EOL);exit(1);});
final class NativePawValidation implements PawValidationClient{
 public function validateSandbox():void{}
 public function getBalance():array{return ['available'=>0,'total_income'=>0,'total_payouts'=>0];}
 public function getRates(string $base):array{return ['base'=>$base,'rates'=>['btc'=>'60000']];}
}
final class NativePawGateway extends PawPayments{
 public array $issued=[];
 protected function createInvoice(string $key,array $f):array{
  $i=['order_id'=>substr(hash('sha256','fixture:'.$f['extra']),0,24),'status'=>'created','sandbox'=>true,'created_at'=>(string)time(),'expires_at'=>(string)(time()+3600),
   'fiat_amount'=>$f['amount'],'fiat_currency'=>$f['fiat_currency'],'billing_type'=>$f['billing_type'],'fee_bearer'=>$f['fee_bearer'],'extra'=>$f['extra'],'metadata'=>$f['metadata']];
  $this->issued=$i;return $i;
 }
}
function nativePawSnapshot():string{global $pdo;$all=[];foreach(['Users','Wallets','Opers','Deps','Hist','IdempotencyKeys','PaymentProviders','PaymentProviderCredentials']as$t)$all[$t]=$pdo->query('SELECT * FROM '.$t.' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);return hash('sha256',serialize($all));}
function nativePawVerify(array $invoice,array $config):array{global $db;$raw=json_encode($invoice,JSON_THROW_ON_ERROR);return (new PaymentManager($db))->handleCallback('PAW',['_raw_body'=>$raw,'_server'=>['HTTP_X_PAW_SIGNATURE'=>hash_hmac('sha256',$raw,$config['key']),'HTTP_X_PAW_SANDBOX'=>'true']],$config);}
function nativePawFailure(callable $f,string $message):void{try{$f();throw new LogicException('Accepted '.$message);}catch(CallbackValidationException|IdempotencyConflict){financialAssert(true,$message);}}
if(($argv[1]??'')==='worker'){
 $_cfg=['UI_NumDec'=>2,'Const_IntCurr'=>0,'Bal_RateEUR'=>'2','Const_Salt'=>'synthetic-paw-native-checkout'];$_currs=$db->fetchIDRows($db->select('Currs'),false,'cID');$_GS['root_url']='https://fixture.invalid/';$_onload=[];
 $invoice=json_decode($argv[2],true,8,JSON_THROW_ON_ERROR);$c=$db->fetch1Row($db->select('Currs','*','cID=?d',[(int)$invoice['metadata']['currency']]));opDecodeCurrParams($c,$p,$config,$api);
 $r=GatewaySettlement::settle($db,'PAW',(int)$c['cID'],nativePawVerify($invoice,$config));echo json_encode(['replayed'=>$r->replayed]);exit;
}
financialReset();
if(!$pdo->query("SHOW COLUMNS FROM Currs LIKE 'cProviderKeyVersion'")->fetch())ProviderConnectionSchema::ensure($db);
HScript\Payment\Routing\PaymentRouteSchema::ensure($db);
HScript\Payment\Routing\TokenCatalogSchema::ensure($db);
$_cfg['Const_Salt']='synthetic-paw-native-checkout';
foreach(['SchemaState','SchemaMigrations','UpdateRuns']as$t)$pdo->exec('CREATE TABLE IF NOT EXISTS '.$t.' ('.$_dbstru[$t].') ENGINE=InnoDB');
$state=new SchemaStateRepository($db);$state->setCurrentVersion('1.0.11');
$runner=new MigrationRunner($db,$state,new MigrationLoader(dirname(__DIR__).'/migrations/versioned'));
try{$runner->run('1.0.12',false);throw new LogicException('Backup bypass');}catch(RuntimeException $e){financialAssert(str_contains($e->getMessage(),'verified backup'),'migration requires verified backup');}
$pdo->exec("INSERT INTO PaymentProviders(ppPublicID,ppKind,ppSlot,ppEnvironment,ppState,ppCreatedAt,ppUpdatedAt) VALUES('".str_repeat('7',32)."','PAW',NULL,'sandbox','retired',1,1)");$legacyPid=(int)$pdo->lastInsertId();
$legacyCid=$db->insert('Currs',['cCID'=>'PAW','cCurrID'=>'USD','cName'=>'Retired PAW identity fixture','cProviderID'=>$legacyPid,'cDisabled'=>1,'cCASHINMode'=>0,'cCASHOUTMode'=>0]);
$legacyRows=$pdo->query('SELECT * FROM Currs ORDER BY cID')->fetchAll(PDO::FETCH_ASSOC);
function nativePawMigrationRefuses(string $label):void{
 global $db,$pdo;$ddl=$pdo->query('SHOW CREATE TABLE Currs')->fetch(PDO::FETCH_NUM)[1];$rows=$pdo->query('SELECT * FROM Currs ORDER BY cID')->fetchAll(PDO::FETCH_ASSOC);$money=nativePawSnapshot();
 try{PawNativeSchema::ensure($db);throw new LogicException('Accepted '.$label);}catch(RuntimeException $e){if($e instanceof LogicException)throw $e;financialAssert($ddl===$pdo->query('SHOW CREATE TABLE Currs')->fetch(PDO::FETCH_NUM)[1] && $rows===$pdo->query('SELECT * FROM Currs ORDER BY cID')->fetchAll(PDO::FETCH_ASSOC) && $money===nativePawSnapshot(),$label.' refused before all writes');}
}
$bad=$db->insert('Currs',['cCID'=>'PAW','cCurrID'=>'USD','cName'=>'Duplicate fixture','cProviderID'=>$legacyPid,'cDisabled'=>1,'cCASHINMode'=>0,'cCASHOUTMode'=>0]);nativePawMigrationRefuses('duplicate nullable provider fiat');$db->delete('Currs','cID=?d',[$bad]);
foreach(['asset:usd','asset:USD ','asset:EUR'] as $wrong){$pdo->prepare('UPDATE Currs SET cAssetKey=? WHERE cID=?')->execute([$wrong,$legacyCid]);nativePawMigrationRefuses('noncanonical existing asset key');}
$pdo->prepare('UPDATE Currs SET cAssetKey=NULL WHERE cID=?')->execute([$legacyCid]);
$bad=$db->insert('Currs',['cCID'=>'PAW','cCurrID'=>'EUR','cName'=>'Cross identity fixture','cProviderID'=>$legacyPid,'cAssetKey'=>'asset:USD','cDisabled'=>1,'cCASHINMode'=>0,'cCASHOUTMode'=>0]);nativePawMigrationRefuses('canonical cross-currency collision');$db->delete('Currs','cID=?d',[$bad]);
$pdo->exec('ALTER TABLE Currs ADD CONSTRAINT chk_paw_inbound_only CHECK (1=1)');
$ddl=$pdo->query('SHOW CREATE TABLE Currs')->fetch(PDO::FETCH_NUM)[1];$rows=nativePawSnapshot();
try{PawNativeSchema::ensure($db);throw new LogicException('Bad native guard accepted');}catch(RuntimeException $e){if($e instanceof LogicException)throw $e;financialAssert($ddl===$pdo->query('SHOW CREATE TABLE Currs')->fetch(PDO::FETCH_NUM)[1] && nativePawSnapshot()===$rows,'incompatible guard rejected before any DDL');}
$drop=str_contains((string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION),'MariaDB')?'DROP CONSTRAINT':'DROP CHECK';
$pdo->exec('ALTER TABLE Currs '.$drop.' chk_paw_inbound_only');
$before=nativePawSnapshot();financialAssert($runner->run('1.0.12',true)===['202610231000_paw_native'],'one ordered PAW migration');
financialAssert(nativePawSnapshot()===$before && $state->currentVersion()==='1.0.12','migration leaves all balances and activation unchanged');PawNativeSchema::ensure($db);
$expectedRows=$legacyRows;foreach($expectedRows as &$row)if((int)$row['cID']===$legacyCid)$row['cAssetKey']='asset:USD';unset($row);
financialAssert($pdo->query('SELECT * FROM Currs ORDER BY cID')->fetchAll(PDO::FETCH_ASSOC)===$expectedRows,'migration changes only missing PAW asset identity metadata');

// Resume safely with both, native-only, and neither guard present.
$pdo->exec("ALTER TABLE Currs ADD CONSTRAINT chk_paw_configuration_only CHECK (cCID<>'PAW' OR (cDisabled IS NOT NULL AND cDisabled=1 AND cCASHINMode IS NOT NULL AND cCASHINMode=0 AND cCASHOUTMode IS NOT NULL AND cCASHOUTMode=0))");
PawNativeSchema::ensure($db);PawNativeSchema::ensure($db);$pdo->exec('ALTER TABLE Currs '.$drop.' chk_paw_inbound_only');PawNativeSchema::ensure($db);
financialAssert((int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='Currs' AND CONSTRAINT_NAME='chk_paw_inbound_only'")->fetchColumn()===1,'all interrupted guard states resume');
if($drop==='DROP CONSTRAINT'){$pdo->exec('SET check_constraint_checks=0');$ddl=$pdo->query('SHOW CREATE TABLE Currs')->fetch(PDO::FETCH_NUM)[1];try{PawNativeSchema::ensure($db);throw new LogicException('Unenforced guard');}catch(RuntimeException $e){if($e instanceof LogicException)throw $e;financialAssert($pdo->query('SHOW CREATE TABLE Currs')->fetch(PDO::FETCH_NUM)[1]===$ddl,'disabled MariaDB checks rejected without DDL');}$pdo->exec('SET check_constraint_checks=1');}
$repo=new PawProviderRepository($db);$service=new PawProviderService($repo,static fn()=>new NativePawValidation());
$view=$service->save(90,['form_complete'=>'1','profile_id'=>'0','revision'=>'0','api_key'=>'sk_sandbox_synthetic_paw_native','enabled'=>'1','fiats'=>['USD','EUR']]);$pid=$view['id'];
$currencies=array_column($view['connections'],null,'cCurrID');
foreach($currencies as $code=>$c){$cid=(int)$c['cID'];$pdo->exec("UPDATE Currs SET cDisabled=0,cCASHINMode=2,cNumDec=2 WHERE cID=$cid");$db->insert('Wallets',['wuID'=>1,'wcID'=>$cid]);}
$_currs=$db->fetchIDRows($db->select('Currs'),false,'cID');
$manager=new PaymentManager($db);$gateway=new NativePawGateway('PAW',$manager->definitions('PAW'),[],$db);
function nativePawCreate(int $cid,string $fee='2.50'):array{
 global $db,$gateway;
 $id=$db->insert('Opers',['ouID'=>1,'ocID'=>$cid,'oOper'=>'CASHIN','oState'=>0,'oSum'=>'25.00','oComis'=>$fee,'oCTS'=>timeToStamp()]);
 $public=DepositCheckout::prepare($db,1,$id,static function(array $o)use($gateway):array{return $gateway->processDeposit(['callback_fee_policy'=>$o,'sum'=>$o['oSum'],'tag'=>(int)$o['oID'],
  'url_ok'=>'https://fixture.invalid/oper?id='.$o['oID'].'&check','url_fail'=>'https://fixture.invalid/balance?fail','url_callback'=>'https://fixture.invalid/status']);});
 financialAssert(array_keys($public)===['url','get','formkind','payment_reference'],'no proof/order/key leaked to browser form');
 $c=$db->fetch1Row($db->select('Currs','*','cID=?d',[$cid]));opDecodeCurrParams($c,$p,$config,$api);
 $invoice=$gateway->issued+['initial_fiat_amount'=>'25','processed_at'=>(string)time()];return [$id,$invoice,$config];
}
$usd=(int)$currencies['USD']['cID'];$eur=(int)$currencies['EUR']['cID'];
$savedPawWalletTime=time();$savedPawWalletParams=['usdt_tron'=>'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t','usdc_eth'=>'0x'.str_repeat('a',40)];
$pdo->prepare('UPDATE Wallets SET wParams=?,wMTS=? WHERE wuID=1 AND wcID=?')->execute([encodeArrayToStr($savedPawWalletParams,$usd.'1'.$savedPawWalletTime),timeToStamp($savedPawWalletTime),$usd]);

foreach([['partially_paid','12.34','9.840000'],['success','25','32.340000'],['paid_over','31.25','61.090000']]as[$status,$actual,$balance]){
 [$id,$invoice,$config]=nativePawCreate($usd);$invoice['status']=$status;$invoice['fiat_amount']=$actual;$verified=nativePawVerify($invoice,$config);
 $q=$pdo->prepare("SELECT ikID,ikResponse FROM IdempotencyKeys WHERE ikNamespace='invoice:attempt' AND ikOperationID=?");$q->execute([$id]);$entry=$q->fetch(PDO::FETCH_ASSOC);$original=$entry['ikResponse'];$changed=json_decode($original,true,32,JSON_THROW_ON_ERROR);unset($changed['data']['paw_profile']);$pdo->prepare('UPDATE IdempotencyKeys SET ikResponse=? WHERE ikID=?')->execute([json_encode($changed,JSON_THROW_ON_ERROR),$entry['ikID']]);
 $before=nativePawSnapshot();nativePawFailure(static fn()=>GatewaySettlement::settle($db,'PAW',$usd,$verified),'missing frozen merchant snapshot rejected');financialAssert(nativePawSnapshot()===$before,'missing proof changes no data');$pdo->prepare('UPDATE IdempotencyKeys SET ikResponse=? WHERE ikID=?')->execute([$original,$entry['ikID']]);
 if($status==='partially_paid'){
  $settings=$pdo->query('SELECT * FROM Currs WHERE cID='.$usd)->fetch(PDO::FETCH_ASSOC);$settings=array_map(static fn($v)=>(string)$v,$settings);$settings['profile_id']=(string)$pid;$settings['revision']=(string)$repo->find($pid)['ppRevision'];
  try{$service->saveCurrency(90,$usd,array_replace($settings,['cNumDec'=>'3']));throw new LogicException('Pending precision changed');}catch(PawProviderException $e){financialAssert($e->reason==='pending_invoices','pending precision change refused');}
  set_error_handler(static function($n,$message){throw new ErrorException($message,0,$n);});
  try{$service->saveCurrency(90,$usd,array_replace($settings,['cDisabled'=>'1','cCASHINMode'=>'0']));}finally{restore_error_handler();}
  financialAssert((int)financialValue('SELECT cDisabled FROM Currs WHERE cID='.$usd)===1,'unchanged precision allows disabling an issued currency');
  $settings['revision']=(string)$repo->find($pid)['ppRevision'];$service->saveCurrency(90,$usd,$settings);
 }
 $r=GatewaySettlement::settle($db,'PAW',$usd,$verified);financialAssert(!$r->replayed && $r->data['settled'],'native VARY '.$status);financialWallet(1,$usd,$balance);
 financialAssert(opDecodeUserCurrParams($db->fetch1Row($db->select('Wallets','*','wuID=1 AND wcID=?d',[$usd])))===$savedPawWalletParams,'native PAW credit preserves both payout addresses');
 financialAssert(financialValue('SELECT oComis FROM Opers WHERE oID='.$id)==='2.500000','original fixed commission preserved');
 $after=nativePawSnapshot();financialAssert(GatewaySettlement::settle($db,'PAW',$usd,$verified)->replayed && nativePawSnapshot()===$after,'exact replay no second effect');
 financialArchivedReplay('PAW',$usd,$verified);$after=nativePawSnapshot();
 $wrong=$invoice;$wrong['fiat_amount']='25.01';nativePawFailure(static fn()=>GatewaySettlement::settle($db,'PAW',$usd,nativePawVerify($wrong,$config)),'changed final amount quarantined');financialAssert(nativePawSnapshot()===$after,'conflict leaves money and permanent proof unchanged');
 $wrong=$invoice;$wrong['metadata']['currency']=(string)$eur;nativePawFailure(static fn()=>GatewaySettlement::settle($db,'PAW',$eur,nativePawVerify($wrong,$config)),'cross-fiat cannot redirect');
}
[$id,$invoice,$config]=nativePawCreate($eur,'0');
foreach(['created','processing','expired','cancelled','failed','refunded','high_risk']as$status){$invoice['status']=$status;$before=nativePawSnapshot();$v=nativePawVerify($invoice,$config);financialAssert(!$v['correct'] && $v['authenticated'] && nativePawSnapshot()===$before,'noncredit '.$status.' creates no claim');nativePawFailure(static fn()=>GatewaySettlement::settle($db,'PAW',$eur,$v),'noncredit direct settle rejected');}
$invoice['status']='success';$invoice['fiat_amount']='25';
$before=nativePawSnapshot();$db->rejectWalletUpdate=true;
try{GatewaySettlement::settle($db,'PAW',$eur,nativePawVerify($invoice,$config));throw new LogicException('Wallet failure accepted');}catch(HScript\Finance\FinancialOperationException){financialAssert(nativePawSnapshot()===$before,'wallet failure rolls back amount/history/claim');}$db->rejectWalletUpdate=false;
// A pending/ambiguous attempt prevents unsafe provider key rotation.
try{$service->save(90,['form_complete'=>'1','profile_id'=>(string)$pid,'revision'=>(string)$repo->find($pid)['ppRevision'],'api_key'=>'sk_sandbox_new_synthetic_key','enabled'=>'1','fiats'=>['USD','EUR']]);throw new LogicException('Rotation accepted');}catch(PawProviderException $e){financialAssert($e->reason==='pending_invoices' && nativePawSnapshot()===$before,'rotation blocked until pending invoices reconciled');}
// Concurrent independent PHP receivers share a single permanent terminal claim.
[$raceId,$raceInvoice,$raceConfig]=nativePawCreate($usd);$raceInvoice['status']='success';$raceInvoice['fiat_amount']='25';
$children=[];
for($i=0;$i<2;$i++){$pipes=[];$p=proc_open([PHP_BINARY,__FILE__,'worker',json_encode($raceInvoice,JSON_THROW_ON_ERROR)],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);$children[]=[$p,$pipes];}
$results=[];
foreach($children as [$p,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);financialAssert(proc_close($p)===0,'concurrent receiver process '.$err);$results[]=json_decode($out,true,8,JSON_THROW_ON_ERROR)['replayed'];}
sort($results);financialAssert($results===[false,true],'one winning receiver and one proven replay');financialWallet(1,$usd,'83.590000');
// The predecessor schema has no outgoing capability, even with stale request flags.
$_currs[$usd]['cCASHOUTMode']=2;
foreach([false,true]as$admin){$before=nativePawSnapshot();financialAssert(opOperCreate(1,'CASHOUT',$usd,'1',[],'Synthetic refusal',false,$admin)==='oper_disabled' && nativePawSnapshot()===$before,'predecessor schema refuses PAW withdrawal before any financial write');}
// Disable/new-invoice policy is separate from already-issued invoice authority.
try{$service->disable(90,$pid,(int)$repo->find($pid)['ppRevision'],true);throw new LogicException('Unresolved invoice archived');}catch(HScript\Payment\Provider\ProviderException$e){financialAssert($e->reason==='currency_archive_operations','new retire refuses unresolved original invoice');}
$pdo->exec("UPDATE PaymentProviders SET ppState='retired',ppSlot=NULL WHERE ppID=$pid");$pdo->exec("UPDATE Currs SET cProviderSelected=0,cDisabled=1,cCASHINMode=0,cCASHOUTMode=0 WHERE cProviderID=$pid");
$r=GatewaySettlement::settle($db,'PAW',$eur,nativePawVerify($invoice,$config));financialAssert(!$r->replayed,'issued callback after retire uses retained exact key/profile');financialWallet(1,$eur,'25.000000');
$after=nativePawSnapshot();financialAssert(GatewaySettlement::settle($db,'PAW',$eur,nativePawVerify($invoice,$config))->replayed && nativePawSnapshot()===$after,'retired exact replay remains proven');
echo "PAW VARY SQL: $assertions assertions passed\n";
