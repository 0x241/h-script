<?php

namespace HScript\Payment;

use InvalidArgumentException;

/** Decode signed form bytes without trimming, key rewriting or duplicate loss. */
final class FormCallbackPayload
{
    public static function decode(string $raw): array
    {
        if ($raw === '' || strlen($raw) > 65536) throw new InvalidArgumentException('callback_body_invalid');
        $fields = explode('&', $raw);
        if (count($fields) > 64) throw new InvalidArgumentException('callback_fields_invalid');
        $result = [];
        foreach ($fields as $field) {
            if (!str_contains($field, '=') || preg_match('/%(?![a-fA-F0-9]{2})/', $field))
                throw new InvalidArgumentException('callback_encoding_invalid');
            [$key, $value] = array_map('urldecode', explode('=', $field, 2));
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,127}$/D', $key)
                || array_key_exists($key, $result) || strlen($value) > 8192 || !preg_match('//u', $value))
                throw new InvalidArgumentException('callback_fields_invalid');
            $result[$key] = $value;
        }
        return $result;
    }
}
