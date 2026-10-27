<?php

declare(strict_types=1);
namespace HScript\Payment\Provider;

use Closure;
use HScript\Database\Connection;
use HScript\Database\TransactionScope;
use HScript\Finance\CurrencyInput;
use HScript\Finance\AdministrativeDecimal;
use HScript\Security\SecretCipher;
use PDO;

/** A shared connection changes credentials, never currency/operation identities. */
final class ProviderService
{
    private ProviderRepository $repository;
    private Closure $probe;
    private Closure $invalidate;
    public function __construct(private Connection $database, ?Closure $probe=null, ?Closure $invalidate=null)
    {
        $this->repository=new ProviderRepository($database);
        $this->probe=$probe ?? static fn(string $kind,array $key,array $currencies,array $wallets): array=>ProviderProbe::validate($kind,$key,$currencies,$wallets);
        $this->invalidate=$invalidate ?? static function(): void {};
    }
    public function save(int $level,string $kind,array $input): int
    {
        $this->authorize($level,$kind);
        CurrencyArchive::requireReady($this->database);
        if(($input['form_complete']??null)!=='1')throw new ProviderException('input_invalid');
        (new ProviderCatalog($this->database))->requireAvailable($kind);
        $id=self::number($input['profile_id']??null);$revision=self::number($input['revision']??null);
        $old=$id ? $this->repository->find($id) : null;
        $this->assertProfile($old,$kind,$id,$revision);
        if (!$old && $this->repository->current($kind)) throw new ProviderException('configuration_changed');
        $previous=$old ? $this->repository->credentials($old,(int)$old['ppCurrentKeyVersion']) : [];
        $credentials=ProviderSettings::credentials($kind,(array)($input['credentials']??[]),$previous);
        $environment=ProviderSettings::environment($kind,$credentials);
        if ($old && $environment!==$old['ppEnvironment']) throw new ProviderException('environment_changed');
        if (!in_array($input['enabled']??null,['0','1'],true)) throw new ProviderException('input_invalid');
        if($kind==='PAYKASSA' && $old && $old['ppEnvironment']!=='live')throw new ProviderException('paykassa_sandbox_removed');
        $choices=ProviderSettings::choices($kind);$selected=$input['currencies']??[];
        if($kind==='CCAPI' && $old)foreach($this->repository->connections($id) as $c)if($c['cCID']==='CCTU'){
            // Existing combined balances can rotate credentials, but cannot be created or rebound.
            $choices['USDT']=['gateway'=>'CCTU','currency'=>'USDT','name'=>'Legacy USDT','scale'=>6];break;
        }
        if (!is_array($selected) || !array_is_list($selected) || count($selected)>count($choices)
            || count(array_unique($selected,SORT_REGULAR))!==count($selected)) throw new ProviderException('input_invalid');
        $activationScope=CurrencySelection::scope($input,array_keys($choices));
        $bindings=[];$wallets=[];
        foreach ($selected as $asset) {
            if (!is_string($asset) || !isset($choices[$asset])) throw new ProviderException('input_invalid');
            if($kind==='PAYKASSA' && !in_array($choices[$asset]['gateway'],['PKB','PKE','PKL','PKTU','PKTC'],true) && !PaykassaAssetSchema::ready($this->repository->pdo))throw new ProviderException('paykassa_migration_required');
            $bindings[$asset]=self::number($input['bindings'][$asset]??'0');
            if ($kind==='VOLET') {
                $wallet=$input['wallets'][$asset]??null;
                if (!is_string($wallet) || !preg_match($asset==='USD'?'/^(?:USD[0-9]{12}|U[0-9]{6,20})$/D':'/^'.preg_quote($asset,'/').'[0-9]{12}$/D',$wallet)) throw new ProviderException('input_invalid');
                $wallets[$asset]=$wallet;
            }
        }
        if (SecretCipher::encrypt('probe','provider-connection-probe')===null) throw new ProviderException('encryption_unavailable');
        // Bounded read-only validation happens before acquiring SQL locks.
        $validation=($this->probe)($kind,$credentials,$selected,$wallets);
        if ($kind==='STRIPE') {
            if (!is_string($validation['account_id']??null) || !preg_match('/^acct_[A-Za-z0-9]{1,80}$/D',$validation['account_id']))
                throw new ProviderException('provider_unavailable');
            $credentials['account_id']=$validation['account_id'];
        }
        $pdo=$this->repository->pdo;
        $id=TransactionScope::run($pdo,function() use ($pdo,$old,$id,$revision,$kind,$credentials,$previous,$environment,$input,$selected,$choices,$bindings,$wallets,$activationScope): int {
            if($kind==='CCAPI')\HScript\Payment\Routing\UsdtOwnership::lock($this->database);
            $now=time();
            if ($id) {
                $p=$this->repository->find($id,true);$this->assertProfile($p,$kind,$id,$revision);
            } else {
                $q=$pdo->prepare("INSERT INTO PaymentProviders(ppPublicID,ppKind,ppSlot,ppEnvironment,ppState,ppCreatedAt,ppUpdatedAt) VALUES(?,?,?,?,'disabled',?,?)");
                $q->execute([bin2hex(random_bytes(16)),$kind,$kind,$environment,$now,$now]);
                $p=$this->repository->find((int)$pdo->lastInsertId(),true);
            }
            $pid=(int)$p['ppID'];$version=(int)$p['ppCurrentKeyVersion'];
            $connections=$this->repository->connections($pid,true);
            $changed=$credentials!==$previous;
            if ($changed) foreach ($connections as $c) $this->assertNoIssuedPayments((int)$c['cID']);
            if ($changed || !$version) {
                $version++;
                $encrypted=SecretCipher::encrypt(json_encode($credentials,JSON_THROW_ON_ERROR),ProviderRepository::context($kind,$p['ppPublicID'],$version));
                if ($encrypted===null) throw new ProviderException('encryption_unavailable');
                $q=$pdo->prepare('INSERT INTO PaymentProviderCredentials(ppcProviderID,ppcVersion,ppcEnvironment,ppcEncryptedKey,ppcCreatedAt) VALUES(?,?,?,?,?)');
                $q->execute([$pid,$version,$environment,$encrypted,$now]);
                $q=$pdo->prepare('UPDATE PaymentProviderCredentials SET ppcRetiredAt=? WHERE ppcProviderID=? AND ppcVersion=?');$q->execute([$now,$pid,$version-1]);
            }
            $byAsset=[];foreach($connections as $connection)if($connection['cCID']!=='CCT')$byAsset[$kind==='PAYKASSA'?\HScript\Payment\PaykassaAssets::choiceKey($connection):$connection['cCurrID']]=$connection;
            $assetColumn=(bool)$pdo->query("SHOW COLUMNS FROM Currs WHERE Field='cAssetKey'")->fetch();
            foreach ($selected as $asset) {
                $choice=$choices[$asset];$c=$byAsset[$asset]??null;
                if($kind==='CCAPI' && $asset==='USDT' && (!$c || $c['cCID']!=='CCTU'))throw new ProviderException('legacy_token_requires_reconciliation');
                if($kind==='CCAPI' && $asset==='USDT')\HScript\Payment\Routing\UsdtOwnership::legacy($this->database);
                if ($c && $c['cCID']!==$choice['gateway']) throw new ProviderException('configuration_changed');
                if ($c && $bindings[$asset] && (int)$c['cID']!==$bindings[$asset]) throw new ProviderException('configuration_changed');
                if ($c && CurrencyArchive::restore($this->database,$c)) continue;
                $newBinding=false;
                if (!$c && $bindings[$asset]) {
                    $q=$pdo->prepare('SELECT * FROM Currs WHERE cID=? FOR UPDATE');$q->execute([$bindings[$asset]]);$c=$q->fetch(PDO::FETCH_ASSOC);
                    if (!$c || $c['cProviderID']!==null || $c['cCID']!==$choice['gateway'] || $c['cCurrID']!==$choice['currency']) throw new ProviderException('configuration_changed');
                    $this->assertNoIssuedPayments((int)$c['cID']);$newBinding=true;
                }
                if (!$c) {
                    if ($kind!=='STRIPE') {
                        $q=$pdo->prepare("SELECT cID FROM Currs LEFT JOIN PaymentProviders ON ppID=cProviderID WHERE cCID=? AND (cProviderID IS NULL OR ppState<>'retired') ORDER BY cID LIMIT 1 FOR UPDATE");$q->execute([$choice['gateway']]);
                        if ($q->fetchColumn()!==false) throw new ProviderException('binding_required');
                    }
                    $q=$pdo->prepare('INSERT INTO Currs(cCID,cCurrID,cName,cCurr,cNumDec,cProviderID,cProviderSelected,cProviderKeyVersion,cDisabled,cCASHINMode,cCASHOUTMode'.($assetColumn?',cAssetKey':'').') VALUES(?,?,?,?,?,?,1,?,1,0,0'.($assetColumn?',?':'').')');
                    $q->execute([$choice['gateway'],$choice['currency'],ProviderCatalog::GROUPS[$kind][0].' '.($kind==='PAYKASSA' && $asset!==$choice['currency']?$choice['name']:$asset),$choice['currency'],$choice['scale'],$pid,$version,...($assetColumn?[$choice['asset_key']??'asset:'.$asset]:[])]);
                    $c=['cID'=>(int)$pdo->lastInsertId(),'cMTS'=>'','cCID'=>$choice['gateway']];$newBinding=true;
                }
                if ($kind!=='STRIPE' && isset($c['cDisabled']) && (int)$c['cDisabled']===0) {
                    $q=$pdo->prepare('SELECT cID FROM Currs WHERE cCID=? AND cDisabled=0 AND cID<>? ORDER BY cID LIMIT 1 FOR UPDATE');$q->execute([$choice['gateway'],(int)$c['cID']]);
                    if ($q->fetchColumn()!==false) throw new ProviderException('binding_required');
                }
                if(!$newBinding && !$changed && $kind!=='VOLET'){
                    // A selection/activation save must not generate fresh ciphertext
                    // for unchanged local data on retained (including hidden) rows.
                    $q=$pdo->prepare('UPDATE Currs SET cProviderKeyVersion=?,cProviderSelected=1 WHERE cID=?');$q->execute([$version,(int)$c['cID']]);continue;
                }
                // Retain only currency-local API data; the shared secrets have one encrypted copy.
                $local=$kind==='VOLET' ? ['acc'=>$wallets[$asset]] : [];
                if ($kind==='VOLET' && !$newBinding) {
                    $key=$c['cID'].$c['cCID'].stampToTime($c['cMTS']??'');
                    $oldLocal=decodeArrayFromStr($c['cParamsAPI']??'',$key,3);
                    if (($oldLocal['acc']??null)!==$wallets[$asset]) $this->assertNoIssuedPayments((int)$c['cID']);
                }
                $key=$c['cID'].$c['cCID'].stampToTime($c['cMTS']??'');
                $q=$pdo->prepare('UPDATE Currs SET cProviderID=?,cProviderKeyVersion=?,cProviderSelected=1,cParamsSCI=?,cParamsAPI=? WHERE cID=?');
                $q->execute([$pid,$version,'',encodeArrayToStr($local,$key,3),(int)$c['cID']]);
            }
            foreach ($connections as $c) if (!($kind==='CCAPI' && isset(\HScript\Payment\Routing\PaymentRoutes::GATEWAYS[$c['cCID']])) && !in_array($kind==='PAYKASSA'?\HScript\Payment\PaykassaAssets::choiceKey($c):$c['cCurrID'],$selected,true)) {
                CurrencyArchive::archive($this->database,$c);
            }
            // Network pages own token membership. Saving shared credentials must not
            // archive or restore children that are absent from the native-coin form.
            if($kind==='CCAPI')foreach($connections as$child){
                if(isset(\HScript\Payment\Routing\PaymentRoutes::GATEWAYS[$child['cCID']]) && ($child['cArchivedAt']??null)===null && (int)$child['cProviderSelected']===1){
                    $q=$pdo->prepare('UPDATE Currs SET cProviderKeyVersion=? WHERE cID=?');$q->execute([$version,(int)$child['cID']]);
                }
            }
            if($input['enabled']==='1' && $activationScope){
                $activeProfile=$p;$activeProfile['ppState']='enabled';
                foreach($this->repository->connections($pid,true)as$row){
                    $asset=$kind==='PAYKASSA'?\HScript\Payment\PaykassaAssets::choiceKey($row):$row['cCurrID'];
                    if(!in_array($asset,$selected,true)||!in_array($asset,$activationScope,true)||($kind==='CCAPI'&&isset(\HScript\Payment\Routing\PaymentRoutes::GATEWAYS[$row['cCID']])))continue;
                    $before=null;foreach($connections as$oldRow)if((int)$oldRow['cID']===(int)$row['cID']){$before=$oldRow;break;}
                    CurrencySelection::activate($this->database,$row,function(array$c)use($kind,$activeProfile):void{$v=$c;$v['cDisabled']='0';$this->assertCurrencyPolicy($kind,$activeProfile,$c,$v);},$before===null||($before['cArchivedAt']??null)!==null);
                }
            }
            if ($input['enabled']==='0') {
                $q=$pdo->prepare('UPDATE Currs SET cDisabled=1,cCASHINMode=0,cCASHOUTMode=0 WHERE cProviderID=?');$q->execute([$pid]);
            }
            $q=$pdo->prepare("UPDATE PaymentProviders SET ppCurrentKeyVersion=?,ppState=?,ppValidationState='valid',ppValidatedAt=?,ppUpdatedAt=?,ppRevision=ppRevision+1 WHERE ppID=?");
            $q->execute([$version,$input['enabled']==='1'?'enabled':'disabled',$now,$now,$pid]);return $pid;
        });
        ($this->invalidate)();return $id;
    }
    public function disable(int $level,string $kind,int $id,int $revision,bool $retire): void
    {
        $this->authorize($level,$kind);CurrencyArchive::requireReady($this->database);$pdo=$this->repository->pdo;
        TransactionScope::run($pdo,function() use ($pdo,$id,$revision,$kind,$retire): void {
            $p=$this->repository->find($id,true);$this->assertProfile($p,$kind,$id,$revision);
            $connections=$this->repository->connections($id,true);
            if($retire)foreach($connections as$c)CurrencyArchive::archive($this->database,$c);
            $q=$pdo->prepare('UPDATE PaymentProviders SET ppState=?,ppSlot=?,ppRevision=ppRevision+1,ppUpdatedAt=? WHERE ppID=?');
            $q->execute([$retire?'retired':'disabled',$retire?null:$kind,time(),$id]);
            $q=$pdo->prepare('UPDATE Currs SET cDisabled=1,cCASHINMode=0,cCASHOUTMode=0 WHERE cProviderID=?');$q->execute([$id]);
        });($this->invalidate)();
    }
    public function saveCurrency(int $level,string $kind,int $currencyId,array $input): void
    {
        $this->authorize($level,$kind);$id=self::number($input['profile_id']??null);$revision=self::number($input['revision']??null);
        if (self::number($input['cID']??null)!==$currencyId || $currencyId<1) throw new ProviderException('input_invalid');
        $values=[];
        foreach (['cName'=>120,'cCurr'=>60] as $field=>$max) {
            $v=$input[$field]??null;
            if (!is_string($v) || trim($v)==='' || mb_strlen($v,'UTF-8')>$max || !preg_match('//u',$v) || preg_match('/[\x00-\x1f\x7f]/',$v)) throw new ProviderException('input_invalid');
            $values[$field]=$v;
        }
        foreach (['cDisabled','cHidden','cCASHINInt','cCASHOUTInt','cEXInt','cTRInt'] as $field) {
            $v=$input[$field]??'0';if (!in_array($v,['0','1'],true)) throw new ProviderException('input_invalid');$values[$field]=$v;
        }
        foreach (['cNumDec'=>6,'cCASHOUTLimitPer'=>2147483647,'cCASHINMode'=>3,'cCASHOUTMode'=>2,'cEXMode'=>2,'cTRMode'=>2,
            'cBUYMode'=>2,'cSELLMode'=>2,'cBUY2Mode'=>2,'cSELL2Mode'=>2,'cGIVEMode'=>2,'cTAKEMode'=>2] as $field=>$max) {
            $v=self::number($input[$field]??null);if ($v>$max) throw new ProviderException('input_invalid');$values[$field]=$v;
        }
        $money=[];foreach (CurrencyInput::normalize([]) as $field=>$zero) $money[$field]=$input[$field]??null;
        $values+=CurrencyInput::normalize($money);
        $values['cBalMin']=AdministrativeDecimal::stored(AdministrativeDecimal::read($input['cBalMin']??null),13,6);
        $pdo=$this->repository->pdo;
        TransactionScope::run($pdo,function() use ($pdo,$id,$revision,$kind,$currencyId,$values): void {
            if($kind==='CCAPI')\HScript\Payment\Routing\UsdtOwnership::lock($this->database);
            $p=$this->repository->find($id,true);$this->assertProfile($p,$kind,$id,$revision);
            $connections=array_column($this->repository->connections($id,true),null,'cID');$c=$connections[$currencyId]??null;
            if (!$c || ($c['cArchivedAt']??null)!==null || ProviderCatalog::kind($c['cCID'])!==$kind) throw new ProviderException('configuration_changed');
            if ((int)$c['cNumDec']!==$values['cNumDec']) $this->assertNoIssuedPayments($currencyId);
            $this->assertCurrencyPolicy($kind,$p,$c,$values);
            $q=$pdo->prepare('UPDATE Currs SET '.implode(',',array_map(static fn($f)=>$f.'=?',array_keys($values))).',cMTS=? WHERE cID=?');
            // Preserve the local encrypted wallet when the legacy stamp changes.
            $key=$c['cID'].$c['cCID'].stampToTime($c['cMTS']??'');$local=decodeArrayFromStr($c['cParamsAPI']??'',$key,3);
            $pay=decodeArrayFromStr($c['cParams']??'',$key);$now=time();$stamp=timeToStamp($now);$newKey=$c['cID'].$c['cCID'].$now;
            $q->execute([...array_values($values),$stamp,$currencyId]);
            $q=$pdo->prepare('UPDATE Currs SET cParams=?,cParamsAPI=? WHERE cID=?');$q->execute([encodeArrayToStr($pay,$newKey),encodeArrayToStr($local,$newKey,3),$currencyId]);
            $q=$pdo->prepare('UPDATE PaymentProviders SET ppRevision=ppRevision+1,ppUpdatedAt=? WHERE ppID=?');$q->execute([$now,$id]);
        });($this->invalidate)();
    }
    /** The same stored-mode checks protect settings saves and common-page activation. */
    public function assertCurrencyPolicy(string $kind,array $p,array $c,array $values): void
    {
        $values['cDisabled']=(string)($values['cDisabled']??'0');
        foreach(CurrencyArchiveSchema::MODES as$mode)$values['c'.$mode.'Mode']=(int)($values['c'.$mode.'Mode']??0);
        foreach(CurrencyArchiveSchema::MODES as$mode)if($values['c'.$mode.'Mode']<0||$values['c'.$mode.'Mode']>($mode==='CASHIN'?3:2))throw new ProviderException('activation_unavailable');
        $money=[];foreach(CurrencyInput::normalize([])as$field=>$zero)$money[$field]=$values[$field]??$zero;CurrencyInput::normalize($money);
            if($c['cCID']==='CCTU' && ($values['cDisabled']==='0' || $values['cCASHINMode']>0))\HScript\Payment\Routing\UsdtOwnership::legacy($this->database);
            if (!in_array($kind,['STRIPE'],true) && $c['cCID']!=='CCT' && $values['cDisabled']==='0') {
                $q=$this->repository->pdo->prepare('SELECT cID FROM Currs WHERE cCID=? AND cDisabled=0 AND cID<>? ORDER BY cID LIMIT 1 FOR UPDATE');$q->execute([$c['cCID'],(int)$c['cID']]);
                if ($q->fetchColumn()!==false) throw new ProviderException('binding_required');
            }
            if($kind==='PAYKASSA' && $p['ppEnvironment']!=='live' && ($values['cDisabled']!=='1' || $values['cCASHINMode'] || $values['cCASHOUTMode']))throw new ProviderException('paykassa_sandbox_removed');
            if (($p['ppState']!=='enabled' || !(int)$c['cProviderSelected']) && ($values['cDisabled']!=='1' || $values['cCASHINMode'] || $values['cCASHOUTMode'])) throw new ProviderException('profile_disabled');
            if($c['cCID']==='CCT' && ($values['cEXMode']!==0 || $values['cGIVEMode']!==0 || $values['cTAKEMode']!==0))throw new ProviderException('token_exchange_unsupported');
            // CCAPI has an asynchronous dispatcher; ambiguous legacy token routes stay manual.
            if ($values['cCASHOUTMode']===2 && (($kind==='PAYKASSA' && !in_array($c['cCID'],['PKB','PKE','PKL'],true)) || $kind==='STRIPE' || ($kind==='VOLET' && $c['cCID']!=='AC') || ($kind!=='CCAPI' && isset(\HScript\Payment\Routing\PaymentRoutes::GATEWAYS[$c['cCID']])))) throw new ProviderException('automatic_withdrawal_unsupported');
            if($kind==='CCAPI' && $values['cCASHOUTMode']===2) {
                try { $route=\HScript\Finance\CcapiPayout::route($c); \HScript\Finance\CcapiPayout::source($this->database,$c,$route); }
                catch(\HScript\Finance\FinancialOperationException $e) {throw new ProviderException($e->getMessage());}
            }
    }
    public function activateToken(array $profile,array $row,bool $reveal=false): void
    {
        CurrencySelection::activate($this->database,$row,function(array$c)use($profile):void{$v=$c;$v['cDisabled']='0';$this->assertCurrencyPolicy('CCAPI',$profile,$c,$v);},$reveal);
    }
    public function saveRoutes(int $level,string $kind,int $currencyId,array $input): void
    {
        $this->authorize($level,$kind);
        $id=self::number($input['profile_id']??null);$revision=self::number($input['revision']??null);
        TransactionScope::run($this->repository->pdo,function()use($id,$revision,$kind,$currencyId,$input):void{
            if($kind==='CCAPI')\HScript\Payment\Routing\UsdtOwnership::lock($this->database);
            $p=$this->repository->find($id,true);$this->assertProfile($p,$kind,$id,$revision);
            $connections=array_column($this->repository->connections($id,true),null,'cID');$c=$connections[$currencyId]??null;
            if(!$c || ($c['cArchivedAt']??null)!==null || !isset(\HScript\Payment\Routing\PaymentRoutes::GATEWAYS[$c['cCID']]) || ProviderCatalog::kind($c['cCID'])!==$kind)throw new ProviderException('configuration_changed');
            $codes=\HScript\Payment\Routing\PaymentRoutes::codes($c['cCID'],$input['payment_routes']??[],$c);
            if($c['cCID']==='CCTU' && $codes)\HScript\Payment\Routing\UsdtOwnership::legacy($this->database);
            $q=$this->repository->pdo->prepare('UPDATE Currs SET cPaymentRoutes=? WHERE cID=?');$q->execute([json_encode($codes,JSON_THROW_ON_ERROR),$currencyId]);
            $q=$this->repository->pdo->prepare('UPDATE PaymentProviders SET ppRevision=ppRevision+1,ppUpdatedAt=? WHERE ppID=?');$q->execute([time(),$id]);
        });($this->invalidate)();
    }
    public static function number(mixed $value): int
    {
        if (!is_string($value) || !preg_match('/^(?:0|[1-9][0-9]{0,9})$/D',$value) || (int)$value>4294967295) throw new ProviderException('input_invalid');
        return (int)$value;
    }
    private function authorize(int $level,string $kind): void
    {
        if ($level<90) throw new ProviderException('forbidden');
        if (!in_array($kind,ProviderCatalog::SHARED,true)) throw new ProviderException('provider_unsupported');
        if ($this->repository->pdo->inTransaction()) throw new ProviderException('configuration_busy');
    }
    private function assertProfile(?array $p,string $kind,int $id,int $revision): void
    {
        if (($id && (!$p || $p['ppKind']!==$kind || $p['ppState']==='retired' || (int)$p['ppRevision']!==$revision)) || (!$id && $revision)) throw new ProviderException('configuration_changed');
    }
    private function assertNoIssuedPayments(int $currency): void
    {
        $q=$this->repository->pdo->prepare("SELECT oID FROM Opers WHERE ocID=? AND oState IN (0,1,2) AND (oOper='CASHOUT' OR (oOper='CASHIN' AND EXISTS(SELECT 1 FROM IdempotencyKeys WHERE ikNamespace='invoice:attempt' AND ikOperationID=oID))) ORDER BY oID LIMIT 1 FOR UPDATE");
        $q->execute([$currency]);if ($q->fetchColumn()!==false) throw new ProviderException('payments_pending');
    }
}
