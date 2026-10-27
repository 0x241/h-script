<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/vendor/autoload.php';
use HScript\Finance\{CurrencyRateCatalog,CoinbaseRateProvider,RateUpdater,CentralBankRateProvider};
$n=0;function rateCatalogAssert(bool $ok,string $why):void{global$n;if(!$ok)throw new RuntimeException($why);++$n;}
$catalog=CurrencyRateCatalog::currencies();
foreach(['USD','EUR','RUB','GBP','NGN','JPY','USDT','USDC','BNB','DASH','DOGE','BCH','MATIC','TRX','TON','SOL']as$code)rateCatalogAssert(isset($catalog[$code]),'reviewed currency '.$code);
rateCatalogAssert(!isset($catalog['DAI']) && !isset($catalog['']),'arbitrary ticker never enables a rate or exchange');
$calls=0;$client=new CoinbaseRateProvider(static function($url)use(&$calls){++$calls;return json_encode(['data'=>['currency'=>'USD','rates'=>['USD'=>'1','BNB'=>'0.002','USDT'=>'0.9999','SOL'=>'0.01','NGN'=>'1500','BAD'=>0.1,'ZERO'=>'0']]],JSON_THROW_ON_ERROR);});
$q=$client->rates(['USD','BNB','USDT','SOL','NGN','BAD','ZERO']);
rateCatalogAssert($calls===1 && $q===['USD'=>'1','BNB'=>'500.000000000000000000','USDT'=>'1.000100010001000100','SOL'=>'100.000000000000000000','NGN'=>'0.000666666666666667'],'exact reciprocal market quotes and invalid quote omission');
$u=new RateUpdater($client,new CentralBankRateProvider(static fn()=>false));$before=$calls;
$q=$u->quotes(['Bal_UpdateBNBRate'=>1,'Bal_UpdateUSDCRate'=>1,'Bal_UpdateNGNRate'=>1],[1=>['cCurrID'=>'USD']]);
rateCatalogAssert($calls===$before+1 && array_keys($q)===['BNB','NGN'],'one request for all extra enabled currencies, unavailable quote omitted');
$before=$calls;rateCatalogAssert($u->quotes([],[])===[] && $calls===$before,'disabled extended quotes do not contact provider');
echo "Currency rate catalog: $n checks passed\n";
