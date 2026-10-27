<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/fixtures/financial_runtime.php';
require __DIR__.'/fixtures/currency_archive_checks.php';
use HScript\Payment\{PaykassaAssets,PaymentManager};
use HScript\Payment\Provider\{ProviderService,ProviderSettings,ProviderRepository,ProviderException,ProviderNetworkSchema,PaykassaAssetSchema};
use HScript\Finance\{DepositCheckout,GatewaySettlement};
putenv('APP_DATA_KEY=synthetic-paykassa-assets-key');
putenv('PAYKASSA_VERIFICATION_BUDGET_PER_MINUTE=100');
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,$e::class.': '.$e->getMessage().' at '.basename($e->getFile()).':'.$e->getLine()."\n".$e->getTraceAsString()."\n");exit(1);});
financialReset();
if(!$pdo->query("SHOW COLUMNS FROM Currs LIKE 'cTokenRoute'")->fetch()){
    HScript\Payment\Provider\ProviderConnectionSchema::ensure($db);
    HScript\Payment\Routing\PaymentRouteSchema::ensure($db);
    HScript\Payment\Routing\TokenCatalogSchema::ensure($db);
}
foreach(['SchemaState','SchemaMigrations','UpdateRuns']as$t)$pdo->exec('CREATE TABLE IF NOT EXISTS '.$t.' ('.$_dbstru[$t].') ENGINE=InnoDB');
$state=new HScript\Update\SchemaStateRepository($db);$state->setCurrentVersion('1.0.13');
$runner=new HScript\Update\MigrationRunner($db,$state,new HScript\Update\MigrationLoader(dirname(__DIR__).'/migrations/versioned'));
$before=financialFingerprint();
try{$runner->run('1.0.14',false);throw new LogicException('Missing backup accepted');}catch(RuntimeException $e){if($e instanceof LogicException)throw $e;financialAssert(financialFingerprint()===$before && $state->currentVersion()==='1.0.13','backup gate preserves database');}
financialAssert($runner->run('1.0.14',true)===['202610241600_paykassa_assets'],'explicit catalog migration');
financialAssert(financialFingerprint()===$before && PaykassaAssetSchema::ready($pdo),'migration changes no finance/identities');
PaykassaAssetSchema::ensure($db);financialAssert(financialFingerprint()===$before,'catalog migration is resumable');
$calls=0;HScript\Payment\Provider\CurrencyArchiveSchema::ensure($db);$service=new ProviderService($db,static function($kind,$credentials,$selected,$wallets)use(&$calls,$pdo):array{financialAssert(!$pdo->inTransaction(),'read-only probe is outside transaction');$calls++;return [];});
$credentials=['merchant_id'=>'122','merchant_secret'=>'synthetic-sci','api_id'=>'123','api_secret'=>'synthetic-api'];
$input=['form_complete'=>'1','profile_id'=>'0','revision'=>'0','enabled'=>'1','credentials'=>$credentials,'currencies'=>array_keys(ProviderSettings::choices('PAYKASSA'))];
$bad=$input;$bad['credentials']['test_mode']='1';
try{$service->save(90,'PAYKASSA',$bad);throw new LogicException('Sandbox accepted');}catch(RuntimeException $e){if($e instanceof LogicException)throw $e;financialAssert($e->getMessage()==='paykassa_sandbox_removed' && $calls===0,'sandbox rejects before provider I/O');}
$pid=$service->save(90,'PAYKASSA',$input);
$repo=new ProviderRepository($db);$profile=$repo->find($pid);$rows=$repo->connections($pid);
financialAssert(count($rows)===25 && $calls===1 && $profile['ppEnvironment']==='live','one live connection creates every reviewed choice');
$ids=[];$assetKeys=[];
foreach($rows as $row){$key=PaykassaAssets::choiceKey($row);$choice=ProviderSettings::choices('PAYKASSA')[$key];$ids[$key]=(int)$row['cID'];$assetKeys[]=$row['cAssetKey'];financialAssert($row['cCurrID']===$choice['currency'] && $row['cAssetKey']===$choice['asset_key'] && (int)$row['cDisabled']===1 && (int)$row['cCASHINMode']===0 && (int)$row['cCASHOUTMode']===0,'choice has exact network identity and disabled modes');}
financialAssert(count(array_unique($assetKeys))===count($rows) && $ids['BTC']!==$ids['PKBB'] && $ids['USDT']!==$ids['PKBT'] && $ids['PKBT']!==$ids['PKTT'],'same ticker has separate ledger in each newly supported network');
// A retained encrypted sandbox override must fail before a committed attempt or send.
$btc=(int)$ids['BTC'];$db->update('Currs',['cDisabled'=>0,'cCASHOUTMode'=>2],'','cID=?d',[$btc]);
$now=time();$db->insert('Wallets',['wuID'=>1,'wcID'=>$btc,'wBal'=>'10','wMTS'=>timeToStamp($now),'wParams'=>encodeArrayToStr(['acc'=>'synthetic-payout-destination'],$btc.'1'.$now)]);$pdo->exec('UPDATE Users SET uBal=10 WHERE uID=1');
$_currs=$db->fetchIDRows($db->select('Currs'),false,'cID');
$oid=HScript\Finance\FinancialOperationException::check(opOperCreate(1,'CASHOUT',$btc,'1'));
HScript\Finance\FinancialOperationException::check(opOperConfirm(1,$oid));HScript\Finance\FinancialOperationException::check(opOperComplete(1,$oid));
foreach([['test'=>'1'],['test_mode'=>'1'],['_provider_environment'=>'sandbox']]as$flags){
    $params=strToArray(financialValue('SELECT oParams FROM Opers WHERE oID='.$oid));$params['payout_api']=HScript\Security\SecretCipher::encrypt(json_encode($flags+['id'=>'123','apipass'=>'synthetic-api','shop_id'=>'122'],JSON_THROW_ON_ERROR),'payout-api:'.$oid);$db->update('Opers',['oParams'=>arrayToStr($params)],'','oID=?d',[$oid]);
    $sends=0;try{HScript\Finance\WithdrawalDispatcher::dispatch($db,$oid,static function()use(&$sends){$sends++;return ['batch'=>'forbidden'];});throw new LogicException('Sandbox override accepted');}catch(HScript\Finance\FinancialOperationException $e){financialAssert($e->getMessage()==='paykassa_sandbox_removed' && $sends===0 && (int)financialValue("SELECT COUNT(*) FROM IdempotencyKeys WHERE ikNamespace='payout:attempt' AND ikOperationID=".$oid)===0,'sandbox override refuses before permanent network attempt');}
}
HScript\Finance\FinancialOperationException::check(opOperCancel(1,$oid));
financialAssert(HScript\Finance\WithdrawalDispatcher::dispatch($db,$oid,static fn()=>throw new LogicException('Cancelled payout sent'))===['skipped'=>true],'cancelled queue work drains without provider I/O');
$pdo->exec("UPDATE Jobs SET jState=2 WHERE jType='financial.withdraw'");
// Saving one subset preserves every ID and disables only deselected choices.
$input['profile_id']=(string)$pid;$input['revision']='1';$input['currencies']=['BTC','PKBB','PKBT','PKTT'];
$service->save(90,'PAYKASSA',$input);$rows=$repo->connections($pid);
foreach($rows as$row)financialAssert($ids[PaykassaAssets::choiceKey($row)]===(int)$row['cID'] && (int)$row['cProviderSelected']===(in_array(PaykassaAssets::choiceKey($row),$input['currencies'],true)?1:0),'subset save preserves identities and independent selection');
$input['revision']='2';$input['currencies']=array_keys(ProviderSettings::choices('PAYKASSA'));$service->save(90,'PAYKASSA',$input);
$pdo->exec("UPDATE Currs SET cDisabled=0,cCASHINMode=2 WHERE cProviderID=".$pid);
$_currs=$db->fetchIDRows($db->select('Currs'),false,'cID');

