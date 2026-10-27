<?php

declare(strict_types=1);
namespace HScript\Payment;

use RuntimeException;
use stdClass;
use Throwable;

/** Bounded v3 calls. Mutating requests are never retried by this transport. */
final class CcapiV3Client
{
    public const CONNECT_TIMEOUT_MS=1500;
    public const TOTAL_TIMEOUT_MS=3500;
    public const MAX_RESPONSE_BYTES=65536;
    public const CHAINS=['bitcoin','litecoin','dash','dogecoin','bitcoincash','ethereum','polygon','bsc','tron','ripple','ton','solana'];

    public static function request(string $chain,string $method,array $config,array $arguments=[]): array
    {
        $handle=null; $body='';
        try {
            $maximum=$method==='tokens'?1048576:self::MAX_RESPONSE_BYTES;
            $options=self::options($chain,$method,$config,$arguments);
            $handle=curl_init();
            if ($handle===false) throw new RuntimeException();
            $options[CURLOPT_WRITEFUNCTION]=static function (mixed $curl,string $chunk) use (&$body,$maximum): int {
                if (strlen($body)+strlen($chunk)>$maximum) return 0;
                $body.=$chunk; return strlen($chunk);
            };
            if (!curl_setopt_array($handle,$options) || curl_exec($handle)===false || curl_errno($handle)!==0
                || curl_getinfo($handle,CURLINFO_RESPONSE_CODE)!==200) throw new RuntimeException();
            return self::decode($body,$method,$chain);
        } catch (Throwable) {
            // Curl diagnostics and provider errors can contain keys/addresses.
            throw new RuntimeException('ccapi_unavailable');
        } finally { if ($handle!==null && $handle!==false) curl_close($handle); }
    }

    public static function configured(array $config): bool
    {
        return ($config['protocol'] ?? null)==='3' && is_string($config['v3_key'] ?? null)
            && trim($config['v3_key'])!=='' && strlen($config['v3_key'])<=8192 && !preg_match('/[\x00-\x20\x7f]/',$config['v3_key']);
    }

    public static function address(mixed $address): bool
    {
        // Signed provider identifiers are opaque: preserve exact bytes. Only
        // fixed native address prefixes can contain the signature separator.
        return is_string($address) && preg_match('/^(?:[A-Za-z0-9_+\/=\-]{26,128}|bitcoincash:[a-z0-9]{40,112}|(?:0|-1):[a-fA-F0-9]{64})$/D',$address)===1;
    }

    public static function transaction(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[A-Za-z0-9_+\/=\-]{32,128}$/D',$id)===1;
    }

    /** Network fee is immutable proof, never a local debit or rounding input. */
    public static function networkFee(string $value): string
    {
        if (!preg_match('/^([0-9]{1,20})(?:\.([0-9]{1,30}))?$/D',$value,$parts)) throw new \InvalidArgumentException();
        $whole=ltrim($parts[1],'0') ?: '0';$fraction=rtrim($parts[2]??'','0');
        return $whole.($fraction!==''?'.'.$fraction:'');
    }

    public static function addressIdentity(string $chain,string $address): ?string
    {
        if (!self::address($address)) return null;
        // X/T-addresses embed a destination tag. This profile requires a unique
        // receiver without an additional tag, including the valid tag zero.
        if ($chain==='ripple') return preg_match('/^r[1-9A-HJ-NP-Za-km-z]{24,34}$/D',$address) ? $address : null;
        if ($chain!=='ton') {
            if (str_contains($address,':') && !($chain==='bitcoincash' && str_starts_with($address,'bitcoincash:'))) return null;
            return $chain==='bitcoincash' && str_starts_with($address,'bitcoincash:') ? substr($address,12) : $address;
        }
        if (preg_match('/^(0|-1):([a-fA-F0-9]{64})$/D',$address,$raw)) return $raw[1].':'.strtolower($raw[2]);
        $bytes=base64_decode(strtr($address,'-_','+/'),true);
        if ($bytes===false || strlen($bytes)!==36 || !in_array(ord($bytes[0]),[0x11,0x51],true)) return null;
        $crc=0;
        for ($i=0;$i<34;$i++) {
            $crc^=ord($bytes[$i])<<8;
            for ($bit=0;$bit<8;$bit++) $crc=(($crc<<1)^(($crc&0x8000)?0x1021:0))&0xffff;
        }
        if (!hash_equals(pack('n',$crc),substr($bytes,34))) return null;
        $workchain=ord($bytes[1]);if ($workchain>=128) $workchain-=256;
        if (!in_array($workchain,[0,-1],true)) return null;
        return $workchain.':'.bin2hex(substr($bytes,2,32));
    }

