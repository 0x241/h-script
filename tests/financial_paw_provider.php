<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/fixtures/financial_runtime.php';

use HScript\Payment\Provider\PaymentProviderSchema;
use HScript\Payment\Provider\PawProviderException;
use HScript\Payment\Provider\PawProviderRepository;
use HScript\Payment\Provider\PawProviderService;
use HScript\Payment\Provider\PawValidationClient;
use HScript\Security\SecretCipher;

putenv('APP_DATA_KEY=synthetic-paw-fixture-data-key-32-bytes');
final class SyntheticPawValidation implements PawValidationClient {
    public function __construct(private PDO $pdo,private ?Closure $before=null,private bool $malformed=false) {}
    public function getBalance(): array {
        financialAssert(!$this->pdo->inTransaction(),'network validation is outside SQL transaction');
        if ($this->before) { ($this->before)();$this->before=null; }
        return $this->malformed ? ['available'=>'0','total_income'=>0,'total_payouts'=>0] : ['available'=>0,'total_income'=>0,'total_payouts'=>0];
    }
    public function getRates(string $base): array { financialAssert(!$this->pdo->inTransaction(),'rates outside transaction');return ['base'=>$base,'rates'=>['btc'=>'60000.01','eth'=>null]]; }
    public function validateSandbox(): void {
        financialAssert(!$this->pdo->inTransaction(),'sandbox validation outside SQL transaction');
        if ($this->before) { ($this->before)();$this->before=null; }
        if ($this->malformed) throw new PawProviderException('provider_unavailable');
    }
}
/** PDO still executes real SQL; corrupt one fiat to provoke its native unique constraint. */
final class SyntheticPawStatement extends PDOStatement {
    public static bool $rejectSecondCurrency=false;
    protected function __construct() {}
    public function execute(?array $params=null): bool {
        if (self::$rejectSecondCurrency && str_starts_with($this->queryString,'INSERT INTO Currs') && ($params[0]??null)==='USD') $params[0]='EUR';
        return parent::execute($params);
    }
}
$repository=new PawProviderRepository($db);
$invalidations=0;$calls=0;
$clients=static function(string $key,int $timeout) use ($pdo,&$calls): PawValidationClient {
    financialAssert($timeout>=1 && $timeout<=5,'validation request deadline bounded');$calls++;
    return new SyntheticPawValidation($pdo);
};
$service=new PawProviderService($repository,$clients,static function() use (&$invalidations): void { $invalidations++; });
function pawInput(int $id,int $revision,string $key='',array $fiats=['USD','EUR'],string $enabled='1'): array {
    return ['profile_id'=>(string)$id,'revision'=>(string)$revision,'api_key'=>$key,'fiats'=>$fiats,'enabled'=>$enabled];
}
function pawFail(callable $call,string $reason): void {
    try { $call();throw new LogicException('Expected provider failure: '.$reason); }
    catch (PawProviderException $e) { financialAssert($e->reason===$reason,'provider failure '.$reason); }
}
function pawStorage(): string {
    global $pdo;$all=[];
    foreach (['PaymentProviders','PaymentProviderCredentials','Currs','Wallets','Opers','Hist'] as $t) $all[$t]=$pdo->query('SELECT * FROM '.$t)->fetchAll(PDO::FETCH_ASSOC);
    return hash('sha256',serialize($all));
}
function pawHistory(): string {
    global $pdo;$rows=[];
    foreach (['Wallets','Opers','Hist'] as $table) $rows[$table]=$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
    return hash('sha256',serialize($rows));
}
if (($argv[1]??'')==='worker') {
    $input=json_decode($argv[2],true,32,JSON_THROW_ON_ERROR);
    // Synchronize validation completion to force competing writers with one revision.
    $concurrent=new PawProviderService($repository,static function() use ($pdo): PawValidationClient {
        return new SyntheticPawValidation($pdo,static function(): void { usleep(200000); });
    });
    try { $result=$concurrent->save(90,$input);echo json_encode(['saved'=>$result['revision']]); }
    catch (PawProviderException $e) { echo json_encode(['error'=>$e->reason]); }
    exit;
}
financialReset();
PaymentProviderSchema::ensure($db);
$first=pawStorage();PaymentProviderSchema::ensure($db);financialAssert(pawStorage()===$first,'fresh schema verification is repeatable');
// Model interrupted upgrade from 1.0.4 with a table and nullable reference already created.
$drop=str_contains((string)$pdo->query('SELECT VERSION()')->fetchColumn(),'MariaDB')?'DROP CONSTRAINT ':'DROP CHECK ';
foreach (['chk_currency_provider','chk_paw_configuration_only'] as $c) $pdo->exec('ALTER TABLE Currs '.$drop.$c);
$pdo->exec('ALTER TABLE Currs DROP FOREIGN KEY fk_currency_provider');
$pdo->exec('ALTER TABLE Currs DROP INDEX uq_currency_provider_fiat');
$pdo->exec('ALTER TABLE Currs DROP COLUMN cProviderSelected');
$pdo->exec('DROP TABLE PaymentProviderCredentials');
$migration=require dirname(__DIR__).'/migrations/versioned/202610041200_payment_provider_profiles.php';
financialAssert($migration['from']==='1.0.4' && $migration['to']==='1.0.5' && $migration['classification']==='backup-required','ordered backup-required migration');
foreach (['SchemaState','SchemaMigrations','UpdateRuns'] as $table) $pdo->exec('CREATE TABLE IF NOT EXISTS '.$table.' ('.$_dbstru[$table].') ENGINE=InnoDB');
$state=new HScript\Update\SchemaStateRepository($db);$state->setCurrentVersion('1.0.4');
$runner=new HScript\Update\MigrationRunner($db,$state,new HScript\Update\MigrationLoader(dirname(__DIR__).'/migrations/versioned'));
$plan=$runner->preflight('1.0.5');
financialAssert(count($plan)===1 && $plan[0]->id()===$migration['id'],'real ordered registry selects only PAW upgrade');
try { $runner->run('1.0.5',false);throw new LogicException('Migration ran without backup'); }
catch (RuntimeException $e) { financialAssert(str_contains($e->getMessage(),'verified backup'),'new migration requires verified backup'); }
financialAssert($state->currentVersion()==='1.0.4','backup refusal leaves schema marker unchanged');
$state->beginMigration($plan[0],HScript\Application::version());
financialAssert($runner->run('1.0.5',true)===[$migration['id']] && $state->currentVersion()==='1.0.5','ordered runner resumes partial DDL and advances marker');
financialAssert($runner->run('1.0.5',true)===[],'completed registry migration does not reapply');
$row=$state->migration($migration['id']);
financialAssert($row['smStatus']==='applied' && $row['smChecksum']===$plan[0]->checksum(),'migration audit/checksum recorded');
PaymentProviderSchema::ensure($db);
financialAssert((int)financialValue('SELECT COUNT(*) FROM Currs')===3,'resumed upgrade preserves legacy currencies');
$before=pawStorage();pawFail(static fn()=>$service->save(89,pawInput(0,0,'sk_sandbox_synthetic-key')),'forbidden');financialAssert($calls===0 && pawStorage()===$before,'authorization before API and SQL writes');
foreach ([['api_key'=>"bad\nheader"],['fiats'=>['RUB']],['fiats'=>['USD','USD']],['enabled'=>'yes'],['revision'=>[]]] as $change) {
    $input=array_replace(pawInput(0,0,'sk_sandbox_synthetic-key'),$change);
    pawFail(static fn()=>$service->save(90,$input),isset($change['api_key'])?'invalid_api_key':(isset($change['fiats']) && $change['fiats']===['RUB']?'fiat_unsupported':'input_invalid'));
    financialAssert($calls===0 && pawStorage()===$before,'malformed input fails before outbound call');
}
$malformed=new PawProviderService($repository,static fn()=>new SyntheticPawValidation($pdo,null,true));
pawFail(static fn()=>$malformed->save(90,pawInput(0,0,'sk_sandbox_synthetic-key')),'provider_unavailable');
financialAssert(pawStorage()===$before,'malformed provider balance does not persist partial config');
putenv('APP_DATA_KEY=');
pawFail(static fn()=>$service->save(90,pawInput(0,0,'sk_sandbox_synthetic-key')),'encryption_unavailable');
financialAssert($calls===0 && pawStorage()===$before,'missing app encryption key blocks API and persistence');
putenv('APP_DATA_KEY=synthetic-paw-fixture-data-key-32-bytes');
$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[SyntheticPawStatement::class]);
SyntheticPawStatement::$rejectSecondCurrency=true;
pawFail(static fn()=>$service->save(90,pawInput(0,0,'sk_sandbox_synthetic-key')),'configuration_changed');
SyntheticPawStatement::$rejectSecondCurrency=false;
$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[PDOStatement::class]);
financialAssert(pawStorage()===$before && $invalidations===0,'profile/key/first currency all rollback atomically');
$calls=0;
$view=$service->save(90,pawInput(0,0,'sk_sandbox_synthetic-key'));
$id=$view['id'];$revision=$view['revision'];
financialAssert($view['state']==='enabled' && $view['environment']==='sandbox' && count($view['connections'])===2,'one profile serves selected USD/EUR');
financialAssert($calls===3 && $invalidations===1,'balance and every selected fiat checked once; cache after commit');
financialAssert((int)financialValue('SELECT COUNT(*) FROM PaymentProviderCredentials')===1,'key encrypted once, not once per currency');
$currencyIds=array_column($view['connections'],'cID','cCurrID');
foreach ($view['connections'] as $c) financialAssert((int)$c['cDisabled']===1 && (int)$c['cCASHINMode']===0 && (int)$c['cCASHOUTMode']===0,'child financial modes disabled');
financialAssert((int)financialValue('SELECT COUNT(*) FROM Currs WHERE cProviderID IS NOT NULL AND (cParams IS NOT NULL OR cParamsSCI IS NOT NULL OR cParamsAPI IS NOT NULL)')===0,'no copied per-currency credentials');
financialAssert(!str_contains(json_encode($view),'synthetic-key') && !str_contains(json_encode($view),'Encrypted') && !str_contains(json_encode($view),'ppPublicID'),'safe view contains no credential envelope');
$credential=$pdo->query('SELECT * FROM PaymentProviderCredentials')->fetch(PDO::FETCH_ASSOC);
financialAssert($credential['ppcEncryptedKey']!=='sk_sandbox_synthetic-key' && $repository->key($id,1)==='sk_sandbox_synthetic-key','only authenticated context decrypts stored key');
financialAssert(SecretCipher::decrypt($credential['ppcEncryptedKey'],'wrong-profile')===null,'foreign profile AAD rejected');
$pdo->prepare('INSERT INTO Wallets(wuID,wcID,wBal) VALUES(1,?,12.5)')->execute([$currencyIds['USD']]);
$pdo->prepare("INSERT INTO Opers(ouID,ocID,oOper,oSum,oState,oBatch) VALUES(1,?,'CASHIN',12.5,3,'synthetic-historical-paw')")->execute([$currencyIds['USD']]);
$pdo->prepare("INSERT INTO Hist(huID,hcID,hOper,hSum) VALUES(1,?,'CASHIN',12.5)")->execute([$currencyIds['USD']]);
$history=pawHistory();
$view=$service->save(90,pawInput($id,$revision));$revision=$view['revision'];
financialAssert((int)financialValue('SELECT COUNT(*) FROM PaymentProviderCredentials')===1 && $repository->key($id,1)==='sk_sandbox_synthetic-key','blank key preserves prior version');
$view=$service->save(90,pawInput($id,$revision,'sk_sandbox_rotated-synthetic-key',['EUR']));$revision=$view['revision'];
financialAssert($repository->key($id,1)==='sk_sandbox_synthetic-key' && $repository->key($id,2)==='sk_sandbox_rotated-synthetic-key','rotation retains old key for recovery');
financialAssert((int)financialValue('SELECT COUNT(*) FROM PaymentProviderCredentials WHERE ppcRetiredAt IS NOT NULL')===1,'one retired credential version');
financialAssert((int)financialValue('SELECT cProviderSelected FROM Currs WHERE cID='.(int)$currencyIds['USD'])===0,'deselection preserves currency row');
$view=$service->save(90,pawInput($id,$revision));$revision=$view['revision'];
financialAssert(array_column($view['connections'],'cID','cCurrID')===$currencyIds,'reselection preserves currency identities');
financialAssert(pawHistory()===$history,'select/rotate changes no financial history');
$currencyInput=$pdo->query('SELECT * FROM Currs WHERE cID='.(int)$currencyIds['USD'])->fetch(PDO::FETCH_ASSOC);
$currencyInput=array_map(static fn($v): string=>(string)$v,$currencyInput);
$currencyInput['profile_id']=(string)$id;$currencyInput['revision']=(string)$revision;
$before=pawStorage();$beforeCalls=$calls;
foreach ([['cDisabled'=>'0'],['cCASHINMode'=>'2'],['cCASHOUTMode'=>'1'],['cNumDec'=>'7'],['cEXMode'=>'3'],['cHidden'=>[]],['cName'=>[]],['cTRComis'=>'0.001'],['cCASHINMin'=>'10000000'],['cBalMin'=>'NaN'],['cBalMin'=>'0.0000001'],['cBalMin'=>'10000000'],['cID'=>(string)$currencyIds['EUR']]] as $change) {
    $reason=isset($change['cTRComis']) || isset($change['cCASHINMin']) || isset($change['cBalMin']) ? 'currency_decimal_invalid' : 'input_invalid';
    pawFail(static fn()=>$service->saveCurrency(90,(int)$currencyIds['USD'],array_replace($currencyInput,$change)),$reason);
    financialAssert(pawStorage()===$before && $calls===$beforeCalls,'bad currency settings never persist or call PAW');
}
pawFail(static fn()=>$service->saveCurrency(89,(int)$currencyIds['USD'],$currencyInput),'forbidden');
$foreign=array_replace($currencyInput,['cID'=>'1']);
pawFail(static fn()=>$service->saveCurrency(90,1,$foreign),'configuration_changed');
$currencyInput=array_replace($currencyInput,['cName'=>'PAW USD custom','cCurr'=>'$','cTRComis'=>'0.01','cCASHINMin'=>'2.345678', 'cCID'=>'PM', 'cCurrID'=>'BTC', 'cProviderID'=>'9999', 'cParams'=>'CREDENTIAL_CANARY']);
$service->saveCurrency(90,(int)$currencyIds['USD'],$currencyInput);$revision++;
$stored=$pdo->query('SELECT * FROM Currs WHERE cID='.(int)$currencyIds['USD'])->fetch(PDO::FETCH_ASSOC);
financialAssert($stored['cName']==='PAW USD custom' && $stored['cCurr']==='$' && $stored['cTRComis']==='0.01' && $stored['cCASHINMin']==='2.345678','ordinary currency settings persist with exact decimal precision');
financialAssert($stored['cCID']==='PAW' && $stored['cCurrID']==='USD' && (int)$stored['cProviderID']===$id && $stored['cParams']===null,'provider identity and legacy credentials are not writable through settings');
financialAssert(pawHistory()===$history && $calls===$beforeCalls && $repository->key($id,2)==='sk_sandbox_rotated-synthetic-key','currency settings preserve money/history/key and make no provider requests');
pawFail(static fn()=>$service->saveCurrency(90,(int)$currencyIds['USD'],$currencyInput),'configuration_changed');
$pdo->beginTransaction();
pawFail(static fn()=>$service->saveCurrency(90,(int)$currencyIds['USD'],array_replace($currencyInput,['revision'=>(string)$revision])),'configuration_busy');
$pdo->rollBack();
$before=pawStorage();$beforeCalls=$calls;
pawFail(static fn()=>$service->save(90,pawInput($id,$revision-1)),'configuration_changed');
pawFail(static fn()=>$service->save(90,pawInput($id,$revision,'live-synthetic-key')),'environment_changed');
financialAssert(pawStorage()===$before && $calls===$beforeCalls,'stale revision/environment fail before API');
$stale=new PawProviderService($repository,static fn()=>new SyntheticPawValidation($pdo,static function() use ($pdo,$id): void {
    $pdo->prepare('UPDATE PaymentProviders SET ppRevision=ppRevision+1 WHERE ppID=?')->execute([$id]);
}));
pawFail(static fn()=>$stale->save(90,pawInput($id,$revision,'sk_sandbox_third-synthetic-key')),'configuration_changed');
financialAssert((int)financialValue('SELECT COUNT(*) FROM PaymentProviderCredentials')===2,'revision changing during API validation cannot rotate key');
$revision++;
$processes=[];
for ($i=0;$i<8;$i++) {
    $p=proc_open([PHP_BINARY,__FILE__,'worker',json_encode(pawInput($id,$revision),JSON_THROW_ON_ERROR)],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($p)) throw new RuntimeException('Worker start failed');$processes[]=[$p,$pipes];
}
$results=[];
foreach ($processes as [$p,$pipes]) {
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    if (proc_close($p)!==0) throw new RuntimeException('PAW worker failed: '.$err);
    $results[]=json_decode($out,true,32,JSON_THROW_ON_ERROR);
}
financialAssert(count(array_filter($results,static fn($r)=>isset($r['saved'])))===1,'exactly one concurrent revision saves');
financialAssert(count(array_filter($results,static fn($r)=>($r['error']??null)==='configuration_changed'))===7,'competing revisions rejected');$revision++;
$service->disable(90,$id,$revision);$revision++;
financialAssert($repository->find($id)['ppState']==='disabled' && pawHistory()===$history,'disable preserves every operation/bucket/history');
$service->disable(90,$id,$revision,true);$revision++;
financialAssert($repository->current()===null && $repository->find($id)['ppState']==='retired','remove retires slot without deleting profile');
financialAssert(pawHistory()===$history && $repository->key($id,1)==='sk_sandbox_synthetic-key','remove preserves history and recovery key');
$replacement=$service->save(90,pawInput(0,0,'live-synthetic-new-key',['USD']));
financialAssert($replacement['id']!==$id && $replacement['environment']==='live' && $replacement['connections'][0]['cID']!==$currencyIds['USD'],'replacement environment uses distinct profile/currency IDs');
foreach ([
    'DELETE FROM PaymentProviders WHERE ppID='.$id,
    'UPDATE PaymentProviders SET ppEnvironment=\'live\' WHERE ppID='.$id,
    'UPDATE Currs SET cCASHINMode=2 WHERE cID='.(int)$currencyIds['USD'],
    'UPDATE Currs SET cDisabled=NULL WHERE cID='.(int)$currencyIds['USD'],
    'UPDATE Currs SET cProviderID=999999 WHERE cID='.(int)$currencyIds['USD'],
    'UPDATE Currs SET cProviderID=NULL WHERE cID='.(int)$currencyIds['USD'],
    "INSERT INTO Currs(cCID,cCurrID,cProviderID,cProviderSelected,cDisabled,cCASHINMode,cCASHOUTMode) VALUES('PAW','USD',$id,1,1,0,0)"
] as $sql) {
    try { $pdo->exec($sql);throw new LogicException('SQL constraint accepted unsafe config'); }
    catch (PDOException $e) { financialAssert(in_array($e->getCode(),['23000','HY000'],true),'DB constraint protects shared profile/history'); }
}
foreach (['ppCurrentKeyVersion'=>7,'ppRevision'=>41,'ppState'=>"'enabled'",'ppValidationState'=>"'valid'"] as $column=>$default) {
    $pdo->exec("ALTER TABLE PaymentProviders ALTER COLUMN $column SET DEFAULT $default");
    try { PaymentProviderSchema::ensure($db);throw new LogicException('Unsafe profile default accepted'); }
    catch (RuntimeException $e) { financialAssert(str_contains($e->getMessage(),'default'),'unsafe profile default rejected'); }
    $safe=match ($column) { 'ppState'=>"'disabled'",'ppValidationState'=>"'none'",default=>0 };
    $pdo->exec("ALTER TABLE PaymentProviders ALTER COLUMN $column SET DEFAULT $safe");
}
if (!str_contains((string)$pdo->query('SELECT VERSION()')->fetchColumn(),'MariaDB')) {
    $pdo->exec('ALTER TABLE Currs ALTER CHECK chk_paw_configuration_only NOT ENFORCED');
    try { PaymentProviderSchema::ensure($db);throw new LogicException('Unenforced CHECK accepted'); }
    catch (RuntimeException $e) { financialAssert(str_contains($e->getMessage(),'enforced'),'NOT ENFORCED is rejected'); }
    $pdo->exec('ALTER TABLE Currs ALTER CHECK chk_paw_configuration_only ENFORCED');
} else {
    $pdo->exec('SET check_constraint_checks=0');
    try { PaymentProviderSchema::ensure($db);throw new LogicException('Disabled CHECK session accepted'); }
    catch (RuntimeException $e) { financialAssert(str_contains($e->getMessage(),'enforced'),'disabled MariaDB CHECK enforcement rejected'); }
    $pdo->exec('SET check_constraint_checks=1');
}
$pdo->exec('ALTER TABLE Currs '.$drop.'chk_currency_provider');
$pdo->exec("ALTER TABLE Currs ADD CONSTRAINT chk_currency_provider CHECK (cProviderID IS NULL AND cProviderSelected=0 AND cCID IS NULL OR cCID<>'PAW' OR (cProviderID IS NOT NULL AND cCID IS NOT NULL AND cCID='PAW' AND cProviderSelected IN (0,1)))");
try { PaymentProviderSchema::ensure($db);throw new LogicException('Weakened grouping accepted'); }
catch (RuntimeException $e) { financialAssert(str_contains($e->getMessage(),'check'),'boolean grouping is verified'); }
$pdo->exec('ALTER TABLE Currs '.$drop.'chk_currency_provider');PaymentProviderSchema::ensure($db);

// Incompatible partial DDL fails closed rather than silently blessing metadata.
$pdo->exec('ALTER TABLE Currs DROP FOREIGN KEY fk_currency_provider');
$pdo->exec('ALTER TABLE Currs MODIFY cProviderID bigint unsigned NULL DEFAULT NULL');
try { PaymentProviderSchema::ensure($db);throw new LogicException('Incompatible reference accepted'); }
catch (RuntimeException $e) { financialAssert(str_contains($e->getMessage(),'column'),'incompatible partial DDL rejected'); }
$pdo->exec('ALTER TABLE Currs MODIFY cProviderID int unsigned NULL DEFAULT NULL');PaymentProviderSchema::ensure($db);
$db->delete('SchemaMigrations','smID=?',[$migration['id']]);$db->delete('SchemaState','ssKey=?',['current']);
financialReset();
echo "PAW profile SQL/upgrade/concurrency tests passed ($assertions checks).\n";
