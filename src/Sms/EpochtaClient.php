<?php

namespace HScript\Sms;

use Closure;
use HScript\Http\Client;
use RuntimeException;
use Throwable;

/**
 * Minimal ePochta/AtomPark SMS API v3 client for single-message campaigns.
 */
final class EpochtaClient
{
	public const BASE_URL = 'https://api.atompark.com/sms/3.0/';

	private string $publicKey;
	private string $privateKey;
	private Closure $request;

	public function __construct(string $publicKey, string $privateKey, ?callable $request = null)
	{
		$this->publicKey = trim($publicKey);
		$this->privateKey = trim($privateKey);
		$this->request = $request !== null
			? Closure::fromCallable($request)
			: static fn(string $url, array $parameters): string|bool => Client::request($url, $parameters);
	}

	/**
	 * Sends one SMS and returns its campaign identifier and price.
	 */
	public function send(string $sender, string $text, string $phone, bool $test = false): array
	{
		$params = array(
			'sender' => trim($sender),
			'text' => $text,
			'phone' => preg_replace('/\D+/', '', $phone),
			'datetime' => '',
			'sms_lifetime' => 0,
		);
		if ($test)
			$params['test'] = 1;

		$result = $this->call('sendSMS', $params);
		$id = trim((string)($result['id'] ?? ''));
		if ($id === '')
			throw new RuntimeException('ePochta response does not contain a campaign ID');

		return array(
			'id' => $id,
			'price' => is_numeric($result['price'] ?? null) ? (float)$result['price'] : 0.0,
		);
	}

	/**
	 * Fetches delivery state for single-recipient campaigns.
	 */
	public function deliveryStatuses(array $campaignIds): array
	{
		$statuses = array();
		foreach ($campaignIds as $campaignId)
		{
			$campaignId = trim((string)$campaignId);
			if ($campaignId === '')
				continue;
			$result = $this->call('getCampaignDeliveryStats', array('id' => $campaignId));
			$status = $result['status'] ?? '0';
			if (is_array($status))
				$status = reset($status);
			$statuses[$campaignId] = self::normalizeStatus((string)$status);
		}
		return $statuses;
	}

	/**
	 * Builds the checksum defined by API v3 documentation.
	 */
	public static function checksum(
		string $action,
		array $parameters,
		string $publicKey,
		string $privateKey
	): string {
		unset($parameters['userapp']);
		$signature = array_merge($parameters, array(
			'action' => $action,
			'key' => $publicKey,
			'version' => '3.0',
		));
		ksort($signature, SORT_STRING);
		$values = '';
		foreach ($signature as $value)
			$values .= is_bool($value) ? (string)(int)$value : (string)$value;
		return md5($values . $privateKey);
	}

	private function call(string $action, array $parameters): array
	{
		if ($this->publicKey === '' || $this->privateKey === '')
			throw new RuntimeException('ePochta API v3 public/private keys are not configured');

		$post = $parameters;
		$post['key'] = $this->publicKey;
		$post['sum'] = self::checksum($action, $parameters, $this->publicKey, $this->privateKey);
		try
		{
			$response = ($this->request)(self::BASE_URL . $action, $post);
		}
		catch (Throwable $exception)
		{
			throw new RuntimeException('ePochta request failed: ' . $exception->getMessage(), 0, $exception);
		}
		if ($response === false || trim((string)$response) === '')
			throw new RuntimeException('ePochta request failed without a response');

		try
		{
			$data = json_decode((string)$response, true, 512, JSON_THROW_ON_ERROR);
		}
		catch (Throwable $exception)
		{
			throw new RuntimeException('ePochta returned invalid JSON', 0, $exception);
		}
		if (!is_array($data))
			throw new RuntimeException('ePochta returned an invalid response');
		if (array_key_exists('error', $data))
		{
			$code = trim((string)($data['code'] ?? ''));
			$message = trim(strip_tags((string)$data['error']));
			throw new RuntimeException('ePochta error' . ($code !== '' ? " $code" : '') . ($message !== '' ? ": $message" : ''));
		}
		if (!isset($data['result']) || !is_array($data['result']))
			throw new RuntimeException('ePochta response does not contain a result');
		return $data['result'];
	}

	private static function normalizeStatus(string $status): string
	{
		$status = strtoupper(trim($status));
		if ($status === 'DELIVERED')
			return 'OK';
		if ($status === '' || $status === '0')
			return 'PENDING';
		return $status;
	}
}
