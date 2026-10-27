<?php

namespace HScript\Finance;

use Closure;

use JsonException;
use InvalidArgumentException;

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
			: self::load(...);
	}

	/**
	 * Returns the value of one cryptocurrency unit in the requested base currency.
	 */
	public function rate(string $currency, string $baseCurrency = 'USD'): ?string
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
			|| !is_string($payload['data']['currency'] ?? null)
			|| strtoupper($payload['data']['currency']) !== $currency
			|| !is_array($payload['data']['rates'] ?? null)
		)
			return null;

		$value = $payload['data']['rates'][$baseCurrency] ?? null;
		// API decimals are strings; decoding JSON numbers can lose precision.
		if (!is_string($value))
			return null;
		try { return (string)Rate::of($value)->decimal(); }
		catch (InvalidArgumentException) { return null; }
	}
    /** Fixed TLS destination, bounded streaming body, no redirects or retries. */
    private static function load(string $url): string|false
    {
        if (!function_exists('curl_init') || !str_starts_with($url, self::ENDPOINT . '?currency=')) return false;
        $handle = curl_init($url);
        if ($handle === false) return false;
        $body = '';
        try {
            $options = [CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT_MS => 1500, CURLOPT_TIMEOUT_MS => 4000,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
                CURLOPT_WRITEFUNCTION => static function ($handle, string $part) use (&$body): int {
                    if (strlen($body) + strlen($part) > 1048576) return 0;
                    $body .= $part;
                    return strlen($part);
                }];
            global $_cfg;
            if (!empty($_cfg['Sec_ProxyHost'])) {
                $options[CURLOPT_PROXY] = $_cfg['Sec_ProxyHost'];
                if (!empty($_cfg['Sec_ProxyAuth'])) $options[CURLOPT_PROXYUSERPWD] = $_cfg['Sec_ProxyAuth'];
            }
            curl_setopt_array($handle, $options);
            return curl_exec($handle) !== false && curl_getinfo($handle, CURLINFO_RESPONSE_CODE) === 200 ? $body : false;
        } catch (\Throwable) { return false; }
        finally { curl_close($handle); }
    }

    /** One bounded request for additional currencies; response is units per base. */
    public function rates(array $currencies,string $baseCurrency='USD'): array
    {
        if(!preg_match('/^[A-Z0-9]{2,10}$/D',$baseCurrency))return [];
        $raw=($this->request)(self::ENDPOINT.'?currency='.rawurlencode($baseCurrency));
        if(!is_string($raw)||strlen($raw)>1048576)return [];
        try{$p=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(JsonException){return [];}
        if(($p['data']['currency']??null)!==$baseCurrency || !is_array($p['data']['rates']??null))return [];
        $result=[];
        foreach($currencies as$code){
            if(!is_string($code)||!preg_match('/^[A-Z0-9]{2,10}$/D',$code))continue;
            if($code===$baseCurrency){$result[$code]='1';continue;}
            $value=$p['data']['rates'][$code]??null;if(!is_string($value)||strlen($value)>100)continue;
            try{$rate=Rate::of($value);$result[$code]=(string)Rate::of((string)$rate->divide(Decimal::of(1),18,\Brick\Math\RoundingMode::HalfUp))->decimal();}
            catch(InvalidArgumentException|\Brick\Math\Exception\MathException){continue;}
        }
        return $result;
    }

}
