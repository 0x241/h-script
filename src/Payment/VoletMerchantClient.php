<?php

declare(strict_types=1);

namespace HScript\Payment;

use DOMDocument;
use DOMElement;
use RuntimeException;
use Throwable;

/** Fixed SOAP read operations; decimal values remain XML strings. */
final class VoletMerchantClient
{
    public const ENDPOINT = 'https://account.volet.com/wsm/apiWebService';
    public const API_NS = 'http://api.volet.com/';
    public const SOAP_NS = 'http://schemas.xmlsoap.org/soap/envelope/';
    public const MAX_RESPONSE_BYTES = 65536;
    public const CONNECT_TIMEOUT_MS = 1500;
    public const TOTAL_TIMEOUT_MS = 3500;
    private const FIELDS = [
        'getBalances'=>['id','amount'],
        'findTransaction'=>['id','amount','amountIncome','api','comment','cryptoCurrencyAmount','cryptoCurrencyCoinName','cryptoCurrencyTxHash','currency','currencyIncome','direction','fullCommission','orderId','parentTransactionId','paysFee','receiverEmail','sci','senderEmail','startTime','status','transactionName','transactionType','updatedTime','walletDestId','walletSrcId'],
        'findPaymentByOrderId'=>['orderId','sciName','buyerEmail','coinName','cryptoCurrencyAddress','cryptoCurrencyAmount','cryptoCurrencyInvoiceCreated','cryptoCurrencyInvoiceStatus','cryptoCurrencyPaymentCompleted','cryptoCurrencyPaymentReceived','cryptoCurrencyPaymentRefundRequested','cryptoCurrencyPaymentRefunded','cryptoCurrencyTransactionId','paymentCreated','paymentMethod','paymentStatus','receivedCryptoCurrencyAmount','refundAddress','srcWalletId','transactionAmount','transactionCanceled','transactionCompleted','transactionCreated','transactionCurrency','transactionId','transactionNote','transactionStatus'],
    ];

    public static function request(array $config, string $method, array $arguments, ?int $now = null): array
    {
        $handle = null;
        $body = '';
        try {
            $xml = self::envelope($config,$method,$arguments,$now ?? time());
            $handle = curl_init();
            if ($handle === false) throw new RuntimeException();
            $options = [
                CURLOPT_URL=>self::ENDPOINT, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$xml,
                CURLOPT_HTTPHEADER=>['Content-Type: text/xml; charset=UTF-8','Accept: text/xml','SOAPAction: ""','User-Agent: HScript-Volet/1.0'],
                CURLOPT_CONNECTTIMEOUT_MS=>self::CONNECT_TIMEOUT_MS, CURLOPT_TIMEOUT_MS=>self::TOTAL_TIMEOUT_MS,
                CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_FOLLOWLOCATION=>false, CURLOPT_MAXREDIRS=>0, CURLOPT_HEADER=>false, CURLOPT_RETURNTRANSFER=>false,
                CURLOPT_WRITEFUNCTION=>static function (mixed $curl, string $chunk) use (&$body): int {
                    if (strlen($body)+strlen($chunk)>self::MAX_RESPONSE_BYTES) return 0;
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ];
            if (defined('CURLOPT_PROTOCOLS_STR')) $options[CURLOPT_PROTOCOLS_STR]='https';
            else $options[CURLOPT_PROTOCOLS]=CURLPROTO_HTTPS;
            if (!curl_setopt_array($handle,$options) || curl_exec($handle)===false || curl_errno($handle)!==0
                || curl_getinfo($handle,CURLINFO_RESPONSE_CODE)!==200) throw new RuntimeException();
            return self::decode($body,$method);
        } catch (Throwable) {
            // Faults, curl errors and XML may contain account data or tokens.
            throw new RuntimeException('volet_confirmation_unavailable');
        } finally {
            if ($handle!==null && $handle!==false) curl_close($handle);
        }
    }

    public static function envelope(array $config, string $method, array $arguments, int $now): string
    {
        if (!isset(self::FIELDS[$method])) throw new RuntimeException('volet_method_unsupported');
        foreach (['name','api','apipass'] as $field) {
            $value=$config[$field] ?? null;
            if (!is_string($value) || trim($value)==='' || strlen($value)>8192 || !preg_match('//u',$value)
                || preg_match('/[\x00-\x1f\x7f]/',$value)) throw new RuntimeException('volet_configuration_missing');
        }
        $escape=static fn(string $value): string=>htmlspecialchars($value,ENT_XML1|ENT_QUOTES,'UTF-8');
        if ($method==='getBalances') {
            if ($arguments!==[]) throw new RuntimeException('volet_request_invalid');
            $argument='';
        } elseif ($method==='findTransaction') {
            if (array_keys($arguments)!==['transaction'] || !is_string($arguments['transaction'])
                || !preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$arguments['transaction']))
                throw new RuntimeException('volet_request_invalid');
            $argument=$escape($arguments['transaction']);
        } else {
            if (array_keys($arguments)!==['order','sci'] || !is_string($arguments['order'])
                || !preg_match('/^[1-9][0-9]{0,14}$/D',$arguments['order']) || !is_string($arguments['sci'])
                || trim($arguments['sci'])==='' || strlen($arguments['sci'])>256 || !preg_match('//u',$arguments['sci'])
                || preg_match('/[\x00-\x1f\x7f]/',$arguments['sci'])) throw new RuntimeException('volet_request_invalid');
            $argument='<orderId>'.$escape($arguments['order']).'</orderId><sciName>'.$escape($arguments['sci']).'</sciName>';
        }
        return '<?xml version="1.0" encoding="UTF-8"?><soap:Envelope xmlns:soap="'.self::SOAP_NS.'" xmlns:v="'.self::API_NS.'"><soap:Body><v:'.$method.'><arg0><accountEmail>'.$escape($config['name']).'</accountEmail><apiName>'.$escape($config['api']).'</apiName><authenticationToken>'.hash('sha256',$config['apipass'].':'.gmdate('Ymd:H',$now)).'</authenticationToken></arg0>'.($method==='getBalances'?'':'<arg1>'.$argument.'</arg1>').'</v:'.$method.'></soap:Body></soap:Envelope>';
    }

