<?php

declare(strict_types=1);
namespace HScript\Payment\Provider;

/** Fixed callers supply the origin; redirects and unbounded bodies are forbidden. */
final class ProviderHttp
{
    public static function request(string $url,array $headers,?string $body=null): string
    {
        $h=curl_init();$response='';
        if ($h===false) throw new ProviderException('provider_unavailable');
        try {
            $options=[CURLOPT_URL=>$url,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT_MS=>1500,CURLOPT_TIMEOUT_MS=>3500,
                CURLOPT_RETURNTRANSFER=>false,CURLOPT_HEADER=>false,
                CURLOPT_WRITEFUNCTION=>static function(mixed $handle,string $chunk) use (&$response): int {
                    if (strlen($response)+strlen($chunk)>65536) return 0;$response.=$chunk;return strlen($chunk);
                }];
            if ($body!==null) $options += [CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body];
            if (defined('CURLOPT_PROTOCOLS_STR')) $options[CURLOPT_PROTOCOLS_STR]='https';else $options[CURLOPT_PROTOCOLS]=CURLPROTO_HTTPS;
            if (!curl_setopt_array($h,$options) || curl_exec($h)===false || curl_errno($h)!==0 || curl_getinfo($h,CURLINFO_RESPONSE_CODE)!==200)
                throw new ProviderException('provider_unavailable');
            return $response;
        } finally { curl_close($h); }
    }
}
