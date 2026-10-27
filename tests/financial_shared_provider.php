<?php

declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
require __DIR__.'/fixtures/financial_runtime.php';
require __DIR__.'/fixtures/currency_archive_checks.php';
use HScript\Finance\{DepositCheckout,GatewaySettlement,IdempotencyResult,IdempotencyStore,StripeContract};
use HScript\Payment\{PaymentManager,CallbackResult};
use HScript\Payment\Provider\{PaymentProviderSchema,ProviderConnectionSchema,ProviderCatalogSchema,ProviderCatalog,ProviderRepository,ProviderService,ProviderException,PawProviderRepository,PawProviderService};
use HScript\Update\{SchemaStateRepository,MigrationRunner,MigrationLoader};

putenv('APP_DATA_KEY=synthetic-shared-provider-fixture-key');
set_exception_handler(static function(Throwable $e): void { fwrite(STDERR,$e::class.': '.$e->getMessage().' at '.basename($e->getFile()).':'.$e->getLine()."\n");exit(1); });
function sharedReject(callable $f,string $reason): void { try {$f();throw new LogicException('Expected '.$reason);} catch (ProviderException $e) { financialAssert($e->reason===$reason,'rejection '.$reason); } }
function sharedSnapshot(): string { global $pdo;$out=[];foreach (['Users','Wallets','Opers','Deps','Hist'] as $t) $out[$t]=$pdo->query('SELECT * FROM '.$t.' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);return hash('sha256',serialize($out)); }
function sharedInput(int $id,int $revision,array $credentials,array $currencies,array $bindings=[]): array { return ['form_complete'=>'1','profile_id'=>(string)$id,'revision'=>(string)$revision,'credentials'=>$credentials,'currencies'=>$currencies,'bindings'=>$bindings,'enabled'=>'1']; }
function sharedCurrency(int $id,int $profile,int $revision,array $extra=[]): array {
    return array_replace(HScript\Finance\CurrencyInput::normalize([]),['cID'=>(string)$id,'profile_id'=>(string)$profile,'revision'=>(string)$revision,'cName'=>'Configured currency','cCurr'=>'unit','cNumDec'=>'2','cDisabled'=>'0','cHidden'=>'0','cBalMin'=>'3.100000',
        'cCASHINMode'=>'3','cCASHOUTMode'=>'1','cCASHOUTLimitPer'=>'0','cEXMode'=>'0','cTRMode'=>'0','cBUYMode'=>'0','cSELLMode'=>'0','cBUY2Mode'=>'0','cSELL2Mode'=>'0','cGIVEMode'=>'0','cTAKEMode'=>'0'],$extra);
}
financialReset();PaymentProviderSchema::ensure($db);
$now=time();$pdo->prepare("INSERT INTO PaymentProviders(ppPublicID,ppKind,ppSlot,ppEnvironment,ppState,ppCurrentKeyVersion,ppCreatedAt,ppUpdatedAt) VALUES(?,'PAW','PAW','sandbox','disabled',1,?,?)")->execute([str_repeat('b',32),$now,$now]);
$pawId=(int)$pdo->lastInsertId();$cipher=HScript\Security\SecretCipher::encrypt('sk_sandbox_preserved',PawProviderRepository::context(str_repeat('b',32),1));
$pdo->prepare("INSERT INTO PaymentProviderCredentials(ppcProviderID,ppcVersion,ppcEnvironment,ppcEncryptedKey,ppcCreatedAt) VALUES(?,1,'sandbox',?,?)")->execute([$pawId,$cipher,$now]);
$db->insert('Currs',['cCID'=>'PAW','cCurrID'=>'EUR','cName'=>'Existing PAW EUR','cDisabled'=>1,'cCASHINMode'=>0,'cCASHOUTMode'=>0,'cProviderID'=>$pawId,'cProviderSelected'=>1]);

$ep=$db->insert('Currs',['cCID'=>'EPCU','cCurrID'=>'USD','cName'=>'Old ePayCore','cDisabled'=>0,'cCASHINMode'=>3,'cParamsSCI'=>'obsolete']);
$db->insert('Wallets',['wuID'=>1,'wcID'=>$ep,'wBal'=>'10.000000','wLock'=>'2.000000','wOut'=>'3.000000']);
$db->insert('Opers',['ouID'=>1,'ocID'=>$ep,'oOper'=>'CASHIN','oState'=>1,'oSum'=>'7.00']);
$db->insert('Deps',['duID'=>1,'dcID'=>$ep,'dpID'=>1,'dState'=>1,'dZD'=>'5']);
$before=sharedSnapshot();$identities=$pdo->query('SELECT cID,cCID,cCurrID FROM Currs ORDER BY cID')->fetchAll(PDO::FETCH_ASSOC);
foreach (['SchemaState','SchemaMigrations','UpdateRuns'] as $t) $pdo->exec('CREATE TABLE IF NOT EXISTS '.$t.' ('.$_dbstru[$t].') ENGINE=InnoDB');
$state=new SchemaStateRepository($db);$state->setCurrentVersion('1.0.5');$runner=new MigrationRunner($db,$state,new MigrationLoader(dirname(__DIR__).'/migrations/versioned'));
try { $runner->run('1.0.6',false);throw new LogicException('No backup'); } catch (RuntimeException $e) { financialAssert(str_contains($e->getMessage(),'verified backup'),'verified backup required'); }
financialAssert($state->currentVersion()==='1.0.5' && sharedSnapshot()===$before,'refusal preserves marker/money');
financialAssert($runner->run('1.0.6',true)===['202610150900_shared_provider_connections'],'ordered shared migration');
financialAssert($state->currentVersion()==='1.0.6' && sharedSnapshot()===$before,'upgrade preserves every financial row');
financialAssert((new PawProviderRepository($db))->key($pawId,1)==='sk_sandbox_preserved','existing PAW identity/key survive expanded schema');
financialAssert($identities===$pdo->query('SELECT cID,cCID,cCurrID FROM Currs ORDER BY cID')->fetchAll(PDO::FETCH_ASSOC),'historical currency identities preserved');
financialAssert((int)financialValue('SELECT cDisabled FROM Currs WHERE cID='.$ep)===1 && financialValue('SELECT cParamsSCI FROM Currs WHERE cID='.$ep)==='','ePayCore retired with credentials removed');
ProviderConnectionSchema::ensure($db);ProviderCatalogSchema::ensure($db);financialAssert(sharedSnapshot()===$before,'repeat ensure no monetary change');
$catalog=new ProviderCatalog($db);financialAssert(count($catalog->available())===11 && $catalog->available()['PAYKASSA']['name']==='PayKassa','one DB row per installed provider');
$pdo->exec("UPDATE PaymentGatewayCatalog SET pgAvailable=0 WHERE pgCode='STRIPE'");ProviderCatalogSchema::ensure($db);financialAssert(count($catalog->available())===10,'repeat seed preserves operator availability');$pdo->exec("UPDATE PaymentGatewayCatalog SET pgAvailable=1 WHERE pgCode='STRIPE'");
financialReset();$repository=new ProviderRepository($db);$calls=0;
$probe=static function($kind,$credentials,$currencies,$wallets) use ($pdo,&$calls): array { financialAssert(!$pdo->inTransaction(),'probe outside SQL locks');$calls++;return $kind==='STRIPE'?['account_id'=>'acct_fixture']:[]; };
HScript\Payment\Provider\CurrencyArchiveSchema::ensure($db);$service=new ProviderService($db,$probe);$input=sharedInput(0,0,['v3_key'=>'syntheticccapisecret'],['BTC']);
sharedReject(static fn()=>$service->save(89,'CCAPI',$input),'forbidden');financialAssert($calls===0,'auth before provider I/O');
$legacy=$db->insert('Currs',['cCID'=>'CCAB','cCurrID'=>'BTC','cName'=>'Existing BTC','cNumDec'=>6,'cDisabled'=>0]);
$before=sharedSnapshot();sharedReject(static fn()=>$service->save(90,'CCAPI',$input),'binding_required');financialAssert(sharedSnapshot()===$before && !$repository->current('CCAPI'),'unbound native row requires explicit choice, full rollback');
$input['bindings']=['BTC'=>(string)$legacy];$pid=$service->save(90,'CCAPI',$input);
financialAssert(sharedSnapshot()===$before,'explicit connection never changes money');
$c=$db->fetch1Row($db->select('Currs','*','cID=?d',[$legacy]));opDecodeCurrParams($c,$pay,$sci,$api);
financialAssert($sci===['protocol'=>'3','v3_key'=>'syntheticccapisecret'] && $api===$sci && $c['cParamsSCI']==='','shared credential resolver, no duplicated legacy secret');
financialAssert((int)$c['cProviderID']===$pid && (int)$c['cProviderKeyVersion']===1 && $c['cCID']==='CCAB' && $c['cCurrID']==='BTC','binding preserves ID/unit');
$service->saveCurrency(90,'CCAPI',$legacy,sharedCurrency($legacy,$pid,1));
financialAssert(financialValue('SELECT cBalMin FROM Currs WHERE cID='.$legacy)==='3.100000' && (int)financialValue('SELECT cCASHINMode FROM Currs WHERE cID='.$legacy)===3,'ordinary settings including balance alert and combined cashin');
sharedReject(static fn()=>$service->save(90,'CCAPI',$input),'configuration_changed');
$op=$db->insert('Opers',['ouID'=>1,'ocID'=>$legacy,'oOper'=>'CASHIN','oState'=>1,'oSum'=>'1']);
(new IdempotencyStore($db))->execute('invoice:attempt',(string)$op,1,str_repeat('a',64),static fn()=>new IdempotencyResult(['started'=>true],202,$op));
$rotate=sharedInput($pid,2,['v3_key'=>'syntheticreplacementkey'],['BTC']);sharedReject(static fn()=>$service->save(90,'CCAPI',$rotate),'payments_pending');
financialAssert((int)$repository->find($pid)['ppCurrentKeyVersion']===1,'pending payment retains original key');
sharedReject(static fn()=>$service->saveCurrency(90,'CCAPI',$legacy,sharedCurrency($legacy,$pid,2,['cNumDec'=>'3'])),'payments_pending');
$pdo->exec('UPDATE Opers SET oState=4 WHERE oID='.$op);$service->save(90,'CCAPI',$rotate);
financialAssert((int)$repository->find($pid)['ppCurrentKeyVersion']===2 && (int)financialValue('SELECT COUNT(*) FROM PaymentProviderCredentials WHERE ppcProviderID='.$pid)===2,'immutable rotation retains old version');
try {(new PawProviderService(new PawProviderRepository($db)))->disable(90,$pid,3,true);throw new LogicException('Cross-kind PAW');} catch (HScript\Payment\Provider\PawProviderException $e) {financialAssert($e->reason==='configuration_changed','PAW cannot retire other provider');}
sharedReject(static fn()=>$service->disable(90,'CCAPI',$pid,3,true),'currency_archive_invoice');$pdo->exec('UPDATE Opers SET oState=3 WHERE oID='.$op);$service->disable(90,'CCAPI',$pid,3,true);$new=$service->save(90,'CCAPI',sharedInput(0,0,['v3_key'=>'newprofilekey'],['BTC']));financialAssert($new!==$pid,'retire and reconnect keeps old profile/history');
$volet=$service->save(90,'VOLET',sharedInput(0,0,['email'=>'merchant@fixture.invalid','sci_name'=>'Fixture SCI','sci_secret'=>'sci-secret','api_name'=>'Fixture API','api_secret'=>'api-secret'],['USD'])+['wallets'=>['USD'=>'U1234567']]);
$v=$repository->connections($volet)[0];$service->saveCurrency(90,'VOLET',(int)$v['cID'],sharedCurrency((int)$v['cID'],$volet,1));
$v=$repository->connections($volet)[0];opDecodeCurrParams($v,$pay,$sci,$api);financialAssert($api['acc']==='U1234567' && $api['apipass']==='api-secret' && $sci['sci']==='Fixture SCI','currency save preserves local wallet across stamp change');
$stripe=$service->save(90,'STRIPE',sharedInput(0,0,['secret_key'=>'sk_test_fixture12345','webhook_secret'=>'whsec_fixture12345'],['USD','EUR','JPY']));
$sc=array_column($repository->connections($stripe),null,'cCurrID');$usd=(int)$sc['USD']['cID'];
$service->saveCurrency(90,'STRIPE',$usd,sharedCurrency($usd,$stripe,1));
$_cfg['Const_Salt']='synthetic-stripe-salt';$db->insert('Wallets',['wuID'=>1,'wcID'=>$usd]);
$_currs=$db->fetchIDRows($db->select('Currs'),false,'cID');
$oid=$db->insert('Opers',['ouID'=>1,'ocID'=>$usd,'oOper'=>'CASHIN','oState'=>0,'oSum'=>'1.010000','oComis'=>'0.010000','oCTS'=>timeToStamp(time())]);
$issued=[];
$public=DepositCheckout::prepare($db,1,$oid,static function(array $o) use (&$issued): array {
    opDecodeCurrParams($o,$p,$sci,$api);$issued=['id'=>'cs_test_sqlfixture','object'=>'checkout.session','mode'=>'payment','livemode'=>false,
        'client_reference_id'=>(string)$o['oID'],'currency'=>'usd','amount_total'=>101,'metadata'=>StripeContract::metadata($o,$sci),'status'=>'open','payment_status'=>'unpaid'];
    return ['url'=>'https://checkout.stripe.com/c/pay/cs_test_sqlfixture','get'=>true,'stripe_session'=>$issued['id'],'stripe_proof'=>json_encode(StripeContract::capture($o,$sci))];
});financialAssert(array_keys($public)===['url','get'],'checkout secrets/proofs never reach browser');
$c=$db->fetch1Row($db->select('Currs','*','cID=?d',[$usd]));opDecodeCurrParams($c,$pay,$sci,$api);$issued['status']='complete';$issued['payment_status']='paid';
function sharedStripeEvent(array $session,array $sci,string $eventId='evt_sqlfixture',string $type='checkout.session.completed'): array {
    $raw=json_encode(['id'=>$eventId,'object'=>'event','type'=>$type,'created'=>time(),'livemode'=>false,'data'=>['object'=>$session]],JSON_THROW_ON_ERROR);$t=time();
    return (new PaymentManager($GLOBALS['db']))->handleCallback('ST',['_raw_body'=>$raw,'_server'=>['HTTP_STRIPE_SIGNATURE'=>'t='.$t.',v1='.hash_hmac('sha256',$t.'.'.$raw,$sci['webhook_secret'])]],$sci);
}
$verified=sharedStripeEvent($issued,$sci);$paid=GatewaySettlement::settle($db,'ST',$usd,$verified);financialAssert(!$paid->replayed && $paid->data['settled'],'authentic Stripe callback settles');
financialWallet(1,$usd,'1.000000');$after=sharedSnapshot();
$retry=GatewaySettlement::settle($db,'ST',$usd,sharedStripeEvent($issued,$sci,'evt_different','checkout.session.async_payment_succeeded'));
financialAssert($retry->replayed && sharedSnapshot()===$after,'different events for same session have one effect');
financialArchivedReplay('ST',$usd,$verified);
$wrong=$issued;$wrong['amount_total']=102;
try {GatewaySettlement::settle($db,'ST',$usd,sharedStripeEvent($wrong,$sci,'evt_bad'));throw new LogicException('Wrong amount');} catch (HScript\Finance\IdempotencyConflict|HScript\Finance\CallbackValidationException) {financialAssert(sharedSnapshot()===$after,'wrong amount never changes money');}
$batch=financialValue('SELECT oBatch FROM Opers WHERE oID='.$oid);financialAssert(strlen($batch)<=39 && $batch===StripeContract::batchAlias($issued['id']),'short legacy batch, full durable session identity');
$service->save(90,'STRIPE',sharedInput($stripe,2,['secret_key'=>'sk_test_fixtureNEW123','webhook_secret'=>'whsec_fixtureNEW123'],['USD','EUR','JPY']));
$replayed=GatewaySettlement::settle($db,'ST',$usd,$verified);financialAssert($replayed->replayed && sharedSnapshot()===$after,'completed replay retains original webhook proof after credential rotation');
// Existing native automatic payouts retain their configured bundle and exact money.
$paykassa=$service->save(90,'PAYKASSA',sharedInput(0,0,['merchant_id'=>'123','merchant_secret'=>'merchant-fixture','api_id'=>'456','api_secret'=>'api-fixture'],['BTC','ETH','LTC']));
$pk=$repository->connections($paykassa)[0];opDecodeCurrParams($pk,$pay,$sci,$api);
financialAssert($sci===['id'=>'123','key'=>'merchant-fixture'] && $api===['id'=>'456','apipass'=>'api-fixture','shop_id'=>'123'],'one PayKassa bundle resolves SCI and payout API across currencies');
foreach ([['VOLET',$volet],['PAYKASSA',$paykassa]] as [$kind,$profileId]) {
    $profile=$repository->find($profileId);$currency=$repository->connections($profileId)[0];$currencyId=(int)$currency['cID'];
    $service->saveCurrency(90,$kind,$currencyId,sharedCurrency($currencyId,$profileId,(int)$profile['ppRevision'],['cCASHOUTMode'=>'2']));
    $_currs=$db->fetchIDRows($db->select('Currs'),false,'cID');
    financialAssert(opChangeBalance(2,$currencyId,0,0,'3')===true,'synthetic outgoing reservation includes mirrored user total');
    $out=$db->insert('Opers',['ouID'=>2,'ocID'=>$currencyId,'oOper'=>'CASHOUT','oState'=>2,'oSum'=>'3','oComis'=>'0.01','oParams2'=>arrayToStr(['acc'=>'synthetic-destination'])]);
    $sent=0;$send=static function(array $payload) use (&$sent,$kind): array {
        $sent++;financialAssert($payload['sum']==='2.99','shared native payout exact reserved net');
        financialAssert($kind==='VOLET' ? $payload['from']['apipass']==='api-secret' && $payload['from']['acc']==='U1234567' : $payload['from']['apipass']==='api-fixture' && $payload['from']['shop_id']==='123','shared bundle reaches existing payout dispatcher');
        return ['batch'=>'synthetic-shared-'.strtolower($kind)];
    };
    financialAssert(HScript\Finance\WithdrawalDispatcher::dispatch($db,$out,$send)===['completed'=>true],'existing automatic native payout remains functional');
    financialAssert(HScript\Finance\WithdrawalDispatcher::dispatch($db,$out,$send)===['completed'=>true] && $sent===1,'native shared payout sends exactly once');
}
// Interrupted ALTERs resume, unknown predecessor clauses fail closed.
$drop=str_contains((string)$pdo->query('SELECT VERSION()')->fetchColumn(),'MariaDB')?'DROP CONSTRAINT':'DROP CHECK';
$money=sharedSnapshot();
foreach ([['PaymentProviders','chk_provider_kind'],['PaymentProviders','chk_provider_slot'],['Currs','chk_currency_provider'],['Currs','chk_paw_configuration_only']] as [$table,$constraint]) {
    $pdo->exec("ALTER TABLE $table $drop $constraint");ProviderConnectionSchema::ensure($db);financialAssert(sharedSnapshot()===$money,'resumed constraint preserves all financial rows');
}
$pdo->exec("ALTER TABLE PaymentProviders $drop chk_provider_kind");$pdo->exec("ALTER TABLE PaymentProviders ADD CONSTRAINT chk_provider_kind CHECK (ppKind<>'UNKNOWN')");
try {ProviderConnectionSchema::ensure($db);throw new LogicException('Unknown predecessor accepted');} catch (RuntimeException $e) {financialAssert(str_contains($e->getMessage(),'Incompatible provider check'),'unknown predecessor rejected');}
$pdo->exec("ALTER TABLE PaymentProviders $drop chk_provider_kind");ProviderConnectionSchema::ensure($db);
if (str_contains((string)$pdo->query('SELECT VERSION()')->fetchColumn(),'MariaDB')) {
    $pdo->exec('SET check_constraint_checks=0');
    try {try {ProviderConnectionSchema::ensure($db);throw new LogicException('Disabled CHECK accepted');} catch (RuntimeException $e) {financialAssert(str_contains($e->getMessage(),'must be enforced'),'disabled constraints rejected');}} finally {$pdo->exec('SET check_constraint_checks=1');}
}
financialAssert(sharedSnapshot()===$money,'all schema refusals preserve money');
// New network expansion is a separate ordered migration, never a replay of 1.0.6.
foreach (['EA'=>'ETH','EAT'=>'USDT','EAT1'=>'DAI','BSC'=>'BNB','BSCT'=>'USDT','XRPA'=>'XRP','TRA'=>'TRX','TRAT'=>'USDT'] as $cid=>$asset) {
    $oldId=$db->insert('Currs',['cCID'=>$cid,'cCurrID'=>$asset,'cName'=>'Old network','cDisabled'=>0,'cCASHINMode'=>3,'cCASHOUTMode'=>2,'cParamsSCI'=>'old-secret']);
    $db->insert('Wallets',['wuID'=>1,'wcID'=>$oldId,'wBal'=>'1.000000','wLock'=>'2.000000','wOut'=>'3.000000']);
    $db->insert('Opers',['ouID'=>1,'ocID'=>$oldId,'oOper'=>'CASHIN','oState'=>1,'oSum'=>'4.000000']);
}
$networkBefore=sharedSnapshot();$identityBefore=$pdo->query('SELECT cID,cCID,cCurrID FROM Currs ORDER BY cID')->fetchAll(PDO::FETCH_ASSOC);
try { $runner->run('1.0.7',false);throw new LogicException('No network backup'); } catch (RuntimeException $e) { financialAssert(str_contains($e->getMessage(),'verified backup'),'network backup required'); }
financialAssert($runner->run('1.0.7',true)===['202610151900_provider_networks'],'network migration ordered separately');
financialAssert(sharedSnapshot()===$networkBefore && $identityBefore===$pdo->query('SELECT cID,cCID,cCurrID FROM Currs ORDER BY cID')->fetchAll(PDO::FETCH_ASSOC),'network migration preserves all financial rows and currency identities');
financialAssert((int)financialValue("SELECT COUNT(*) FROM Currs WHERE cCID IN (".HScript\Payment\Provider\ProviderNetworkSchema::RETIRED.") AND (cDisabled<>1 OR cHidden<>1 OR cCASHINMode<>0 OR cCASHOUTMode<>0 OR cParamsSCI<>'')")===0,'retired old adapters disabled and secrets removed');
HScript\Payment\Provider\ProviderNetworkSchema::ensure($db);financialAssert(sharedSnapshot()===$networkBefore,'repeated network expansion does not touch money');
$pdo->exec("ALTER TABLE Currs $drop chk_currency_provider");HScript\Payment\Provider\ProviderNetworkSchema::ensure($db);financialAssert(sharedSnapshot()===$networkBefore,'interrupted network constraint resumes');
$profile=$repository->current('CCAPI');$all=array_keys(array_filter(HScript\Payment\Provider\ProviderSettings::choices('CCAPI'),static fn($choice)=>isset(HScript\Payment\Gateways\CryptoCurrencyApi::NATIVE[$choice['gateway']])));
$service->save(90,'CCAPI',sharedInput((int)$profile['ppID'],(int)$profile['ppRevision'],['v3_key'=>'all-twelve-key'],$all));
financialAssert(count($repository->connections((int)$profile['ppID']))===12,'all twelve native networks share one profile');
$profile=$repository->current('VOLET');$choices=HScript\Payment\Provider\ProviderSettings::choices('VOLET');$wallets=[];
foreach ($choices as $asset=>$choice) $wallets[$asset]=$asset.'123456789012';
$service->save(90,'VOLET',sharedInput((int)$profile['ppID'],(int)$profile['ppRevision'],['email'=>'merchant@fixture.invalid','sci_name'=>'Fixture SCI','sci_secret'=>'sci-secret','api_name'=>'Fixture API','api_secret'=>'api-secret'],array_keys($choices))+['wallets'=>$wallets]);
financialAssert(count($repository->connections((int)$profile['ppID']))===11,'all documented Volet settlement currencies share one profile');
$profile=$repository->current('VOLET');$currencies=array_column($repository->connections((int)$profile['ppID']),null,'cCurrID');$eur=$currencies['EUR'];
sharedReject(static fn()=>$service->saveCurrency(90,'VOLET',(int)$eur['cID'],sharedCurrency((int)$eur['cID'],(int)$profile['ppID'],(int)$profile['ppRevision'],['cCASHOUTMode'=>'2'])),'automatic_withdrawal_unsupported');
financialAssert(sharedSnapshot()===$networkBefore,'adding shared choices changes no financial rows');
echo 'Shared providers: '.$assertions." assertions passed\n";
