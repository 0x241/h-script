<?php

namespace HScript\Payment;

use HScript\Database\Connection;
use HScript\Payment\Gateways\AdvCash;
use HScript\Payment\Gateways\BankWire;
use HScript\Payment\Gateways\BnbApi;
use HScript\Payment\Gateways\CoinPayments;
use HScript\Payment\Gateways\CryptoCurrencyApi;
use HScript\Payment\Gateways\EtherApi;
use HScript\Payment\Gateways\Paykassa;
use HScript\Payment\Gateways\Sberbank;
use HScript\Payment\Gateways\TronApi;
use HScript\Payment\Gateways\WebMoney;
use HScript\Payment\Gateways\XrpApi;
use HScript\Payment\Gateways\YooMoney;
use RuntimeException;

/**
 * Registers active payment gateways and dispatches normalized operations.
 *
 * Definitions remain compatible with module payment-system arrays, while
 * execution is delegated to focused gateway classes.
 */
class PaymentManager
{
	public const DEPRECATED_IDS = array(
		'LR', 'LR0', 'EP', 'PZ', 'PM', 'PKPM', 'PY', 'PYR', 'PYE', 'PKPU', 'PKPR',
		'NX', 'NXE', 'NXB', 'NXR', 'STP', 'OK', 'OKR', 'QW', 'QW2', 'QWA', 'QG',
		'FKV', 'FKY', 'FKQ', 'FKA', 'HM', 'EPCU', 'EPCB', 'EPCT', 'IBC', 'CKE',
		'CB', 'BC', 'BCM', 'AEX1', 'AEX2', 'AEX3', 'AEX4', 'AEX5', 'AEX6', 'AEX7',
		'AEX8', 'GDP', 'LP', 'MR', 'PP', 'EGC', 'C4P', 'C4', 'W1', 'ON', 'SY',
		'IK', 'A1', 'SP', 'PC',
	);

	private array $gateways = array();

	public function __construct(private Connection $db)
	{
		$this->registerDefaults();
	}

	public function register(string $id, PaymentGatewayInterface $gateway): void
	{
		$this->gateways[$id] = $gateway;
	}

	public function registerGateway(string $id, PaymentGatewayInterface $gateway): void
	{
		$this->register($id, $gateway);
	}

	public function gateway(string $id): PaymentGatewayInterface
	{
		if (!isset($this->gateways[$id]))
			throw new RuntimeException("Unknown gateway: $id");
		return $this->gateways[$id];
	}

	public function has(string $id): bool
	{
		return isset($this->gateways[$id]);
	}

	public function definitions(?string $id = null): array
	{
		if ($id !== null)
			return $this->has($id) ? $this->gatewayObject($id)->getDefinition() : array();
		$result = array();
		foreach ($this->gateways as $gatewayId => $gateway)
			$result[$gatewayId] = $this->gatewayObject($gatewayId)->getDefinition();
		return $result;
	}

	public function fields(string $id, string $type = 'pay'): array
	{
		return $this->has($id) ? $this->gatewayObject($id)->getFields($type) : array();
	}

	public function processDeposit(string $gatewayId, array $params): array
	{
		return $this->gateway($gatewayId)->processDeposit($params);
	}

	public function processWithdrawal(string $gatewayId, array $params): array
	{
		return $this->gateway($gatewayId)->processWithdrawal($params);
	}

	public function handleCallback(string $gatewayId, array $request, array $config = array()): array
	{
		$request['_config'] = $config;
		return $this->gateway($gatewayId)->handleCallback($request);
	}

	public function getBalance(string $gatewayId, array $config): array
	{
		return $this->gatewayObject($gatewayId)->getBalance($config);
	}

	public function detectCallback(array $request, array $query = array()): string
	{
		if (isset($request['private_hash']))
		{
			$key = strtolower((string)($request['system'] ?? '') . '_' . (string)($request['currency'] ?? ''));
			$paykassa = array(
				'advcash_usd' => 'PKAU',
				'advcash_rub' => 'PKAR',
				'bitcoin_btc' => 'PKB',
				'ethereum_eth' => 'PKE',
				'litecoin_ltc' => 'PKL',
			);
			return $paykassa[$key] ?? '';
		}

		foreach ($this->definitions() as $id => $definition)
		{
			$field = $definition[5] ?? '?';
			if ($field === '?' || !array_key_exists($field, $request))
				continue;
			if ($id === 'AC')
				return ($request['ac_merchant_currency'] ?? '') === 'RUR' ? 'ACR' : 'AC';
			if ($id === 'YM')
				return ($request['notification_type'] ?? '') === 'card-incoming' ? 'YMC' : 'YM';
			if ($id === 'CP')
			{
				$detected = (string)($query['its'] ?? 'CP');
				return in_array($detected, array('CP', 'CPE', 'CPL', 'CPR'), true) ? $detected : '';
			}
			if ($id === 'BSC')
				return empty($request['token']) ? 'BSC' : 'BSCT';
			if ($id === 'EA')
			{
				if (($request['token'] ?? '') === 'USDT')
					return 'EAT';
				if (($request['token'] ?? '') === 'DAI')
					return 'EAT1';
				return 'EA';
			}
			if ($id === 'TRA')
				return empty($request['token']) ? 'TRA' : 'TRAT';
			if ($id === 'CCAB')
			{
				$currencies = array(
					'BTC' => 'CCAB',
					'LTC' => 'CCAL',
					'DASH' => 'CCAD',
					'DOGE' => 'CCAG',
					'BCH' => 'CCAH',
				);
				return $currencies[$request['currency'] ?? ''] ?? '';
			}
			return $id;
		}
		return '';
	}

