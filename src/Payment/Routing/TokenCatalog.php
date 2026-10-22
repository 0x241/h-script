<?php

declare(strict_types=1);
namespace HScript\Payment\Routing;

use HScript\Finance\CallbackValidationException;

/** Provider symbols are labels. A token's identity includes its chain and contract. */
final class TokenCatalog
{
    public const NETWORKS=['ethereum'=>['Ethereum (ERC-20)','ETH','CCAE'], 'polygon'=>['Polygon (ERC-20)','MATIC','CCAM'],
        'bsc'=>['BNB Smart Chain (BEP-20)','BNB','CCAN'], 'tron'=>['TRON (TRC-20)','TRX','CCAT']];
    public static function symbol(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,31}$/D',$value)===1;
    }
    public static function route(string $network,mixed $symbol,mixed $contract): array
    {
        if(!isset(self::NETWORKS[$network]) || !self::symbol($symbol) || !is_string($contract))throw new CallbackValidationException('payment_route_invalid');
        $canonical=PaymentRoutes::address(['network'=>$network],$contract);
        if($canonical===null)throw new CallbackValidationException('payment_route_invalid');
        return ['version'=>2,'code'=>'ccapi-token-'.hash('sha256',$network."\0".$canonical."\0".$symbol),
            'provider'=>'CCAPI','gateway'=>'CCT','asset'=>$symbol,'network'=>$network,'label'=>self::NETWORKS[$network][0],
            'wire'=>self::NETWORKS[$network][1],'contract'=>$canonical];
    }
    public static function normalize(string $network,array $tokens): array
    {
        if(!isset(self::NETWORKS[$network]) || count($tokens)>5000)throw new CallbackValidationException('payment_route_invalid');
        $result=[];$contracts=[];
        foreach($tokens as $symbol=>$contract){
            // Unsupported provider identifiers stay out of the selectable catalog.
            try{$route=self::route($network,(string)$symbol,$contract);}catch(CallbackValidationException){continue;}
            if(isset($contracts[$route['contract']]))throw new CallbackValidationException('token_catalog_ambiguous');
            $contracts[$route['contract']]=true;$result[$route['code']]=$route;
        }
        uasort($result,static fn($a,$b)=>strnatcasecmp($a['asset'],$b['asset']));return $result;
    }
    public static function assetKey(array $route): string {return 'token:'.$route['network'].':'.$route['contract'];}
    public static function curated(array $route): ?array
    {
        foreach(['CCTU','CCTC'] as $gateway)foreach(PaymentRoutes::catalog($gateway) as $known)
            if($known['network']===$route['network'] && $known['contract']===$route['contract']){
                if($known['asset']!==$route['asset'])throw new CallbackValidationException('token_catalog_ambiguous');return $known;
            }
        return null;
    }
}
