<?php

declare(strict_types=1);
namespace HScript\Payment\Routing;
use HScript\Database\Connection;
use HScript\Finance\CallbackValidationException;
use HScript\Payment\{CcapiV3Client,Gateways\CryptoCurrencyApi};
use PDO;

/** Network forwarding policy is captured before give; existing invoices keep their receiver. */
final class CcapiWallets
{
    public static function validAddress(string $chain,string $address): bool
    {
        if(isset(TokenCatalog::NETWORKS[$chain]))return PaymentRoutes::address(['network'=>$chain],$address)!==null;
        $format=match($chain){
            'bitcoin'=>'/^(?:[13][1-9A-HJ-NP-Za-km-z]{25,34}|bc1[ac-hj-np-z02-9]{11,87})$/D',
            'litecoin'=>'/^(?:[LM3][1-9A-HJ-NP-Za-km-z]{25,34}|ltc1[ac-hj-np-z02-9]{11,87})$/D',
            'dogecoin'=>'/^[DA9][1-9A-HJ-NP-Za-km-z]{25,34}$/D',
            'dash'=>'/^[X7][1-9A-HJ-NP-Za-km-z]{25,34}$/D',
            'bitcoincash'=>'/^(?:(?:bitcoincash:)?[qp][a-z0-9]{41}|[13][1-9A-HJ-NP-Za-km-z]{25,34})$/D',
            'ripple'=>'/^r[1-9A-HJ-NP-Za-km-z]{24,34}$/D',
            'solana'=>'/^[1-9A-HJ-NP-Za-km-z]{32,44}$/D',
            'ton'=>null,default=>false};
        return $format!==false && ($format===null || preg_match($format,$address)===1) && CcapiV3Client::addressIdentity($chain,$address)!==null;
    }
    public static function read(Connection $db,int $provider,string $network,bool $lock=false): array
    {
        $s=$db->link->prepare('SELECT pnForwardTo,pnPayoutFrom FROM ProviderNetworkWallets WHERE pnProviderID=? AND pnNetwork=?'.($lock?' LOCK IN SHARE MODE':''));$s->execute([$provider,$network]);
        return $s->fetch(PDO::FETCH_ASSOC)?:['pnForwardTo'=>'','pnPayoutFrom'=>''];
    }
    public static function capture(Connection $db,array $operation,?array $route): ?array
    {
        if(!CryptoCurrencyApi::supports($operation['cCID']) || empty($operation['cProviderID']) || !array_key_exists('cAssetKey',$operation))return null;
        $chain=$route['network']??CryptoCurrencyApi::NATIVE[$operation['cCID']][0];$value=self::read($db,(int)$operation['cProviderID'],$chain,true)['pnForwardTo'];
        if($value!=='' && !self::validAddress($chain,$value))throw new CallbackValidationException('payment_wallet_invalid');
        return ['version'=>1,'network'=>$chain,'forward_to'=>$value];
    }
    public static function assertInvoice(Connection $db,array $operation,array $form): void
    {
        if(!CryptoCurrencyApi::supports($operation['cCID']))return;
        $s=$db->link->prepare("SELECT ikResponse FROM IdempotencyKeys WHERE ikNamespace='invoice:attempt' AND ikKeyHash=? FOR UPDATE");$s->execute([hash('sha256',(int)$operation['ouID']."\0".(int)$operation['oID'])]);$raw=$s->fetchColumn();$data=is_string($raw)?json_decode($raw,true,32):null;
        $policy=$data['data']['ccapi_wallets']??null;
        if($policy===null){if(array_key_exists('ccapi_forward_to',$form))throw new CallbackValidationException('payment_wallet_proof_invalid');return;}
        if(!is_array($policy) || array_keys($policy)!==['version','network','forward_to'] || $policy['version']!==1 || ($form['ccapi_chain']??null)!==$policy['network'] || ($form['ccapi_forward_to']??null)!==$policy['forward_to'])throw new CallbackValidationException('payment_wallet_proof_invalid');
    }
}
