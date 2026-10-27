<?php

declare(strict_types=1);
namespace HScript\Payment\Gateways;

use HScript\Finance\CallbackAmount;
use HScript\Finance\CcapiV3Contract;
use HScript\Finance\RequestDecimal;
use HScript\Payment\AbstractGateway;
use HScript\Payment\CallbackResult;
use HScript\Payment\CcapiV3Client;
use HScript\Payment\JsonCallbackPayload;
use HScript\Security\SafeUrl;
use InvalidArgumentException;
use RuntimeException;
use HScript\Payment\Routing\PaymentRoutes;
use HScript\Finance\CallbackValidationException;

/** Explicit v3 single-label invoices and queued payouts with exact money. */
class CryptoCurrencyApi extends AbstractGateway
{
    public const NATIVE=['CCAB'=>['bitcoin','BTC'],'CCAL'=>['litecoin','LTC'],'CCAD'=>['dash','DASH'],
        'CCAG'=>['dogecoin','DOGE'],'CCAH'=>['bitcoincash','BCH'],
        'CCAE'=>['ethereum','ETH'],'CCAM'=>['polygon','MATIC'],'CCAN'=>['bsc','BNB'],'CCAT'=>['tron','TRX'],
        'CCAX'=>['ripple','XRP'],'CCAO'=>['ton','TON'],'CCAS'=>['solana','SOL']];
    private const FIELDS=['cryptocurrencyapi.net','chain','currency','type','date','from','to','token','tokenContract','amount','fee','txid','pos','confirmation','label','sign'];

    public static function supports(string $gateway): bool {return isset(self::NATIVE[$gateway]) || in_array($gateway,['CCTU','CCTC','CCT'],true);}

    public function getFields(string $type): array
    {
        if ($type==='pay') return ['acc'=>['Address']];
        if ($type==='sci' || $type==='api') return ['protocol'=>['Protocol version (3)','3','3'],'v3_key'=>['Version 3 API key']];
        return [];
    }

    public function validateConfig(array $config): bool { return CcapiV3Client::configured($config); }

    public function processDeposit(array $params): array
    {
        $sci=(array)($params['sci'] ?? []);
        if (!CcapiV3Client::configured($sci)) throw new RuntimeException('ccapi_configuration_missing');
        $route=isset(PaymentRoutes::GATEWAYS[$this->id])?PaymentRoutes::assertSnapshot($this->id,$params['payment_route']??null):null;
        [$chain,$asset]=$route!==null?[$route['network'],$route['asset']]:self::NATIVE[$this->id];
        if (!is_string($params['tag'] ?? null) && !is_int($params['tag'] ?? null)) throw new RuntimeException('ccapi_request_invalid');
        $label=(string)$params['tag']; $scale=$params['callback_scale'] ?? null;
        if (!preg_match('/^[1-9][0-9]{0,9}$/D',$label) || !is_int($scale)) throw new RuntimeException('ccapi_request_invalid');
        $amount=CallbackAmount::atScale($params['sum'] ?? null,$scale);
        if (!is_string($params['url_callback'] ?? null) || !is_string($params['url_ok'] ?? null)
            || !SafeUrl::redirect($params['url_ok'])) throw new RuntimeException('ccapi_request_invalid');
        $callback=$params['url_callback']; $requestId=CcapiV3Client::requestId($callback,$label,$route!==null?$chain.':'.$asset:'');
        $arguments=['label'=>$label,'uniqID'=>$requestId,'statusURL'=>$callback];
        if($route!==null)$arguments['token']=$asset;
        $wallets=$params['ccapi_wallets']??null;
        if($wallets!==null){
            if(!is_array($wallets) || array_keys($wallets)!==['version','network','forward_to'] || $wallets['version']!==1 || $wallets['network']!==$chain || !is_string($wallets['forward_to']) || ($wallets['forward_to']!=='' && !\HScript\Payment\Routing\CcapiWallets::validAddress($chain,$wallets['forward_to'])))throw new RuntimeException('ccapi_request_invalid');
            if($wallets['forward_to']!=='')$arguments['to']=$wallets['forward_to'];
        }
        $response=$this->apiRequest($chain,'give',$sci,$arguments);
        if (!is_string($response['address']??null) || CcapiV3Client::addressIdentity($chain,$response['address'])===null || ($route!==null && PaymentRoutes::address($route,$response['address'])===null)) throw new RuntimeException('ccapi_unavailable');
        $metadata=['ccapi_profile'=>CcapiV3Contract::PROFILE,'ccapi_address'=>$response['address'],'ccapi_chain'=>$chain,
            'ccapi_asset'=>$asset,'ccapi_label'=>$label,'ccapi_scale'=>(string)$scale,'ccapi_gross'=>$amount,
            'ccapi_key_hash'=>hash('sha256',$sci['v3_key']),'ccapi_request_id'=>$requestId];
        if($route!==null)$metadata=array_replace($metadata,['ccapi_profile'=>CcapiV3Contract::TOKEN_PROFILE,'payment_route'=>$route,
            'ccapi_route_hash'=>PaymentRoutes::hash($route),'ccapi_native_asset'=>$route['wire'],'ccapi_token_contract'=>$route['contract'],'payment_network'=>$chain]);
        if($wallets!==null)$metadata['ccapi_forward_to']=$wallets['forward_to'];
        $metadata['ccapi_invoice_hash']=CcapiV3Contract::invoiceHash($metadata);
        // An address display is an internal operation page, with no external
        // form action. A relative URL also works on the local HTTP test stand.
        $path=parse_url($params['url_ok'],PHP_URL_PATH) ?: '/';
        $url=$path.'?id='.$label;
        return ['url'=>$url,'formkind'=>'native_address','payment_address'=>$response['address'],'payment_asset'=>$asset,'payment_amount'=>$amount]+$metadata;
    }

