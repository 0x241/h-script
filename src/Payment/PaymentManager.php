<?php

namespace HScript\Payment;

use HScript\Database\Connection;
use HScript\Observability\MetricRegistry;
use HScript\Observability\StructuredLogger;
use HScript\Payment\Gateways\AdvCash;
use HScript\Payment\Gateways\BankWire;
use HScript\Payment\Gateways\CoinPayments;
use HScript\Payment\Gateways\CryptoCurrencyApi;
use HScript\Payment\Gateways\Paykassa;
use HScript\Payment\Gateways\PawPayments;
use HScript\Payment\Gateways\Sberbank;
use HScript\Payment\Gateways\Stripe;
use HScript\Payment\Gateways\WebMoney;
use HScript\Payment\Gateways\YooMoney;
use HScript\Security\SafeUrl;
use RuntimeException;
use Throwable;

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
		'IK', 'A1', 'SP', 'PC', 'EA', 'EAT', 'EAT1', 'BSC', 'BSCT', 'XRPA', 'TRA', 'TRAT',
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
		if (CallbackCapabilities::disabledAutomation($gatewayId)) return ['error'=>'automatic_payment_unsupported'];
		$result = $this->execute('deposit', $gatewayId, fn(): array => $this->gateway($gatewayId)->processDeposit($params));
		if (array_key_exists('html', $result))
			return $this->rejectDepositForm($gatewayId);
		if (!array_key_exists('url', $result))
			return $result;
		if (!is_string($result['url']) || !SafeUrl::paymentAction($result['url']))
			return $this->rejectDepositForm($gatewayId);
		foreach ($result as $name => $value)
		{
            if($name==='payment_route') {
                try { \HScript\Payment\Routing\PaymentRoutes::assertSnapshot($gatewayId,$value);continue; }
                catch(\HScript\Finance\CallbackValidationException) {return $this->rejectDepositForm($gatewayId);}
            }
			if (!is_string($name) || !preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/D', $name)
				|| (!is_scalar($value) && $value !== null))
				return $this->rejectDepositForm($gatewayId);
		}
		return $result;
	}

	private function rejectDepositForm(string $gatewayId): array
	{
		error_log('[payment] unsafe deposit form rejected for gateway ' . $gatewayId);
		return array('error' => 'payment_form_unavailable');
	}

	public function processWithdrawal(string $gatewayId, array $params): array
	{
		if (CallbackCapabilities::disabledAutomation($gatewayId)) return ['result'=>'automatic_payment_unsupported'];
		return $this->execute('withdrawal', $gatewayId, fn(): array => $this->gateway($gatewayId)->processWithdrawal($params));
	}

	public function handleCallback(string $gatewayId, array $request, array $config = array()): array
	{
        if (!$this->has($gatewayId) || !CallbackCapabilities::allows($gatewayId,'in'))
			return CallbackResult::failure('unsupported', 'automatic_callback_unsupported');
		$secret = $gatewayId==='ST' ? 'webhook_secret' : (CryptoCurrencyApi::supports($gatewayId) ? 'v3_key'
            : 'key');
		if (!isset($config[$secret]) || !is_string($config[$secret]) || trim($config[$secret]) === '')
			return CallbackResult::failure('authentication_failed', 'callback_configuration_missing');
		if (count($request) > 68 || (isset($request['_raw_body']) && !is_string($request['_raw_body']))
			|| strlen($request['_raw_body'] ?? '') > 65536)
			return CallbackResult::failure('invalid_payload', 'callback_body_invalid');
        if ($gatewayId==='ST') {
            $request['_config']=$config;
            return CallbackResult::normalize($this->execute('callback',$gatewayId,fn(): array=>$this->gateway($gatewayId)->handleCallback($request)));
        }
		$payload = is_array($request['_json'] ?? null) ? $request['_json'] : $request;
		if (count($payload) > 68) return CallbackResult::failure('invalid_payload', 'callback_fields_invalid');
		foreach ($payload as $key => $value) {
			if (in_array($key, ['_config','_raw_body','_json','_server'], true)) continue;
			if (!is_string($key) || strlen($key) > 128 || (!is_scalar($value) && $value !== null)
				|| (is_string($value) && strlen($value) > 8192))
				return CallbackResult::failure('invalid_payload', 'callback_fields_invalid');
		}
		$request['_config'] = $config;
		$result = $this->execute('callback', $gatewayId, fn(): array => $this->gateway($gatewayId)->handleCallback($request));
		return CallbackResult::normalize($result);
	}

	public function getBalance(string $gatewayId, array $config): array
	{
		if (CallbackCapabilities::disabledAutomation($gatewayId)) return ['result'=>'automatic_payment_unsupported','sum'=>-1];
		return $this->execute('balance', $gatewayId, fn(): array => $this->gatewayObject($gatewayId)->getBalance($config));
	}

	private function execute(string $operation, string $gatewayId, callable $callback): array
	{
		$startedAt = microtime(true);
		try
		{
			$result = $callback();
			$outcome = $this->operationSucceeded($operation, $result) ? 'success' : 'failure';
			$this->observeGateway($operation, $gatewayId, $outcome, $startedAt);
			return $result;
		}
		catch (Throwable $exception)
		{
			$this->observeGateway($operation, $gatewayId, 'failure', $startedAt, $exception);
			throw $exception;
		}
	}

	private function operationSucceeded(string $operation, array $result): bool
	{
		if ($operation === 'callback' && array_key_exists('correct', $result)) return !empty($result['correct']);
		if (array_key_exists('result', $result))
			return in_array((string)$result['result'], array('', 'OK', 'success'), true);
		return $result !== array();
	}

	private function observeGateway(string $operation, string $gatewayId, string $outcome, float $startedAt, ?Throwable $exception = null): void
	{
		$duration = max(0, (int)round((microtime(true) - $startedAt) * 1000));
		MetricRegistry::increment('gateway_attempts_total', array('operation' => $operation, 'outcome' => $outcome));
		StructuredLogger::event(
			$outcome === 'success' ? 'info' : 'error', 'payment', 'gateway_attempt', $outcome, $duration,
			StructuredLogger::resourceId('gateway', $gatewayId), array(
				'gateway' => strtolower($gatewayId),
				'gateway_operation' => $operation,
				'error_class' => $exception instanceof Throwable ? $exception::class : '',
			)
		);
	}

	public function detectCallback(array $request, array $query = array()): string
	{
		if (count($request) > 64) return '';
		foreach ($request as $value) if (!is_scalar($value) && $value !== null) return '';
		if (isset($query['its']) && !is_string($query['its'])) return '';
        if (array_key_exists('cryptocurrencyapi.net',$request)) {
            if (!in_array($request['cryptocurrencyapi.net'],[3,'3'],true)) return '';
            if(!empty($request['token']) || !empty($request['tokenContract'])){
                $label=$request['label']??null;
                if(is_scalar($label) && preg_match('/^[1-9][0-9]{0,9}$/D',(string)$label) && isset($this->db->link) && $this->db->link instanceof \PDO){
                    $o=$this->db->fetch1Row($this->db->query("SELECT cCID FROM Opers JOIN Currs ON cID=ocID WHERE oID=?d AND oOper='CASHIN'",[(int)$label]));
                    if($o && isset(\HScript\Payment\Routing\PaymentRoutes::GATEWAYS[$o['cCID']]) && CryptoCurrencyApi::supports($o['cCID']))return $o['cCID'];
                }
                return ['USDT'=>'CCTU','USDC'=>'CCTC'][$request['token']??'']??'CCT';
            }
            foreach (CryptoCurrencyApi::NATIVE as $id=>[$chain,$asset])
                if (($request['chain'] ?? null)===$chain && ($request['currency'] ?? null)===$asset) return $id;
            return '';
        }
		if (isset($request['private_hash']))
		{
			$key = strtolower((string)($request['system'] ?? '') . '_' . (string)($request['currency'] ?? ''));
			$paykassa = array(
				'advcash_usd' => 'PKAU',
				'advcash_rub' => 'PKAR',
				'bitcoin_btc' => 'PKB',
				'ethereum_eth' => 'PKE',
				'litecoin_ltc' => 'PKL',
                'tron_trc20_usdt'=>'PKTU','ethereum_erc20_usdt'=>'PKTU','ethereum_erc20_usdc'=>'PKTC',
			);
			return $paykassa[$key] ?? '';
		}

		foreach ($this->definitions() as $id => $definition)
		{
			$field = $definition[5] ?? '?';
			if ($field === '?' || !array_key_exists($field, $request))
				continue;
			if ($id === 'AC')
				return array_search($request['ac_merchant_currency'] ?? '', \HScript\Finance\VoletContract::CURRENCIES, true) ?: '';
			if ($id === 'YM')
				return ($request['notification_type'] ?? '') === 'card-incoming' ? 'YMC' : 'YM';
			if ($id === 'CP')
			{
				$detected = (string)($query['its'] ?? 'CP');
				return in_array($detected, array('CP', 'CPE', 'CPL', 'CPR'), true) ? $detected : '';
			}
			if ($id === 'CCAB') return '';
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
			'AC' => AdvCash::class,
            'VEUR'=>AdvCash::class,'VUST'=>AdvCash::class,'VUSC'=>AdvCash::class,'VBTC'=>AdvCash::class,'VETH'=>AdvCash::class,
            'VSOL'=>AdvCash::class,'VTRX'=>AdvCash::class,'VXRP'=>AdvCash::class,'VTON'=>AdvCash::class,'VLTC'=>AdvCash::class,
            'ACR'=>AdvCash::class,
			'PKAU' => AdvCash::class, 'PKAR' => AdvCash::class,
			'WM' => WebMoney::class,
			'YM' => YooMoney::class, 'YMC' => YooMoney::class,
			'SB' => Sberbank::class,
			'BW' => BankWire::class,
			'CP' => CoinPayments::class, 'CPE' => CoinPayments::class,
			'CPL' => CoinPayments::class, 'CPR' => CoinPayments::class,
			'PKB' => Paykassa::class, 'PKL' => Paykassa::class, 'PKE' => Paykassa::class,
            'CCT'=>CryptoCurrencyApi::class,'CCTU'=>CryptoCurrencyApi::class,'CCTC'=>CryptoCurrencyApi::class,'PKTU'=>Paykassa::class,'PKTC'=>Paykassa::class,
			'CCAB' => CryptoCurrencyApi::class, 'CCAL' => CryptoCurrencyApi::class,
			'CCAD' => CryptoCurrencyApi::class, 'CCAG' => CryptoCurrencyApi::class,
			'CCAH' => CryptoCurrencyApi::class,
            'CCAE'=>CryptoCurrencyApi::class,'CCAM'=>CryptoCurrencyApi::class,'CCAN'=>CryptoCurrencyApi::class,
            'CCAT'=>CryptoCurrencyApi::class,'CCAX'=>CryptoCurrencyApi::class,'CCAO'=>CryptoCurrencyApi::class,'CCAS'=>CryptoCurrencyApi::class,
			'PAW' => PawPayments::class, 'ST'=>Stripe::class,
		);
		foreach ($classes as $id => $class)
			$this->register($id, new $class($id, $definitions[$id], array(), $this->db));
	}

	private static function defaultDefinitions(): array
	{
		return array(
			'AC' => array('Volet USD', 'USD', 1, 1, 'USD', 'ac_transfer'),
            'VEUR'=>array('Volet EUR','EUR',1,0,'EUR','ac_transfer'),
            'VUST'=>array('Volet USDT','USDT',1,0,'USDT','ac_transfer'),
            'VUSC'=>array('Volet USDC','USDC',1,0,'USDC','ac_transfer'),
            'VBTC'=>array('Volet BTC','BTC',1,0,'BTC','ac_transfer'),
            'VETH'=>array('Volet ETH','ETH',1,0,'ETH','ac_transfer'),
            'VSOL'=>array('Volet SOL','SOL',1,0,'SOL','ac_transfer'),
            'VTRX'=>array('Volet TRX','TRX',1,0,'TRX','ac_transfer'),
            'VXRP'=>array('Volet XRP','XRP',1,0,'XRP','ac_transfer'),
            'VTON'=>array('Volet TON','TON',1,0,'TON','ac_transfer'),
            'VLTC'=>array('Volet LTC','LTC',1,0,'LTC','ac_transfer'),
			'ACR' => array('Volet RUB', 'RUB', 0, 0, 'RUR', 'ac_transfer'),
			'PKAU' => array('Volet USD (Paykassa.pro)', 'USD', 0, 0, 'USD', 'private_hash'),
			'PKAR' => array('Volet RUB (Paykassa.pro)', 'RUB', 0, 0, 'RUB', 'private_hash'),
			'BW' => array('BankWire', 'USD', 0, 0, 'USD', '?'),
			'SB' => array('SberBank', 'RUB', 0, 0, 'RUB', '?'),
			'YM' => array('YooMoney', 'RUB', 1, 1, 643, 'operation_id'),
			'YMC' => array('YooMoney (cards)', 'RUB', 1, 0, 643, 'operation_id'),
			'WM' => array('WebMoney', 'USD', 1, 0, 'USD', 'LMI_PAYEE_PURSE'),
			'CCT'=>array('Token (CryptoCurrencyAPI v3)','',1,0,'','?'),
            'CCTU'=>array('Tether (CryptoCurrencyAPI v3)','USDT',1,0,'USDT','?'),
            'CCTC'=>array('USD Coin (CryptoCurrencyAPI v3)','USDC',1,0,'USDC','?'),
            'PKTU'=>array('Tether (Paykassa.pro)','USDT',1,0,'USDT','?'),
            'PKTC'=>array('USD Coin (Paykassa.pro)','USDC',1,0,'USDC','?'),
            'CCAB' => array('Bitcoin (CryptoCurrencyAPI v3)', 'BTC', 1, 0, 'BTC', 'cryptocurrencyapi.net'),
			'CCAL' => array('Litecoin (CryptoCurrencyAPI v3)', 'LTC', 1, 0, 'LTC', 'cryptocurrencyapi.net'),
			'CCAD' => array('Dash (CryptoCurrencyAPI v3)', 'DASH', 1, 0, 'DASH', 'cryptocurrencyapi.net'),
			'CCAG' => array('Dogecoin (CryptoCurrencyAPI v3)', 'DOGE', 1, 0, 'DOGE', 'cryptocurrencyapi.net'),
			'CCAH' => array('BitcoinCash (CryptoCurrencyAPI v3)', 'BCH', 1, 0, 'BCH', 'cryptocurrencyapi.net'),
            'CCAE'=>array('Ethereum (CryptoCurrencyAPI v3)','ETH',1,0,'ETH','cryptocurrencyapi.net'),
            'CCAM'=>array('Polygon (CryptoCurrencyAPI v3)','MATIC',1,0,'MATIC','cryptocurrencyapi.net'),
            'CCAN'=>array('BSC (CryptoCurrencyAPI v3)','BNB',1,0,'BNB','cryptocurrencyapi.net'),
            'CCAT'=>array('TRON (CryptoCurrencyAPI v3)','TRX',1,0,'TRX','cryptocurrencyapi.net'),
            'CCAX'=>array('Ripple (CryptoCurrencyAPI v3)','XRP',1,0,'XRP','cryptocurrencyapi.net'),
            'CCAO'=>array('TON (CryptoCurrencyAPI v3)','TON',1,0,'TON','cryptocurrencyapi.net'),
            'CCAS'=>array('Solana (CryptoCurrencyAPI v3)','SOL',1,0,'SOL','cryptocurrencyapi.net'),
			'CP' => array('Bitcoin (Coinpayments.net)', 'BTC', 1, 1, 'BTC', 'txn_id'),
			'CPE' => array('Ether (Coinpayments.net)', 'ETH', 1, 1, 'ETH', 'txn_id'),
			'CPL' => array('Litecoin (Coinpayments.net)', 'LTC', 1, 1, 'LTC', 'txn_id'),
			'CPR' => array('Ripple (Coinpayments.net)', 'XRP', 1, 1, 'XRP', 'txn_id'),
			'PKB' => array('Bitcoin (Paykassa.pro)', 'BTC', 1, 1, 'BTC', 'private_hash'),
			'PKL' => array('Litecoin (Paykassa.pro)', 'LTC', 1, 1, 'LTC', 'private_hash'),
			'PKE' => array('Ethereum (Paykassa.pro)', 'ETH', 1, 1, 'ETH', 'private_hash'),
			'PAW' => array('PAW Payments', '', 0, 0, '', '?'),
            'ST'=>array('Stripe','',1,0,'','?'),
		);
	}
}
