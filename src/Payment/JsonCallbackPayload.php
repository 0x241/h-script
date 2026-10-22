<?php

declare(strict_types=1);
namespace HScript\Payment;

use InvalidArgumentException;
use JsonException;
use stdClass;

/** Preserve scalar JSON signature values and reject duplicate decoded keys. */
final class JsonCallbackPayload
{
    public static function object(string $raw,int $maximumBytes=65536): stdClass
    {
        if ($raw==='' || strlen($raw)>min(1048576,max(1,$maximumBytes))) throw new InvalidArgumentException('callback_body_invalid');
        try { $object=json_decode($raw,false,8,JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new InvalidArgumentException('callback_json_invalid'); }
        if (!$object instanceof stdClass) throw new InvalidArgumentException('callback_json_invalid');
        $tokens=self::tokens($raw); $index=0; self::scan($tokens,$index);
        return $object;
    }

    /** Raw fractional/exponent numbers cannot have a stable PHP string sign. */
    public static function scalars(string $raw): array
    {
        self::object($raw);
        $tokens=self::tokens($raw); $index=1; $result=[];
        if (($tokens[$index] ?? '')==='}') return [];
        do {
            $key=json_decode($tokens[$index++],true,8,JSON_THROW_ON_ERROR); $index++;
            $value=$tokens[$index++];
            if (str_starts_with($value,'"')) $value=json_decode($value,true,8,JSON_THROW_ON_ERROR);
            elseif ($value==='null') $value=null;
            elseif (!preg_match('/^(?:0|[1-9][0-9]{0,14})$/D',$value)) throw new InvalidArgumentException('callback_scalar_invalid');
            if (!is_string($key) || strlen($key)>128 || array_key_exists($key,$result)
                || (is_string($value) && strlen($value)>8192)) throw new InvalidArgumentException('callback_fields_invalid');
            $result[$key]=$value; $end=$tokens[$index++];
        } while ($end===',');
        return $result;
    }

    private static function tokens(string $raw): array
    {
        preg_match_all('/"(?:[^"\\\\]|\\\\[\s\S])*"|[{}\[\]:,]|[^ \t\r\n{}\[\]:,]+/',$raw,$matches);
        return $matches[0];
    }

    /** JSON syntax/depth/UTF-8 is checked first; this retains all key tokens. */
    private static function scan(array $tokens,int &$index): void
    {
        $token=$tokens[$index++];
        if ($token==='{') {
            $keys=[];
            if ($tokens[$index]==='}') { $index++; return; }
            do {
                $key=json_decode($tokens[$index++],true,8,JSON_THROW_ON_ERROR); $id='key:'.$key;
                if (isset($keys[$id])) throw new InvalidArgumentException('callback_fields_invalid');
                $keys[$id]=true; $index++; self::scan($tokens,$index); $end=$tokens[$index++];
            } while ($end===',');
        } elseif ($token==='[') {
            if ($tokens[$index]===']') { $index++; return; }
            do { self::scan($tokens,$index); $end=$tokens[$index++]; } while ($end===',');
        }
    }
}