    protected function apiRequest(string $chain,string $method,array $config,array $arguments=[]): array
    {
        return CcapiV3Client::request($chain,$method,$config,$arguments);
    }

    public function handleCallback(array $request): array
    {
        $config=(array)($request['_config'] ?? []);
        if (!CcapiV3Client::configured($config)) return CallbackResult::failure('authentication_failed','callback_configuration_missing');
        try {
            $payload=JsonCallbackPayload::scalars($request['_raw_body'] ?? '');
            if (($payload['cryptocurrencyapi.net'] ?? null)!=='3') return CallbackResult::failure('unsupported','ccapi_protocol_unsupported');
            $out=($payload['type']??null)==='out';
            if($out && ($config['_ccapi_payout_context']??null)!==true)return CallbackResult::failure('unsupported','ccapi_type_unsupported');
            if (!$out && ($payload['type'] ?? null)!=='in') return CallbackResult::failure('unsupported','ccapi_type_unsupported');
            $fields=$out?[...self::FIELDS,'id']:self::FIELDS;
            if (array_diff($fields,array_keys($payload)) || array_diff(array_keys($payload),$fields)) throw new InvalidArgumentException();
            foreach ($payload as $field=>$value) {
                if ($value===null && in_array($field,['from','token','tokenContract'],true)) $payload[$field]=$value='';
                if (!is_string($value) || (str_contains($value,':') && !in_array($field,['from','to'],true)) || preg_match('/[\x00-\x1f\x7f]/',$value)) throw new InvalidArgumentException();
            }
            if (!preg_match('/^[a-f0-9]{40}$/D',$payload['sign'])) throw new InvalidArgumentException();
            $sign=$payload['sign']; unset($payload['sign']); ksort($payload,SORT_STRING);
            if (!hash_equals(sha1(implode(':',array_values($payload)).':'.md5($config['v3_key'])),$sign))
                return CallbackResult::failure('authentication_failed','signature_invalid');
            if($out && !preg_match('/^[1-9][0-9]{0,19}$/D',$payload['id']))throw new InvalidArgumentException();
            $route=isset(PaymentRoutes::GATEWAYS[$this->id])?PaymentRoutes::ccapi($this->id,$payload):null;
            [$chain,$asset]=$route!==null?[$route['network'],$route['asset']]:self::NATIVE[$this->id];
            if ($route===null && ($payload['chain']!==$chain || $payload['currency']!==$asset)) return CallbackResult::failure('invalid_payload','callback_currency_mismatch',true);
            if ($route===null && ($payload['token']!=='' || $payload['tokenContract']!=='')) return CallbackResult::failure('unsupported','ccapi_token_unsupported',true);
            foreach (['date','pos','confirmation','label'] as $field)
                if (!preg_match('/^(?:0|[1-9][0-9]{0,9})$/D',$payload[$field])) throw new InvalidArgumentException();
            if ($payload['date']==='0' || $payload['label']==='0' || (int)$payload['label']>2147483647
                || !CcapiV3Client::transaction($payload['txid']) || CcapiV3Client::addressIdentity($chain,$payload['to'])===null || ($payload['from']!=='' && CcapiV3Client::addressIdentity($chain,$payload['from'])===null)) throw new InvalidArgumentException();
            foreach (['amount'] as $field)
                if (!preg_match($route!==null?'/^\d{1,12}(?:\.\d{1,30})?$/D':'/^\d{1,12}(?:\.\d{1,8})?$/D',$payload[$field])) throw new InvalidArgumentException();
            $amount=RequestDecimal::normalize($route!==null?CcapiV3Client::networkFee($payload['amount']):$payload['amount']); $fee=CcapiV3Client::networkFee($payload['fee']);
            $confirmation=(int)$payload['confirmation'];
            if (in_array($confirmation,[100,101],true)) return CallbackResult::failure('unsupported','ccapi_manual_confirmation_unsupported',true);
            $event=$payload; unset($event['confirmation']); $event['amount']=$amount; $event['fee']=$fee;
            if($route!==null) {
                $event['to']=PaymentRoutes::address($route,$payload['to']);$event['from']=$payload['from']===''?'':PaymentRoutes::address($route,$payload['from']);
                if($event['to']===null || $event['from']===null)throw new CallbackValidationException('payment_route_mismatch');
                $event['tokenContract']=$route['contract'];$event['txid']=PaymentRoutes::transaction($payload['txid']);
            }
            return ['authenticated'=>true,'correct'=>true,'wait'=>$confirmation<=1,'outcome'=>$confirmation<=1?'pending':'verified','reason'=>'signature_verified',
                'tag'=>$payload['label'],'sum'=>$amount,'sum2'=>$amount,'curr'=>$asset,'accto'=>$route!==null?$event['to']:$payload['to'],'accfrom'=>$route!==null?$event['from']:$payload['from'],
                'signed_at'=>(int)$payload['date'],'date'=>(int)$payload['date'],'batch'=>($route!==null?hash('sha256',$chain."\0".$route['contract']."\0".$event['txid']):$payload['txid']).'#'.$payload['pos'],'response'=>'OK',
                'ccapi_profile'=>$route!==null?CcapiV3Contract::TOKEN_PROFILE:CcapiV3Contract::PROFILE,'ccapi_route_hash'=>$route!==null?PaymentRoutes::hash($route):null,'ccapi_chain'=>$chain,'ccapi_position'=>$payload['pos'],'ccapi_network_fee'=>$fee,
                'ccapi_confirmations'=>$confirmation,'ccapi_key_hash'=>hash('sha256',$config['v3_key']),'ccapi_event_hash'=>hash('sha256',json_encode($event,JSON_THROW_ON_ERROR))]
                +($out?['outbound'=>true,'ccapi_payout_id'=>$payload['id']]:[]);
        } catch (CallbackValidationException $e) {return CallbackResult::failure('invalid_payload',$e->reason,true);}
        catch (InvalidArgumentException) { return CallbackResult::failure('invalid_payload','callback_fields_invalid'); }
    }

    public function getBalance(array $config): array
    {
        if(isset(PaymentRoutes::GATEWAYS[$this->id]))return ['result'=>'token_balance_unsupported','sum'=>-1];
        if (!CcapiV3Client::configured($config)) return ['result'=>'ccapi_configuration_missing','sum'=>-1];
        try { $answer=$this->apiRequest(self::NATIVE[$this->id][0],'balance',$config); return ['result'=>'OK','sum'=>$answer['balance']]; }
        catch (RuntimeException) { return ['result'=>'ccapi_unavailable','sum'=>-1]; }
    }

    public function processWithdrawal(array $params): array
    {
        // Only the durable dispatcher constructs this closed request after its
        // original intent has committed. A queue id is not a completed payout.
        try {
            if(!\HScript\Finance\CcapiPayout::validRequest($this->id,$params))throw new RuntimeException();
            return $this->apiRequest($params['ccapi_chain'],'send',$params['from'],$params['ccapi_arguments'])+['result'=>'OK'];
        } catch(\Throwable) {return ['result'=>'ccapi_payout_reconciliation_required'];}
    }
}
