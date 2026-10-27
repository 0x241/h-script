<?php

declare(strict_types=1);
namespace HScript\Payment;

use Closure;
use PawPayments\Sdk\Version;
use RuntimeException;

/** Official SDK stays installed; its float-decoding/unwrap is unsuitable for money. */
final class PawInvoiceClient
{
    private Closure $transport;
    public function __construct(private string $key,?Closure $transport=null)
    {
        if(strlen($key)<8 || strlen($key)>4096 || preg_match('/[\x00-\x20\x7f]/',$key))throw new RuntimeException('paw_configuration_invalid');
        $this->transport=$transport??self::send(...);
    }
    public function create(array $fields): array {return $this->request('/api/v2/invoices',$fields,201);}
    public function assets(): array {return $this->request('/api/v2/assets',null,200);}
    public function payout(array $fields,string $uniqId): array
    {
        if(str_starts_with($this->key,'sk_sandbox_'))throw new RuntimeException('paw_payout_sandbox_unsupported');
        if(!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$uniqId))throw new RuntimeException('paw_configuration_invalid');
        return $this->request('/api/v2/payouts',$fields,201,['X-Uniq-Id: '.$uniqId]);
    }
    public function payoutStatus(string $id): array
    {
        if(!preg_match('/^[a-f0-9]{24}$/D',$id))throw new RuntimeException('paw_configuration_invalid');
        return $this->request('/api/v2/payouts/'.$id,null,200);
    }
    private function request(string $path,?array $fields,int $status,array $extraHeaders=[]): array
    {
        $response=($this->transport)('https://api.pawpayments.com'.$path,
            ['x-api-key: '.$this->key,'Accept: application/json','Content-Type: application/json','User-Agent: '.Version::USER_AGENT.' H-Script',...$extraHeaders],
            $fields===null?null:json_encode($fields,JSON_THROW_ON_ERROR));
        if(!is_array($response) || ($response['status']??null)!==$status || !is_string($response['body']??null)
            || !preg_match('~^application/json(?:\s*;[^\r\n]*)?$~iD',$response['content_type']??''))throw new RuntimeException('paw_provider_unavailable');
        $body=PawPayload::decode($response['body']);
        $keys=array_keys($body);sort($keys);
        if($keys!==['ok','result'] || $body['ok']!==true || !is_array($body['result']))throw new RuntimeException('paw_provider_unavailable');
        return $body['result'];
    }
    private static function send(string $url,array $headers,?string $body): array
    {
        $ch=curl_init();$raw='';
        if($ch===false)throw new RuntimeException('paw_provider_unavailable');
        try {
            $options=[CURLOPT_URL=>$url,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT_MS=>2000,CURLOPT_TIMEOUT_MS=>7000,
                CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>static function($handle,string $part)use(&$raw):int {
                    if(strlen($raw)+strlen($part)>65536)return 0;$raw.=$part;return strlen($part);
                }];
            if($body!==null)$options+=[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body];
            if(!curl_setopt_array($ch,$options) || curl_exec($ch)===false)throw new RuntimeException('paw_provider_unavailable');
            return ['status'=>(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE),'body'=>$raw,'content_type'=>(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE)];
        } finally {curl_close($ch);}
    }
}
