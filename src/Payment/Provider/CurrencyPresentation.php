<?php
declare(strict_types=1);
namespace HScript\Payment\Provider;

use HScript\Payment\Routing\PaymentRoutes;

/** Display codes never replace ledger, adapter or immutable invoice identities. */
final class CurrencyPresentation
{
    public static function describe(array $currency): array
    {
        $result=['code'=>(string)($currency['cCID']??''),'network'=>null,'contract'=>null];
        if(!in_array($result['code'],['CCT','CCTU','CCTC'],true))return $result;
        try {
            $routes=PaymentRoutes::catalog($result['code'],$currency);
            if(count($routes)!==1){$result['code']='CCAT';return $result;}
            $route=reset($routes);
            $result['code']=match($route['network']){'ethereum'=>'CCAET','tron'=>'CCATT','polygon'=>'CCAMT','bsc'=>'CCANT',default=>'CCAT'};
            $result['network']=$route['label'];$result['contract']=$route['contract'];
        }catch(\Throwable){/* Malformed settings must not prevent reading the currency list. */}
        return $result;
    }
}
