<?php

namespace HScript\Finance;

use InvalidArgumentException;

final class IdempotencyRequest
{
    public static function validate(string $namespace, string $key, int $owner): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9:._-]{0,95}$/D', $namespace)
            || !preg_match('/^[A-Za-z0-9:._-]{1,128}$/D', $key)
            || $owner < 0 || $owner > 2147483647)
            throw new InvalidArgumentException('Invalid idempotency namespace, key or owner');
    }

    /** Call after route validation and explicit normalization of money/optional fields. */
    public static function fingerprint(array $normalizedInput): string
    {
        return hash('sha256', json_encode(self::canonical($normalizedInput), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function canonical(mixed $value): mixed
    {
        if (is_array($value)) {
            if (!array_is_list($value)) ksort($value, SORT_STRING);
            foreach ($value as &$item) $item = self::canonical($item);
            unset($item);
        } elseif (is_object($value) || is_resource($value)) {
            throw new InvalidArgumentException('Request fingerprints require JSON values');
        }
        return $value;
    }
}
