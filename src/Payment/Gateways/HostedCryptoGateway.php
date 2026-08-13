<?php

namespace HScript\Payment\Gateways;

use HScript\Payment\AbstractGateway;

/**
 * Implements shared operations for hosted blockchain gateway endpoints.
 */
abstract class HostedCryptoGateway extends AbstractGateway
{
	public function getFields(string $type): array
	{
		if ($type === 'pay')
			return array('acc' => array($this->addressLabel(), $this->addressPattern()));
		if ($type === 'sci' || $type === 'api')
			return array('apipass' => array('API token'));
		return array();
	}

	public function processDeposit(array $params): array
	{
		$sci = (array)$this->value($params, 'sci', array());
		$response = json_decode((string)$this->request($this->depositUrl($sci, $params)), true);
		$address = $this->depositAddress((array)$response);
		$url = str_replace('&check', '', (string)$this->value($params, 'url_ok'));
		return array('url' => $url . '&payto=' . $address);
	}

	public function getBalance(array $config): array
	{
		$answer = (string)$this->request($this->balanceUrl($config));
		$response = json_decode($answer, true);
		if (is_array($response) && array_key_exists('result', $response))
			return array('answer' => $answer, 'result' => 'OK', 'sum' => $response['result']);
		return array('answer' => $answer, 'result' => $answer, 'sum' => -1);
	}

	public function processWithdrawal(array $params): array
	{
		$from = (array)$this->value($params, 'from', array());
		$response = json_decode((string)$this->request($this->withdrawalUrl($from, $params)), true);
		if (!empty($response['result']))
			return array('answer' => $response, 'result' => 'OK', 'batch' => '?' . $response['result']);
		return array('answer' => $response, 'result' => $response['error'] ?? 'NoConn');
	}

	public function handleCallback(array $request): array
	{
		$payload = $this->jsonBody($request);
		$config = (array)$this->value($request, '_config', array());
		if (empty($payload[$this->marker()]))
			return array('correct' => false, 'error' => 'Invalid callback source');
		$parts = array(
			$this->value($payload, 'type'),
			$this->value($payload, 'date'),
			$this->value($payload, 'from'),
			$this->value($payload, 'to'),
			$this->value($payload, 'token'),
			$this->value($payload, 'amount'),
			$this->value($payload, 'txid'),
			$this->value($payload, 'confirmations'),
			$this->value($payload, 'tag'),
			$this->value($config, 'apipass'),
		);
		if ($this->omitEmptyTokenFromSignature() && !$this->value($payload, 'token'))
			unset($parts[4]);
		$signature = sha1(implode(':', $parts));
		$result = array(
			'accto' => $this->value($payload, 'to'),
			'accfrom' => $this->value($payload, 'from'),
			'sum' => $this->value($payload, 'amount'),
			'sum2' => $this->value($payload, 'amount'),
			'tag' => $this->value($payload, 'tag'),
			'date' => (int)$this->value($payload, 'date') > 0 ? (int)$payload['date'] : time(),
			'batch' => $this->value($payload, 'txid'),
			'hash' => $this->value($payload, 'sign'),
			'cc' => $signature,
			'response' => hash_equals($signature, (string)$this->value($payload, 'sign')) ? 'OK' : 'sign_wrong',
		);
		$result['correct'] = hash_equals($signature, (string)$result['hash'])
			&& (int)$this->value($payload, 'confirmations') >= $this->minimumConfirmations()
			&& $this->matchesToken($payload);
		if ($this->value($payload, 'type') === 'out')
			return $this->updateOutbound($result);
		return $result;
	}

	protected function requiredConfigKeys(): array
	{
		return array('apipass');
	}

	protected function depositAddress(array $response): string
	{
		$result = $response['result'] ?? '';
		return is_array($result) ? (string)($result['address'] ?? '') : (string)$result;
	}

	protected function matchesToken(array $payload): bool
	{
		$token = (string)$this->value($payload, 'token');
		if ($this->isTokenVariant())
			return $token === $this->getCurrencyId();
		return $token === '';
	}

	protected function omitEmptyTokenFromSignature(): bool
	{
		return true;
	}

	abstract protected function marker(): string;
	abstract protected function addressLabel(): string;
	abstract protected function addressPattern(): string;
	abstract protected function isTokenVariant(): bool;
	abstract protected function minimumConfirmations(): int;
	abstract protected function depositUrl(array $config, array $params): string;
	abstract protected function balanceUrl(array $config): string;
	abstract protected function withdrawalUrl(array $config, array $params): string;
}
