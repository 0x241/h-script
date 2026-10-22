<?php

namespace HScript\Payment\Gateways;

use HScript\Payment\AbstractGateway;
use HScript\Payment\GatewayAmount;
use HScript\Payment\GatewayBalance;
use HScript\Payment\CallbackResult;
use HScript\Payment\FormCallbackPayload;
use HScript\Finance\RequestDecimal;
use InvalidArgumentException;
use Throwable;

/**
 * Implements CoinPayments invoices, callbacks, balances, and withdrawals.
 */
class CoinPayments extends AbstractGateway
{
	public function getFields(string $type): array
	{
		if ($type === 'pay')
		{
			if ($this->id === 'CP')
				return array('acc' => array('Bitcoin-address', '[1-9A-Za-z]{27,34}'));
			if ($this->id === 'CPE')
				return array('acc' => array('Ether-address', '', '0x...'));
			if ($this->id === 'CPL')
				return array('acc' => array('Litecoin-address'));
			return array(
				'acc' => array('Ripple-address'),
				'tag' => array('Destination TAG'),
			);
		}
		if ($type === 'sci')
			return array(
				'id' => array('Merchant ID'),
				'key' => array('IPN Secret'),
			);
		if ($type === 'api')
			return array(
				'publicpass' => array('Public Key'),
				'apipass' => array('Private Key'),
			);
		return array();
	}

	public function processDeposit(array $params): array
	{
		$sci = (array)$this->value($params, 'sci', array());
		if (!is_string($sci['key'] ?? null) || trim($sci['key'])==='') return ['error'=>'callback_configuration_missing'];
		return array(
			'url' => 'https://legacy.coinpayments.net/index.php',
			'cmd' => '_pay_simple',
			'reset' => '1',
			'merchant' => $this->value($sci, 'id'),
			'item_name' => $this->value($params, 'memo'),
			'invoice' => $this->value($params, 'tag'),
			'currency' => $this->definition[4],
			'amountf' => GatewayAmount::exact($this->value($params,'sum'),$this->getCurrencyId(),8),
			'want_shipping' => '0',
			'success_url' => $this->value($params, 'url_ok'),
			'cancel_url' => $this->value($params, 'url_fail'),
			'ipn_url' => $this->value($params, 'url_callback') . '?its=' . $this->id,
		);
	}

