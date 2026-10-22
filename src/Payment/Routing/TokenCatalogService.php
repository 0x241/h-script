<?php

declare(strict_types=1);
namespace HScript\Payment\Routing;
use Closure;
use HScript\Database\{Connection,TransactionScope};
use HScript\Payment\CcapiV3Client;
use HScript\Payment\Provider\{ProviderRepository,ProviderSettings,ProviderException,ProviderCatalog,ProviderService};
use PDO;

/** Explicit administrator discovery; a page view never calls the provider. */
final class TokenCatalogService
{
    private ProviderRepository $repository;
    public function __construct(private Connection $db,private ?Closure $fetch=null,private ?Closure $invalidate=null){$this->repository=new ProviderRepository($db);}
    private function profile(int $level,int $id,int $revision,bool $lock=false): array
    {
        if($level<90)throw new ProviderException('permission_denied');
        $p=$this->repository->find($id,$lock);
        if(!$p || $p['ppKind']!=='CCAPI' || $p['ppState']==='retired' || (int)$p['ppRevision']!==$revision)throw new ProviderException('configuration_changed');
        (new ProviderCatalog($this->db))->requireAvailable('CCAPI');return $p;
    }
    private function network(array $profile,string $network,bool $tokens): void
    {
        $native=\HScript\Payment\Gateways\CryptoCurrencyApi::NATIVE;
        $id=null;foreach($native as $gateway=>[$chain,$asset])if($chain===$network)$id=$gateway;
        if($id===null || ($tokens && !isset(TokenCatalog::NETWORKS[$network])))throw new ProviderException('input_invalid');
        $s=$this->repository->pdo->prepare('SELECT cID FROM Currs WHERE cProviderID=? AND cCID=? AND cProviderSelected=1');$s->execute([(int)$profile['ppID'],$id]);
        if(!$s->fetchColumn())throw new ProviderException('network_not_selected');
    }
    public function refresh(int $level,int $id,int $revision,string $network): void
    {
        if($this->repository->pdo->inTransaction())throw new ProviderException('configuration_busy');
        $p=TransactionScope::run($this->repository->pdo,function()use($level,$id,$revision,$network){
            $p=$this->profile($level,$id,$revision,true);$this->network($p,$network,true);$pdo=$this->repository->pdo;
            $s=$pdo->prepare("INSERT IGNORE INTO ProviderTokenCatalogs(ptProviderID,ptNetwork,ptKeyVersion,ptTokens) VALUES(?,?,?,'{}')");$s->execute([$id,$network,(int)$p['ppCurrentKeyVersion']]);
            $row=$this->catalog($id,$network,true);
            if((int)$row['ptAttemptedAt']>time()-60)throw new ProviderException('validation_cooldown');
            $s=$pdo->prepare('UPDATE ProviderTokenCatalogs SET ptAttemptedAt=? WHERE ptProviderID=? AND ptNetwork=?');$s->execute([time(),$id,$network]);return $p;
        });
        $keys=$this->repository->credentials($p,(int)$p['ppCurrentKeyVersion']);$config=ProviderSettings::configuration('CCAPI',$keys)['sci'];unset($keys);
        $routes=$this->fetch?($this->fetch)($network,$config):CcapiV3Client::request($network,'tokens',$config)['tokens'];unset($config);
        if(!is_array($routes) || count($routes)>5000)throw new ProviderException('provider_unavailable');
        foreach($routes as $code=>$route)if($code!==PaymentRoutes::assertSnapshot('CCT',$route)['code'] || $route['network']!==$network)throw new ProviderException('provider_unavailable');
        TransactionScope::run($this->repository->pdo,function()use($level,$id,$revision,$network,$p,$routes){
            $current=$this->profile($level,$id,$revision,true);$this->network($current,$network,true);
            if($current['ppCurrentKeyVersion']!==$p['ppCurrentKeyVersion'])throw new ProviderException('configuration_changed');
            $s=$this->repository->pdo->prepare('UPDATE ProviderTokenCatalogs SET ptTokens=?,ptKeyVersion=?,ptRevision=ptRevision+1,ptUpdatedAt=? WHERE ptProviderID=? AND ptNetwork=?');
            $s->execute([json_encode($routes,JSON_THROW_ON_ERROR),(int)$p['ppCurrentKeyVersion'],time(),$id,$network]);
        });
    }
    public function catalog(int $id,string $network,bool $lock=false): ?array
    {
        $s=$this->repository->pdo->prepare('SELECT * FROM ProviderTokenCatalogs WHERE ptProviderID=? AND ptNetwork=?'.($lock?' FOR UPDATE':''));$s->execute([$id,$network]);return $s->fetch(PDO::FETCH_ASSOC)?:null;
    }
    public static function routes(?array $catalog,array $profile): array
    {
        if(!$catalog || (int)$catalog['ptKeyVersion']!==(int)$profile['ppCurrentKeyVersion'] || (int)$catalog['ptUpdatedAt']<time()-3600)return [];
        $routes=json_decode($catalog['ptTokens'],true,16,JSON_THROW_ON_ERROR);
        if(!is_array($routes) || count($routes)>5000)throw new ProviderException('configuration_changed');
        foreach($routes as $code=>$route)if(PaymentRoutes::assertSnapshot('CCT',$route)['code']!==$code || $route['network']!==$catalog['ptNetwork'])throw new ProviderException('configuration_changed');
        return $routes;
    }
    public function add(int $level,int $id,int $revision,string $network,int $catalogRevision,mixed $selected): array
    {
        if($this->repository->pdo->inTransaction())throw new ProviderException('configuration_busy');
        if(!is_array($selected) || !array_is_list($selected) || !$selected || count($selected)>100 || count(array_unique($selected,SORT_REGULAR))!==count($selected))throw new ProviderException('input_invalid');
        $result=TransactionScope::run($this->repository->pdo,function()use($level,$id,$revision,$network,$catalogRevision,$selected){
            $pdo=$this->repository->pdo;
            // Serialize first USDT additions before taking profile and currency locks.
            UsdtOwnership::lock($this->db);
            $p=$this->profile($level,$id,$revision,true);$this->network($p,$network,true);$catalog=$this->catalog($id,$network,true);
            if(!$catalog || (int)$catalog['ptRevision']!==$catalogRevision)throw new ProviderException('configuration_changed');
            $routes=self::routes($catalog,$p);$pdo=$this->repository->pdo;$result=[];
            foreach($selected as $code){
                if(!is_string($code) || !isset($routes[$code]))throw new ProviderException('input_invalid');$route=$routes[$code];
                // Validate curated symbols/contracts; retain the old USDC receipt domain.
                $curated=TokenCatalog::curated($route);$gateway=$curated['gateway']??'CCT';$route=$curated??$route;
                if($route['asset']==='USDT'){
                    UsdtOwnership::fresh($this->db,$id,TokenCatalog::assetKey($route));
                    $route=$routes[$code];$curated=null;$gateway='CCT';
                }
                $key=$curated?'asset:'.$route['asset']:TokenCatalog::assetKey($route);
                $s=$pdo->prepare('SELECT * FROM Currs WHERE cProviderID=? AND cAssetKey=? FOR UPDATE');$s->execute([$id,$key]);$c=$s->fetch(PDO::FETCH_ASSOC);
                if($c && ($c['cCID']!==$gateway || $c['cCurrID']!==$route['asset']))throw new ProviderException('configuration_changed');
                if($c && $route['asset']==='USDT' && PaymentRoutes::catalog('CCT',$c)!==[$route['code']=>$route])throw new ProviderException('legacy_token_requires_reconciliation');
                if(!$c){
                    if($curated){$s=$pdo->prepare('SELECT cID FROM Currs WHERE cCID=? AND cProviderID IS NULL LIMIT 1 FOR UPDATE');$s->execute([$gateway]);if($s->fetchColumn()!==false)throw new ProviderException('binding_required');}
                    $s=$pdo->prepare('INSERT INTO Currs(cCID,cCurrID,cName,cCurr,cNumDec,cProviderID,cProviderKeyVersion,cProviderSelected,cDisabled,cCASHINMode,cCASHOUTMode,cAssetKey,cTokenRoute,cPaymentRoutes) VALUES(?,?,?,?,6,?,?,1,1,0,0,?,?,?)');
                    $s->execute([$gateway,$route['asset'],'CryptoCurrencyAPI.net '.$route['asset'].($curated?'':' · '.$route['label']),$route['asset'],$id,(int)$p['ppCurrentKeyVersion'],$key,$curated?null:json_encode($route,JSON_THROW_ON_ERROR),json_encode([$route['code']],JSON_THROW_ON_ERROR)]);
                    $result[]=(int)$pdo->lastInsertId();
                }else{
                    $enabled=array_keys(PaymentRoutes::enabled($c));if(!in_array($route['code'],$enabled,true))$enabled[]=$route['code'];
                    $s=$pdo->prepare('UPDATE Currs SET cProviderSelected=1,cPaymentRoutes=? WHERE cID=?');$s->execute([json_encode($enabled,JSON_THROW_ON_ERROR),(int)$c['cID']]);$result[]=(int)$c['cID'];
                }
            }
            $s=$pdo->prepare('UPDATE PaymentProviders SET ppRevision=ppRevision+1,ppUpdatedAt=? WHERE ppID=?');$s->execute([time(),$id]);return $result;
        });if($this->invalidate)($this->invalidate)();return $result;
    }
    public function saveWallets(int $level,int $id,int $revision,string $network,mixed $forward,mixed $payout): void
    {
        if($this->repository->pdo->inTransaction())throw new ProviderException('configuration_busy');
        foreach([$forward,$payout] as $value)if(!is_string($value) || ($value!=='' && !CcapiWallets::validAddress($network,$value)))throw new ProviderException('input_invalid');
        TransactionScope::run($this->repository->pdo,function()use($level,$id,$revision,$network,$forward,$payout){
            $p=$this->profile($level,$id,$revision,true);$this->network($p,$network,false);
            $s=$this->repository->pdo->prepare('INSERT INTO ProviderNetworkWallets(pnProviderID,pnNetwork,pnForwardTo,pnPayoutFrom) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE pnForwardTo=VALUES(pnForwardTo),pnPayoutFrom=VALUES(pnPayoutFrom)');$s->execute([$id,$network,$forward,$payout]);
            $s=$this->repository->pdo->prepare('UPDATE PaymentProviders SET ppRevision=ppRevision+1,ppUpdatedAt=? WHERE ppID=?');$s->execute([time(),$id]);
        });if($this->invalidate)($this->invalidate)();
    }
}
