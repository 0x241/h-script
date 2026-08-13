<?php

use HScript\Finance\CoinbaseRateProvider;
use HScript\Finance\CentralBankRateProvider;

useLib('balance');


if (stampToTime($_cfg['Bal_LastUpdate']) + HS2_UNIX_HOUR > time())
	return;

$ratesUpdated = false;
$baseCurrency = 'USD';
$baseCurrencyRow = isset($_currs[1]) && is_array($_currs[1])
	? $_currs[1]
	: (is_array($_currs) && $_currs ? reset($_currs) : array());
if (is_array($baseCurrencyRow))
{
	$candidate = strtoupper(trim((string)($baseCurrencyRow['cCurrID'] ?? '')));
	if (in_array($candidate, array('USD', 'EUR', 'RUB'), true))
		$baseCurrency = $candidate;
}
$cryptoRates = new CoinbaseRateProvider();
foreach (array('BTC', 'ETH', 'XRP', 'LTC') as $curr)
	if (!empty($_cfg["Bal_Update{$curr}Rate"]))
	{
		$value = $cryptoRates->rate($curr, $baseCurrency);
		if ($value !== null)
		{
			opWriteCfg('Bal', "Rate{$curr}", $value);
			$ratesUpdated = true;
		}
	}

$currlist = array('USD', 'EUR');

if ($_cfg['Bal_UpdateRates'])
	if (($c = (new CentralBankRateProvider())->rates($currlist)) && count($c) === count($currlist))
	{
		try
		{
			foreach ($currlist as $v)
				if ($c[$v] < 1)
					xAbort('course_wrong');
			switch ($baseCurrency)
			{
			case 'EUR':
				$c = array(
					'USD' => round($c['USD'] / $c['EUR'], 4),
					'EUR' => 1,
					'RUB' => round(1 / $c['EUR'], 4)
				);
				break;
			case 'RUB':
				$c = array(
					'USD' => $c['USD'],
					'EUR' => $c['EUR'],
					'RUB' => 1
				);
				break;
			default:
				$c = array(
					'USD' => 1,
					'EUR' => round($c['EUR'] / $c['USD'], 4),
					'RUB' => round(1 / $c['USD'], 4)
				);
			}
			foreach ($c as $f => $v)
				opWriteCfg('Bal', 'Rate' . $f, $v);
			$ratesUpdated = true;
		}
		catch (FormAbortException $e)
{
}
	}

if ($ratesUpdated)
	opWriteCfg('Bal', 'LastUpdate', timeToStamp());

?>
