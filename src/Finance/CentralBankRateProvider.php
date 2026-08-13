<?php

namespace HScript\Finance;

use Closure;
use HScript\Http\Client;
use SimpleXMLElement;
use Throwable;

/**
 * Loads official fiat rates from the Central Bank of Russia over verified TLS.
 */
final class CentralBankRateProvider
{
	private const ENDPOINT = 'https://www.cbr.ru/scripts/XML_daily.asp';

	private Closure $request;

	public function __construct(?callable $request = null)
	{
		$this->request = $request !== null
			? Closure::fromCallable($request)
			: static fn(string $url): string|bool => Client::request($url);
	}

	/**
	 * Returns the RUB value of one unit for every requested currency code.
	 *
	 * @return array<string, float>|null
	 */
	public function rates(array $currencies): ?array
	{
		$wanted = array();
		foreach ($currencies as $currency)
		{
			$currency = strtoupper(trim((string)$currency));
			if (preg_match('/^[A-Z]{3}$/', $currency))
				$wanted[$currency] = true;
		}
		if (!$wanted)
			return array();

		$response = ($this->request)(self::ENDPOINT);
		if (!is_string($response) || trim($response) === '')
			return null;

		$previousErrors = libxml_use_internal_errors(true);
		try
		{
			$xml = simplexml_load_string($response, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
		}
		catch (Throwable)
		{
			$xml = false;
		}
		finally
		{
			libxml_clear_errors();
			libxml_use_internal_errors($previousErrors);
		}
		if (!$xml)
			return null;

		$result = array();
		foreach ($xml->Valute as $valute)
		{
			$code = strtoupper(trim((string)$valute->CharCode));
			if (!isset($wanted[$code]))
				continue;
			$nominal = (float)str_replace(',', '.', trim((string)$valute->Nominal));
			$value = (float)str_replace(',', '.', trim((string)$valute->Value));
			if ($nominal <= 0 || $value <= 0)
				return null;
			$result[$code] = $value / $nominal;
		}

		return count($result) === count($wanted) ? $result : null;
	}
}
