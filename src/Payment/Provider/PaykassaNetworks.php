<?php
declare(strict_types=1);
namespace HScript\Payment\Provider;

use HScript\Payment\PaykassaAssets;

/** Presentation grouping never changes the SCI system or ledger identity. */
final class PaykassaNetworks
{
    public const LABELS=['bitcoin'=>'Bitcoin','ethereum'=>'Ethereum (ERC-20)','litecoin'=>'Litecoin','dogecoin'=>'Dogecoin','dash'=>'Dash','bitcoincash'=>'Bitcoin Cash','ripple'=>'XRP','tron'=>'TRON (TRC-20)','stellar'=>'Stellar','binancecoin'=>'BNB · BinanceCoin','bsc'=>'BNB Smart Chain (BEP-20)','ton'=>'TON','shared'=>'Ethereum / TRON · общий счёт'];
    public static function native(string $gateway): ?string
    {
        $a=PaykassaAssets::ASSETS[$gateway]??null;
        return $a&&!self::token($gateway)?$a[2]:null;
    }
    public static function token(string $gateway): ?string
    {
        if($gateway==='PKTU')return 'shared';
        if($gateway==='PKTC')return 'ethereum';
        if($gateway==='PKTT')return 'ton';
        return match(PaykassaAssets::ASSETS[$gateway][1]??null){31=>'bsc',32=>'ethereum',default=>null};
    }
    public static function navigation(string $nativeGateway): ?string
    {
        $network=$nativeGateway==='PKBN'?'bsc':self::native($nativeGateway);
        return $network!==null && self::visible($network)?$network:null;
    }
    public static function visible(?string $network): array
    {
        if($network!==null&&!isset(self::LABELS[$network]))throw new ProviderException('input_invalid');
        $choices=[];foreach(PaykassaAssets::choices()as$key=>$choice)if(self::token($choice['gateway'])===$network||(in_array($network,['ethereum','tron'],true)&&self::token($choice['gateway'])==='shared'))$choices[$key]=$choice;
        return $choices;
    }
}
