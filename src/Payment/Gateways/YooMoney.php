<?php

namespace HScript\Payment\Gateways;

use HScript\Payment\AbstractGateway;
use Throwable;

/**
 * Implements YooMoney deposits, notifications, balances, and payouts.
 */
class YooMoney extends AbstractGateway
{
	public function getFields(string $type): array
	{
		if ($type === 'pay')
			return $this->id === 'YMC'
				? array('acc' => array('Card number', '\d{16}', '1111222233334444'))
				: array('acc' => array('Account number', '\d{13,16}', '410111222333444'));
		if ($type === 'sci')
			return array(
				'acc' => array('Account number', '\d{13,16}', '410111222333444'),
				'key' => array('Secret word'),
				'_url' => array('Status URL'),
			);
		if ($type === 'api')
			return array(
				'id' => array('Client ID'),
				'secretpass' => array('Client secret'),
				'apipass' => array('Access token'),
			);
		return array();
	}

	public function processDeposit(array $params): array
	{
		$sci = (array)$this->value($params, 'sci', array());
		$result = array(
			'url' => 'https://yoomoney.ru/quickpay/confirm.xml',
			'receiver' => $this->value($sci, 'acc'),
			'sum' => $this->normalizedAmount($this->value($params, 'sum', 0)),
			'writable-sum' => 'false',
			'formcomment' => $this->value($params, 'memo'),
			'short-dest' => $this->value($params, 'memo'),
			'targets' => $this->value($params, 'memo'),
			'writable-targets' => 'false',
			'label' => $this->value($params, 'tag'),
			'quickpay-form' => 'shop',
			'fio' => 0,
			'mail' => 0,
			'phone' => 0,
			'address' => 0,
		);
		if ($this->id === 'YMC')
			$result['paymentType'] = 'AC';
		return $result;
	}

	public function handleCallback(array $request): array
	{
		$config = (array)$this->value($request, '_config', array());
		$isCard = $this->value($request, 'notification_type') === 'card-incoming';
		$commission = $isCard ? 0.02 : 0.005;
		$sum = round((float)$this->value($request, 'amount') * (1 + $commission), 2);
		$result = array(
			'accfrom' => $this->id === 'YM' ? $this->value($request, 'sender') : '',
			'sum' => $sum,
			'sum2' => $sum,
			'curr' => $this->value($request, 'currency'),
			'tag' => $this->value($request, 'label'),
			'date' => time(),
			'batch' => $this->value($request, 'operation_id'),
			'testmode' => $this->value($request, 'test_notification'),
			'hash' => $this->value($request, 'sha1_hash'),
		);
		if (!$result['testmode'] && $this->value($request, 'codepro') === 'false')
		{
			$signature = strtolower(sha1(implode('&', array(
				$this->value($request, 'notification_type'),
				$this->value($request, 'operation_id'),
				$this->value($request, 'amount'),
				$this->value($request, 'currency'),
				$this->value($request, 'datetime'),
				$this->value($request, 'sender'),
				$this->value($request, 'codepro'),
				$this->value($config, 'key'),
				$this->value($request, 'label'),
			))));
			$result['cc'] = $signature;
			$result['correct'] = hash_equals($signature, (string)$result['hash']);
		}
		return $result;
	}

	public function getBalance(array $config): array
	{
		if ($this->id !== 'YM')
			return parent::getBalance($config);
		try
		{
			$this->requireLegacy('lib/yandexmoney/api.php');
			$api = new \YandexMoney\API($this->value($config, 'apipass'));
			$info = (array)$api->accountInfo();
			if (isset($info['balance']))
				return array('result' => 'OK', 'sum' => $info['balance']);
		}
		catch (Throwable $error)
		{
			return $this->errorResult($error) + array('sum' => -1);
		}
		return array('result' => 'NoConn', 'sum' => -1);
	}

	public function processWithdrawal(array $params): array
	{
		if ($this->id !== 'YM')
			return parent::processWithdrawal($params);
		$from = (array)$this->value($params, 'from', array());
		$to = (array)$this->value($params, 'to', array());
		try
		{
			$this->requireLegacy('lib/yandexmoney/api.php');
			$api = new \YandexMoney\API($this->value($from, 'apipass'));
			$requested = $api->requestPayment(array(
				'pattern_id' => 'p2p',
				'to' => $this->value($to, 'acc'),
				'amount_due' => (float)$this->value($params, 'sum'),
				'comment' => $this->value($params, 'memo'),
				'message' => $this->value($params, 'memo'),
			));
			$processed = $api->processPayment(array('request_id' => $requested->request_id));
			if ($processed->status === 'success' && $processed->payment_id)
				return array('result' => 'OK', 'batch' => $processed->payment_id);
			return array('result' => json_encode($processed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'payment_failed');
		}
		catch (Throwable $error)
		{
			return $this->errorResult($error);
		}
	}

	protected function requiredConfigKeys(): array
	{
		return array('key');
	}
}