    public static function sameAddress(string $chain,string $one,string $two): bool
    {
        $a=self::addressIdentity($chain,$one);$b=self::addressIdentity($chain,$two);
        return $a!==null && $b!==null && hash_equals($a,$b);
    }

    public static function requestId(string $callback,string $label,string $scope=''): string
    {
        return 'hscript:'.hash('sha256',$callback.($scope!==''?"\0".$scope:'')).':'.$label;
    }

    public static function options(string $chain,string $method,array $config,array $arguments): array
    {
        if (!in_array($chain,self::CHAINS,true) || !in_array($method,['give','balance','tokens','send','status'],true)) throw new RuntimeException('ccapi_method_unsupported');
        if($method==='tokens' && !isset(\HScript\Payment\Routing\TokenCatalog::NETWORKS[$chain]))throw new RuntimeException('ccapi_method_unsupported');
        if (!self::configured($config)) throw new RuntimeException('ccapi_configuration_missing');
        if ($method==='give') {
            $forward=$arguments['to']??null;
            if(array_key_exists('to',$arguments)){
                if(!is_string($forward) || !\HScript\Payment\Routing\CcapiWallets::validAddress($chain,$forward))throw new RuntimeException('ccapi_request_invalid');
                unset($arguments['to']);
            }
            if ((array_keys($arguments)!==['label','uniqID','statusURL'] && array_keys($arguments)!==['label','uniqID','statusURL','token']) || (isset($arguments['token']) && !\HScript\Payment\Routing\TokenCatalog::symbol($arguments['token'])) || !is_string($arguments['label'])
                || !preg_match('/^[1-9][0-9]{0,9}$/D',$arguments['label']) || !is_string($arguments['statusURL'])
                || $arguments['uniqID']!==self::requestId($arguments['statusURL'],$arguments['label'],isset($arguments['token'])?$chain.':'.$arguments['token']:'') || strlen($arguments['statusURL'])>250
                || filter_var($arguments['statusURL'],FILTER_VALIDATE_URL)===false
                || !in_array(parse_url($arguments['statusURL'],PHP_URL_SCHEME),['http','https'],true)
                || parse_url($arguments['statusURL'],PHP_URL_USER)!==null || parse_url($arguments['statusURL'],PHP_URL_PASS)!==null
                || preg_match('/[\x00-\x20\x7f]/',$arguments['statusURL'])) throw new RuntimeException('ccapi_request_invalid');
        } elseif ($method==='send') {
            $required=['to','amount','label','uniqID','statusURL'];
            if(array_diff($required,array_keys($arguments)) || array_diff(array_keys($arguments),[...$required,'from','token'])
                || !is_string($arguments['to']) || !\HScript\Payment\Routing\CcapiWallets::validAddress($chain,$arguments['to'])
                || !is_string($arguments['amount']) || !preg_match('/^\d{1,12}(?:\.\d{1,8})?$/D',$arguments['amount'])
                || !is_string($arguments['label']) || !preg_match('/^[1-9][0-9]{0,9}$/D',$arguments['label'])
                || !is_string($arguments['uniqID']) || !preg_match('/^hscript-out:[a-f0-9]{64}$/D',$arguments['uniqID'])
                || !is_string($arguments['statusURL']) || strlen($arguments['statusURL'])>250
                || !\HScript\Security\SafeUrl::redirect($arguments['statusURL'])
                || !in_array(parse_url($arguments['statusURL'],PHP_URL_SCHEME),['http','https'],true)
                || (isset($arguments['from']) && (!is_string($arguments['from']) || !\HScript\Payment\Routing\CcapiWallets::validAddress($chain,$arguments['from'])))
                || (isset($arguments['token']) && (!isset(\HScript\Payment\Routing\TokenCatalog::NETWORKS[$chain]) || !\HScript\Payment\Routing\TokenCatalog::symbol($arguments['token'])))) throw new RuntimeException('ccapi_request_invalid');
            \HScript\Finance\CallbackAmount::atScale($arguments['amount'],8);
        } elseif ($method==='status') {
            if(array_keys($arguments)!==['uniqID'] || !is_string($arguments['uniqID']) || !preg_match('/^hscript-out:[a-f0-9]{64}$/D',$arguments['uniqID']))throw new RuntimeException('ccapi_request_invalid');
        } elseif ($arguments!==[]) throw new RuntimeException('ccapi_request_invalid');
        if(isset($forward))$arguments['to']=$forward;
        $options=[CURLOPT_URL=>'https://new.cryptocurrencyapi.net/api/'.$chain.'/.'.$method,
            CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($arguments,'','&',PHP_QUERY_RFC3986),
            CURLOPT_HTTPHEADER=>['CCAPI-KEY: '.$config['v3_key'],'Content-Type: application/x-www-form-urlencoded','Accept: application/json','User-Agent: HScript-CCAPI/3'],
            CURLOPT_CONNECTTIMEOUT_MS=>self::CONNECT_TIMEOUT_MS,CURLOPT_TIMEOUT_MS=>self::TOTAL_TIMEOUT_MS,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,
            CURLOPT_HEADER=>false,CURLOPT_RETURNTRANSFER=>false];
        if (defined('CURLOPT_PROTOCOLS_STR')) $options[CURLOPT_PROTOCOLS_STR]='https'; else $options[CURLOPT_PROTOCOLS]=CURLPROTO_HTTPS;
        return $options;
    }

