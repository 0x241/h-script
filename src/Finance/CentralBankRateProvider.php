<?php

namespace HScript\Finance;

use Closure;
use HScript\Http\Client;
use SimpleXMLElement;
use Throwable;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

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
	 * Per-unit division is explicitly rounded HALF_UP to 18 decimal places.
	 * @return array<string, string>|null
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
		if (!is_string($response) || trim($response) === '' || stripos($response, '<!DOCTYPE') !== false)
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
		if (!$xml || $xml->getName() !== 'ValCurs')
			return null;

		$result = array();
		foreach ($xml->Valute as $valute)
		{
			$code = strtoupper(trim((string)$valute->CharCode));
			if (!isset($wanted[$code]))
				continue;
			if (isset($result[$code]) || count($valute->CharCode) !== 1
				|| count($valute->Nominal) !== 1 || count($valute->Value) !== 1
				|| count($valute->Nominal->children()) || count($valute->Value->children()))
				return null;
			$nominal = trim((string)$valute->Nominal);
			if (!preg_match('/^[0-9]{1,36}$/D', $nominal)) return null;
			try {
				$divisor = Rate::of($nominal);
				$value = Rate::of(str_replace(',', '.', trim((string)$valute->Value)));
				$unit = $divisor->divide($value->decimal(), 18, RoundingMode::HalfUp);
				$result[$code] = (string)Rate::of((string)$unit)->decimal();
			} catch (InvalidArgumentException) { return null; }
		}

		return count($result) === count($wanted) ? $result : null;
	}
}
