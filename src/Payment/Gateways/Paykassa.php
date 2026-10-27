<?php

namespace HScript\Payment\Gateways;

use HScript\Payment\AbstractGateway;
use HScript\Payment\GatewayAmount;
use HScript\Payment\GatewayBalance;
use HScript\Payment\CallbackResult;
use HScript\Payment\FormCallbackPayload;
use HScript\Payment\PaykassaSciClient;
use HScript\Payment\PaykassaAssets;
use HScript\Finance\RequestDecimal;
use HScript\Finance\PaykassaVerification;
use InvalidArgumentException;
use Throwable;
use HScript\Payment\Routing\PaymentRoutes;
use HScript\Finance\CallbackValidationException;

/**
 * Implements Paykassa deposits, callbacks, balances, and withdrawals.
 */
class Paykassa extends AbstractGateway
{
	private const PAYOUT_IDS = array('PKB' => 11, 'PKE' => 12, 'PKL' => 14);
	private const BALANCE_KEYS = array(
		'PKB' => 'bitcoin_btc',
		'PKE' => 'ethereum_eth',
		'PKL' => 'litecoin_ltc',
	);

	public function getFields(string $type): array
	{
		if ($type === 'pay')
		{
            if(isset(PaymentRoutes::GATEWAYS[$this->id]))return ['acc'=>['Address']];
            if(isset(PaykassaAssets::ASSETS[$this->id]))return ['acc'=>['Address'],...(PaykassaAssets::ASSETS[$this->id][4]?['tag'=>['Tag / Memo']]:[])];
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
        $config = (array)($params['sci'] ?? []);
        $token=isset(PaymentRoutes::GATEWAYS[$this->id]);
        try {
            if (!self::configuration($config) || (!$token && !isset(PaykassaAssets::ASSETS[$this->id][1])))
                return ['error'=>'payment_configuration_invalid'];
            $route=$token?PaymentRoutes::assertSnapshot($this->id,$params['payment_route']??null):null;
            $system=$route!==null?(int)$route['wire']:PaykassaAssets::ASSETS[$this->id][1];
            $amount = self::decimal($params['sum'] ?? null);
            $order = self::positiveId($params['tag'] ?? null);
            $comment = $params['memo'] ?? '';
            if (!is_string($comment) || strlen($comment) > 8192 || !preg_match('//u', $comment))
                return ['error'=>'payment_description_invalid'];
            $response = $this->sciRequest($config, [
                'func'=>'sci_create_order_get_data','amount'=>$amount,
                'currency'=>$this->getCurrencyId(),'order_id'=>$order,
                'comment'=>$comment,'system'=>$system,
            ]);
            if ($response['error']) return ['error'=>'paykassa_invoice_unavailable'];
            $data = $response['data'];
            if (self::positiveId($data['order_id'] ?? null) !== $order
                || self::decimal($data['amount'] ?? null) !== $amount
                || ($data['currency'] ?? null) !== $this->getCurrencyId()
                || !self::matchesSystem($this->id, $data['system'] ?? null) || ($route!==null && strtolower((string)($data['system']??''))!==PaymentRoutes::paykassaSystem($route)))
                return ['error'=>'paykassa_invoice_invalid'];
            $invoice = self::positiveId($data['invoice'] ?? null);
            $address = self::address($data['wallet'] ?? null);
            $destinationTag = self::destinationTag($data['tag'] ?? null,$this->id);
            $url = $data['url'] ?? null;
            if(!self::environmentMatches($data))return ['error'=>'paykassa_invoice_invalid'];
            $hash = self::paymentLinkHash($url);
            if(isset($data['invoice_id']) && self::positiveId($data['invoice_id'])!==$invoice)return ['error'=>'paykassa_invoice_invalid'];
            // Stored by DepositCheckout before browser fields are stripped.
            return ['url'=>$url,'get'=>true,'hash'=>$hash,'psysorder'=>$invoice,
                'paykassa_merchant_id'=>(string)$config['id'],
                'paykassa_amount'=>$amount,'paykassa_currency'=>$this->getCurrencyId(),
                'paykassa_system'=>strtolower((string)$data['system']),
                'paykassa_address'=>$address,'paykassa_tag'=>$destinationTag,
                'paykassa_invoice'=>$invoice,'paykassa_hash'=>hash('sha256',$hash),
                ...($route!==null?['payment_route'=>$route]:[]),
            ];
        } catch (InvalidArgumentException) {
            return ['error'=>'paykassa_invoice_invalid'];
        } catch (Throwable) {
            return ['error'=>'paykassa_invoice_unavailable'];
        }
    }

    public function handleCallback(array $request): array
    {
        if (!\HScript\Payment\CallbackCapabilities::allows($this->id,'in'))
            return CallbackResult::failure('unsupported','automatic_callback_unsupported');
        $config = (array)($request['_config'] ?? []);
        if (!self::configuration($config))
            return CallbackResult::failure('authentication_failed','callback_configuration_missing');
        try {
            $payload = FormCallbackPayload::decode($request['_raw_body'] ?? '');
            $token = $payload['private_hash'] ?? null;
            if (!is_string($token) || !preg_match('/^[\x21-\x7e]{1,256}$/D',$token))
                throw new InvalidArgumentException();
            $order = self::positiveId($payload['order_id'] ?? null);
            if (($payload['currency'] ?? null) !== $this->getCurrencyId()
                || !self::matchesSystem($this->id,$payload['system'] ?? null))
                return CallbackResult::failure('invalid_payload','paykassa_route_mismatch');
        } catch (InvalidArgumentException|\TypeError) {
            return CallbackResult::failure('invalid_payload','callback_fields_invalid');
        }
        $context=$this->callbackIntent((int)$order,$config);
        if(isset($context['verified']['paykassa_system']) && strtolower($payload['system'])!==$context['verified']['paykassa_system'])return CallbackResult::failure('invalid_payload','payment_route_mismatch');
        $claim=$this->verificationClaim($context,$token);
        if ($claim['cached']) return $context['verified'];
        // Only the exact decoded form token is submitted, never global request state.
        // Transport failure propagates a fixed exception to the controller's 503.
        $response = $this->sciRequest($config,['func'=>'sci_confirm_order','private_hash'=>$token]);
        if ($response['error'])
            return CallbackResult::failure('authentication_failed','paykassa_confirmation_failed');
        $data = $response['data'];
        if(!self::environmentMatches($data))return CallbackResult::failure('invalid_payload','paykassa_environment_mismatch',true);
        try {
            $merchant = self::positiveId($data['shop_id'] ?? null);
            if (!hash_equals((string)$config['id'],$merchant))
                return CallbackResult::failure('authentication_failed','merchant_mismatch');
            if (self::positiveId($data['order_id'] ?? null) !== $order)
                return CallbackResult::failure('invalid_payload','paykassa_order_mismatch',true);
            if (($data['currency'] ?? null) !== $this->getCurrencyId()
                || !self::matchesSystem($this->id,$data['system'] ?? null))
                return CallbackResult::failure('invalid_payload','currency_mismatch',true);
            if (($data['partial'] ?? null) !== 'no')
                return CallbackResult::failure('unsupported','paykassa_partial_unsupported',true);
            $amount = self::decimal($data['amount'] ?? null);
            $batch = self::positiveId($data['transaction'] ?? null);
            $address = self::address($data['address'] ?? null);
            $destinationTag = self::destinationTag($data['tag'] ?? null,$this->id);
            $invoiceHash = self::opaqueHash($data['hash'] ?? null);
        } catch (InvalidArgumentException) {
            return CallbackResult::failure('invalid_payload','paykassa_confirmation_invalid',true);
        }
        if(isset($context['verified']['paykassa_system']) && strtolower($data['system'])!==$context['verified']['paykassa_system'])return CallbackResult::failure('invalid_payload','payment_route_mismatch',true);
        $verified=['outcome'=>'verified','reason'=>'provider_confirmed','authenticated'=>true,'correct'=>true,
            'sum'=>$amount,'sum2'=>$amount,'curr'=>$this->getCurrencyId(),'tag'=>$order,
            'accto'=>$address,'destination_tag'=>$destinationTag,'merchant_id'=>$merchant,
            'provider_invoice_hash'=>hash('sha256',$invoiceHash),'batch'=>$batch,
            'response'=>$order.'|success','profile_key_hash'=>hash('sha256',$config['key']),'profile_timezone'=>'',
            ...(isset($context['verified']['payment_route_hash'])?['payment_route_hash'=>$context['verified']['payment_route_hash'],'paykassa_system'=>strtolower($data['system'])]:[]),
        ];
        $this->verificationComplete($context,$claim,$verified);
        return $verified;
    }

    protected function callbackIntent(int $order,array $config): array
    {
        return PaykassaVerification::intent($this->db,$this->id,$order,$config);
    }

    protected function verificationClaim(array $context,string $token): array
    {
        return PaykassaVerification::claim($this->db,$context,$token);
    }

    protected function verificationComplete(array $context,array $claim,array $verified): void
    {
        PaykassaVerification::complete($this->db,$context,$claim,$verified);
    }

    protected function sciRequest(array $config, array $fields): array
    {
        return PaykassaSciClient::request((string)$config['id'],(string)$config['key'],$fields);
    }

    private static function configuration(array $config): bool
    {
        return PaykassaAssets::liveConfiguration($config) && is_string($config['id'] ?? null) && preg_match('/^[1-9][0-9]{0,14}$/D',$config['id'])
            && is_string($config['key'] ?? null) && trim($config['key']) !== '' && strlen($config['key']) <= 8192;
    }

    private static function matchesSystem(string $id, mixed $system): bool
    {
        if(isset(PaymentRoutes::GATEWAYS[$id])) {foreach(PaymentRoutes::catalog($id) as $r)if(is_string($system) && strtolower($system)===PaymentRoutes::paykassaSystem($r))return true;return false;}
        return is_string($system) && strtolower($system) === (PaykassaAssets::ASSETS[$id][2]??null);
    }

    private static function positiveId(mixed $value): string
    {
        if (is_int($value)) $value = (string)$value;
        if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,14}$/D',$value))
            throw new InvalidArgumentException();
        return $value;
    }

