<?php

namespace HScript\Payment\Gateways;

use HScript\Payment\AbstractGateway;
use HScript\Payment\CallbackResult;
use HScript\Payment\FormCallbackPayload;
use HScript\Finance\CallbackAmount;
use HScript\Finance\InboundProfile;
use HScript\Finance\CallbackValidationException;
use InvalidArgumentException;

/**
 * Implements WebMoney deposit forms and signed callback validation.
 */
class WebMoney extends AbstractGateway
{
	public function getFields(string $type): array
	{
		if ($type === 'pay')
			return array(
				'WMID' => array('WMID', '', '123456789012'),
				'acc' => array('WMZ wallet number', 'Z\d{12}', 'Z123456789012'),
			);
		if ($type === 'sci')
			return array('key' => array('Secret Key'),'timezone'=>array('Notification time zone (IANA)', '', 'UTC'));
		return array();
	}

	public function processDeposit(array $params): array
	{
		$sci=(array)($params['sci'] ?? []);
		if (!InboundProfile::timezone($sci['timezone'] ?? null)) return ['error'=>'webmoney_timezone_unsupported'];
		if (!is_string($sci['key'] ?? null) || trim($sci['key'])==='') return ['error'=>'callback_configuration_missing'];
		$pay = (array)$this->value($params, 'pay', array());
        $description = (string)$this->value($params, 'memo');
        if ($description === '' || !preg_match('//u', $description) || str_contains($description,"\0")
            || mb_strlen($description,'UTF-8') > 255)
            return ['error'=>'payment_description_invalid'];
        $order = $this->value($params,'tag');
        if ((!is_string($order) && !is_int($order)) || !preg_match('/^[1-9][0-9]{0,14}$/D',(string)$order)
            || (string)$order === '999999999999999')
            return ['error'=>'payment_order_invalid'];
		return array(
			'url' => 'https://merchant.wmtransfer.com/lmi/payment_utf.asp',
			'LMI_PAYEE_PURSE' => $this->value($pay, 'acc'),
			'LMI_PAYMENT_AMOUNT' => $this->normalizedAmount($this->value($params, 'sum', 0)),
            'LMI_PAYMENT_DESC_BASE64' => base64_encode($description),
			'LMI_PAYMENT_NO' => $order,
			'LMI_SUCCESS_URL' => $this->value($params, 'url_ok'),
			'LMI_SUCCESS_METHOD' => '1',
			'LMI_FAIL_URL' => $this->value($params, 'url_fail'),
			'LMI_FAIL_METHOD' => '1',
			'LMI_RESULT_URL' => $this->value($params, 'url_callback'),
		);
	}