	public function handleCallback(array $request): array
	{
		$config = (array)$this->value($request, '_config', array());
		if (!\HScript\Payment\CallbackCapabilities::allows($this->id,'in'))
			return CallbackResult::failure('unsupported','automatic_callback_unsupported');
		if (!is_string($config['key'] ?? null) || trim($config['key'])==='')
			return CallbackResult::failure('authentication_failed','callback_configuration_missing');
		$hmac = $this->server($request, 'HTTP_HMAC');
		$raw = $request['_raw_body'] ?? '';
		if (!is_string($raw) || $raw==='' || !preg_match('/^[a-f0-9]{128}$/D',$hmac))
			return CallbackResult::failure('authentication_failed','signature_missing_or_invalid');
		$expected = hash_hmac('sha512', $raw, (string)$this->value($config, 'key'));
		if (!hash_equals($expected, $hmac))
			return CallbackResult::failure('authentication_failed','signature_invalid');
		try { $request = FormCallbackPayload::decode($raw); }
		catch (InvalidArgumentException) { return CallbackResult::failure('invalid_payload', 'callback_fields_invalid', true); }
		if (empty($config['id']) || !hash_equals((string)$config['id'], $this->value($request, 'merchant')))
			return CallbackResult::failure('authentication_failed', 'merchant_mismatch');
		if ($this->value($request, 'ipn_mode') !== 'hmac') return CallbackResult::failure('invalid_payload', 'ipn_mode_invalid', true);
		if ($this->value($request,'ipn_version')!=='1.0') return CallbackResult::failure('unsupported','coinpayments_protocol_unsupported',true);
		$notification=$this->value($request,'ipn_id');
		if (!is_string($notification) || $notification==='' || strlen($notification)>256 || preg_match('/[\x00-\x1f\x7f]/',$notification))
			return CallbackResult::failure('invalid_payload','callback_notification_id_invalid',true);
		if (!in_array($this->value($request, 'ipn_type'), ['simple','button','api'], true))
			return CallbackResult::failure('unsupported', 'ipn_type_unsupported', true);
		$transaction=$this->value($request,'txn_id');
		if (!is_string($transaction) || !preg_match('/^[A-Za-z0-9-]{1,128}$/D',$transaction))
			return CallbackResult::failure('invalid_payload','callback_transaction_id_invalid',true);
		// Legacy receipt adds a pending marker even on immediate confirmation.
		if (strlen($transaction)>39)
			return CallbackResult::failure('unsupported','callback_transaction_id_unsupported',true);
		if ($this->value($request, 'currency1') !== $this->getCurrencyId())
			return CallbackResult::failure('invalid_payload', 'currency_mismatch', true);
		$statusWire=$this->value($request,'status');
		$status = is_string($statusWire) && preg_match('/^(?:0|-?[1-9][0-9]{0,9})$/D',$statusWire) ? filter_var($statusWire,FILTER_VALIDATE_INT) : false;
		if ($status === false) return CallbackResult::failure('invalid_payload', 'payment_status_invalid', true);
		try {
			$money=$this->value($request,'amount1');
			if (!is_string($money) || !preg_match('/^[0-9]{1,12}(?:\.[0-9]{1,8})?$/D',$money)) throw new InvalidArgumentException();
			$amount = RequestDecimal::normalize($money);
		}
		catch (InvalidArgumentException) { return CallbackResult::failure('invalid_payload', 'callback_amount_invalid', true); }
		if ($status < 0) return CallbackResult::failure('rejected', 'gateway_rejected', true) + ['response'=>'IPN OK'];
		$pending = $status < 100 && $status !== 2;
		$result = array(
			'sum' => $amount,
            'curr' => $this->value($request, 'currency1'),
            'accto' => $this->value($request, 'merchant'),
			'tag' => $this->value($request, 'invoice'),
			'date' => time(),
			'status' => $status,
			'batch' => $this->value($request, 'txn_id'),
			'response' => 'IPN OK',
			'outcome' => $pending ? 'pending' : 'verified',
			'authenticated' => true,
			'profile_key_hash'=>hash('sha256',$config['key']),'profile_timezone'=>'',
			'wait' => $pending,
		);
		$result['correct'] = true;
		return $result;
	}

	public function getBalance(array $config): array
	{
		try
		{
			$this->requireLegacy('lib/cp/coinpayments.php');
			$client = new \CoinPaymentsAPI();
			$client->Setup($this->value($config, 'apipass'), $this->value($config, 'publicpass'));
			$response = $client->GetBalances();
			if ($response['error'] === 'ok')
				return array('result' => 'OK', 'sum' => GatewayBalance::read($response['result'][$this->definition[4]]['balancef']));
			return array('result' => $response['error'], 'sum' => -1);
		}
		catch (Throwable $error)
		{
			return $this->errorResult($error) + array('sum' => -1);
		}
	}

	public function processWithdrawal(array $params): array
	{
		$from = (array)$this->value($params, 'from', array());
		$to = (array)$this->value($params, 'to', array());
		if ($this->id === 'CP' && !preg_match('/[1-9A-Za-z]{27,34}/', (string)$this->value($to, 'acc')))
			return array('result' => 'NoConn');
		try
		{
			$amount=GatewayAmount::exact($this->value($params,'sum'),$this->getCurrencyId(),8);
			$this->requireLegacy('lib/cp/coinpayments.php');
			$client = new \CoinPaymentsAPI();
			$client->Setup($this->value($from, 'apipass'), $this->value($from, 'publicpass'));
			$response = $client->CreateWithdrawal(
				$amount,
				$this->definition[4],
				$this->value($to, 'acc'),
				true,
				$this->value($to, 'tag')
			);
			if ($response['error'] === 'ok')
				return array('result' => 'OK', 'batch' => $response['result']['id']);
			return array('result' => $response['error']);
		}
		catch (Throwable $error)
		{
			return $this->errorResult($error);
		}
	}

	protected function requiredConfigKeys(): array
	{
		return array('id', 'key');
	}
}
