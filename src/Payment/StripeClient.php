<?php

declare(strict_types=1);
namespace HScript\Payment;

use HScript\Payment\Provider\ProviderHttp;
use RuntimeException;
use Throwable;

/** Stripe v1 REST with the official SDK API version and bounded, non-retried I/O. */
final class StripeClient
{
    public static function request(string $key,string $method,array $fields=[],?string $idempotency=null): array
    {
        if (!preg_match('/^(?:sk|rk)_(?:test|live)_[A-Za-z0-9]{8,}$/D',$key) || !in_array($method,['account','balance','checkout/sessions'],true)) throw new RuntimeException('stripe_configuration_invalid');
        $headers=['Authorization: Bearer '.$key,'Stripe-Version: '.\Stripe\Util\ApiVersion::CURRENT,'Accept: application/json','Content-Type: application/x-www-form-urlencoded'];
        if ($method==='checkout/sessions') {
            if (!is_string($idempotency) || !preg_match('/^hscript:[a-f0-9]{64}$/D',$idempotency)) throw new RuntimeException('stripe_request_invalid');
            $headers[]='Idempotency-Key: '.$idempotency;
        } elseif ($fields!==[] || $idempotency!==null) throw new RuntimeException('stripe_request_invalid');
        try {
            $raw=ProviderHttp::request('https://api.stripe.com/v1/'.$method,$headers,$method==='checkout/sessions'?http_build_query($fields,'','&',PHP_QUERY_RFC3986):null);
            JsonCallbackPayload::object($raw);
            return json_decode($raw,true,8,JSON_THROW_ON_ERROR);
        } catch (Throwable) { throw new RuntimeException('stripe_unavailable'); }
    }
}