final class CatalogPaykassa extends HScript\Payment\Gateways\Paykassa
{
    protected function sciRequest(array $config,array $fields):array
    {
        global $pdo;
        financialAssert(!$pdo->inTransaction(),'SCI I/O outside financial transaction');
        $GLOBALS['pkCatalogCalls']++;
        [$currency,$systemId,$system,$network,$hasTag]=PaykassaAssets::ASSETS[$this->id];
        $id=$fields['func']==='sci_create_order_get_data'?(int)$fields['order_id']:(int)substr($fields['private_hash'],8);
        $invoice=(string)(700000+$id);$address='syntheticCatalogAddress'.str_pad((string)$id,12,'0',STR_PAD_LEFT);$tag=$hasTag?'123456':'';$hash='catalog_hash_'.$id;
        return ['error'=>false,'data'=>$fields['func']==='sci_create_order_get_data'
            ?['order_id'=>(string)$id,'invoice'=>$invoice,'wallet'=>$address,'amount'=>'1.230000','currency'=>$currency,'system'=>$system,'tag'=>$tag,'url'=>'https://crypto.paykassa.pro/sci/index.php?hash='.$hash,'test_mode'=>false]
            :['shop_id'=>'122','order_id'=>(string)$id,'transaction'=>$invoice,'address'=>$address,'amount'=>'1.230000','currency'=>$currency,'system'=>$system,'tag'=>$tag,'hash'=>$hash,'partial'=>'no','test_mode'=>false]];
    }
}
$manager=new PaymentManager($db);$GLOBALS['pkCatalogCalls']=0;
foreach(PaykassaAssets::ASSETS as$gateway=>$definition){
    $key=PaykassaAssets::choiceKey(['cCID'=>$gateway,'cCurrID'=>$definition[0]]);$cid=$ids[$key];
    $id=opOperCreate(1,'CASHIN',$cid,'1.23');financialAssert(is_int($id),'catalog invoice created');
    $manager->register($gateway,new CatalogPaykassa($gateway,$manager->definitions($gateway),[],$db));
    $config=ProviderSettings::configuration('PAYKASSA',$credentials)['sci'];
    $public=DepositCheckout::prepare($db,1,$id,static fn()=>$manager->processDeposit($gateway,['sci'=>$config,'sum'=>'1.23','tag'=>$id,'memo'=>'Synthetic catalog']));
    financialAssert(array_keys($public)===['url','get','hash'],'browser gets only sanitized form');
    $body=http_build_query(['private_hash'=>'catalog_'.$id,'system'=>$definition[2],'currency'=>$definition[0],'order_id'=>(string)$id]);
    $verified=$manager->handleCallback($gateway,['_raw_body'=>$body],$config);
    financialAssert($verified['correct'] && $verified['destination_tag']===($definition[4]?'123456':''),'credentialed callback binds exact tag and network');
    financialAssert(GatewaySettlement::settle($db,$gateway,$cid,$verified)->data===['settled'=>true],'exact network-specific ledger credited');
    financialAssert(GatewaySettlement::settle($db,$gateway,$cid,$verified)->replayed,'duplicate callback cannot credit again');
    financialArchivedReplay($gateway,$cid,$verified);
    HScript\Database\TransactionScope::run($pdo,static function()use($db,$cid):void{HScript\Payment\Provider\CurrencyArchive::archive($db,$db->fetch1Row($db->query('SELECT * FROM Currs WHERE cID=?d FOR UPDATE',[$cid])));});
    $calls=$GLOBALS['pkCatalogCalls'];$cached=$manager->handleCallback($gateway,['_raw_body'=>$body],$config);financialAssert($cached['correct']&&$GLOBALS['pkCatalogCalls']===$calls,'archived exact PayKassa token uses no API');
    HScript\Database\TransactionScope::run($pdo,static fn()=>HScript\Payment\Provider\CurrencyArchive::restore($db,$db->fetch1Row($db->query('SELECT * FROM Currs WHERE cID=?d FOR UPDATE',[$cid]))));
    $before=financialFingerprint();$wrong=$verified;$wrong['accto']='another-synthetic-receiver';
    try{GatewaySettlement::settle($db,$gateway,$cid,$wrong);throw new LogicException('Wrong receiver accepted');}catch(HScript\Finance\CallbackValidationException){financialAssert(financialFingerprint()===$before,'cached success still requires original receiver');}
}
financialAssert((int)financialValue("SELECT COUNT(*) FROM Opers WHERE oState=3 AND oOper='CASHIN'")===23,'twenty-three distinct exact invoices settle once each');
financialAssert((int)financialValue("SELECT COUNT(*) FROM IdempotencyKeys WHERE ikNamespace LIKE 'gateway:pk%:currency:%:identity'")===23,'twenty-three permanent original-invoice proofs');
echo "PayKassa catalog SQL: $assertions checks passed\n";
