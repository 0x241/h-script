<?php

declare(strict_types=1);
namespace HScript\Payment\Provider;

use Closure;
use PawPayments\Sdk\Version;
use Throwable;

/**
 * Direct read-only OpenAPI validation: SDK 2.0.1 privately unwraps HTTP/envelopes
 * before callers can enforce them. No redirects, merchant proxy or write calls.
 */
final class PawApiClient implements PawValidationClient
{
    public const ORIGIN='https://api.pawpayments.com';
    public const MAX_BODY=131072;
    private Closure $transport;
    private int $timeout;
    public function __construct(private string $key,int $timeout,?Closure $transport=null)
    {
        if (strlen($key)<8 || strlen($key)>4096 || preg_match('/[\x00-\x20\x7f]/',$key)) throw new PawProviderException('invalid_api_key');
        $this->timeout=max(1,min(5,$timeout));
        // This seam is trusted PHP only, never environment or administrator input.
        $this->transport=$transport ?? self::get(...);
    }
    public function getBalance(): array { return $this->read('/api/v2/balance'); }
    public function validateSandbox(): void
    {
        // Sandbox refuses balance/ledger. Read one authenticated invoice page;
        // never interpret this connectivity probe as a monetary balance.
        PawResponse::sandboxInvoicePage($this->response('/api/v2/invoices?page=1&per_page=1'));
    }
    public function getRates(string $base): array
    {
        if (!in_array($base,PawProviderService::FIATS,true)) throw new PawProviderException('fiat_unsupported');
        return $this->read('/api/v2/rates?base='.rawurlencode($base));
    }
    private function read(string $path): array
    {
        return PawResponse::result($this->response($path));
    }
    private function response(string $path): string
    {
        try {
            $r=($this->transport)(self::ORIGIN.$path,['x-api-key: '.$this->key,'Accept: application/json','User-Agent: '.Version::USER_AGENT.' H-Script-profile'],$this->timeout);
        } catch (Throwable $e) { throw new PawProviderException('provider_unavailable'); }
        if (!is_array($r) || !is_int($r['status']??null)) throw new PawProviderException('provider_unavailable');
        $status=$r['status'];
        if ($status<200 || $status>=300) throw new PawProviderException(match (true) {
            $status===401=>'invalid_api_key', $status===403=>'merchant_restricted',
            ($status===400 || $status===422) && str_starts_with($path,'/api/v2/rates?')=>'fiat_unsupported',
            default=>'provider_unavailable'
        });
        if (!is_string($r['body']??null) || strlen($r['body'])>self::MAX_BODY || !is_string($r['content_type']??null)
            || !preg_match('~^application/json(?:\s*;[^\r\n]*)?$~iD',$r['content_type'])) throw new PawProviderException('provider_unavailable');
        return $r['body'];
    }
    private static function get(string $url,array $headers,int $timeout): array
    {
        $body='';$ch=curl_init();
        if ($ch===false) throw new PawProviderException('provider_unavailable');
        try {
            curl_setopt_array($ch,[
                CURLOPT_URL=>$url,CURLOPT_HTTPHEADER=>$headers,CURLOPT_HTTPGET=>true,
                CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_TIMEOUT=>$timeout,CURLOPT_CONNECTTIMEOUT=>min(2,$timeout),
                CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk) use (&$body): int {
                    if (strlen($body)+strlen($chunk)>self::MAX_BODY) return 0;
                    $body.=$chunk;return strlen($chunk);
                }
            ]);
            if (curl_exec($ch)===false) throw new PawProviderException('provider_unavailable');
            return ['status'=>(int)curl_getinfo($ch,CURLINFO_HTTP_CODE),'content_type'=>(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE),'body'=>$body];
        } finally { curl_close($ch); }
    }
}
