<?php

use HScript\Finance\CentralBankRateProvider;

require dirname(__DIR__) . '/vendor/autoload.php';

$provider = new CentralBankRateProvider(static function (string $url): string {
	if ($url !== 'https://www.cbr.ru/scripts/XML_daily.asp')
		throw new RuntimeException('Central Bank endpoint is not HTTPS');
	return '<?xml version="1.0"?><ValCurs>'
		. '<Valute><Nominal>1</Nominal><CharCode>USD</CharCode><Value>90,5000</Value></Valute>'
		. '<Valute><Nominal>100</Nominal><CharCode>EUR</CharCode><Value>9800,0000</Value></Valute>'
		. '</ValCurs>';
});
$rates = $provider->rates(array('USD', 'EUR'));
if ($rates !== array('USD' => 90.5, 'EUR' => 98.0))
	throw new RuntimeException('Central Bank rates were parsed incorrectly');

$invalid = new CentralBankRateProvider(static fn(string $url): string => '<broken>');
if ($invalid->rates(array('USD')) !== null)
	throw new RuntimeException('Invalid Central Bank XML was accepted');

echo "Fiat rate provider tests passed.\n";
