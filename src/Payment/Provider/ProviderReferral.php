<?php

declare(strict_types=1);
namespace HScript\Payment\Provider;

/** Shared distribution defaults; optional deployment override, no remote fetch. */
final class ProviderReferral
{
    public static function ccapi(): ?string { return self::link('CCAPI'); }
    public static function link(string $kind): ?string
    {
        $hosts=match($kind) {
            'CCAPI'=>['new.cryptocurrencyapi.net','cryptocurrencyapi.net','h-script.com'],
            'PAW'=>['pawpayments.com','h-script.com'],'PAYKASSA'=>['paykassa.pro','h-script.com'],
            'VOLET'=>['volet.onelink.me','volet.com','h-script.com'],default=>[]
        };
        if (!$hosts) return null;
        $url=getenv($kind.'_PARTNER_URL');
        if (!is_string($url) || $url==='') {
            $defaults=json_decode((string)file_get_contents(dirname(__DIR__,3).'/resources/payment-provider-links.json'),true);
            $url=$defaults[$kind]??null;
        }
        if (!is_string($url) || $url==='' || strlen($url)>2048 || preg_match('/[\x00-\x20\x7f]/',$url)
            || filter_var($url,FILTER_VALIDATE_URL)===false) return null;
        $parts=parse_url($url);
        if (($parts['scheme']??'')!=='https' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['port']) && $parts['port']!==443
            || !in_array(strtolower($parts['host']??''),$hosts,true)) return null;
        return $url;
    }
}
