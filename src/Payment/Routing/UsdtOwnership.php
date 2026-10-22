<?php
declare(strict_types=1);
namespace HScript\Payment\Routing;

use HScript\Database\Connection;
use HScript\Payment\Provider\ProviderException;
use PDO;

/** Split USDT must never overlap an existing combined receipt domain. */
final class UsdtOwnership
{
    public static function lock(Connection $db): void
    {
        if(!$db->link->inTransaction())throw new \LogicException('A token ownership transaction is required');
        if($db->link->query("SELECT pgCode FROM PaymentGatewayCatalog WHERE pgCode='CCAPI' AND pgAvailable=1 FOR UPDATE")->fetchColumn()!=='CCAPI')throw new ProviderException('provider_unavailable');
    }
    public static function fresh(Connection $db,int $provider,string $assetKey): void
    {
        $p=$db->link;
        if($p->query("SELECT cID FROM Currs WHERE cCID='CCTU' LIMIT 1 FOR UPDATE")->fetchColumn()!==false
            || $p->query("SELECT ikID FROM IdempotencyKeys WHERE LOWER(ikNamespace) LIKE 'gateway:cctu:%' LIMIT 1 FOR UPDATE")->fetchColumn()!==false
            || $p->query("SELECT cID FROM Currs WHERE cAssetKey='asset:USDT' AND (cProviderID IS NULL OR cProviderID IN (SELECT ppID FROM PaymentProviders WHERE ppKind='CCAPI')) LIMIT 1 FOR UPDATE")->fetchColumn()!==false)self::refuse();
        $s=$p->prepare('SELECT cID FROM Currs WHERE cAssetKey=? AND (cProviderID IS NULL OR cProviderID<>?) LIMIT 1 FOR UPDATE');$s->execute([$assetKey,$provider]);
        if($s->fetchColumn()!==false)self::refuse();
        foreach(self::tokens($db) as $c){
            $route=self::route($c);
            if(TokenCatalog::assetKey($route)===$assetKey && (int)($c['cProviderID']??0)!==$provider)self::refuse();
        }
    }
    public static function legacy(Connection $db): void
    {
        foreach(self::tokens($db) as $c)if(self::route($c)['asset']==='USDT')self::refuse();
    }
    private static function tokens(Connection $db): array
    {
        return $db->link->query("SELECT * FROM Currs WHERE cCID='CCT' ORDER BY cID FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC);
    }
    private static function route(array $c): array
    {
        try{$r=array_values(PaymentRoutes::catalog('CCT',$c))[0];TokenCatalog::curated($r);
            if($c['cAssetKey']!==TokenCatalog::assetKey($r) || $c['cCurrID']!==$r['asset'])self::refuse();return $r;
        }catch(\Throwable){self::refuse();}
    }
    private static function refuse(): never {throw new ProviderException('legacy_token_requires_reconciliation');}
}
