<?php

namespace HScript\Payment\Gateways;

use HScript\Payment\AbstractGateway;
use HScript\Payment\GatewayAmount;
use HScript\Payment\GatewayBalance;
use HScript\Payment\CallbackResult;
use Throwable;
use RuntimeException;
use InvalidArgumentException;
use HScript\Payment\FormCallbackPayload;
use HScript\Payment\VoletMerchantClient;
use HScript\Finance\VoletContract;
use HScript\Finance\CallbackContract;
use HScript\Finance\CallbackAmount;
use HScript\Finance\CallbackValidationException;
use HScript\Finance\DepositCheckout;

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
        if (\HScript\Payment\CallbackCapabilities::disabledAutomation($this->id)) return ['error'=>'automatic_payment_unsupported'];
        if (!VoletContract::supports($this->id) || VoletContract::CURRENCIES[$this->id]!==$this->getCurrencyId()) return ['error'=>'volet_currency_contract_unsupported'];
        $sci=(array)($params['sci'] ?? []); $api=(array)($params['api'] ?? []);
        if (!VoletContract::configured($sci,$api)) return ['error'=>'volet_api_configuration_required'];
        try {
            $amount=CallbackAmount::atScale($params['sum'] ?? null,VoletContract::wireScale($this->getCurrencyId()));
            $order=$params['tag'] ?? null;
            if (is_int($order)) $order=(string)$order;
            $scale=$params['callback_scale'] ?? 2;
            if (!is_string($order) || !preg_match('/^[1-9][0-9]{0,14}$/D',$order)
                || !is_int($scale) || $scale<0 || $scale>8 || CallbackAmount::atScale($amount,$scale)==='')
                return ['error'=>'volet_invoice_invalid'];
            $fees=VoletContract::localFeePolicy($params['callback_fee_policy'] ?? []);
            $memo=$params['memo'] ?? '';
            if (!is_string($memo) || preg_match('//u',$memo)!==1 || mb_strlen($memo,'UTF-8')>255)
                return ['error'=>'volet_invoice_invalid'];
            foreach (['url_ok','url_fail','url_callback'] as $field)
                if (!is_string($params[$field] ?? '') || strlen($params[$field] ?? '')>100)
                    return ['error'=>'volet_invoice_invalid'];
            $sign=hash('sha256',implode(':',[$sci['name'],$sci['sci'],$amount,$this->getCurrencyId(),$sci['key'],$order]));
            return ['url'=>VoletContract::CHECKOUT_URL,
                'ac_account_email'=>$sci['name'],'ac_sci_name'=>$sci['sci'],'ac_amount'=>$amount,'ac_currency'=>$this->getCurrencyId(),
                'ac_order_id'=>$order,'ac_comments'=>$params['memo'] ?? '', 'ac_ps'=>'VOLET',
                'ac_success_url'=>$params['url_ok'] ?? '', 'ac_success_url_method'=>'POST',
                'ac_fail_url'=>$params['url_fail'] ?? '', 'ac_fail_url_method'=>'POST',
                'ac_status_url'=>$params['url_callback'] ?? '', 'ac_status_url_method'=>'POST','ac_sign'=>$sign,
                // DepositCheckout encrypts these before exposing the public form.
                'volet_profile'=>VoletContract::profile($this->id),'volet_api_email'=>$api['name'],'volet_api_name'=>$api['api'],
                'volet_api_secret'=>$api['apipass'],'volet_wallet'=>$api['acc'],'volet_sci_key_hash'=>hash('sha256',$sci['key']),
                'volet_amount'=>$amount,'volet_scale'=>(string)$scale,
                'volet_local_fee_percent'=>$fees['percent'],'volet_local_fee_min'=>$fees['min'],
                'volet_local_fee_max'=>$fees['max'],'volet_local_fee_commission'=>$fees['commission'],
            ];
        } catch (CallbackValidationException $error) { return ['error'=>$error->reason==='volet_local_fee_contract_unsupported' ? $error->reason : 'volet_invoice_invalid']; }
    }

    public function handleCallback(array $request): array
    {
        if (!\HScript\Payment\CallbackCapabilities::allows($this->id,'in')) return CallbackResult::failure('unsupported','automatic_callback_unsupported');
        if (!VoletContract::supports($this->id) || VoletContract::CURRENCIES[$this->id]!==$this->getCurrencyId())
            return CallbackResult::failure('unsupported','volet_currency_contract_unsupported');
        $sci=(array)($request['_config'] ?? []);
        if (!is_string($sci['key'] ?? null) || trim($sci['key'])==='')
            return CallbackResult::failure('authentication_failed','callback_configuration_missing');
        try {
            $payload=FormCallbackPayload::decode($request['_raw_body'] ?? '');
            $signed=['ac_transfer','ac_start_date','ac_sci_name','ac_src_wallet','ac_dest_wallet','ac_order_id','ac_amount','ac_merchant_currency'];
            foreach ($signed as $field)
                if (!isset($payload[$field]) || $payload[$field]==='' || preg_match('/[\x00-\x1f\x7f]/',$payload[$field]))
                    throw new InvalidArgumentException();
            $signature=hash('sha256',implode(':',array_map(static fn($field)=>$payload[$field],$signed)).':'.$sci['key']);
            if (!preg_match('/^[a-f0-9]{64}$/D',$payload['ac_hash'] ?? '') || !hash_equals($signature,$payload['ac_hash']))
                return CallbackResult::failure('authentication_failed','signature_invalid');
            if ($payload['ac_merchant_currency']!==$this->getCurrencyId() || !preg_match('/^[1-9][0-9]{0,14}$/D',$payload['ac_order_id'])
                || !preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$payload['ac_transfer'])
                || !preg_match('/^[A-Za-z0-9]{1,64}$/D',$payload['ac_src_wallet'])
                || !preg_match('/^[A-Za-z0-9]{1,64}$/D',$payload['ac_dest_wallet'])) throw new InvalidArgumentException();
            $signedAt=VoletContract::sciTime($payload['ac_start_date']);
            // Read intent before provider I/O; final settlement re-reads it locked.
            $operation=$this->callbackOperation((int)$payload['ac_order_id']);
            if (!$operation || $operation['oOper']!=='CASHIN' || $operation['cCID']!==$this->id || $operation['cCurrID']!==$this->getCurrencyId())
                throw new CallbackValidationException('callback_order_unavailable');
            opDecodeCurrParams($operation,$pay,$currentSci,$api);
            if (!VoletContract::configured($currentSci,$api))
                return CallbackResult::failure('unsupported','volet_api_configuration_required',true);
            if (($currentSci['key'] ?? null)!==$sci['key']) throw new CallbackValidationException('callback_merchant_configuration_changed');
            $scale=CallbackContract::scale($operation);
            $amount=CallbackAmount::atScale($payload['ac_amount'],$scale);
            $expected=CallbackAmount::atScale($operation['oSum'],$scale);
            if ($amount!==$expected) throw new CallbackValidationException('callback_amount_mismatch');
            $form=DepositCheckout::storedForm($operation);
            $intent=VoletContract::intent($operation,$form,$currentSci,$api,$scale,$expected);
            if ($payload['ac_order_id']!==$form['ac_order_id'] || $payload['ac_sci_name']!==$form['ac_sci_name'] || $payload['ac_dest_wallet']!==$form['volet_wallet'])
                throw new CallbackValidationException('callback_destination_mismatch');
            $result=['outcome'=>'verified','reason'=>'volet_api_order_verified','authenticated'=>true,'correct'=>true,
                'tag'=>$payload['ac_order_id'],'sum'=>$amount,'sum2'=>$amount,'curr'=>$this->getCurrencyId(),
                'accto'=>$payload['ac_dest_wallet'],'accfrom'=>$payload['ac_src_wallet'],'batch'=>$payload['ac_transfer'],
                'signed_at'=>$signedAt,'date'=>$signedAt,'volet_profile'=>VoletContract::profile($this->id)];
            $result['volet_fee_policy_hash']=$intent['local_fee_policy_hash'];
            $result['volet_proof_hash']=VoletContract::proofHash($intent,$result);
            // Includes local creation/skew validation without changing any state.
            VoletContract::validate($operation,$result,$form,$currentSci,$api,$scale,$expected,time(),(int)$operation['oState']!==3);
        } catch (CallbackValidationException $error) {
            return CallbackResult::failure('invalid_payload',$error->reason,true);
        } catch (InvalidArgumentException|\TypeError) {
            return CallbackResult::failure('invalid_payload','callback_fields_invalid');
        }
        $order=$this->merchantRequest($api,'findPaymentByOrderId',['order'=>$form['ac_order_id'],'sci'=>$form['ac_sci_name']]);
        foreach (['orderId','sciName','paymentCreated','paymentStatus','transactionId','transactionStatus','transactionAmount','transactionCurrency','transactionCreated'] as $field)
            if (!is_string($order[$field] ?? null) || $order[$field]==='') throw new RuntimeException('volet_confirmation_unavailable');
        if ($order['orderId']!==$form['ac_order_id'] || $order['sciName']!==$form['ac_sci_name']
            || $order['transactionId']!==$result['batch'] || $order['transactionCurrency']!==$this->getCurrencyId()
            || CallbackAmount::atScale($order['transactionAmount'],$scale)!==$amount)
            throw new CallbackValidationException('callback_volet_order_mismatch');
        if ($order['paymentStatus']!=='COMPLETED' || $order['transactionStatus']!=='COMPLETED')
            return CallbackResult::failure('unsupported','volet_payment_not_completed',true);
        $created=VoletContract::apiTime($order['transactionCreated']);
        if ($created!==$signedAt || VoletContract::apiTime($order['paymentCreated'])>$created
            || isset($order['transactionCompleted']) && VoletContract::apiTime($order['transactionCompleted'])<$created)
            throw new CallbackValidationException('callback_time_conflict');
        $record=$this->merchantRequest($api,'findTransaction',['transaction'=>$order['transactionId']]);
        foreach (['id','amount','amountIncome','api','currency','currencyIncome','direction','fullCommission','orderId','sci','startTime','status','transactionType','walletDestId','walletSrcId'] as $field)
            if (!is_string($record[$field] ?? null) || $record[$field]==='') throw new RuntimeException('volet_confirmation_unavailable');
        if ($record['status']!=='COMPLETED' || $record['transactionType']!=='SCI_PAYMENT' || $record['direction']!=='INCOMING'
            || !in_array($record['sci'],['true','1'],true) || !in_array($record['api'],['false','0'],true))
            return CallbackResult::failure('unsupported','volet_transaction_contract_unsupported',true);
        if ($record['id']!==$result['batch'] || $record['orderId']!==$result['tag'] || $record['walletDestId']!==$result['accto']
            || $record['walletSrcId']!==$result['accfrom'] || $record['currency']!==$this->getCurrencyId()
            || isset($order['srcWalletId']) && $order['srcWalletId']!==$result['accfrom']
            || isset($record['receiverEmail']) && $record['receiverEmail']!==$api['name'])
            throw new CallbackValidationException('callback_volet_transaction_mismatch');
        if (CallbackAmount::atScale($record['amount'],$scale)!==$amount)
            throw new CallbackValidationException('callback_amount_mismatch');
        if (!preg_match('/^0{1,12}(?:\.0{1,8})?$/D',$record['fullCommission']))
            return CallbackResult::failure('unsupported','volet_fee_contract_unsupported',true);
        if (CallbackAmount::atScale($record['amountIncome'],$scale)!==$amount || $record['currencyIncome']!==$this->getCurrencyId())
            return CallbackResult::failure('unsupported','volet_income_contract_unsupported',true);
        foreach ([$order,$record] as $proof)
            foreach ($proof as $field=>$value)
                if ((str_starts_with($field,'cryptoCurrency') || in_array($field,['coinName','receivedCryptoCurrencyAmount','refundAddress','parentTransactionId'],true)) && $value!=='')
                    return CallbackResult::failure('unsupported','volet_payment_method_unsupported',true);
        if (VoletContract::apiTime($record['startTime'])!==$signedAt
            || isset($record['updatedTime']) && VoletContract::apiTime($record['updatedTime'])<$created)
            throw new CallbackValidationException('callback_time_conflict');
        // Unsigned SCI status, fee, merchant_amount and buyer email never decide
        // settlement, financial amount, sender identity or durable proof.
        return $result;
    }

    protected function callbackOperation(int $id): array|false
    {
        return $this->db->fetch1Row($this->db->query('SELECT Opers.*,Currs.* FROM Opers JOIN Currs ON cID=ocID WHERE oID=?d',[$id]));
    }

    protected function merchantRequest(array $config, string $method, array $arguments): array
    {
        return VoletMerchantClient::request($config,$method,$arguments);
    }

	public function getBalance(array $config): array
	{
		if (\HScript\Payment\CallbackCapabilities::disabledAutomation($this->id)) return ['result'=>'automatic_payment_unsupported','sum'=>-1];
		$result = array('result' => 'NoConn', 'sum' => -1);
		try
		{
			$this->requireLegacy('lib/advcash/MerchantWebService.php');
			$service = $this->merchantService();
			$auth = new \authDTO();
			$auth->apiName = $this->value($config, 'api');
			$auth->accountEmail = $this->value($config, 'name');
			$auth->authenticationToken = $service->getAuthenticationToken($this->value($config, 'apipass'));
			$query = new \getBalances();
			$query->arg0 = $auth;
			$response = $service->getBalances($query);
			$return=$response->return ?? null;
			$result['answer'] = print_r($return, true);
			$balances=$return===null ? [] : (is_array($return) ? $return : [$return]);
			foreach ($balances as $balance)
				if ($balance->id === $this->value($config, 'acc'))
				{
					$result['result'] = 'OK';
					$result['sum'] = GatewayBalance::read($balance->amount);
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
        if (VoletContract::supports($this->id) && $this->id!=='AC') return ['result'=>'automatic_payment_unsupported'];
		if (\HScript\Payment\CallbackCapabilities::disabledAutomation($this->id)) return ['result'=>'automatic_payment_unsupported'];
		$from = (array)$this->value($params, 'from', array());
		$to = (array)$this->value($params, 'to', array());
		try
		{
			$amount=GatewayAmount::exact($this->value($params,'sum'),$this->getCurrencyId(),2);
			$this->requireLegacy('lib/advcash/MerchantWebService.php');
			$service = $this->merchantService();
			$auth = new \authDTO();
			$auth->apiName = $this->value($from, 'api');
			$auth->accountEmail = $this->value($from, 'name');
			$auth->authenticationToken = $service->getAuthenticationToken($this->value($from, 'apipass'));
			$payment = new \sendMoneyRequest();
			$payment->amount = $amount;
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

	/** Trusted PHP seam for the generated SDK; no request/configuration URL override. */
	protected function merchantService(): \MerchantWebService
	{
		return new \MerchantWebService();
	}

	protected function requiredConfigKeys(): array
	{
		return $this->isPaykassa() ? array('id', 'key') : array('name', 'sci', 'key');
	}

	private function isPaykassa(): bool
	{
		return str_starts_with($this->id, 'PK');
	}

}
