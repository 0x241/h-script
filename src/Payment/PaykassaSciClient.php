<?php

declare(strict_types=1);

namespace HScript\Payment;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Credentialed SCI confirmation uses its own bounded, nonredirecting transport. */
final class PaykassaSciClient
{
    public const ENDPOINT = 'https://paykassa.app/sci/0.4/index.php';
    public const MAX_RESPONSE_BYTES = 65536;
    public const CONNECT_TIMEOUT_MS = 1500;
    public const TOTAL_TIMEOUT_MS = 3500;

    public static function request(string $merchant, string $secret, array $fields): array
    {
        if (!preg_match('/^[1-9][0-9]{0,14}$/D', $merchant) || trim($secret) === '' || strlen($secret) > 8192)
            throw new InvalidArgumentException('callback_configuration_missing');
        if (!in_array($fields['func'] ?? null, ['sci_confirm_order','sci_create_order_get_data'], true))
            throw new InvalidArgumentException('paykassa_method_unsupported');
        foreach ($fields as $key => $value)
            if (!is_string($key) || !is_scalar($value) || strlen((string)$value) > 8192)
                throw new InvalidArgumentException('paykassa_request_invalid');
        $body = '';
        $handle = null;
        try {
            $handle = curl_init();
            if ($handle === false) throw new RuntimeException('paykassa_transport_unavailable');
            $options = [
                CURLOPT_URL => self::ENDPOINT,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(array_replace($fields, ['sci_id'=>$merchant,'sci_key'=>$secret,'test'=>'false']), '', '&', PHP_QUERY_RFC3986),
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded','Accept: application/json'],
                CURLOPT_CONNECTTIMEOUT_MS => self::CONNECT_TIMEOUT_MS,
                CURLOPT_TIMEOUT_MS => self::TOTAL_TIMEOUT_MS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_HEADER => false,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_WRITEFUNCTION => static function (mixed $curl, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > self::MAX_RESPONSE_BYTES) return 0;
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ];
            if (defined('CURLOPT_PROTOCOLS_STR')) $options[CURLOPT_PROTOCOLS_STR] = 'https';
            else $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
            if (!curl_setopt_array($handle, $options) || curl_exec($handle) === false
                || curl_errno($handle) !== 0 || curl_getinfo($handle, CURLINFO_RESPONSE_CODE) !== 200)
                throw new RuntimeException('paykassa_transport_unavailable');
            return self::decode($body);
        } catch (Throwable) {
            // Never include curl text, response bodies, merchant secrets or tokens.
            throw new RuntimeException('paykassa_confirmation_unavailable');
        } finally {
            if ($handle !== null && $handle !== false) curl_close($handle);
        }
    }

    public static function decode(string $body): array
    {
        try {
            if ($body === '' || strlen($body) > self::MAX_RESPONSE_BYTES || !str_starts_with(ltrim($body), '{'))
                throw new RuntimeException();
            $result = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($result) || !is_bool($result['error'] ?? null)
                || !is_array($result['data'] ?? null) || array_is_list($result['data']) && $result['data'] !== []
                || count($result['data']) > 32)
                throw new RuntimeException();
            foreach ($result['data'] as $key => $value)
                if (!is_string($key) || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $key)
                    || (!is_scalar($value) && $value !== null)
                    || is_string($value) && (strlen($value) > 8192 || !preg_match('//u', $value)))
                    throw new RuntimeException();
            // Provider message is not returned or reflected to an HTTP caller.
            return ['error'=>$result['error'],'data'=>$result['data']];
        } catch (Throwable) {
            throw new RuntimeException('paykassa_response_invalid');
        }
    }
}
