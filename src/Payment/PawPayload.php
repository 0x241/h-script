<?php

declare(strict_types=1);
namespace HScript\Payment;

/** Validate JSON once, then preserve every number token as an exact string. */
final class PawPayload
{
    public static function decode(string $raw): array
    {
        JsonCallbackPayload::object($raw);
        $exact=preg_replace_callback('/"(?:[^"\\\\]|\\\\[\s\S])*"|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/',
            static fn(array $m): string=>$m[0][0]==='"'?$m[0]:json_encode($m[0],JSON_THROW_ON_ERROR),$raw);
        return json_decode($exact,true,8,JSON_THROW_ON_ERROR);
    }
}
