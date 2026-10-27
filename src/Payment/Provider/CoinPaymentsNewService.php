<?php
declare(strict_types=1);
namespace HScript\Payment\Provider;

use Closure;
use HScript\Database\Connection;
use HScript\Database\TransactionScope;
use HScript\Payment\CoinPaymentsNewClient;
use HScript\Security\SecretCipher;
use PDO;

/** Versioned NEW credentials and selected assets; no payment execution. */
final class CoinPaymentsNewService
{
    private ProviderRepository $repository;
    private Closure $authenticate;
    private Closure $catalog;
    private Closure $invalidate;
    public function __construct(private Connection $database,?Closure $authenticate=null,?Closure $catalog=null,?Closure $invalidate=null)
    {
        $this->repository=new ProviderRepository($database);
        $this->authenticate=$authenticate??static fn(array $c)=>CoinPaymentsNewClient::authenticate($c);
        $this->catalog=$catalog??static fn(string $instance)=>CoinPaymentsNewClient::catalog($instance);
        $this->invalidate=$invalidate??static function():void{};
    }

    public function save(int $level,array $input): int
    {
        if($level<90)throw new ProviderException('forbidden');
        if($this->repository->pdo->inTransaction())throw new ProviderException('configuration_busy');
        CurrencyArchive::requireReady($this->database);
        if(($input['form_complete']??null)!=='1')throw new ProviderException('input_invalid');
        if(!CoinPaymentsNewSchema::ready($this->repository->pdo))throw new ProviderException('migration_required');
        (new ProviderCatalog($this->database))->requireAvailable('COINNEW');
        $id=ProviderService::number($input['profile_id']??null);$revision=ProviderService::number($input['revision']??null);
        $old=$id?$this->repository->find($id):null;$this->assertProfile($old,$id,$revision);
        if(!$old&&$this->repository->current('COINNEW'))throw new ProviderException('configuration_changed');
        $previous=$old?$this->repository->credentials($old,(int)$old['ppCurrentKeyVersion']):[];
        $credentials=CoinPaymentsNewClient::credentials((array)($input['credentials']??[]),$previous);
        $selected=$input['assets']??[];
        if(!is_array($selected)||!array_is_list($selected)||count($selected)>2500||count(array_unique($selected,SORT_REGULAR))!==count($selected)
            ||!in_array($input['enabled']??null,['0','1'],true))throw new ProviderException('input_invalid');
        foreach($selected as $asset)if(!is_string($asset)||strlen($asset)>160)throw new ProviderException('input_invalid');
        if(SecretCipher::encrypt('probe','coinnew-config-probe')===null)throw new ProviderException('encryption_unavailable');
        ($this->authenticate)($credentials);
        $catalog=$selected?($this->catalog)($credentials['instance']):[];
        $known=[];
        if($old)foreach($this->repository->connections($id)as$c){$asset=self::asset($c);$known[$asset['id']]=$c;}
        foreach($selected as$asset){
            $metadata=$catalog[$asset]??null;
            if(!$metadata&&!isset($known[$asset]))throw new ProviderException('asset_unavailable');
            if(!isset($known[$asset])&&(!self::selectable($metadata,$credentials['instance'])))throw new ProviderException('asset_unavailable');
        }
        $pdo=$this->repository->pdo;
        $id=TransactionScope::run($pdo,function()use($pdo,$old,$id,$revision,$credentials,$previous,$selected,$catalog,$input):int{
            $now=time();
            if($id){$profile=$this->repository->find($id,true);$this->assertProfile($profile,$id,$revision);}
            else{
                $s=$pdo->prepare("INSERT INTO PaymentProviders(ppPublicID,ppKind,ppSlot,ppEnvironment,ppState,ppCreatedAt,ppUpdatedAt) VALUES(?,'COINNEW','COINNEW',?,'disabled',?,?)");
                $s->execute([bin2hex(random_bytes(16)),$credentials['instance']==='SANDBOX'?'sandbox':'live',$now,$now]);$profile=$this->repository->find((int)$pdo->lastInsertId(),true);
            }
            $pid=(int)$profile['ppID'];$version=(int)$profile['ppCurrentKeyVersion'];$connections=$this->repository->connections($pid,true);$existing=[];
            foreach($connections as$c){$asset=self::asset($c);if($asset['instance']!==$credentials['instance'])throw new ProviderException('configuration_changed');$existing[$asset['id']]=$c;}
            if($credentials!==$previous||!$version){
                $q=$pdo->prepare("SELECT COUNT(*) FROM Opers JOIN Currs ON cID=ocID WHERE cProviderID=? AND oOper IN ('CASHIN','CASHOUT') AND oState<=2");$q->execute([$pid]);
                if((int)$q->fetchColumn()>0)throw new ProviderException('payments_pending');
                $version++;$cipher=SecretCipher::encrypt(json_encode($credentials,JSON_THROW_ON_ERROR),ProviderRepository::context('COINNEW',$profile['ppPublicID'],$version));
                if($cipher===null)throw new ProviderException('encryption_unavailable');
                $q=$pdo->prepare('INSERT INTO PaymentProviderCredentials(ppcProviderID,ppcVersion,ppcEnvironment,ppcEncryptedKey,ppcCreatedAt) VALUES(?,?,?,?,?)');$q->execute([$pid,$version,$profile['ppEnvironment'],$cipher,$now]);
                $q=$pdo->prepare('UPDATE PaymentProviderCredentials SET ppcRetiredAt=? WHERE ppcProviderID=? AND ppcVersion=?');$q->execute([$now,$pid,$version-1]);
            }
            foreach($selected as$asset){
                $c=$existing[$asset]??null;
                if(!$c){
                    $metadata=$catalog[$asset];$metadata['instance']=$credentials['instance'];$metadata['format']=1;
                    $assetKey=CoinPaymentsNewClient::assetKey($credentials['instance'],$asset);
                    // cCurrID is a display/legacy catalog code, never a token/FX identity.
                    $short='CN'.substr(hash('sha256',$assetKey),0,8);
                    $q=$pdo->prepare('INSERT INTO Currs(cCID,cCurrID,cName,cCurr,cNumDec,cProviderID,cProviderKeyVersion,cProviderSelected,cDisabled,cHidden,cCASHINMode,cCASHOUTMode,cAssetKey,cTokenRoute) VALUES(?,?,?,?,?,?,?,1,1,1,0,0,?,?)');
                    $q->execute(['CPN',$short,mb_substr('CoinPayments NEW '.$metadata['symbol'].' · '.$metadata['network'],0,120,'UTF-8'),$metadata['symbol'],min(6,$metadata['decimals']),$pid,$version,$assetKey,json_encode($metadata,JSON_THROW_ON_ERROR)]);
                }else{
                    if(CurrencyArchive::restore($this->database,$c))continue;
                    $q=$pdo->prepare('UPDATE Currs SET cProviderSelected=1,cProviderKeyVersion=? WHERE cID=?');$q->execute([$version,(int)$c['cID']]);
                }
            }
            foreach($connections as$c)if(!in_array(self::asset($c)['id'],$selected,true)){
                CurrencyArchive::archive($this->database,$c);
            }
            $q=$pdo->prepare("UPDATE PaymentProviders SET ppState=?,ppCurrentKeyVersion=?,ppValidationState='valid',ppValidatedAt=?,ppUpdatedAt=?,ppRevision=ppRevision+1 WHERE ppID=?");
            $q->execute([$input['enabled']==='1'?'enabled':'disabled',$version,$now,$now,$pid]);return $pid;
        });
        ($this->invalidate)();return $id;
    }

    public static function selectable(array $asset,string $instance): bool
    {
        return $asset['status']==='active'&&$asset['enabled']&&($instance==='SANDBOX'||$asset['id']!=='1002');
    }

    public static function asset(array $currency): array
    {
        try{$asset=json_decode($currency['cTokenRoute']??'',true,8,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new ProviderException('asset_identity_invalid');}
        if(($currency['cCID']??null)!=='CPN'||!is_array($asset)||($asset['format']??null)!==1
            ||!is_string($asset['id']??null)||!is_string($asset['instance']??null)||!isset(CoinPaymentsNewClient::ORIGINS[$asset['instance']])
            ||($currency['cAssetKey']??null)!==CoinPaymentsNewClient::assetKey($asset['instance'],$asset['id']))throw new ProviderException('asset_identity_invalid');
        return $asset;
    }
    private function assertProfile(?array $profile,int $id,int $revision): void
    {
        if(($id===0&&($profile!==null||$revision!==0))||($id>0&&(!$profile||$profile['ppKind']!=='COINNEW'||$profile['ppState']==='retired'||(int)$profile['ppRevision']!==$revision)))throw new ProviderException('configuration_changed');
    }
}
