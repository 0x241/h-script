<?php

namespace HScript\Finance;

use HScript\Database\Connection;

/** First acceptance binds a verified transaction to local money and intent. */
final class CallbackContract
{
    public const YOO_MAX_AGE = 86400;
    public const CLOCK_SKEW = 300;

    public static function validate(Connection $db, string $gateway, array $operation, array $verified, int $now, bool $firstAcceptance = true): array
    {
        $outbound = !empty($verified['outbound']);
        if ($operation['cCID'] !== $gateway)
            throw new CallbackValidationException('callback_gateway_mismatch');
        $asset = (string)$operation['cCurrID'];
        if ($asset === '' || ($verified['curr'] ?? null) !== $asset)
            throw new CallbackValidationException('callback_currency_mismatch');
        $scale = self::scale($operation);
        $amount = CallbackAmount::atScale($verified['sum'] ?? null, $scale);
        $expected = CallbackAmount::atScale($outbound ? $operation['callbackNetAmount'] : $operation['oSum'], $scale);
        if (!hash_equals($expected, $amount))
            throw new CallbackValidationException('callback_amount_mismatch');
        $inboundIntent=$outbound ? null : InboundIntent::callbackProof($db,$operation);

        $destination = '';
        $descriptionHash = null;
        $paykassa = null;
        $volet = null;
        $ccapi = null;
        $stripe = null;
        $profile = null;
        if (!$outbound && InboundProfile::managed($gateway)) {
            $profile=InboundProfile::original($db,$operation);
            InboundProfile::assertBinding($profile,$verified);
        }
        if ($outbound) {
            $params = strToArray($operation['oParams2']);
            $destination = (string)($params['acc'] ?? '');
            $attempt = WithdrawalDispatcher::callbackProof($db, (int)$operation['oID']);
            if (($attempt['amount'] ?? null) !== $amount || ($attempt['asset'] ?? null) !== $asset
                || ($attempt['gateway'] ?? null) !== $gateway || ($attempt['scale'] ?? null) !== $scale
                || ($attempt['gross'] ?? null) !== CallbackAmount::atScale($operation['oSum'],$scale)
                || ($attempt['commission'] ?? null) !== RequestDecimal::normalize($operation['oComis'],true))
                throw new CallbackValidationException('callback_payout_intent_mismatch');
            if (!hash_equals((string)($attempt['destination_hash'] ?? ''), hash('sha256',$destination))
                || !hash_equals((string)($attempt['destination_tag_hash'] ?? ''), hash('sha256',(string)($params['tag'] ?? '')))
                || !hash_equals((string)($attempt['destination_tag_hash'] ?? ''), hash('sha256',(string)($verified['destination_tag'] ?? ''))))
                throw new CallbackValidationException('callback_destination_mismatch');
        } elseif ($gateway==='ST') {
            $stripe=StripeContract::validate($operation,$verified,DepositCheckout::storedForm($operation));
            $destination=$stripe['destination'];
        } elseif (VoletContract::supports($gateway)) {
            opDecodeCurrParams($operation, $pay, $sci, $api);
            $volet = VoletContract::validate($operation,$verified,DepositCheckout::storedForm($operation),$sci,$api,$scale,$expected,$now,$firstAcceptance);
            $destination = $volet['destination'];
        } elseif (\HScript\Payment\Gateways\CryptoCurrencyApi::supports($gateway)) {
            opDecodeCurrParams($operation,$pay,$sci,$api);
            $ccapi=CcapiV3Contract::validate($db,$operation,$verified,DepositCheckout::storedForm($operation),$sci,$scale,$expected,$now,$firstAcceptance);
            $destination=$ccapi['destination'];
        } elseif (isset(PaykassaContract::SYSTEMS[$gateway])) {
            opDecodeCurrParams($operation, $pay, $sci, $api);
            $paykassa = PaykassaContract::validate($operation,$verified,DepositCheckout::storedForm($operation),$sci,$scale,$expected);
            $destination = $paykassa['destination'];
        } elseif (in_array($gateway, ['CP','CPE','CPL','CPR','YM','YMC','WM'], true)) {
            opDecodeCurrParams($operation, $pay, $sci, $api);
            $form = DepositCheckout::storedForm($operation);
            $field = $gateway === 'WM' ? 'LMI_PAYEE_PURSE' : (str_starts_with($gateway, 'CP') ? 'merchant' : 'receiver');
            $amountField = $gateway === 'WM' ? 'LMI_PAYMENT_AMOUNT' : ($field === 'merchant' ? 'amountf' : 'sum');
            if ($form !== null && CallbackAmount::atScale($form[$amountField] ?? null, $scale) !== $expected)
                throw new CallbackValidationException('callback_invoice_amount_mismatch');
            // A saved invoice must itself identify its receiver. Missing or
            // unreadable fields cannot silently fall back to mutable settings.
            $destination = $form !== null ? (string)($form[$field] ?? '')
                : ($gateway === 'WM' ? (string)($pay['acc'] ?? '') : (string)($sci[$field === 'merchant' ? 'id' : 'acc'] ?? ''));
            if ($gateway === 'WM') {
                $description = $form !== null ? ($form['LMI_PAYMENT_DESC'] ?? null)
                    : (strToArray($operation['oParams2'])['memo'] ?? null);
                // BASE64 is the provider's effective description when present.
                if ($form !== null && array_key_exists('LMI_PAYMENT_DESC_BASE64',$form))
                    $description = is_string($form['LMI_PAYMENT_DESC_BASE64']) ? base64_decode($form['LMI_PAYMENT_DESC_BASE64'],true) : false;
                if (!is_string($description) || $description === '' || !preg_match('//u',$description)
                    || str_contains($description,"\0") || mb_strlen($description,'UTF-8') > 255)
                    throw new CallbackValidationException('callback_invoice_description_unavailable');
                $descriptionHash = hash('sha256',$description);
                if (!is_string($verified['description_hash'] ?? null) || !hash_equals($descriptionHash,$verified['description_hash']))
                    throw new CallbackValidationException('callback_description_mismatch');
            }
        }
        // Every supported automatic incoming profile supplies receiving authority.
        if ($outbound || $paykassa !== null || $volet !== null || in_array($gateway, ['CP','CPE','CPL','CPR','YM','YMC','WM'], true)) {
            $provided = $verified['accto'] ?? null;
            if ($destination === '' || !is_string($provided) || !hash_equals($destination, $provided))
                throw new CallbackValidationException('callback_destination_mismatch');
        }
        $signedAt = $volet['signed_at'] ?? ($ccapi['signed_at'] ?? 0);
        if ($gateway === 'WM' && ($verified['outcome'] ?? '') !== 'preflight') {
            $signedAt = $verified['signed_at'] ?? null;
            if (!is_int($signedAt) || $signedAt < 1)
                throw new CallbackValidationException('callback_time_invalid');
            $created=stampToTime($operation['oCTS']);
            if (!$created) throw new CallbackValidationException('callback_time_invalid');
            if ($firstAcceptance && ($signedAt>$now+self::CLOCK_SKEW || $signedAt<$created-self::CLOCK_SKEW))
                throw new CallbackValidationException('callback_time_outside_window');
        }
        if (in_array($gateway, ['YM','YMC'], true)) {
            $signedAt = $verified['signed_at'] ?? null;
            $created = stampToTime($operation['oCTS']);
            if (!is_int($signedAt) || $signedAt < 1 || !$created)
                throw new CallbackValidationException('callback_time_invalid');
            if ($firstAcceptance && ($signedAt > $now + self::CLOCK_SKEW || $signedAt < $now - self::YOO_MAX_AGE
                || $signedAt < $created - self::CLOCK_SKEW))
                throw new CallbackValidationException('callback_time_outside_window');
        }
        return ['amount'=>$amount, 'asset'=>$asset, 'scale'=>$scale, 'destination_hash'=>hash('sha256', $destination),
            'inbound_profile'=>$profile, 'stripe_proof_hash'=>$stripe['stripe_proof_hash']??null,
            'inbound_intent'=>$inboundIntent,
            'ccapi_route_hash'=>$ccapi['ccapi_route_hash']??null,'ccapi_profile'=>$ccapi['ccapi_profile'] ?? null,'ccapi_invoice_hash'=>$ccapi['ccapi_invoice_hash'] ?? null,
            'ccapi_key_hash'=>$ccapi['ccapi_key_hash'] ?? null,'ccapi_event_hash'=>$ccapi['ccapi_event_hash'] ?? null,
            'ccapi_batch_hash'=>$ccapi['ccapi_batch_hash'] ?? null,
            'ccapi_chain'=>$ccapi['ccapi_chain'] ?? null,'ccapi_position'=>$ccapi['ccapi_position'] ?? null,'ccapi_network_fee'=>$ccapi['ccapi_network_fee'] ?? null,
            'description_hash'=>$descriptionHash,
            'volet_profile'=>$volet['volet_profile'] ?? null,'volet_proof_hash'=>$volet['volet_proof_hash'] ?? null,
            'volet_fee_policy_hash'=>$volet['volet_fee_policy_hash'] ?? null,
            'payment_route_hash'=>$paykassa['payment_route_hash']??null,'merchant_hash'=>$paykassa['merchant_hash'] ?? null,'provider_invoice_hash'=>$paykassa['provider_invoice_hash'] ?? null,
            'destination_tag_bound'=>$paykassa !== null,
            'destination_tag_hash'=>$outbound ? $attempt['destination_tag_hash'] : ($paykassa['destination_tag_hash'] ?? hash('sha256','')), 'signed_at'=>$signedAt];
    }

