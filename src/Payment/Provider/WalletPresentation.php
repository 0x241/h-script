<?php
declare(strict_types=1);
namespace HScript\Payment\Provider;

use HScript\Payment\Gateways\CryptoCurrencyApi;
use HScript\Payment\PaykassaAssets;
use HScript\Payment\Routing\{PaymentRoutes,TokenCatalog};

/** Read-only grouping; never combines currency IDs or recipient fields. */
final class WalletPresentation
{
    public static function network(array $currency): ?array
    {
        $gateway=$currency['cCID']??'';
        if(isset(CryptoCurrencyApi::NATIVE[$gateway])) {
            $network=CryptoCurrencyApi::NATIVE[$gateway][0];
            return ['key'=>$network,'label'=>TokenCatalog::NETWORKS[$network][0]??$currency['cCurrID']];
        }
        if(isset(PaymentRoutes::GATEWAYS[$gateway])) {
            try {
                $routes=PaymentRoutes::enabled($currency)?:PaymentRoutes::catalog($gateway,$currency);
                $networks=[];foreach($routes as$route)$networks[$route['network']]=$route['label'];
                if(count($networks)===1)return ['key'=>(string)array_key_first($networks),'label'=>reset($networks)];
                if($networks)return ['key'=>'multiple','label'=>implode(' / ',array_values($networks))];
            }catch(\HScript\Finance\CallbackValidationException) { return null; }
        }
        if(isset(PaykassaAssets::ASSETS[$gateway])) {
            $asset=PaykassaAssets::ASSETS[$gateway];
            $network=match($asset[2]){'ethereum_erc20'=>'ethereum','binancesmartchain_bep20'=>'bsc',default=>$asset[2]};
            return ['key'=>$network,'label'=>TokenCatalog::NETWORKS[$network][0]??$asset[3]];
        }
        return null;
    }
}
