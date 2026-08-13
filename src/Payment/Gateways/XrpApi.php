<?php

namespace HScript\Payment\Gateways;

use HScript\Payment\AbstractGateway;

/**
 * Implements XRP API deposits, callbacks, balances, and withdrawals.
 */
class XrpApi extends AbstractGateway
{
	public function getFields(string $type): array
	{
		if ($type === 'pay')
			return array(
				'acc' => array('Address', '[r|X][rpshnaf39wBUDNEGHJKLM4PQRST7VWXYZ2bcdeCg65jkm8oFqi1tuvAxyz]{24,50}[\:\d{1,6}]?'),
				'tag' => array('Destination tag'),
			);
		if ($type === 'sci' || $type === 'api')
			return array('apipass' => array('API token'));
		return array();
	}

	public function processDeposit(array $params): array
	{
		$sci = (array)$this->value($params, 'sci', array());
		$response = json_decode((string)$this->request(
			'https://xrpapi.net/api/.give?key=' . urlencode((string)$this->value($sci, 'apipass'))
			. '&label=' . urlencode((string)$this->value($params, 'tag'))
			. '&statusURL=' . urlencode((string)$this->value($params, 'url_callback'))
		), true);
		$address = (string)($response['result']['address'] ?? '');
		$tag = (string)($response['result']['tag'] ?? '');
		$url = str_replace('&check', '', (string)$this->value($params, 'url_ok'));
		return array('url' => $url . '&payto=' . $address . ':' . $tag);
	}

	public function handleCallback(array $request): array
	{
		$payload = $this->jsonBody($request);
		$config = (array)$this->value($request, '_config', array());
		if (empty($payload['xrpapi.net']))
			return array('correct' => false, 'error' => 'Invalid callback source');
		$signature = sha1(implode(':', array(
			$this->value($payload, 'type'),
			$this->value($payload, 'date'),
			$this->value($payload, 'from'),
			$this->value($payload, 'to'),
			$this->value($payload, 'tag'),
			$this->value($payload, 'amount'),
			$this->value($payload, 'fee'),
			$this->value($payload, 'txid'),
			$this->value($payload, 'label'),
			$this->value($config, 'apipass'),
		)));
		$result = array(
			'sum' => $this->value($payload, 'amount'),
			'sum2' => $this->value($payload, 'amount'),
			'tag' => $this->value($payload, 'label'),
			'date' => (int)$this->value($payload, 'date') > 0 ? (int)$payload['date'] : time(),
			'batch' => $this->value($payload, 'txid'),
			'hash' => $this->value($payload, 'sign'),
			'cc' => $signature,
			'response' => hash_equals($signature, (string)$this->value($payload, 'sign')) ? 'OK' : 'sign_wrong',
		);
		$result['correct'] = hash_equals($signature, (string)$result['hash']);
		if ($this->value($payload, 'type') === 'out')
			return $this->updateOutbound($result);
		return $result;
	}

	public function getBalance(array $config): array
	{
		$answer = (string)$this->request(
			'https://xrpapi.net/api/.balance?key=' . urlencode((string)$this->value($config, 'apipass'))
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
			'https://xrpapi.net/api/.send?key=' . urlencode((string)$this->value($from, 'apipass'))
			. '&address=' . urlencode((string)$this->value($to, 'acc'))
			. '&tag=' . urlencode((string)$this->value($to, 'tag'))
			. '&amount=' . urlencode((string)$this->value($params, 'sum'))
			. '&label=' . urlencode((string)$this->value($params, 'tag'))
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