    public static function scale(array $operation): int
    {
        global $_cfg;
        $scale = (int)$operation['cNumDec'];
        return $scale > 0 ? $scale : (int)($_cfg['UI_NumDec'] ?? 2);
    }

    /** Align legacy arithmetic with the locked currency row just validated. */
    public static function refreshCurrencyContext(array $operation, int $scale): void
    {
        global $_currs;
        $id = (int)$operation['ocID'];
        $currencyFields = array_filter($operation, static fn($field)=>preg_match('/^c[A-Z]/D', (string)$field), ARRAY_FILTER_USE_KEY);
        $_currs[$id] = array_replace($_currs[$id] ?? [], $currencyFields);
        $_currs[$id]['cNumDec'] = $scale;
    }

    /** Accepted provider time/receiver cannot be redirected on a later stage. */
    public static function assertBinding(array $proof, array $verified): void
    {
        if (($proof['asset'] ?? null) !== ($verified['curr'] ?? null))
            throw new CallbackValidationException('callback_currency_mismatch');
        if (isset($proof['inbound_profile'])) InboundProfile::assertBinding($proof['inbound_profile'],$verified);
        if (($proof['signed_at'] ?? 0) && ($verified['signed_at'] ?? null) !== $proof['signed_at'])
            throw new CallbackValidationException('callback_time_conflict');
        if(isset($proof['payment_route_hash']) && ($verified['payment_route_hash']??null)!==$proof['payment_route_hash'])throw new CallbackValidationException('payment_route_mismatch');
        if (isset($proof['ccapi_profile'])) CcapiV3Contract::assertBinding($proof,$verified);
        if (isset($proof['volet_proof_hash']) && (($verified['volet_profile'] ?? null)!==($proof['volet_profile'] ?? null)
            || !is_string($verified['volet_proof_hash'] ?? null) || !hash_equals($proof['volet_proof_hash'],$verified['volet_proof_hash'])))
            throw new CallbackValidationException('callback_volet_proof_mismatch');
        if (isset($proof['volet_fee_policy_hash']) && (!is_string($verified['volet_fee_policy_hash'] ?? null)
            || !hash_equals($proof['volet_fee_policy_hash'],$verified['volet_fee_policy_hash'])))
            throw new CallbackValidationException('callback_volet_proof_mismatch');
        if (isset($proof['description_hash']) && (!is_string($verified['description_hash'] ?? null)
            || !hash_equals($proof['description_hash'],$verified['description_hash'])))
            throw new CallbackValidationException('callback_description_mismatch');
        if (isset($proof['merchant_hash']) && (!is_string($verified['merchant_id'] ?? null)
            || !hash_equals($proof['merchant_hash'],hash('sha256',$verified['merchant_id']))))
            throw new CallbackValidationException('callback_merchant_mismatch');
        if (isset($proof['provider_invoice_hash']) && (!is_string($verified['provider_invoice_hash'] ?? null)
            || !hash_equals($proof['provider_invoice_hash'],$verified['provider_invoice_hash'])))
            throw new CallbackValidationException('callback_invoice_identity_mismatch');
        if (($proof['destination_hash'] ?? hash('sha256','')) !== hash('sha256','')
            && !hash_equals($proof['destination_hash'], hash('sha256', (string)($verified['accto'] ?? ''))))
            throw new CallbackValidationException('callback_destination_mismatch');
        if ((!empty($verified['outbound']) || !empty($proof['destination_tag_bound']))
            && !hash_equals($proof['destination_tag_hash'] ?? '', hash('sha256',(string)($verified['destination_tag'] ?? ''))))
            throw new CallbackValidationException('callback_destination_mismatch');
    }
}
