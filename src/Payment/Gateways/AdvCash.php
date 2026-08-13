<?php

namespace HScript\Payment\Gateways;

use HScript\Payment\AbstractGateway;
use Throwable;

/**
 * Implements AdvCash deposit, callback, balance, and withdrawal operations.
 */
class AdvCash extends AbstractGateway
{
	private const PAYKASSA_SYSTEM_ID = 4;

	public function getName(): string
	{
		return str_replace('Advanced Cash', 'Volet', parent::getName());
	}

	public function getFields(string $type): array
	{
		if ($type === 'pay')
			return array('acc' => array('Account e-mail', '.+@.+\..+', 'sample@domain.zn'));
		if ($type === 'sci')
		{
			if ($this->isPaykassa())
				return array(
					'id' => array('SCI ID (<a href="https://paykassa.pro/user/">open</a>)'),
					'key' => array('SCI Password (<a href="https://paykassa.pro/user/">open</a>)'),
				);
			return array(
				'name' => array('Account e-mail', '.+@.+\..+', 'sample@domain.zn'),
				'sci' => array('SCI name'),
				'key' => array('Password'),
			);
		}
		if ($type === 'api')
		{
			if ($this->isPaykassa())
				return array(
					'id' => array('API ID (<a href="https://paykassa.pro/user/">open</a>)'),
					'apipass' => array('API Password (<a href="https://paykassa.pro/user/">open</a>)'),
					'shop_id' => array('SCI ID (<a href="https://paykassa.pro/user/">open</a>)'),
				);
			return array(
				'name' => array('Account e-mail', '.+@.+\..+', 'sample@domain.zn'),
				'acc' => array('Wallet'),
				'api' => array('API name'),
				'apipass' => array('Password'),
			);
		}
		return array();
	}

	public function processDeposit(array $params): array
	{
		if ($this->isPaykassa())
			return $this->processPaykassaDeposit($params);

		$sci = (array)$this->value($params, 'sci', array());
		$amount = $this->normalizedAmount($this->value($params, 'sum', 0));
		$signature = hash('sha256', implode(':', array(
			$this->value($sci, 'name'),
			$this->value($sci, 'sci'),
			$amount,
			$this->definition[4],
			$this->value($sci, 'key'),
			$this->value($params, 'tag'),
		)));
		return array(
			'url' => 'https://account.volet.com/sci/',
			'ac_account_email' => $this->value($sci, 'name'),
			'ac_sci_name' => $this->value($sci, 'sci'),
			'ac_amount' => $amount,
			'ac_currency' => $this->definition[4],
			'ac_order_id' => $this->value($params, 'tag'),
			'ac_comments' => $this->value($params, 'memo'),
			'ac_success_url' => $this->value($params, 'url_ok'),
			'ac_success_url_method' => 'POST',
			'ac_fail_url' => $this->value($params, 'url_fail'),
			'ac_fail_url_method' => 'POST',
			'ac_status_url' => $this->value($params, 'url_callback'),
			'ac_status_url_method' => 'POST',
			'ac_sign' => $signature,
		);
	}

	public function handleCallback(array $request): array
	{
		if ($this->isPaykassa())
			return $this->handlePaykassaCallback($request);

		$config = (array)$this->value($request, '_config', array());
		$result = array(
			'sci' => $this->value($request, 'ac_sci_name'),
			'accto' => $this->value($request, 'ac_dest_wallet'),
			'accfrom' => $this->value($request, 'ac_buyer_email'),
			'sum' => $this->value($request, 'ac_amount'),
			'sum2' => (float)$this->value($request, 'ac_amount') - (float)$this->value($request, 'ac_fee'),
			'curr' => $this->value($request, 'ac_merchant_currency'),
			'tag' => $this->value($request, 'ac_order_id'),
			'date' => time(),
			'batch' => $this->value($request, 'ac_transfer'),
			'hash' => $this->value($request, 'ac_hash'),
		);
		$signature = hash('sha256', implode(':', array(
			$this->value($request, 'ac_transfer'),
			$this->value($request, 'ac_start_date'),
			$this->value($request, 'ac_sci_name'),
			$this->value($request, 'ac_src_wallet'),
			$this->value($request, 'ac_dest_wallet'),
			$this->value($request, 'ac_order_id'),
			$this->value($request, 'ac_amount'),
			$this->value($request, 'ac_merchant_currency'),
			$this->value($config, 'key'),
		)));
		$result['cc'] = $signature;
		$result['correct'] = $result['curr'] === $this->definition[4]
			&& hash_equals($signature, (string)$result['hash']);
		return $result;
	}

	public function getBalance(array $config): array
	{
		if ($this->isPaykassa())
			return $this->getPaykassaBalance($config);
		$result = array('result' => 'NoConn', 'sum' => -1);
		try
		{
			$this->requireLegacy('lib/advcash/MerchantWebService.php');
			$service = new \MerchantWebService();
			$auth = new \authDTO();
			$auth->apiName = $this->value($config, 'api');
			$auth->accountEmail = $this->value($config, 'name');
			$auth->authenticationToken = $service->getAuthenticationToken($this->value($config, 'apipass'));
			$query = new \getBalances();
			$query->arg0 = $auth;
			$response = $service->getBalances($query);
			$result['answer'] = print_r($response->return, true);
			foreach ((array)$response->return as $balance)
				if ($balance->id === $this->value($config, 'acc'))
				{
					$result['result'] = 'OK';
					$result['sum'] = (float)$balance->amount;
					return $result;
				}
		}
		catch (Throwable $error)
		{
			$result['result'] = $error->getMessage();
		}
		return $result;
	}