    /** Current public WSDL has direct, unqualified scalar return fields. */
    public static function decode(string $xml, string $method): array
    {
        if (!isset(self::FIELDS[$method]) || $xml==='' || strlen($xml)>self::MAX_RESPONSE_BYTES
            || !preg_match('//u',$xml) || str_contains($xml,"\0") || preg_match('/<!\s*(?:DOCTYPE|ENTITY)/i',$xml))
            throw new RuntimeException('volet_response_invalid');
        $document=new DOMDocument();
        $document->resolveExternals=false; $document->substituteEntities=false; $document->validateOnParse=false;
        $old=libxml_use_internal_errors(true);
        try {
            if (!$document->loadXML($xml,LIBXML_NONET|LIBXML_COMPACT|LIBXML_NOBLANKS) || $document->doctype)
                throw new RuntimeException('volet_response_invalid');
        } finally { libxml_clear_errors(); libxml_use_internal_errors($old); }
        $root=$document->documentElement;
        if (!$root || $root->localName!=='Envelope' || $root->namespaceURI!==self::SOAP_NS)
            throw new RuntimeException('volet_response_invalid');
        // Processing instructions outside the root are also rejected.
        foreach ($document->childNodes as $node)
            if (!in_array($node->nodeType,[XML_ELEMENT_NODE,XML_COMMENT_NODE],true)) throw new RuntimeException('volet_response_invalid');
        $envelope=self::children($root);
        if (count($envelope)===2 && $envelope[0]->localName==='Header' && $envelope[0]->namespaceURI===self::SOAP_NS
            && self::children($envelope[0])===[]) array_shift($envelope);
        if (count($envelope)!==1 || $envelope[0]->localName!=='Body' || $envelope[0]->namespaceURI!==self::SOAP_NS)
            throw new RuntimeException('volet_response_invalid');
        $body=self::children($envelope[0]);
        if (count($body)!==1 || $body[0]->localName!==$method.'Response' || $body[0]->namespaceURI!==self::API_NS)
            throw new RuntimeException('volet_response_invalid');
        $returns=self::children($body[0]);
        if ($method==='getBalances') {
            if (count($returns)>128) throw new RuntimeException('volet_response_invalid');
            $balances=[];
            foreach ($returns as $return) {
                if ($return->localName!=='return' || ($return->namespaceURI??'')!=='') throw new RuntimeException('volet_response_invalid');
                $row=[];
                foreach (self::children($return) as $field) {
                    if (!in_array($field->localName,['id','amount'],true) || isset($row[$field->localName]) || self::children($field)!==[] || ($field->namespaceURI??'')!=='') throw new RuntimeException('volet_response_invalid');
                    $row[$field->localName]=$field->textContent;
                }
                if (count($row)!==2 || !preg_match('/^[A-Za-z0-9]{1,64}$/D',$row['id']??'') || !preg_match('/^[0-9]{1,16}(?:\.[0-9]{1,8})?$/D',$row['amount']??'') || isset($balances[$row['id']])) throw new RuntimeException('volet_response_invalid');
                $balances[$row['id']]=$row['amount'];
            }
            return $balances;
        }
        if (count($returns)!==1 || $returns[0]->localName!=='return' || ($returns[0]->namespaceURI ?? '')!=='')
            throw new RuntimeException('volet_response_invalid');
        $fields=self::children($returns[0]); $result=[];
        if (count($fields)>32) throw new RuntimeException('volet_response_invalid');
        foreach ($fields as $field) {
            if (($field->namespaceURI ?? '')!=='' || self::children($field)!==[] || array_key_exists($field->localName,$result)
                || !in_array($field->localName,self::FIELDS[$method],true) || strlen($field->textContent)>8192)
                throw new RuntimeException('volet_response_invalid');
            $result[$field->localName]=$field->textContent;
        }
        return $result;
    }

    private static function children(DOMElement $element): array
    {
        foreach ($element->attributes as $attribute)
            if ($attribute->namespaceURI!=='http://www.w3.org/2000/xmlns/') throw new RuntimeException('volet_response_invalid');
        $out=[];
        foreach ($element->childNodes as $node) {
            if ($node instanceof DOMElement) $out[]=$node;
            elseif (in_array($node->nodeType,[XML_TEXT_NODE,XML_CDATA_SECTION_NODE],true)) {
                if (trim($node->textContent)!=='')
                    foreach ($element->childNodes as $child)
                        if ($child instanceof DOMElement) throw new RuntimeException('volet_response_invalid');
            } elseif ($node->nodeType!==XML_COMMENT_NODE) throw new RuntimeException('volet_response_invalid');
        }
        return $out;
    }
}
