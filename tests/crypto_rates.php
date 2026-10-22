<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/vendor/autoload.php';
use HScript\Finance\CoinbaseRateProvider;
$checks=0;
function cryptoRateAssert(bool $condition,string $message): void {
    global $checks; if (!$condition) throw new RuntimeException($message); $checks++;
}
function cryptoPayload(mixed $value): string { return json_encode(['data'=>['currency'=>'BTC','rates'=>['USD'=>$value,'EUR'=>'60000.00']]],JSON_THROW_ON_ERROR); }
$urlSeen='';
$p=new CoinbaseRateProvider(static function($url) use (&$urlSeen) { $urlSeen=$url; return cryptoPayload('65432.10'); });
cryptoRateAssert($p->rate(' btc ','usd')==='65432.10','exact string with trailing precision');
cryptoRateAssert($urlSeen==='https://api.coinbase.com/v2/exchange-rates?currency=BTC','keyless HTTPS endpoint');
cryptoRateAssert($p->rate('BTC','EUR')==='60000.00','requested base');
foreach (['0.000000000000000001','9007199254740993.123456789012345678',str_repeat('9',36).'.'.str_repeat('9',18)] as $v) {
    $p=new CoinbaseRateProvider(static fn($url)=>cryptoPayload($v));
    cryptoRateAssert($p->rate('BTC')===$v,'no binary loss or scientific notation');
}
foreach ([0,1,1.25,true,false,null,[],['1'],'0','-1','1e3','+1',' 1 ','1,2','',str_repeat('9',37),'0.'.str_repeat('1',19)] as $v) {
    $p=new CoinbaseRateProvider(static fn($url)=>cryptoPayload($v));
    cryptoRateAssert($p->rate('BTC')===null,'invalid wire type/value refused');
}
foreach (['{broken','[]','{"data":{"currency":[],"rates":{"USD":"1"}}}','{"data":{"currency":"ETH","rates":{"USD":"1"}}}','{"data":{"currency":"BTC","rates":"1"}}','{"data":{"currency":"BTC","rates":{}}}'] as $raw) {
    $p=new CoinbaseRateProvider(static fn($url)=>$raw);
    cryptoRateAssert($p->rate('BTC')===null,'malformed response refused');
}
$calls=0; $p=new CoinbaseRateProvider(static function($url) use (&$calls) { $calls++; return false; });
cryptoRateAssert($p->rate('../BTC')===null && $p->rate('BTC','USD?x=1')===null && $calls===0,'invalid code never requested');
cryptoRateAssert($p->rate('BTC')===null,'transport failure');
echo json_encode(['status'=>'passed','crypto_checks'=>$checks],JSON_THROW_ON_ERROR)."\n";
