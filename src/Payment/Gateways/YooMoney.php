<?php

namespace HScript\Payment\Gateways;

use HScript\Payment\AbstractGateway;
use HScript\Payment\GatewayAmount;
use HScript\Payment\GatewayBalance;
use HScript\Payment\CallbackResult;
use HScript\Payment\FormCallbackPayload;
use HScript\Finance\RequestDecimal;
use DateTimeImmutable;
use InvalidArgumentException;
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
		if (!is_string($sci['key'] ?? null) || trim($sci['key'])==='') return ['error'=>'callback_configuration_missing'];
		$result = array(
			'url' => 'https://yoomoney.ru/quickpay/confirm.xml',
			'receiver' => $this->value($sci, 'acc'),
			'sum' => GatewayAmount::exact($this->value($params,'sum'),$this->getCurrencyId(),2),
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
		if (!\HScript\Payment\CallbackCapabilities::allows($this->id,'in'))
			return CallbackResult::failure('unsupported','automatic_callback_unsupported');
		if (!is_string($config['key'] ?? null) || trim($config['key'])==='')
			return CallbackResult::failure('authentication_failed','callback_configuration_missing');
		try {
			if (array_key_exists('_raw_body', $request)) $payload = FormCallbackPayload::decode($request['_raw_body']);
			else {
				$payload = array_diff_key($request, array_flip(['_config','_json','_server']));
				foreach ($payload as $value) if (!is_string($value)) throw new InvalidArgumentException();
			}
		} catch (InvalidArgumentException) { return CallbackResult::failure('invalid_payload', 'callback_fields_invalid'); }
		$provided = $payload['sign'] ?? '';
		if (!preg_match('/^[a-f0-9]{64}$/D', $provided))
			return CallbackResult::failure('authentication_failed', 'signature_missing_or_invalid');
		unset($payload['sign']);
		ksort($payload, SORT_STRING);
		$canonical = [];
		foreach ($payload as $key=>$value) $canonical[] = $key . '=' . rawurlencode($value);
		$expected = hash_hmac('sha256', implode('&', $canonical), (string)$this->value($config, 'key'));
		if (!hash_equals($expected, $provided)) return CallbackResult::failure('authentication_failed', 'signature_invalid');
		foreach (['notification_type','operation_id','amount','withdraw_amount','currency','datetime','sender','codepro','label','unaccepted'] as $field)
			if (!array_key_exists($field, $payload)) return CallbackResult::failure('invalid_payload', 'callback_field_missing', true);
		if ($payload['currency'] !== '643') return CallbackResult::failure('invalid_payload', 'currency_mismatch', true);
		if ($payload['notification_type'] !== ($this->id === 'YM' ? 'p2p-incoming' : 'card-incoming'))
			return CallbackResult::failure('invalid_payload', 'payment_type_mismatch', true);
		if ($payload['operation_id']==='' || preg_match('/[\x00-\x1f\x7f]/',$payload['operation_id']))
			return CallbackResult::failure('invalid_payload','callback_transaction_id_invalid',true);
		if (strlen($payload['operation_id'])>39)
			return CallbackResult::failure('unsupported','callback_transaction_id_unsupported',true);
		if (!in_array($payload['codepro'], ['true','false'], true) || !in_array($payload['unaccepted'], ['true','false'], true)
			|| !in_array($payload['test_notification'] ?? 'false', ['true','false'], true))
			return CallbackResult::failure('invalid_payload', 'callback_boolean_invalid', true);
		if (($payload['test_notification'] ?? 'false') === 'true') return CallbackResult::failure('rejected', 'test_notification', true);
		if ($payload['codepro'] === 'true') return CallbackResult::failure('rejected', 'protected_payment', true);
		try {
			foreach (['amount','withdraw_amount'] as $field)
				if (!preg_match('/^\d{1,12}(?:\.\d{1,2})?$/D', $payload[$field])) throw new InvalidArgumentException();
			$sum = RequestDecimal::normalize($payload['withdraw_amount']);
			RequestDecimal::normalize($payload['amount']);
			if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $payload['datetime'])) throw new InvalidArgumentException();
			$date = new DateTimeImmutable($payload['datetime']);
			$errors = DateTimeImmutable::getLastErrors();
			if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) throw new InvalidArgumentException();
		} catch (\Exception) { return CallbackResult::failure('invalid_payload', 'callback_amount_or_date_invalid', true); }
		$pending = $payload['unaccepted'] === 'true';
		return [
			'outcome'=>$pending ? 'pending' : 'verified', 'reason'=>$pending ? 'payment_held' : 'signature_verified',
			'authenticated'=>true, 'correct'=>true, 'wait'=>$pending,
			'accfrom'=>$this->id === 'YM' ? $payload['sender'] : '', 'sum'=>$sum, 'sum2'=>$sum,
            'curr'=>'RUB', 'tag'=>$payload['label'], 'batch'=>$payload['operation_id'], 'date'=>$date->getTimestamp(),
            'signed_at'=>$date->getTimestamp(), 'accto'=>(string)$this->value($config, 'acc'),
			'profile_key_hash'=>hash('sha256',$config['key']),'profile_timezone'=>'',
		];
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
				return array('result' => 'OK', 'sum' => GatewayBalance::read($info['balance']));
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
			$amount=GatewayAmount::exact($this->value($params,'sum'),$this->getCurrencyId(),2);
			$this->requireLegacy('lib/yandexmoney/api.php');
			$api = new \YandexMoney\API($this->value($from, 'apipass'));
			$requested = $api->requestPayment(array(
				'pattern_id' => 'p2p',
				'to' => $this->value($to, 'acc'),
				'amount_due' => $amount,
				'comment' => $this->value($params, 'memo'),
				'message' => $this->value($params, 'memo'),
			));
			if (($requested->status ?? null)!=='success' || !is_string($requested->request_id ?? null) || $requested->request_id==='')
				return ['result'=>'payment_request_failed'];
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
