<?php

namespace HScript\Payment\Gateways;

use HScript\Payment\AbstractGateway;

/**
 * Implements the generic cryptocurrency HTTP API integration.
 */
class CryptoCurrencyApi extends AbstractGateway
{
	public function getFields(string $type): array
	{
		if ($type === 'pay')
			return array('acc' => array('Address'));
		if ($type === 'sci' || $type === 'api')
			return array('apipass' => array('API token'));
		return array();
	}

	public function processDeposit(array $params): array
	{
		$sci = (array)$this->value($params, 'sci', array());
		$response = json_decode((string)$this->request(
			'https://cryptocurrencyapi.net/api?token=' . urlencode((string)$this->value($sci, 'apipass'))
			. '&currency=' . urlencode((string)$this->definition[4])
			. '&method=give&tag=' . urlencode((string)$this->value($params, 'tag'))
			. '&statusURL=' . urlencode((string)$this->value($params, 'url_callback'))
		), true);
		$url = str_replace('&check', '', (string)$this->value($params, 'url_ok'));
		return array('url' => $url . '&payto=' . (string)($response['result'] ?? ''));
	}

	public function handleCallback(array $request): array
	{
		$payload = $this->jsonBody($request);
		$config = (array)$this->value($request, '_config', array());
		if (empty($payload['cryptocurrencyapi.net']))
			return array('correct' => false, 'error' => 'Invalid callback source');
		$signature = sha1(implode(':', array(
			$this->value($payload, 'currency'),
			$this->value($payload, 'type'),
			$this->value($payload, 'date'),
			$this->value($payload, 'address'),
			$this->value($payload, 'amount'),
			$this->value($payload, 'txid'),
			$this->value($payload, 'pos'),
			$this->value($payload, 'confirmations'),
			$this->value($payload, 'tag'),
			$this->value($config, 'apipass'),
		)));
		$result = array(
			'sum' => $this->value($payload, 'amount'),
			'sum2' => $this->value($payload, 'amount'),
			'curr' => $this->value($payload, 'currency'),
			'tag' => $this->value($payload, 'tag'),
			'date' => (int)$this->value($payload, 'date') > 0 ? (int)$payload['date'] : time(),
			'batch' => $this->value($payload, 'txid') . ((int)$this->value($payload, 'pos') > 0 ? '#' . $payload['pos'] : ''),
			'hash' => $this->value($payload, 'sign2'),
			'cc' => $signature,
			'response' => hash_equals($signature, (string)$this->value($payload, 'sign2')) ? 'OK' : 'sign_wrong',
		);
		$result['correct'] = hash_equals($signature, (string)$result['hash'])
			&& (int)$this->value($payload, 'confirmations') >= 3
			&& $result['curr'] === $this->definition[4];
		if ($this->value($payload, 'type') === 'out')
			return $this->updateOutbound($result);
		return $result;
	}

	public function getBalance(array $config): array
	{
		$answer = (string)$this->request(
			'https://cryptocurrencyapi.net/api?token=' . urlencode((string)$this->value($config, 'apipass'))
			. '&currency=' . urlencode((string)$this->definition[4]) . '&method=balance'
		);
		$response = json_decode($answer, true);
		if (is_array($response) && array_key_exists('result', $response))
			return array('answer' => $answer, 'result' => 'OK', 'sum' => $response['result']);
		return array('answer' => $answer, 'result' => $answer, 'sum' => -1);
	}

	public function processWithdrawal(array $params): array
	{
		$from = (array)$this->value($params, 'from', array());
		$to = (array)$this->value($params, 'to', array());
		$answer = (string)$this->request(
			'https://cryptocurrencyapi.net/api?token=' . urlencode((string)$this->value($from, 'apipass'))
			. '&currency=' . urlencode((string)$this->definition[4])
			. '&method=send&address=' . urlencode((string)$this->value($to, 'acc'))
			. '&amount=' . urlencode((string)$this->value($params, 'sum'))
			. '&tag=' . urlencode((string)$this->value($params, 'tag'))
			. '&statusURL=' . urlencode((string)$this->value($params, 'url_callback'))
		);
		$response = json_decode($answer, true);
		if (!empty($response['result']))
			return array('answer' => $answer, 'result' => 'OK', 'batch' => '?pending' . $response['result']);
		return array('answer' => $answer, 'result' => $response['error'] ?? 'NoConn');
	}

	protected function requiredConfigKeys(): array
	{
		return array('apipass');
	}
}
