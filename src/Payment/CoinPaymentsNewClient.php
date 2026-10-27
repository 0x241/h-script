<?php

declare(strict_types=1);
namespace HScript\Payment;

use HScript\Payment\Provider\ProviderException;

/** NEW API discovery only. This client cannot create wallets, invoices or payouts. */
final class CoinPaymentsNewClient
{
    public const ORIGINS=['A'=>'https://a-api.coinpayments.net','B'=>'https://b-api.coinpayments.net','C'=>'https://c-api.coinpayments.net','SANDBOX'=>'https://api.coinpayments.net'];
    public const MAX_BYTES=1048000;

    public static function credentials(array $input,array $old=[]): array
    {
        $instance=$input['instance']??'A';$client=$input['client_id']??'';$secret=$input['client_secret']??'';
        if ($secret==='') $secret=$old['client_secret']??'';
        if (!is_string($instance) || !isset(self::ORIGINS[$instance]) || !is_string($client)
            || !preg_match('/^[a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12}$/D',$client)
            || !is_string($secret) || $secret==='' || strlen($secret)>4096 || !preg_match('//u',$secret) || preg_match('/[\x00-\x20\x7f]/',$secret))
            throw new ProviderException('input_invalid');
        if ($old && ($instance!==$old['instance'] || $client!==$old['client_id'])) throw new ProviderException('connection_identity_changed');
        return ['instance'=>$instance,'client_id'=>$client,'client_secret'=>$secret];
    }

    public static function catalog(string $instance): array
    {
        return self::parseCatalog(self::get($instance,'/api/v2/currencies?types=crypto%2Ctoken&capabilities=payments'));
    }

    public static function authenticate(array $credentials): void
    {
        // Empty API-wallet lists are valid. Dashboard wallets are a different collection.
        $raw=self::get($credentials['instance'],'/api/v2/merchant/wallets?take=1',$credentials);
        try {
            if(!is_array(json_decode($raw,false,8,JSON_THROW_ON_ERROR)))throw new \RuntimeException();
            $data=JsonCallbackPayload::object('{"items":'.$raw.'}',self::MAX_BYTES+10)->items;
            if (!is_array($data) || count($data)>1) throw new \RuntimeException();
            foreach ($data as $wallet) if (!$wallet instanceof \stdClass || !is_string($wallet->walletId??null)) throw new \RuntimeException();
        } catch (\Throwable) { throw new ProviderException('provider_unavailable'); }
    }

    public static function parseCatalog(string $raw): array
    {
        try {
            if(!is_array(json_decode($raw,false,8,JSON_THROW_ON_ERROR)))throw new \RuntimeException();
            $data=JsonCallbackPayload::object('{"items":'.$raw.'}',self::MAX_BYTES+10)->items;
            if (!is_array($data) || count($data)>2500) throw new \RuntimeException();
            $result=[];
            foreach ($data as $item) {
                if (!$item instanceof \stdClass || !is_string($item->id??null) || !preg_match('/^[1-9][0-9]{0,10}(?::[A-Za-z0-9_.=+-]{1,128})?$/D',$item->id)
                    || isset($result[$item->id]) || !in_array($item->type??null,['crypto','token','fiat'],true)
                    || !is_string($item->symbol??null) || $item->symbol==='' || strlen($item->symbol)>60 || preg_match('/[\x00-\x20\x7f]/',$item->symbol)
                    || !is_string($item->name??null) || trim($item->name)==='' || mb_strlen($item->name,'UTF-8')>120 || preg_match('/[\x00-\x1f\x7f]/',$item->name)
                    || !is_int($item->decimalPlaces??null) || $item->decimalPlaces<0 || $item->decimalPlaces>36
                    || !in_array($item->status??null,['active','underMaintenance','deleted'],true)
                    || !is_array($item->capabilities??null) || count($item->capabilities)>32 || !is_bool($item->isEnabledForPayment??null)
                    || !is_int($item->requiredConfirmations??null) || $item->requiredConfirmations<0 || $item->requiredConfirmations>2147483647) throw new \RuntimeException();
                foreach ($item->capabilities as $capability) if (!is_string($capability) || strlen($capability)>64) throw new \RuntimeException();
                if ($item->type==='fiat' || !in_array('payments',$item->capabilities,true)) continue;
                [$chain,$contract]=array_pad(explode(':',$item->id,2),2,null);
                if (($item->type==='token')!==($contract!==null)) throw new \RuntimeException();
                $result[$item->id]=['id'=>$item->id,'type'=>$item->type,'symbol'=>$item->symbol,'name'=>$item->name,'decimals'=>$item->decimalPlaces,
                    'status'=>$item->status,'enabled'=>$item->isEnabledForPayment,'confirmations'=>$item->requiredConfirmations,'chain'=>$chain,'contract'=>$contract];
            }
            foreach ($result as &$item) $item['network']=$result[$item['chain']]['name']??('CoinPayments #'.$item['chain']);
            unset($item);
            return $result;
        } catch (\Throwable) { throw new ProviderException('catalog_unavailable'); }
    }

    public static function assetKey(string $instance,string $id): string
    {
        if (!isset(self::ORIGINS[$instance])) throw new ProviderException('input_invalid');
        return 'coinpayments:v2:'.hash('sha256',$instance."\0".$id);
    }

    private static function get(string $instance,string $path,?array $credentials=null): string
    {
        if (!isset(self::ORIGINS[$instance]) || !in_array($path,['/api/v2/currencies?types=crypto%2Ctoken&capabilities=payments','/api/v2/merchant/wallets?take=1'],true)) throw new ProviderException('provider_unavailable');
        $url=self::ORIGINS[$instance].$path;$headers=['Accept: application/json'];
        if ($credentials!==null) {
            $timestamp=gmdate('Y-m-d\TH:i:s');
            $signature=base64_encode(hash_hmac('sha256',"\xef\xbb\xbf".'GET'.$url.$credentials['client_id'].$timestamp,$credentials['client_secret'],true));
            $headers[]='X-CoinPayments-Client: '.$credentials['client_id'];$headers[]='X-CoinPayments-Timestamp: '.$timestamp;$headers[]='X-CoinPayments-Signature: '.$signature;
        }
        $handle=curl_init();$body='';if ($handle===false) throw new ProviderException('provider_unavailable');
        try {
            $options=[CURLOPT_URL=>$url,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT_MS=>1500,CURLOPT_TIMEOUT_MS=>3500,
                CURLOPT_HEADER=>false,CURLOPT_RETURNTRANSFER=>false,CURLOPT_WRITEFUNCTION=>static function(mixed $h,string $chunk)use(&$body):int{
                    if(strlen($body)+strlen($chunk)>self::MAX_BYTES)return 0;$body.=$chunk;return strlen($chunk);
                }];
            if(defined('CURLOPT_PROTOCOLS_STR'))$options[CURLOPT_PROTOCOLS_STR]='https';else$options[CURLOPT_PROTOCOLS]=CURLPROTO_HTTPS;
            if(!curl_setopt_array($handle,$options)||curl_exec($handle)===false||curl_errno($handle)!==0||curl_getinfo($handle,CURLINFO_RESPONSE_CODE)!==200)throw new ProviderException('provider_unavailable');
            return $body;
        }finally{curl_close($handle);}
    }
}