    public static function decode(string $raw,string $method,?string $chain=null): array
    {
        try {
            $object=JsonCallbackPayload::object($raw,$method==='tokens'?1048576:65536); $envelope=get_object_vars($object);
            if (array_keys($envelope)!==['result']) throw new RuntimeException();
            if($method==='send') {
                if(!is_string($object->result) || !preg_match('/^[1-9][0-9]{0,19}$/D',$object->result))throw new RuntimeException();
                return ['queue_id'=>$object->result];
            }
            if($method==='status') {
                if(!$object->result instanceof stdClass)throw new RuntimeException();
                $result=get_object_vars($object->result);
                foreach(['id','from','to','token','amount','fee','label','state','result','txid'] as $field) {
                    if(!array_key_exists($field,$result))throw new RuntimeException();
                    if($result[$field]===null && in_array($field,['from','token','result','txid'],true))$result[$field]='';
                    if(!is_string($result[$field]) || strlen($result[$field])>512 || preg_match('/[\x00-\x1f\x7f]/',$result[$field]))throw new RuntimeException();
                }
                if(!preg_match('/^[1-9][0-9]{0,19}$/D',$result['id']) || !preg_match('/^[0-9]$/D',$result['state']))throw new RuntimeException();
                return array_intersect_key($result,array_flip(['id','from','to','token','amount','fee','label','state','result','txid']));
            }
            if($method==='tokens'){
                if(!$object->result instanceof stdClass || $chain===null)throw new RuntimeException();
                return ['tokens'=>\HScript\Payment\Routing\TokenCatalog::normalize($chain,get_object_vars($object->result))];
            }
            if ($method==='give') {
                if (!$object->result instanceof stdClass) throw new RuntimeException();
                $result=get_object_vars($object->result);
                if (!self::address($result['address'] ?? null) || array_diff(array_keys($result),['address','publicKey','privateKey','extraID'])
                    || (array_key_exists('extraID',$result) && $result['extraID']!==null && $result['extraID']!=='')
                    || ($chain!==null && self::addressIdentity($chain,$result['address'])===null)) throw new RuntimeException();
                foreach (['publicKey','privateKey'] as $field)
                    if (array_key_exists($field,$result) && (!is_string($result[$field]) || strlen($result[$field])>8192)) throw new RuntimeException();
                return ['address'=>$result['address']];
            }
            if ($method!=='balance' || !is_string($object->result) || !preg_match('/^-?\d{1,12}(?:\.\d{1,30})?$/D',$object->result)) throw new RuntimeException();
            $negative=str_starts_with($object->result,'-');$balance=self::networkFee(ltrim($object->result,'-'));
            return ['balance'=>($negative && $balance!=='0'?'-':'').$balance];
        } catch (Throwable) { throw new RuntimeException('ccapi_unavailable'); }
    }
}
