<?php

declare(strict_types=1);
namespace HScript\Payment\Routing;

use HScript\Finance\CallbackValidationException;
use HScript\Finance\IdempotencyRequest;
use HScript\Payment\Provider\ProviderCatalog;

/** Ledger assets and payment networks are separate. Codes resolve only to reviewed routes. */
final class PaymentRoutes
{
    public const GATEWAYS=['CCTU'=>'USDT','CCTC'=>'USDC','PKTU'=>'USDT','PKTC'=>'USDC','CCT'=>'*'];
    public static function catalog(string $gateway,?array $currency=null): array
    {
        if($gateway==='CCT'){
            if($currency===null)return [];
            $raw=$currency['cTokenRoute']??null;
            if(!is_string($raw) || strlen($raw)>4096)throw new CallbackValidationException('payment_route_invalid');
            $route=self::assertSnapshot('CCT',json_decode($raw,true,8));return [$route['code']=>$route];
        }
        $asset=self::GATEWAYS[$gateway]??null;
        if ($asset===null) return [];
        $kind=ProviderCatalog::kind($gateway);$routes=[];
        $networks=$kind==='CCAPI'
            ? ($asset==='USDT' ? ['ethereum'=>['Ethereum (ERC-20)','ETH','0xdac17f958d2ee523a2206206994597c13d831ec7'],
                'tron'=>['TRON (TRC-20)','TRX','TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t']]
                : ['ethereum'=>['Ethereum (ERC-20)','ETH','0xa0b86991c6218b36c1d19d4a2e9eb0ce3606eb48']])
            : ($asset==='USDT' ? ['ethereum'=>['Ethereum (ERC-20)','32',''], 'tron'=>['TRON (TRC-20)','30','']]
                : ['ethereum'=>['Ethereum (ERC-20)','32','']]);
        foreach ($networks as $network=>[$label,$wire,$contract]) {
            $code=strtolower($kind).'-'.strtolower($asset).'-'.$network;
            $routes[$code]=['version'=>1,'code'=>$code,'provider'=>$kind,'gateway'=>$gateway,'asset'=>$asset,
                'network'=>$network,'label'=>$label,'wire'=>$wire,'contract'=>$contract];
        }
        return $routes;
    }
    public static function known(string $gateway,mixed $code,?array $currency=null): array
    {
        if (!is_string($code) || !isset(self::catalog($gateway,$currency)[$code])) throw new CallbackValidationException('payment_route_invalid');
        return self::catalog($gateway,$currency)[$code];
    }
    public static function codes(string $gateway,mixed $input,?array $currency=null): array
    {
        if (!is_array($input) || !array_is_list($input) || count($input)>16) throw new CallbackValidationException('payment_route_invalid');
        $result=[];
        foreach($input as $code) {self::known($gateway,$code,$currency);if(in_array($code,$result,true))throw new CallbackValidationException('payment_route_invalid');$result[]=$code;}
        return $result;
    }
    public static function enabled(array $currency): array
    {
        if (!isset(self::GATEWAYS[$currency['cCID']??''])) return [];
        $raw=$currency['cPaymentRoutes']??null;
        if ($raw===null) return [];
        if (!is_string($raw) || strlen($raw)>4096) throw new CallbackValidationException('payment_route_invalid');
        $codes=json_decode($raw,true,4);$result=[];
        foreach(self::codes($currency['cCID'],$codes,$currency) as $code)$result[$code]=self::known($currency['cCID'],$code,$currency);
        return $result;
    }
    /** Called under the owning currency/operation lock, before invoice I/O. */
    public static function select(array $currency,mixed $code): ?array
    {
        if (!isset(self::GATEWAYS[$currency['cCID']])) {
            if ($code!==null && $code!=='') throw new CallbackValidationException('payment_route_invalid');
            return null;
        }
        $route=self::known($currency['cCID'],$code,$currency);
        if (!isset(self::enabled($currency)[$route['code']]) || ($currency['cCurrID']??null)!==$route['asset'])
            throw new CallbackValidationException('payment_route_disabled');
        return $route;
    }
    public static function assertSnapshot(string $gateway,mixed $route): array
    {
        if($gateway==='CCT'){
            if(!is_array($route) || !is_string($route['label']??null) || strlen($route['label'])>100 || !preg_match('//u',$route['label']))throw new CallbackValidationException('payment_route_proof_invalid');
            $known=TokenCatalog::route((string)($route['network']??''),$route['asset']??null,$route['contract']??null);
            if(array_diff_key($known,['label'=>true])!==array_diff_key($route,['label'=>true]))throw new CallbackValidationException('payment_route_proof_invalid');return $route;
        }
        if (!is_array($route) || ($route['gateway']??null)!==$gateway || !is_string($route['label']??null) || strlen($route['label'])>100 || !preg_match('//u',$route['label'])
            || array_diff(array_keys($route),array_keys(self::known($gateway,$route['code']??null))) || array_diff(array_keys(self::known($gateway,$route['code']??null)),array_keys($route))
            || array_diff_key(self::known($gateway,$route['code']??null),['label'=>true])!==array_diff_key($route,['label'=>true]))
            throw new CallbackValidationException('payment_route_proof_invalid');
        return $route;
    }
    public static function hash(array $route): string {unset($route['label']);return IdempotencyRequest::fingerprint($route);}
    public static function address(array $route,string $address): ?string
    {
        return match($route['network']) {
            'ethereum','polygon','bsc'=>preg_match('/^0x[a-fA-F0-9]{40}$/D',$address)?strtolower($address):null,
            'tron'=>preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/D',$address)?$address:null,
            default=>null,
        };
    }
    public static function transaction(string $id): string
    {
        if(!preg_match('/^(?:0x)?([a-fA-F0-9]{64})$/D',$id,$m))throw new CallbackValidationException('payment_route_mismatch');
        return strtolower($m[1]);
    }
    public static function ccapi(string $gateway,array $payload): array
    {
        if($gateway==='CCT'){
            $r=TokenCatalog::route((string)($payload['chain']??''),$payload['token']??null,$payload['tokenContract']??null);
            if(($payload['currency']??null)!==$r['wire'])throw new CallbackValidationException('payment_route_mismatch');return $r;
        }
        foreach(self::catalog($gateway) as $route) {
            $contract=$payload['tokenContract']??null;
            if ($route['network']==='ethereum' && is_string($contract)) $contract=strtolower($contract);
            if (($payload['chain']??null)===$route['network'] && ($payload['currency']??null)===$route['wire']
                && ($payload['token']??null)===$route['asset'] && $contract===$route['contract']) return $route;
        }
        throw new CallbackValidationException('payment_route_mismatch');
    }
    public static function assertInvoice(\HScript\Database\Connection $db,array $operation,array $form): void
    {
        if(!isset(self::GATEWAYS[$operation['cCID']]))return;
        $route=self::assertSnapshot($operation['cCID'],$form['payment_route']??null);
        $s=$db->link->prepare("SELECT ikOwnerID,ikOperationID,ikState,ikResponse FROM IdempotencyKeys WHERE ikNamespace='invoice:attempt' AND ikKeyHash=? FOR UPDATE");
        $s->execute([hash('sha256',(int)$operation['ouID']."\0".(int)$operation['oID'])]);$row=$s->fetch();
        $data=$row?json_decode((string)$row['ikResponse'],true,32):null;
        if(!$row || (int)$row['ikOwnerID']!==(int)$operation['ouID'] || (int)$row['ikOperationID']!==(int)$operation['oID'] || $row['ikState']!=='completed'
            || ($data['data']['started']??null)!==true || ($data['data']['payment_route']??null)!==$route)throw new CallbackValidationException('payment_route_proof_invalid');
    }
    public static function paykassaSystem(array $route): string
    {
        return match($route['network']) {'ethereum'=>'ethereum_erc20','tron'=>'tron_trc20',default=>throw new CallbackValidationException('payment_route_invalid')};
    }
}
