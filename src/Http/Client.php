<?php

namespace HScript\Http;

use HScript\Application;
use CurlHandle;
use Throwable;

/**
 * Provides the shared cURL client with mandatory TLS certificate validation.
 */
final class Client
{
	private static ?self $default = null;

	private CurlHandle|false|null $handle;

	public function __construct(array $config = [])
	{
		if (!function_exists('curl_init'))
		{
			$this->handle = null;
			return;
		}

		$this->handle = curl_init();
		$this->setOption(CURLOPT_CONNECTTIMEOUT, 10);
		$this->setOption(CURLOPT_TIMEOUT, 30);
		$this->setOption(CURLOPT_SSL_VERIFYPEER, true);
		$this->setOption(CURLOPT_SSL_VERIFYHOST, 2);
		$this->setOption(CURLOPT_FOLLOWLOCATION, true);
		$this->setOption(CURLOPT_MAXREDIRS, 5);
		$this->setOption(CURLOPT_RETURNTRANSFER, true);

		if (!empty($config['Sec_ProxyHost']))
		{
			$this->setOption(CURLOPT_PROXY, $config['Sec_ProxyHost']);
			if (!empty($config['Sec_ProxyAuth']))
				$this->setOption(CURLOPT_PROXYUSERPWD, $config['Sec_ProxyAuth']);
		}
	}

	public function __destruct()
	{
		if ($this->handle instanceof CurlHandle)
			curl_close($this->handle);
	}

	public static function request(
		string $url,
		array|string $parameters = [],
		string $cookieFile = '',
		string $userAgent = '',
		bool $headersOnly = false
	): string|bool {
		return self::default()->send($url, $parameters, $cookieFile, $userAgent, $headersOnly);
	}

	public static function lastUrl(): string
	{
		return self::default()->effectiveUrl();
	}

	public static function configure(int $option, mixed $value): bool
	{
		return self::default()->setOption($option, $value);
	}

	public static function info(int $option = 0): mixed
	{
		return self::default()->getInfo($option);
	}

	public function send(
		string $url,
		array|string $parameters = [],
		string $cookieFile = '',
		string $userAgent = '',
		bool $headersOnly = false
	): string|bool {
		if (!$this->handle)
			return false;

		try
		{
			$this->setOption(CURLOPT_URL, trim($url));
			$this->setOption(CURLOPT_HEADER, $headersOnly);
			$this->setOption(CURLOPT_NOBODY, $headersOnly);
			$this->setOption(CURLOPT_USERAGENT, $this->userAgent($userAgent));

			if (!$headersOnly && empty($parameters))
			{
				$this->setOption(CURLOPT_POST, false);
				$this->setOption(CURLOPT_HTTPGET, true);
			}
			elseif (!$headersOnly)
			{
				$this->setOption(CURLOPT_POST, true);
				$this->setOption(CURLOPT_POSTFIELDS, $parameters);
			}

			$this->setOption(CURLOPT_COOKIEFILE, $cookieFile);
			$this->setOption(CURLOPT_COOKIEJAR, $cookieFile);
			$response = curl_exec($this->handle);
			return curl_errno($this->handle) === 0 ? $response : false;
		}
		catch (Throwable)
		{
			return false;
		}
	}

	public function effectiveUrl(): string
	{
		if (!$this->handle)
			return '';
		return (string)curl_getinfo($this->handle, CURLINFO_EFFECTIVE_URL);
	}

	public function getInfo(int $option = 0): mixed
	{
		if (!$this->handle)
			return $option === 0 ? [] : false;
		return $option === 0
			? curl_getinfo($this->handle)
			: curl_getinfo($this->handle, $option);
	}

	private static function default(): self
	{
		if (!self::$default)
		{
			global $_cfg;
			self::$default = new self(is_array($_cfg ?? null) ? $_cfg : []);
		}
		return self::$default;
	}

	private function userAgent(string $userAgent): string
	{
		global $_cfg;
		$userAgent = trim($userAgent);
		if ($userAgent !== '')
			return $userAgent;
		if (!empty($_cfg['HTTP_UserAgent']))
			return trim((string)$_cfg['HTTP_UserAgent']);
		return Application::userAgent();
	}

	private function setOption(int $option, mixed $value): bool
	{
		if (!$this->handle)
			return false;

		$error = null;
		set_error_handler(static function (int $severity, string $message) use (&$error): bool {
			$error = $message;
			return true;
		});
		try
		{
			$result = curl_setopt($this->handle, $option, $value);
		}
		catch (Throwable $exception)
		{
			$error = $exception->getMessage();
			$result = false;
		}
		finally
		{
			restore_error_handler();
		}

		if ($error !== null && function_exists('xAddToLog'))
			\xAddToLog($error, 'curl');
		return $result;
	}
}
