<?php

namespace HScript\Finance;

use Closure;
use HScript\Http\Client;
use JsonException;

/**
 * Loads keyless cryptocurrency exchange rates from Coinbase Data API.
 */
final class CoinbaseRateProvider
{
	private const ENDPOINT = 'https://api.coinbase.com/v2/exchange-rates';

	private Closure $request;

	public function __construct(?callable $request = null)
	{
		$this->request = $request !== null
			? Closure::fromCallable($request)
			: static fn(string $url): string|bool => Client::request($url);
	}

	/**
	 * Returns the value of one cryptocurrency unit in the requested base currency.
	 */
	public function rate(string $currency, string $baseCurrency = 'USD'): ?float
	{
		$currency = strtoupper(trim($currency));
		$baseCurrency = strtoupper(trim($baseCurrency));
		if (
			!preg_match('/^[A-Z0-9]{2,10}$/', $currency)
			|| !preg_match('/^[A-Z0-9]{2,10}$/', $baseCurrency)
		)
			return null;

		$response = ($this->request)(
			self::ENDPOINT . '?currency=' . rawurlencode($currency)
		);
		if (!is_string($response) || trim($response) === '')
			return null;

		try
		{
			$payload = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
		}
		catch (JsonException)
		{
			return null;
		}

		if (
			!is_array($payload)
			|| !is_array($payload['data'] ?? null)
			|| strtoupper((string)($payload['data']['currency'] ?? '')) !== $currency
		)
			return null;

		$value = $payload['data']['rates'][$baseCurrency] ?? null;
		if (!is_numeric($value) || (float)$value <= 0)
			return null;
		return (float)$value;
	}
}