	private function gatewayObject(string $id): AbstractGateway
	{
		$gateway = $this->gateway($id);
		if (!$gateway instanceof AbstractGateway)
			throw new RuntimeException("Gateway $id does not expose H-Script metadata");
		return $gateway;
	}

	private function registerDefaults(): void
	{
		$definitions = self::defaultDefinitions();
		$classes = array(
			'AC' => AdvCash::class, 'ACR' => AdvCash::class,
			'PKAU' => AdvCash::class, 'PKAR' => AdvCash::class,
			'WM' => WebMoney::class,
			'YM' => YooMoney::class, 'YMC' => YooMoney::class,
			'SB' => Sberbank::class,
			'BW' => BankWire::class,
			'CP' => CoinPayments::class, 'CPE' => CoinPayments::class,
			'CPL' => CoinPayments::class, 'CPR' => CoinPayments::class,
			'PKB' => Paykassa::class, 'PKL' => Paykassa::class, 'PKE' => Paykassa::class,
			'CCAB' => CryptoCurrencyApi::class, 'CCAL' => CryptoCurrencyApi::class,
			'CCAD' => CryptoCurrencyApi::class, 'CCAG' => CryptoCurrencyApi::class,
			'CCAH' => CryptoCurrencyApi::class,
			'EA' => EtherApi::class, 'EAT' => EtherApi::class, 'EAT1' => EtherApi::class,
			'BSC' => BnbApi::class, 'BSCT' => BnbApi::class,
			'XRPA' => XrpApi::class,
			'TRA' => TronApi::class, 'TRAT' => TronApi::class,
		);
		foreach ($classes as $id => $class)
			$this->register($id, new $class($id, $definitions[$id], array(), $this->db));
	}

	private static function defaultDefinitions(): array
	{
		return array(
			'AC' => array('Volet USD', 'USD', 1, 1, 'USD', 'ac_transfer'),
			'ACR' => array('Volet RUB', 'RUB', 1, 1, 'RUR', 'ac_transfer'),
			'PKAU' => array('Volet USD (Paykassa.pro)', 'USD', 1, 1, 'USD', 'private_hash'),
			'PKAR' => array('Volet RUB (Paykassa.pro)', 'RUB', 1, 1, 'RUB', 'private_hash'),
			'BW' => array('BankWire', 'USD', 0, 0, 'USD', '?'),
			'SB' => array('SberBank', 'RUB', 0, 0, 'RUB', '?'),
			'YM' => array('YooMoney', 'RUB', 1, 1, 643, 'operation_id'),
			'YMC' => array('YooMoney (cards)', 'RUB', 1, 0, 643, 'operation_id'),
			'WM' => array('WebMoney', 'USD', 1, 0, 'USD', 'LMI_PAYEE_PURSE'),
			'BSC' => array('Binance Smart Chain (BNBAPI.net)', 'BNB', 1, 1, 'BNB', 'bnbapi.net'),
			'BSCT' => array('BSC BEP20 Token (BNBAPI.net)', '', 1, 1, '', 'bnbapi.net'),
			'EA' => array('Ethereum (EtherAPI.net)', 'ETH', 1, 1, 'ETH', 'etherapi.net'),
			'EAT' => array('Ethereum ERC20 token USDT (EtherAPI.net)', 'USDT', 1, 1, 'USDT', 'etherapi.net'),
			'EAT1' => array('Ethereum ERC20 token DAI (EtherAPI.net)', 'DAI', 1, 1, 'DAI', 'etherapi.net'),
			'CCAB' => array('Bitcoin (CryptoCurrencyAPI.net)', 'BTC', 1, 1, 'BTC', 'cryptocurrencyapi.net'),
			'CCAL' => array('Litecoin (CryptoCurrencyAPI.net)', 'LTC', 1, 1, 'LTC', 'cryptocurrencyapi.net'),
			'CCAD' => array('Dash (CryptoCurrencyAPI.net)', 'DASH', 1, 1, 'DASH', 'cryptocurrencyapi.net'),
			'CCAG' => array('Dogecoin (CryptoCurrencyAPI.net)', 'DOGE', 1, 1, 'DOGE', 'cryptocurrencyapi.net'),
			'CCAH' => array('BitcoinCash (CryptoCurrencyAPI.net)', 'BCH', 1, 1, 'BCH', 'cryptocurrencyapi.net'),
			'XRPA' => array('Ripple (XRPAPI.net)', 'XRP', 1, 1, 'XRP', 'xrpapi.net'),
			'TRA' => array('TRON (TRONAPI.net)', 'TRX', 1, 1, 'TRX', 'tronapi.net'),
			'TRAT' => array('TRON TRC20 token USDT (TRONAPI.net)', 'USDT', 1, 1, 'USDT', 'tronapi.net'),
			'CP' => array('Bitcoin (Coinpayments.net)', 'BTC', 1, 1, 'BTC', 'txn_id'),
			'CPE' => array('Ether (Coinpayments.net)', 'ETH', 1, 1, 'ETH', 'txn_id'),
			'CPL' => array('Litecoin (Coinpayments.net)', 'LTC', 1, 1, 'LTC', 'txn_id'),
			'CPR' => array('Ripple (Coinpayments.net)', 'XRP', 1, 1, 'XRP', 'txn_id'),
			'PKB' => array('Bitcoin (Paykassa.pro)', 'BTC', 1, 1, 'BTC', 'private_hash'),
			'PKL' => array('Litecoin (Paykassa.pro)', 'LTC', 1, 1, 'LTC', 'private_hash'),
			'PKE' => array('Ethereum (Paykassa.pro)', 'ETH', 1, 1, 'ETH', 'private_hash'),
		);
	}
}
