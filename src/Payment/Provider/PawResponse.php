<?php

declare(strict_types=1);
namespace HScript\Payment\Provider;

use JsonException;
use stdClass;

/** Bounded object envelope, with duplicate decoded keys rejected before unwrap. */
final class PawResponse
{
    public static function result(string $raw): array
    {
        $object=self::decode($raw);
        if (count(get_object_vars($object))!==2 || !($object->result??null) instanceof stdClass)
            throw new PawProviderException('provider_unavailable');
        $result=get_object_vars($object->result);
        if (isset($result['rates'])) {
            if (!$result['rates'] instanceof stdClass) throw new PawProviderException('provider_unavailable');
            $result['rates']=get_object_vars($result['rates']);
        }
        return $result;
    }
    public static function sandboxInvoicePage(string $raw): void
    {
        $object=self::decode($raw);
        $pagination=$object->pagination??null;
        if (count(get_object_vars($object))!==3 || !is_array($object->result??null)
            || count($object->result)>1 || !$pagination instanceof stdClass
            || count(get_object_vars($pagination))!==4 || ($pagination->page??null)!==1
            || ($pagination->per_page??null)!==1 || !is_int($pagination->total??null)
            || $pagination->total<0 || !is_int($pagination->pages??null) || $pagination->pages<0)
            throw new PawProviderException('provider_unavailable');
        foreach ($object->result as $invoice) {
            if (!$invoice instanceof stdClass || !is_string($invoice->order_id??null)
                || !preg_match('/^[a-f0-9]{24}$/D',$invoice->order_id))
                throw new PawProviderException('provider_unavailable');
        }
    }
    private static function decode(string $raw): stdClass
    {
        if ($raw==='' || strlen($raw)>PawApiClient::MAX_BODY) throw new PawProviderException('provider_unavailable');
        try { $object=json_decode($raw,false,16,JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { throw new PawProviderException('provider_unavailable'); }
        if (!$object instanceof stdClass || ($object->ok??null)!==true) throw new PawProviderException('provider_unavailable');
        preg_match_all('/"(?:[^"\\\\]|\\\\[\s\S])*"|[{}\[\]:,]|[^ \t\r\n{}\[\]:,]+/',$raw,$matches);
        $index=0;self::scan($matches[0],$index);
        return $object;
    }
    /** Syntax/UTF-8/depth is already checked by json_decode; retain all key tokens. */
    private static function scan(array $tokens,int &$i): void
    {
        $token=$tokens[$i++];
        if ($token==='{') {
            $keys=[];
            if ($tokens[$i]==='}') { $i++;return; }
            do {
                $key=json_decode($tokens[$i++],true,16,JSON_THROW_ON_ERROR);
                $id='key:'.$key;
                if (isset($keys[$id])) throw new PawProviderException('provider_unavailable');
                $keys[$id]=true;$i++;self::scan($tokens,$i);
                $end=$tokens[$i++];
            } while ($end===',');
        } elseif ($token==='[') {
            if ($tokens[$i]===']') { $i++;return; }
            do { self::scan($tokens,$i);$end=$tokens[$i++]; } while ($end===',');
        }
    }
}
