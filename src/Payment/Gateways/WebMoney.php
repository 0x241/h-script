<?php

namespace HScript\Payment\Gateways;

use HScript\Payment\AbstractGateway;

/**
 * Implements WebMoney deposit forms and signed callback validation.
 */
class WebMoney extends AbstractGateway
{
	public function getFields(string $type): array
	{
		if ($type === 'pay')
			return array(
				'WMID' => array('WMID', '', '123456789012'),
				'acc' => array('WMZ wallet number', 'Z\d{12}', 'Z123456789012'),
			);
		if ($type === 'sci')
			return array('key' => array('Secret Key'));
		return array();
	}

	public function processDeposit(array $params): array
	{
		$pay = (array)$this->value($params, 'pay', array());
		return array(
			'url' => 'https://merchant.webmoney.ru/lmi/payment.asp',
			'LMI_PAYEE_PURSE' => $this->value($pay, 'acc'),
			'LMI_PAYMENT_AMOUNT' => $this->normalizedAmount($this->value($params, 'sum', 0)),
			'LMI_PAYMENT_DESC' => $this->value($params, 'memo'),
			'LMI_PAYMENT_DESC_BASE64' => base64_encode((string)$this->value($params, 'memo')),
			'LMI_PAYMENT_NO' => $this->value($params, 'tag'),
			'LMI_SUCCESS_URL' => $this->value($params, 'url_ok'),
			'LMI_SUCCESS_METHOD' => 'POST',
			'LMI_FAIL_URL' => $this->value($params, 'url_fail'),
			'LMI_FAIL_METHOD' => 'POST',
			'LMI_RESULT_URL' => $this->value($params, 'url_callback'),
		);
	}

	public function handleCallback(array $request): array
	{
		if (!empty($request['LMI_PREREQUEST']))
			return array('handled' => true, 'response' => 'YES');

		$config = (array)$this->value($request, '_config', array());
		$date = (string)$this->value($request, 'LMI_SYS_TRANS_DATE');
		$timestamp = gmmktime(
			(int)substr($date, 9, 2),
			(int)substr($date, 12, 2),
			(int)substr($date, 15, 2),
			(int)substr($date, 4, 2),
			(int)substr($date, 6, 2),
			(int)substr($date, 0, 4)
		);
		$result = array(
			'accto' => $this->value($request, 'LMI_PAYEE_PURSE'),
			'accfrom' => $this->value($request, 'LMI_PAYER_PURSE'),
			'accwmid' => $this->value($request, 'LMI_PAYER_WM'),
			'paymer' => $this->value($request, 'LMI_PAYMER_NUMBER'),
			'euronote' => $this->value($request, 'LMI_EURONOTE_NUMBER'),
			'atm' => $this->value($request, 'LMI_CASHIER_ATMNUMBERINSIDE'),
			'sum' => $this->value($request, 'LMI_PAYMENT_AMOUNT'),
			'sum2' => $this->value($request, 'LMI_PAYMENT_AMOUNT'),
			'tag' => $this->value($request, 'LMI_PAYMENT_NO'),
			'date' => $timestamp,
			'batch' => $this->value($request, 'LMI_SYS_TRANS_NO'),
			'testmode' => $this->value($request, 'LMI_MODE'),
			'hash' => $this->value($request, 'LMI_HASH'),
		);
		if (!$result['testmode'])
		{
			$signature = strtoupper(hash('sha256', implode('', array(
				$result['accto'],
				$result['sum'],
				$result['tag'],
				$result['testmode'],
				$this->value($request, 'LMI_SYS_INVS_NO'),
				$result['batch'],
				$date,
				$this->value($config, 'key'),
				$result['accfrom'],
				$result['accwmid'],
			))));
			$result['cc'] = $signature;
			$result['correct'] = hash_equals($signature, (string)$result['hash']);
		}
		return $result;
	}

	protected function requiredConfigKeys(): array
	{
		return array('key');
	}
}
