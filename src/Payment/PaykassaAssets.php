<?php

declare(strict_types=1);
namespace HScript\Payment;

/** Fixed SCI identities from PayKassa documentation and merchant currency options; each new network has its own ledger. */
final class PaykassaAssets
{
    // currency, system ID, exact SCI system name, display network, destination tag.
    public const ASSETS = [
        'PKB'=>['BTC',11,'bitcoin','Bitcoin',false],
        'PKE'=>['ETH',12,'ethereum','Ethereum',false],
        'PKL'=>['LTC',14,'litecoin','Litecoin',false],
        'PKDG'=>['DOGE',15,'dogecoin','Dogecoin',false],
        'PKDA'=>['DASH',16,'dash','Dash',false],
        'PKBC'=>['BCH',18,'bitcoincash','Bitcoin Cash',false],
        'PKXR'=>['XRP',22,'ripple','XRP',true],
        'PKTR'=>['TRX',27,'tron','TRON',false],
        'PKXL'=>['XLM',28,'stellar','Stellar',true],
        'PKBN'=>['BNB',29,'binancecoin','BNB',false],
        'PKBB'=>['BTC',31,'binancesmartchain_bep20','BNB Smart Chain (BEP-20)',false],
        'PKBE'=>['ETH',31,'binancesmartchain_bep20','BNB Smart Chain (BEP-20)',false],
        'PKBD'=>['DOGE',31,'binancesmartchain_bep20','BNB Smart Chain (BEP-20)',false],
        'PKBT'=>['USDT',31,'binancesmartchain_bep20','BNB Smart Chain (BEP-20)',false],
        'PKBZ'=>['BUSD',31,'binancesmartchain_bep20','BNB Smart Chain (BEP-20)',false],
        'PKEZ'=>['BUSD',32,'ethereum_erc20','Ethereum (ERC-20)',false],
        'PKBU'=>['USDC',31,'binancesmartchain_bep20','BNB Smart Chain (BEP-20)',false],
        'PKBO'=>['EOS',31,'binancesmartchain_bep20','BNB Smart Chain (BEP-20)',false],
        'PKBA'=>['ADA',31,'binancesmartchain_bep20','BNB Smart Chain (BEP-20)',false],
        'PKBS'=>['SHIB',31,'binancesmartchain_bep20','BNB Smart Chain (BEP-20)',false],
        'PKES'=>['SHIB',32,'ethereum_erc20','Ethereum (ERC-20)',false],
        'PKTN'=>['TON',33,'ton','TON',true],
        'PKTT'=>['USDT',33,'ton','TON',true],
    ];

    public static function choiceKey(array $currency): string
    {
        $id=$currency['cCID']??'';
        return isset(self::ASSETS[$id]) && !in_array($id,['PKB','PKE','PKL'],true) ? $id : (string)$currency['cCurrID'];
    }

    public static function liveConfiguration(array $config): bool
    {
        foreach(['test','test_mode'] as $field)
            if(array_key_exists($field,$config) && !in_array($config[$field],['0','false',false,0],true))return false;
        foreach(['environment','_provider_environment'] as $field)
            if(array_key_exists($field,$config) && $config[$field]!=='live')return false;
        return true;
    }

    public static function choices(): array
    {
        $rows=[];
        foreach(self::ASSETS as $id=>[$currency,$system,$wire,$network]) {
            $key=self::choiceKey(['cCID'=>$id,'cCurrID'=>$currency]);
            $rows[$key]=['gateway'=>$id,'currency'=>$currency,'name'=>$currency.' · '.$network,'scale'=>6,
                'asset_key'=>in_array($id,['PKB','PKE','PKL'],true)?'asset:'.$currency:'paykassa:'.$system.':'.$currency];
        }
        // Existing combined USDT and ERC-20 USDC histories retain their original IDs/routes.
        $rows['USDT']=['gateway'=>'PKTU','currency'=>'USDT','name'=>'USDT · Ethereum / TRON','scale'=>6,'asset_key'=>'asset:USDT'];
        $rows['USDC']=['gateway'=>'PKTC','currency'=>'USDC','name'=>'USDC · Ethereum (ERC-20)','scale'=>6,'asset_key'=>'asset:USDC'];
        return $rows;
    }
}
