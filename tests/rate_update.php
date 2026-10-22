<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/vendor/autoload.php';
use HScript\Finance\{RateUpdater,CentralBankRateProvider,CoinbaseRateProvider};
$checks=0;
function rateAssert(bool $condition,string $message): void { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; }
function updater(string $usd='90,5',string $eur='98',mixed $crypto='9007199254740993.123456789012345678',array &$requests=[]): RateUpdater {
    return new RateUpdater(new CoinbaseRateProvider(static function($url) use ($crypto,&$requests) {
        $requests[]=$url; parse_str(parse_url($url,PHP_URL_QUERY),$q);
        return json_encode(['data'=>['currency'=>$q['currency'],'rates'=>['USD'=>$crypto,'EUR'=>$crypto,'RUB'=>$crypto]]],JSON_THROW_ON_ERROR);
    }),new CentralBankRateProvider(static function($url) use ($usd,$eur,&$requests) {
        $requests[]=$url;
        return '<ValCurs><Valute><Nominal>1</Nominal><CharCode>USD</CharCode><Value>'.$usd.'</Value></Valute><Valute><Nominal>1</Nominal><CharCode>EUR</CharCode><Value>'.$eur.'</Value></Valute></ValCurs>';
    }));
}
foreach ([['USD',['USD'=>'1','EUR'=>'1.0829','RUB'=>'0.0110']],['EUR',['USD'=>'0.9235','EUR'=>'1','RUB'=>'0.0102']],['RUB',['USD'=>'90.500000000000000000','EUR'=>'98.000000000000000000','RUB'=>'1']],['unsupported',['USD'=>'1','EUR'=>'1.0829','RUB'=>'0.0110']]] as [$base,$expected]) {
    rateAssert(updater()->quotes(['Bal_UpdateRates'=>1],[1=>['cCurrID'=>$base]])===$expected,'base rules');
}
rateAssert(updater('80','90,004')->quotes(['Bal_UpdateRates'=>1],[])['EUR']==='1.1251','HALF_UP four-place tie');
rateAssert(updater()->quotes(['Bal_UpdateRates'=>1],[7=>['cCurrID'=>'EUR']])['USD']==='0.9235','first-row fallback');
foreach (['0,99','0','broken','20000,000001'] as $bad) {
    $u=updater($bad,$bad);
    rateAssert($u->quotes(['Bal_UpdateRates'=>1],[])===[],'invalid fiat group supplies no publishable quotes');
}
$requests=[]; $u=updater('broken','98','123.450000000000000001',$requests);
$quotes=$u->quotes(['Bal_UpdateRates'=>1,'Bal_UpdateBTCRate'=>1],[]);
rateAssert($quotes===['BTC'=>'123.450000000000000001'],'invalid fiat cannot contaminate successful crypto quotes');
rateAssert(count($requests)===2,'only enabled providers requested');
$requests=[]; rateAssert(updater('90','98','1',$requests)->quotes([],[])===[] && $requests===[],'disabled providers never requested');
rateAssert(updater('90','98',1)->quotes(['Bal_UpdateBTCRate'=>1],[])===[],'numeric JSON quote cannot be published');
$quotes=updater()->quotes(['Bal_UpdateRates'=>1,'Bal_UpdateBTCRate'=>1,'Bal_UpdateETHRate'=>1,'Bal_UpdateXRPRate'=>1,'Bal_UpdateLTCRate'=>1],[]);
rateAssert(count($quotes)===7 && count(array_filter($quotes,'is_string'))===7,'all published values are strings');
echo json_encode(['status'=>'passed','rate_update_checks'=>$checks],JSON_THROW_ON_ERROR)."\n";
