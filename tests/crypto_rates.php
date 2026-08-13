<?php

use HScript\Finance\CoinbaseRateProvider;

require dirname(__DIR__) . '/vendor/autoload.php';

function cryptoRateAssert(bool $condition, string $message): void
{
	if (!$condition)
		throw new RuntimeException($message);
}

$provider = new CoinbaseRateProvider(static function (string $url): string {
	cryptoRateAssert(
		$url === 'https://api.coinbase.com/v2/exchange-rates?currency=BTC',
		'Unexpected Coinbase endpoint'
	);
	return json_encode(array(
		'data' => array(
			'currency' => 'BTC',
			'rates' => array('USD' => '65432.10', 'EUR' => '60000.00'),
		),
	), JSON_THROW_ON_ERROR);
});

cryptoRateAssert($provider->rate('btc', 'usd') === 65432.10, 'USD rate was not parsed');
cryptoRateAssert($provider->rate('BTC', 'EUR') === 60000.0, 'Base currency rate was not parsed');

$invalidProvider = new CoinbaseRateProvider(
	static fn(string $url): string => '{"data":{"currency":"ETH","rates":{"USD":"0"}}}'
);
cryptoRateAssert($invalidProvider->rate('ETH', 'USD') === null, 'Invalid zero rate was accepted');

$brokenProvider = new CoinbaseRateProvider(static fn(string $url): string => '{broken');
cryptoRateAssert($brokenProvider->rate('XRP', 'USD') === null, 'Invalid JSON was accepted');

echo "Crypto rate provider tests passed.\n";
