<?php

namespace HScript\Payment\Gateways;

use HScript\Payment\AbstractGateway;

/**
 * Implements Paykassa deposits, callbacks, balances, and withdrawals.
 */
class Paykassa extends AbstractGateway
{
	private const SYSTEM_IDS = array('PKB' => 11, 'PKE' => 12, 'PKL' => 14);
	private const BALANCE_KEYS = array(
		'PKB' => 'bitcoin_btc',
		'PKE' => 'ethereum_eth',
		'PKL' => 'litecoin_ltc',
	);

	public function getFields(string $type): array
	{
		if ($type === 'pay')
		{
			if ($this->id === 'PKB')
				return array('acc' => array('Bitcoin-address', '[1-9A-Za-z]{27,34}'));
			if ($this->id === 'PKE')
				return array('acc' => array('Ether-address', '', '0x...'));
			return array('acc' => array('Litecoin-address'));
		}
		if ($type === 'sci')
			return array(
				'id' => array('SCI ID (<a href="https://paykassa.pro/user/">open</a>)'),
				'key' => array('SCI Password (<a href="https://paykassa.pro/user/">open</a>)'),
			);
		if ($type === 'api')
			return array(
				'id' => array('API ID (<a href="https://paykassa.pro/user/">open</a>)'),
				'apipass' => array('API Password (<a href="https://paykassa.pro/user/">open</a>)'),
				'shop_id' => array('SCI ID (<a href="https://paykassa.pro/user/">open</a>)'),
			);
		return array();
	}

	public function processDeposit(array $params): array
	{
		$sci = (array)$this->value($params, 'sci', array());
		$this->requireLegacy('lib/paykassa/paykassa_sci.class.php');
		$client = new \PayKassaSCI($this->value($sci, 'id'), $this->value($sci, 'key'));
		$response = $client->sci_create_order(
			(float)$this->value($params, 'sum'),
			$this->definition[4],
			$this->value($params, 'tag'),
			$this->value($params, 'memo'),
			self::SYSTEM_IDS[$this->id]
		);
		if (!empty($response['error']))
			return array('error' => $response['message']);
		return array('url' => $response['data']['url']);
	}

	public function handleCallback(array $request): array
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

	public function getBalance(array $config): array
	{
		$this->requireLegacy('lib/paykassa/paykassa_api.class.php');
		$client = new \PayKassaAPI($this->value($config, 'id'), $this->value($config, 'apipass'));
		$response = $client->api_get_shop_balance($this->value($config, 'shop_id'));
		if (!empty($response['error']))
			return array('result' => $response['message'], 'sum' => -1);
		return array('result' => 'OK', 'sum' => $response['data'][self::BALANCE_KEYS[$this->id]]);
	}

	public function processWithdrawal(array $params): array
	{
		$from = (array)$this->value($params, 'from', array());
		$to = (array)$this->value($params, 'to', array());
		$this->requireLegacy('lib/paykassa/paykassa_api.class.php');
		$client = new \PayKassaAPI($this->value($from, 'id'), $this->value($from, 'apipass'));
		$response = $client->api_payment(
			$this->value($from, 'shop_id'),
			self::SYSTEM_IDS[$this->id],
			$this->value($to, 'acc'),
			(float)$this->value($params, 'sum'),
			$this->definition[4],
			$this->value($to, 'tag')
		);
		if (!empty($response['error']))
			return array('result' => $response['message']);
		return array('result' => 'OK', 'batch' => $response['data']['transaction']);
	}

	protected function requiredConfigKeys(): array
	{
		return array('id', 'key');
	}
}
