<?php

namespace HScript\Payment;

use HScript\Database\Connection;
use HScript\Http\Client;
use HScript\Security\SensitiveDataRedactor;
use RuntimeException;
use Throwable;

/**
 * Supplies shared identity, configuration, and default operations for gateways.
 */
abstract class AbstractGateway implements PaymentGatewayInterface
{
	protected array $config;
	protected Connection $db;
	protected string $id;
	protected array $definition;

	public function __construct(string $id, array $definition, array $config, Connection $db)
	{
		$this->id = $id;
		$this->definition = $definition;
		$this->config = $config;
		$this->db = $db;
	}

	public function getName(): string
	{
		return $this->definition[0];
	}

	public function getCurrencyId(): string
	{
		return (string)$this->definition[1];
	}

	public function getDefinition(): array
	{
		return $this->definition;
	}

	public function getFormFields(array $operation): string
	{
		return json_encode($this->getFields((string)($operation['type'] ?? 'pay')), JSON_UNESCAPED_SLASHES) ?: '{}';
	}

	public function getFields(string $type): array
	{
		return array();
	}

	public function getBalance(array $config): array
	{
		return array('result' => 'UnknPSys', 'sum' => -1);
	}

	public function processDeposit(array $params): array
	{
		return array();
	}

	public function processWithdrawal(array $params): array
	{
		return array('result' => 'UnknPSys');
	}

	public function handleCallback(array $request): array
	{
		return array();
	}

	public function validateConfig(array $config): bool
	{
		foreach ($this->requiredConfigKeys() as $key)
			if (!isset($config[$key]) || $config[$key] === '')
				return false;
		return true;
	}

	protected function requiredConfigKeys(): array
	{
		return array();
	}

	protected function logOperation(string $type, array $data): void
	{
		if (function_exists('xAddToLog'))
			xAddToLog(
				$this->id . ' ' . $type . ': ' . print_r(SensitiveDataRedactor::redact($data), true),
				'payment'
			);
	}

	protected function validateAmount(float $amount, float $min, float $max): bool
	{
		return $amount >= $min && ($max <= 0 || $amount <= $max);
	}

	protected function normalizedAmount(mixed $amount): string
	{
		$currency = (string)($this->definition[4] ?? $this->getCurrencyId());
		if (in_array($currency, array('USD', 'RUB', 'RUR', 'EUR'), true))
			return number_format((float)$amount, 2, '.', '');
		return (string)$amount;
	}

	protected function value(array $data, string $key, mixed $default = ''): mixed
	{
		return array_key_exists($key, $data) ? $data[$key] : $default;
	}

	protected function request(string $url, mixed $data = null): string|false
	{
		return Client::request($url, $data);
	}

	protected function jsonBody(array $request): array
	{
		if (isset($request['_json']) && is_array($request['_json']))
			return $request['_json'];
		if (!empty($request['_raw_body']))
		{
			$decoded = json_decode((string)$request['_raw_body'], true);
			if (is_array($decoded))
				return $decoded;
		}
		return $request;
	}

	protected function rawBody(array $request): string
	{
		return (string)($request['_raw_body'] ?? http_build_query($request));
	}

	protected function server(array $request, string $key): string
	{
		if (isset($request['_server'][$key]))
			return (string)$request['_server'][$key];
		return (string)($_SERVER[$key] ?? '');
	}

	protected function updateOutbound(array $callback, string $batchPrefix = ''): array
	{
		$correct = !empty($callback['correct']);
		$batch = $batchPrefix . (string)($callback['batch'] ?? '');
		$this->db->update(
			'Opers',
			array('oBatch' => ($correct ? '' : '?') . $batch),
			'',
			'oID=?d and oOper=? and oState=3 and SUBSTR(oBatch, 1, 1)=?',
			array((int)($callback['tag'] ?? 0), 'CASHOUT', '?')
		);
		return array('handled' => true, 'correct' => $correct) + $callback;
	}

	protected function errorResult(Throwable|string $error): array
	{
		return array('result' => $error instanceof Throwable ? $error->getMessage() : $error);
	}

	protected function requireLegacy(string $path): void
	{
		$absolute = dirname(__DIR__, 2) . '/' . ltrim($path, '/');
		if (!is_file($absolute))
			throw new RuntimeException('Payment dependency not found: ' . $path);
		require_once $absolute;
	}
}
