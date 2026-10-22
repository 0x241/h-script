<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/vendor/autoload.php';
use HScript\Finance\CentralBankRateProvider;
$checks=0;
function fiatAssert(bool $condition, string $message): void {
    global $checks; if (!$condition) throw new RuntimeException($message); $checks++;
}
function fiatXml(string $nominal='1', string $value='90,5000', string $extra=''): string {
    return '<ValCurs><Valute><Nominal>'.$nominal.'</Nominal><CharCode>USD</CharCode><Value>'.$value.'</Value></Valute>'.$extra.'</ValCurs>';
}
$eur='<Valute><Nominal>100</Nominal><CharCode>EUR</CharCode><Value>9800,0000</Value></Valute>';
$urlSeen='';
$p=new CentralBankRateProvider(static function($url) use (&$urlSeen,$eur) { $urlSeen=$url; return fiatXml('1','90,5000',$eur); });
fiatAssert($p->rates(['usd',' EUR '])===['USD'=>'90.500000000000000000','EUR'=>'98.000000000000000000'],'nominal divided exactly');
fiatAssert($urlSeen==='https://www.cbr.ru/scripts/XML_daily.asp','verified HTTPS endpoint');
foreach ([['3','1','0.333333333333333333'],['100','9007199254740993,123456','90071992547409.931234560000000000'],['2','0,000000000000000001','0.000000000000000001']] as [$n,$v,$expected]) {
    $p=new CentralBankRateProvider(static fn($url)=>fiatXml($n,$v));
    fiatAssert(($p->rates(['USD'])['USD'] ?? null)===$expected,'explicit per-unit HALF_UP18');
}
foreach (['0','-1','1.5','1e2','1,0','',str_repeat('9',37)] as $n) {
    $p=new CentralBankRateProvider(static fn($url)=>fiatXml($n));
    fiatAssert($p->rates(['USD'])===null,'invalid nominal refused');
}
foreach (['0','-1','1e3','+1','1,2,3','',str_repeat('9',37),'0.'.str_repeat('1',19)] as $v) {
    $p=new CentralBankRateProvider(static fn($url)=>fiatXml('1',$v));
    fiatAssert($p->rates(['USD'])===null,'invalid value refused');
}
$bad=['<broken>',str_replace('ValCurs','Other',fiatXml()),fiatXml('1','90',substr(fiatXml(),9,-10).$eur),fiatXml('1','90'),
    fiatXml('1</Nominal><Nominal>2','90',$eur),fiatXml('1','<x>90</x>',$eur),
    '<!DOCTYPE ValCurs [<!ENTITY a "90">]>'.fiatXml('1','&a;',$eur),fiatXml('3','0,000000000000000001',$eur)];
foreach ($bad as $xml) {
    $p=new CentralBankRateProvider(static fn($url)=>$xml);
    fiatAssert($p->rates(['USD','EUR'])===null,'malformed/missing/duplicate group refused');
}
$p=new CentralBankRateProvider(static fn($url)=>false);
fiatAssert($p->rates(['USD'])===null,'transport failure');
fiatAssert($p->rates([])===[],'empty requested group');
echo json_encode(['status'=>'passed','fiat_checks'=>$checks],JSON_THROW_ON_ERROR)."\n";