    private static function decimal(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[0-9]{1,12}(?:\.[0-9]{1,8})?$/D',$value))
            throw new InvalidArgumentException();
        return RequestDecimal::normalize($value);
    }

    private static function address(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9:_-]{20,128}$/D',$value))
            throw new InvalidArgumentException();
        return $value;
    }

    private static function destinationTag(mixed $value,string $gateway): string
    {
        if(!(PaykassaAssets::ASSETS[$gateway][4]??false)){
            if($value==='' || $value===false)return '';
            throw new InvalidArgumentException();
        }
        if(!is_string($value) || trim($value)==='' || !preg_match('/^[A-Za-z0-9 ._:-]{1,128}$/D',$value))throw new InvalidArgumentException();
        return $value;
    }

    private static function opaqueHash(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$value))
            throw new InvalidArgumentException();
        return $value;
    }

    private static function environmentMatches(array $data): bool
    {
        return !array_key_exists('test_mode',$data) || $data['test_mode']===false;
    }

    private static function paymentLinkHash(mixed $url): string
    {
        if (!is_string($url) || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/',$url))
            throw new InvalidArgumentException();
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') !== 'crypto.paykassa.pro' || ($parts['path'] ?? '') !== '/sci/index.php'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment']))
            throw new InvalidArgumentException();
        $query = FormCallbackPayload::decode($parts['query'] ?? '');
        if (array_keys($query) !== ['hash']) throw new InvalidArgumentException();
        return self::opaqueHash($query['hash']);
    }

	public function getBalance(array $config): array
	{
        if(!PaykassaAssets::liveConfiguration($config))return ['result'=>'paykassa_sandbox_removed','sum'=>-1];
        if(!isset(self::BALANCE_KEYS[$this->id]))return ['result'=>'balance_unsupported','sum'=>-1];
		$this->requireLegacy('lib/paykassa/paykassa_api.class.php');
		$client = new \PayKassaAPI($this->value($config, 'id'), $this->value($config, 'apipass'));
		$response = $client->api_get_shop_balance($this->value($config, 'shop_id'));
		if (!empty($response['error']))
			return array('result' => $response['message'], 'sum' => -1);
		return array('result' => 'OK', 'sum' => GatewayBalance::read($response['data'][self::BALANCE_KEYS[$this->id]]));
	}

	public function processWithdrawal(array $params): array
	{
        if(!isset(self::PAYOUT_IDS[$this->id]))return ['result'=>'automatic_payout_unsupported'];
        if(!PaykassaAssets::liveConfiguration($params) || !is_array($params['from']??[]) || !PaykassaAssets::liveConfiguration($params['from']??[]))return ['result'=>'paykassa_sandbox_removed'];
		$from = (array)$this->value($params, 'from', array());
		$to = (array)$this->value($params, 'to', array());
		$amount=GatewayAmount::exact($this->value($params,'sum'),$this->getCurrencyId(),8);
		$this->requireLegacy('lib/paykassa/paykassa_api.class.php');
		$client = new \PayKassaAPI($this->value($from, 'id'), $this->value($from, 'apipass'));
		$response = $client->api_payment(
			$this->value($from, 'shop_id'),
			self::PAYOUT_IDS[$this->id],
			$this->value($to, 'acc'),
			$amount,
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
