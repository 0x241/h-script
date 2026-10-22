<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/fixtures/financial_runtime.php';
use HScript\Payment\Routing\{PaymentRoutes as R,PaymentRouteSchema};
use HScript\Payment\Provider\{ProviderService,ProviderRepository,ProviderConnectionSchema,ProviderNetworkSchema};
use HScript\Finance\{DepositCheckout,GatewaySettlement,CallbackValidationException,IdempotencyConflict};
use HScript\Payment\{PaymentManager,Gateways\CryptoCurrencyApi,Gateways\Paykassa};
putenv('APP_DATA_KEY=synthetic-token-route-fixture');
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,$e::class.': '.$e->getMessage().' '.basename($e->getFile()).':'.$e->getLine()."\n".$e->getTraceAsString()."\n");exit(1);});
financialReset();
if(!$pdo->query("SHOW COLUMNS FROM Currs LIKE 'cProviderKeyVersion'")->fetch())ProviderConnectionSchema::ensure($db);
PaymentRouteSchema::ensure($db);
foreach(['SchemaState','SchemaMigrations','UpdateRuns'] as $t)$pdo->exec('CREATE TABLE IF NOT EXISTS '.$t.' ('.$_dbstru[$t].') ENGINE=InnoDB');
$state=new HScript\Update\SchemaStateRepository($db);$state->setCurrentVersion('1.0.8');
$pdo->exec('ALTER TABLE Currs DROP COLUMN cPaymentRoutes');$beforeMigration=tokenMoney();
$runner=new HScript\Update\MigrationRunner($db,$state,new HScript\Update\MigrationLoader(dirname(__DIR__).'/migrations/versioned'));
try{$runner->run('1.0.9',false);throw new LogicException('Backup missing');}catch(RuntimeException $e){financialAssert(str_contains($e->getMessage(),'verified backup'),'route migration requires verified backup');}
financialAssert($runner->run('1.0.9',true)===['202610161800_payment_routes'] && tokenMoney()===$beforeMigration,'explicit route migration preserves money');
PaymentRouteSchema::ensure($db);
$service=new ProviderService($db,static fn()=>[]);$repo=new ProviderRepository($db);
final class TokenCcapi extends CryptoCurrencyApi { public static int $calls=0;protected function apiRequest(string $chain,string $method,array $config,array $arguments=[]):array{++self::$calls;if(($arguments['token']??null)!=='USDT' && ($arguments['token']??null)!=='USDC')throw new LogicException('Missing token');return ['address'=>$chain==='tron'?'T'.str_repeat('1',33):'0x'.str_repeat('1',40)];}}
function tokenMoney():string{global $pdo;$data=[];foreach(['Users','Wallets','Opers','Deps','Hist'] as $t)$data[$t]=$pdo->query('SELECT * FROM '.$t.' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);return hash('sha256',serialize($data));}
function tokenOperation(int $id):array{global $db;return $db->fetch1Row($db->query('SELECT Opers.*,Currs.* FROM Opers JOIN Currs ON cID=ocID WHERE oID=?d',[$id]));}
function tokenCreate(array $o):array{global $db;opDecodeCurrParams($o,$p,$sci,$api);$g=new TokenCcapi($o['cCID'],[$o['cCID'],$o['cCurrID']],[],$db);return $g->processDeposit(['ccapi_wallets'=>$o['_ccapi_wallets']??null,'payment_route'=>$o['_payment_route'],'sci'=>$sci,'tag'=>$o['oID'],'sum'=>$o['oSum'],'callback_scale'=>6,'url_callback'=>'https://fixture.invalid/status','url_ok'=>'https://fixture.invalid/operation']);}
function tokenRaw(int $id,int $confirmation=2,array $changes=[]):string{global $db;$o=tokenOperation($id);$f=DepositCheckout::storedForm($o);$r=$f['payment_route'];$p=array_replace(['cryptocurrencyapi.net'=>3,'chain'=>$r['network'],'currency'=>$r['wire'],'type'=>'in','date'=>stampToTime($o['oCTS']),'from'=>null,'to'=>$f['payment_address'],'token'=>$r['asset'],'tokenContract'=>$r['contract'],'amount'=>'10.000000000000000000','fee'=>'0.0000000000000001','txid'=>str_repeat('a',64),'pos'=>0,'confirmation'=>$confirmation,'label'=>(string)$id],$changes);ksort($p);$p['sign']=sha1(implode(':',array_map(static fn($v)=>(string)$v,array_values($p))).':'.md5('synthetic-token-secret'));return json_encode($p,JSON_THROW_ON_ERROR);}
function tokenVerified(int $id,int $confirmation=2,array $changes=[]):array{global $db;$o=tokenOperation($id);opDecodeCurrParams($o,$p,$sci,$api);return (new PaymentManager($db))->handleCallback($o['cCID'],['_raw_body'=>tokenRaw($id,$confirmation,$changes)],$sci);}
$pid=$service->save(90,'CCAPI',['profile_id'=>'0','revision'=>'0','credentials'=>['v3_key'=>'synthetic-token-secret'],'currencies'=>['ETH','USDC'],'enabled'=>'1']);
// Historical combined USDT fixture: new connections no longer issue this ledger asset.
$legacy=['cCID'=>'CCTU','cCurrID'=>'USDT','cName'=>'Legacy combined USDT','cCurr'=>'USDT','cNumDec'=>6,'cProviderID'=>$pid,'cProviderKeyVersion'=>1,'cProviderSelected'=>1,'cDisabled'=>1];
if($pdo->query("SHOW COLUMNS FROM Currs LIKE 'cAssetKey'")->fetch())$legacy['cAssetKey']='asset:USDT';
$db->insert('Currs',$legacy);
$connections=array_column($repo->connections($pid),null,'cCurrID');$c=$connections['USDT'];$cid=(int)$c['cID'];
$before=tokenMoney();$service->saveRoutes(90,'CCAPI',$cid,['profile_id'=>(string)$pid,'revision'=>'1','payment_routes'=>array_keys(R::catalog('CCTU'))]);financialAssert($before===tokenMoney(),'route settings preserve money');
$db->update('Currs',['cDisabled'=>0,'cCASHINMode'=>2,'cCASHINComis'=>10],'','cID=?d',[$cid]);$_currs=$db->fetchIDRows($db->select('Currs'),false,'cID');
$ids=[];
foreach(['ccapi-usdt-tron','ccapi-usdt-ethereum'] as $code){$id=opOperCreate(1,'CASHIN',$cid,'10');$ids[]=$id;
 try{DepositCheckout::prepare($db,1,$id,'tokenCreate');throw new LogicException('No route accepted');}catch(CallbackValidationException){financialAssert(true,'route required before I/O');}
 $form=DepositCheckout::prepare($db,1,$id,'tokenCreate',$code);financialAssert($form['payment_asset']==='USDT' && !isset($form['payment_route']),'public route is safe');
 $calls=TokenCcapi::$calls;DepositCheckout::prepare($db,1,$id,'tokenCreate',$code);financialAssert(TokenCcapi::$calls===$calls,'retry no provider I/O');
 try{DepositCheckout::prepare($db,1,$id,'tokenCreate',$code==='ccapi-usdt-tron'?'ccapi-usdt-ethereum':'ccapi-usdt-tron');throw new LogicException('Route switched');}catch(IdempotencyConflict){financialAssert(TokenCcapi::$calls===$calls,'route switch conflict before I/O');}
 foreach(['token'=>'USDC','tokenContract'=>'T'.str_repeat('2',33),'currency'=>'USDT'] as $field=>$value){$v=tokenVerified($id,2,[$field=>$value]);financialAssert(!$v['correct'],'signed route mismatch refuses');}
 $beforePrecision=financialFingerprint();try{GatewaySettlement::settle($db,'CCTU',$cid,tokenVerified($id,2,['amount'=>'10.0000001']));throw new LogicException('Excess precision accepted');}catch(CallbackValidationException){financialAssert($beforePrecision===financialFingerprint(),'precision refuses without rounding or money');}
 $pending=tokenVerified($id,1);GatewaySettlement::settle($db,'CCTU',$cid,$pending);$final=tokenVerified($id);GatewaySettlement::settle($db,'CCTU',$cid,$final);$snapshot=financialFingerprint();GatewaySettlement::settle($db,'CCTU',$cid,$final);financialAssert($snapshot===financialFingerprint(),'final replay no second money');
 financialAssert(tokenOperation($id)['oState']===3,'token invoice settled');
}
financialAssert(financialValue('SELECT wBal FROM Wallets WHERE wuID=1 AND wcID='.$cid)==='18.000000','two networks credit one USDT wallet, exact fees');
financialAssert(count(array_unique(array_map(static fn($id)=>tokenOperation($id)['oBatch'],$ids)))===2,'same txid position different chain distinct identities');
financialAssert((int)financialValue("SELECT COUNT(*) FROM Currs WHERE cProviderID=$pid AND cCurrID='USDT'")===1,'one ledger currency per provider asset');
$profile=$repo->find($pid);$service->saveRoutes(90,'CCAPI',$cid,['profile_id'=>(string)$pid,'revision'=>(string)$profile['ppRevision'],'payment_routes'=>[]]);
$snapshot=financialFingerprint();GatewaySettlement::settle($db,'CCTU',$cid,tokenVerified($ids[0]));financialAssert($snapshot===financialFingerprint(),'disabled route retains original invoice replay');
final class TokenPaykassa extends Paykassa {
 public static array $invoices=[];public static int $calls=0;public static bool $wrongNetwork=false;
 protected function sciRequest(array $config,array $fields):array{++self::$calls;
 if($fields['func']==='sci_create_order_get_data'){$order=$fields['order_id'];$r=['shop_id'=>$config['id'],'order_id'=>$order,'amount'=>$fields['amount'],'currency'=>$fields['currency'],'system'=>(int)$fields['system']===30?'TRON_TRC20':'Ethereum_ERC20','invoice'=>(string)(10000+(int)$order),'wallet'=>'T'.str_repeat('1',33),'tag'=>'','url'=>'https://crypto.paykassa.pro/sci/index.php?hash=synthetic'.$order];self::$invoices[$order]=$r;return ['error'=>false,'data'=>$r];}
 if($fields['func']==='sci_confirm_order' && preg_match('/^synthetic-token-([1-9][0-9]*)$/D',$fields['private_hash'],$m)){ $r=self::$invoices[$m[1]];if(self::$wrongNetwork)$r['system']=$r['system']==='TRON_TRC20'?'Ethereum_ERC20':'TRON_TRC20';return ['error'=>false,'data'=>$r+['partial'=>'no','transaction'=>$r['invoice'],'address'=>$r['wallet'],'hash'=>'synthetic'.$m[1]]];}
 throw new LogicException('Unexpected token SCI call');}
}
function tokenPayCreate(array $o):array{global $db;opDecodeCurrParams($o,$p,$sci,$api);return(new TokenPaykassa($o['cCID'],[$o['cCID'],$o['cCurrID']],[],$db))->processDeposit(['payment_route'=>$o['_payment_route'],'sci'=>$sci,'tag'=>$o['oID'],'sum'=>$o['oSum']]);}
$pp=$service->save(90,'PAYKASSA',['profile_id'=>'0','revision'=>'0','credentials'=>['merchant_id'=>'100','merchant_secret'=>'synthetic-sci','api_id'=>'200','api_secret'=>'synthetic-api'],'currencies'=>['USDT'],'enabled'=>'1']);
$pk=$repo->connections($pp)[0];$pc=(int)$pk['cID'];$service->saveRoutes(90,'PAYKASSA',$pc,['profile_id'=>(string)$pp,'revision'=>'1','payment_routes'=>array_keys(R::catalog('PKTU'))]);
$db->update('Currs',['cDisabled'=>0,'cCASHINMode'=>2,'cCASHINComis'=>10],'','cID=?d',[$pc]);$_currs=$db->fetchIDRows($db->select('Currs'),false,'cID');
foreach(R::catalog('PKTU') as $code=>$route){$id=opOperCreate(1,'CASHIN',$pc,'10');$form=DepositCheckout::prepare($db,1,$id,'tokenPayCreate',$code);financialAssert(!isset($form['payment_route']) && !isset($form['paykassa_system']),'PayKassa public form has no private route proof');$o=tokenOperation($id);opDecodeCurrParams($o,$p,$sci,$api);$g=new TokenPaykassa('PKTU',['PKTU','USDT'],[],$db);$payload=['order_id'=>(string)$id,'system'=>R::paykassaSystem($route),'currency'=>'USDT','private_hash'=>'synthetic-token-'.$id];
$bad=$payload;$bad['system']=$route['network']==='tron'?'ethereum_erc20':'tron_trc20';$calls=TokenPaykassa::$calls;$result=$g->handleCallback(['_config'=>$sci,'_raw_body'=>http_build_query($bad)]);financialAssert(!$result['correct'] && TokenPaykassa::$calls===$calls,'PayKassa wrong network refuses before confirmation I/O');
$v=$g->handleCallback(['_config'=>$sci,'_raw_body'=>http_build_query($payload)]);GatewaySettlement::settle($db,'PKTU',$pc,$v);$before=financialFingerprint();$calls=TokenPaykassa::$calls;$v=$g->handleCallback(['_config'=>$sci,'_raw_body'=>http_build_query($payload)]);GatewaySettlement::settle($db,'PKTU',$pc,$v);financialAssert(financialFingerprint()===$before && TokenPaykassa::$calls===$calls,'PayKassa exact replay no duplicate credit or confirmation I/O');}
financialAssert(financialValue('SELECT wBal FROM Wallets WHERE wuID=1 AND wcID='.$pc)==='18.000000','PayKassa two networks one USDT wallet');
$id=opOperCreate(1,'CASHIN',$pc,'10');DepositCheckout::prepare($db,1,$id,'tokenPayCreate','paykassa-usdt-ethereum');$o=tokenOperation($id);opDecodeCurrParams($o,$p,$sci,$api);$g=new TokenPaykassa('PKTU',['PKTU','USDT'],[],$db);
$raw=http_build_query(['order_id'=>(string)$id,'system'=>'Ethereum_ERC20','currency'=>'USDT','private_hash'=>'synthetic-token-'.$id]);$before=tokenMoney();TokenPaykassa::$wrongNetwork=true;
try{$v=$g->handleCallback(['_config'=>$sci,'_raw_body'=>$raw]);if($v['correct'])GatewaySettlement::settle($db,'PKTU',$pc,$v);}catch(CallbackValidationException){}
financialAssert(tokenMoney()===$before,'confirmed other network cannot credit');
$cache=$pdo->query("SELECT ikResponse FROM IdempotencyKeys WHERE ikNamespace='verification:paykassa:order' AND ikOperationID=".$id)->fetchColumn();$cache=json_decode($cache,true,16,JSON_THROW_ON_ERROR);financialAssert($cache['token_hash']==='' && $cache['verified_at']===0,'wrong network cannot poison verified token cache');
try{$v=$g->handleCallback(['_config'=>$sci,'_raw_body'=>$raw]);if($v['correct'])GatewaySettlement::settle($db,'PKTU',$pc,$v);}catch(RuntimeException){}
financialAssert(tokenMoney()===$before,'same rejected private token retry cannot credit from cache');TokenPaykassa::$wrongNetwork=false;
echo "Financial token route checks passed ($assertions assertions).\n";