    public function handleCallback(array $request): array
    {
        $config = (array)$this->value($request, '_config', []);
        if (!\HScript\Payment\CallbackCapabilities::allows($this->id,'in'))
            return CallbackResult::failure('unsupported','automatic_callback_unsupported');
        if (!is_string($config['key'] ?? null) || trim($config['key'])==='')
            return CallbackResult::failure('authentication_failed','callback_configuration_missing');
        if (!InboundProfile::timezone($config['timezone'] ?? null))
            return CallbackResult::failure('unsupported','webmoney_timezone_unsupported');
        try {
            $payload = array_key_exists('_raw_body', $request)
                ? FormCallbackPayload::decode($request['_raw_body'])
                : array_diff_key($request, array_flip(['_config','_json','_server']));
            foreach ($payload as $value) if (!is_string($value)) throw new InvalidArgumentException();
            if (!preg_match('/^Z[0-9]{12}$/D', $payload['LMI_PAYEE_PURSE'] ?? '')
                || !preg_match('/^[1-9][0-9]{0,14}$/D', $payload['LMI_PAYMENT_NO'] ?? '')
                || ($payload['LMI_PAYMENT_NO'] ?? '') === '999999999999999'
                || !in_array($payload['LMI_MODE'] ?? null, ['0','1'], true))
                throw new InvalidArgumentException();
            CallbackAmount::atScale($payload['LMI_PAYMENT_AMOUNT'] ?? null, 2);
            $description = $payload['LMI_PAYMENT_DESC'] ?? null;
            if (!is_string($description) || $description === '' || !preg_match('//u', $description)
                || str_contains($description,"\0") || mb_strlen($description,'UTF-8') > 255)
                throw new InvalidArgumentException();
            if (isset($payload['LMI_HOLD']) && !preg_match('/^(?:0|[1-9][0-9]{0,2})$/D', $payload['LMI_HOLD']))
                throw new InvalidArgumentException();
        } catch (InvalidArgumentException) {
            return CallbackResult::failure('invalid_payload', 'callback_fields_invalid');
        }
        $data = ['accto'=>$payload['LMI_PAYEE_PURSE'], 'sum'=>$payload['LMI_PAYMENT_AMOUNT'],
            'sum2'=>$payload['LMI_PAYMENT_AMOUNT'], 'curr'=>$this->getCurrencyId(), 'tag'=>$payload['LMI_PAYMENT_NO'],
            'description_hash'=>hash('sha256',$description),'profile_key_hash'=>hash('sha256',$config['key']),'profile_timezone'=>$config['timezone']];
        if (($payload['LMI_PREREQUEST'] ?? '0') === '1') {
            if ($payload['LMI_MODE'] !== '0') return CallbackResult::failure('rejected', 'test_notification');
            if ((int)($payload['LMI_HOLD'] ?? 0) > 0) return CallbackResult::failure('unsupported', 'webmoney_hold_unsupported');
            // The controller validates the local order before replying YES.
            // This unsigned exchange never authenticates or settles a payment.
            return ['outcome'=>'preflight','reason'=>'order_validation_required','authenticated'=>false,'correct'=>false] + $data;
        }
        if (($payload['LMI_PREREQUEST'] ?? '0') !== '0')
            return CallbackResult::failure('invalid_payload', 'callback_prerequest_invalid');
        foreach (['LMI_SYS_INVS_NO','LMI_SYS_TRANS_NO'] as $field)
            if (!preg_match('/^[1-9][0-9]{0,31}$/D', $payload[$field] ?? ''))
                return CallbackResult::failure('invalid_payload', 'callback_identity_invalid');
        foreach (['LMI_PAYER_PURSE','LMI_PAYER_WM','LMI_SYS_TRANS_DATE'] as $field)
            if (!isset($payload[$field])) return CallbackResult::failure('invalid_payload', 'callback_field_missing');
        if (!preg_match('/^(?:[A-Z][0-9]{12})?$/D', $payload['LMI_PAYER_PURSE'])
            || !preg_match('/^(?:[0-9]{12})?$/D', $payload['LMI_PAYER_WM']))
            return CallbackResult::failure('invalid_payload', 'callback_payer_invalid');
        if (!preg_match('/^[0-9]{8} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $payload['LMI_SYS_TRANS_DATE']))
            return CallbackResult::failure('invalid_payload', 'callback_time_invalid');
        try { $signedAt=InboundProfile::wallTime($payload['LMI_SYS_TRANS_DATE'],$config['timezone']); }
        catch (CallbackValidationException $error) { return CallbackResult::failure('invalid_payload',$error->reason); }
        $before = [$payload['LMI_PAYEE_PURSE'], $payload['LMI_PAYMENT_AMOUNT']];
        $after = [$payload['LMI_PAYMENT_NO'], $payload['LMI_MODE'], $payload['LMI_SYS_INVS_NO'],
            $payload['LMI_SYS_TRANS_NO'], $payload['LMI_SYS_TRANS_DATE'], (string)$this->value($config,'key'),
            $payload['LMI_PAYER_PURSE'], $payload['LMI_PAYER_WM']];
        $hold = isset($payload['LMI_HOLD']) ? [$payload['LMI_HOLD']] : [];
        $canonical = ['LMI_HASH2'=>implode(';', array_merge($before,$hold,$after)),
            'LMI_HASH'=>implode('', $before) . ($hold ? ';'.$hold[0].';' : '') . implode('', $after)];
        $present = false;
        foreach ($canonical as $field=>$text) {
            if (!isset($payload[$field])) continue;
            $present = true;
            if (preg_match('/^[a-fA-F0-9]{132}$/D', $payload[$field]))
                return CallbackResult::failure('unsupported', 'webmoney_sign_unsupported');
            if (!preg_match('/^[a-fA-F0-9]{64}$/D', $payload[$field])
                || !hash_equals(strtoupper(hash('sha256',$text)), strtoupper($payload[$field])))
                return CallbackResult::failure('authentication_failed', 'signature_invalid');
        }
        if (!$present) return CallbackResult::failure('authentication_failed', 'signature_missing_or_invalid');
        // A delimiter-free HASH cannot bind variable numeric field boundaries.
        // Never fall back to it after absent/invalid HASH2 for automatic credit.
        if (!isset($payload['LMI_HASH2']))
            return CallbackResult::failure('unsupported', 'webmoney_hash2_required', true);
        if ($payload['LMI_MODE'] !== '0') return CallbackResult::failure('rejected', 'test_notification', true);
        if ((int)($payload['LMI_HOLD'] ?? 0) > 0)
            return CallbackResult::failure('unsupported', 'webmoney_hold_unsupported', true);
        return ['outcome'=>'verified','reason'=>'signature_verified','authenticated'=>true,'correct'=>true,
            'accfrom'=>$payload['LMI_PAYER_PURSE'],'date'=>$signedAt,'signed_at'=>$signedAt,
            'batch'=>$payload['LMI_SYS_TRANS_NO']] + $data;
    }

	protected function requiredConfigKeys(): array
	{
		return array('key');
	}
}