	public function processWithdrawal(array $params): array
	{
		if ($this->isPaykassa())
			return $this->processPaykassaWithdrawal($params);
		$from = (array)$this->value($params, 'from', array());
		$to = (array)$this->value($params, 'to', array());
		try
		{
			$this->requireLegacy('lib/advcash/MerchantWebService.php');
			$service = new \MerchantWebService();
			$auth = new \authDTO();
			$auth->apiName = $this->value($from, 'api');
			$auth->accountEmail = $this->value($from, 'name');
			$auth->authenticationToken = $service->getAuthenticationToken($this->value($from, 'apipass'));
			$payment = new \sendMoneyRequest();
			$payment->amount = (float)$this->value($params, 'sum');
			$payment->currency = $this->definition[4];
			$payment->email = $this->value($to, 'acc');
			$payment->note = $this->value($params, 'memo');
			$payment->savePaymentTemplate = false;
			$validation = new \validationSendMoney();
			$validation->arg0 = $auth;
			$validation->arg1 = $payment;
			$service->validationSendMoney($validation);
			$send = new \sendMoney();
			$send->arg0 = $auth;
			$send->arg1 = $payment;
			$response = $service->sendMoney($send);
			$batch = (string)$response->return;
			return array('result' => $batch ? 'OK' : $batch, 'batch' => $batch);
		}
		catch (Throwable $error)
		{
			return $this->errorResult($error);
		}
	}

	protected function requiredConfigKeys(): array
	{
		return $this->isPaykassa() ? array('id', 'key') : array('name', 'sci', 'key');
	}

	private function isPaykassa(): bool
	{
		return str_starts_with($this->id, 'PK');
	}

	private function processPaykassaDeposit(array $params): array
	{
		$sci = (array)$this->value($params, 'sci', array());
		$this->requireLegacy('lib/paykassa/paykassa_sci.class.php');
		$client = new \PayKassaSCI($this->value($sci, 'id'), $this->value($sci, 'key'));
		$response = $client->sci_create_order(
			(float)$this->value($params, 'sum'),
			$this->definition[4],
			$this->value($params, 'tag'),
			$this->value($params, 'memo'),
			self::PAYKASSA_SYSTEM_ID
		);
		if (!empty($response['error']))
			return array('error' => $response['message']);
		return array('url' => $response['data']['url']);
	}

	private function handlePaykassaCallback(array $request): array
	{
		$config = (array)$this->value($request, '_config', array());
		$this->requireLegacy('lib/paykassa/paykassa_sci.class.php');
		$client = new \PayKassaSCI($this->value($config, 'id'), $this->value($config, 'key'));
		$response = $client->sci_confirm_order();
		if (!empty($response['error']))
			return array('error' => $response['message'], 'correct' => false);
		$data = $response['data'];
		$lookup = json_decode((string)$this->request(
			'https://crypto.paykassa.pro/api/0.4a/index.php/?func=api_public_get_transactions_info'
			. '&currency=' . urlencode((string)$data['currency'])
			. '&address=' . urlencode((string)$data['address'])
			. '&tag=' . urlencode((string)$data['tag'])
		), true);
		return array(
			'sum' => $data['amount'],
			'curr' => $data['currency'],
			'tag' => $data['order_id'],
			'date' => time(),
			'batch' => $lookup['data']['transactions'][0]['transaction'] ?? $data['transaction'],
			'status' => 'success',
			'correct' => true,
			'response' => $data['order_id'] . '|success',
		);
	}

	private function getPaykassaBalance(array $config): array
	{
		$this->requireLegacy('lib/paykassa/paykassa_api.class.php');
		$client = new \PayKassaAPI($this->value($config, 'id'), $this->value($config, 'apipass'));
		$response = $client->api_get_shop_balance($this->value($config, 'shop_id'));
		if (!empty($response['error']))
			return array('result' => $response['message'], 'sum' => -1);
		$key = $this->id === 'PKAR' ? 'advcash_rub' : 'advcash_usd';
		return array('result' => 'OK', 'sum' => $response['data'][$key]);
	}

	private function processPaykassaWithdrawal(array $params): array
	{
		$from = (array)$this->value($params, 'from', array());
		$to = (array)$this->value($params, 'to', array());
		$this->requireLegacy('lib/paykassa/paykassa_api.class.php');
		$client = new \PayKassaAPI($this->value($from, 'id'), $this->value($from, 'apipass'));
		$response = $client->api_payment(
			$this->value($from, 'shop_id'),
			self::PAYKASSA_SYSTEM_ID,
			$this->value($to, 'acc'),
			(float)$this->value($params, 'sum'),
			$this->definition[4],
			$this->value($to, 'tag')
		);
		if (!empty($response['error']))
			return array('result' => $response['message']);
		return array('result' => 'OK', 'batch' => $response['data']['transaction']);
	}
}
