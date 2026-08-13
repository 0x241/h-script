<?php

namespace HScript\Payment\Gateways;

use HScript\Payment\AbstractGateway;
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
		return array(
			'url' => 'https://www.coinpayments.net/index.php',
			'cmd' => '_pay_simple',
			'reset' => '1',
			'merchant' => $this->value($sci, 'id'),
			'item_name' => $this->value($params, 'memo'),
			'invoice' => $this->value($params, 'tag'),
			'currency' => $this->definition[4],
			'amountf' => $this->value($params, 'sum'),
			'want_shipping' => '0',
			'success_url' => $this->value($params, 'url_ok'),
			'cancel_url' => $this->value($params, 'url_fail'),
			'ipn_url' => $this->value($params, 'url_callback') . '?its=' . $this->id,
		);
	}

	public function handleCallback(array $request): array
	{
		$config = (array)$this->value($request, '_config', array());
		$hmac = $this->server($request, 'HTTP_HMAC');
		$raw = $this->rawBody($request);
		if ($hmac === '' || $raw === '')
			return array('correct' => false, 'error' => 'Missing HMAC or request body');
		if ($this->value($request, 'merchant') !== $this->value($config, 'id'))
			return array('correct' => false, 'error' => 'Invalid Merchant ID');
		$expected = hash_hmac('sha512', $raw, (string)$this->value($config, 'key'));
		if (!hash_equals($expected, $hmac))
			return array('correct' => false, 'error' => 'HMAC signature does not match');
		$status = (int)$this->value($request, 'status');
		$result = array(
			'sum' => (float)$this->value($request, 'amount1'),
			'curr' => $this->value($request, 'currency1'),
			'tag' => $this->value($request, 'invoice'),
			'date' => time(),
			'status' => $status,
			'batch' => $this->value($request, 'txn_id'),
			'response' => 'IPN OK',
		);
		$result['correct'] = $status >= 100 && $result['curr'] === $this->getCurrencyId();
		if ($status < 100 && $status !== 2)
			$result['handled'] = true;
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
				return array('result' => 'OK', 'sum' => $response['result'][$this->definition[4]]['balancef']);
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
			$this->requireLegacy('lib/cp/coinpayments.php');
			$client = new \CoinPaymentsAPI();
			$client->Setup($this->value($from, 'apipass'), $this->value($from, 'publicpass'));
			$response = $client->CreateWithdrawal(
				(float)$this->value($params, 'sum'),
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
