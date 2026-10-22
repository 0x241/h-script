<?php

declare(strict_types=1);

namespace HScript\Finance;

use DateTimeImmutable;
use DateTimeZone;

/** Currency-bound full/fee-zero SCI intent, saved inside encrypted checkout. */
final class VoletContract
{
    public const PROFILE = 'volet-soap-v1-usd-full-provider-and-local-fee0';
    public const CURRENCIES=['AC'=>'USD','VEUR'=>'EUR','VUST'=>'USDT','VUSC'=>'USDC','VBTC'=>'BTC','VETH'=>'ETH','VSOL'=>'SOL','VTRX'=>'TRX','VXRP'=>'XRP','VTON'=>'TON','VLTC'=>'LTC'];
    public static function supports(string $gateway): bool { return isset(self::CURRENCIES[$gateway]); }
    public static function profile(string $gateway): string { return $gateway==='AC' ? self::PROFILE : 'volet-soap-v2-currency-full-fee0'; }
    public static function wireScale(string $asset): int { return in_array($asset,['USD','EUR'],true)?2:6; }
    public const CHECKOUT_URL = 'https://account.volet.com/api/public/payment-gateway';

    public static function configured(array $sci, array $api): bool
    {
        foreach (['name','sci','key'] as $key)
            if (!self::text($sci[$key] ?? null, $key==='sci' ? 256 : 8192)) return false;
        foreach (['name','api','apipass','acc'] as $key)
            if (!self::text($api[$key] ?? null,8192)) return false;
        return mb_strlen($sci['sci'],'UTF-8')<=50 && filter_var($sci['name'],FILTER_VALIDATE_EMAIL)!==false
            && $sci['name']===$api['name'] && preg_match('/^[A-Za-z0-9]{1,64}$/D',$api['acc'])===1;
    }

    private static function text(mixed $value, int $limit): bool
    {
        return is_string($value) && trim($value)!=='' && strlen($value)<=$limit && preg_match('//u',$value)===1
            && !preg_match('/[\x00-\x1f\x7f]/',$value);
    }

    /** This bounded profile does not change the legacy commission algorithm. */
    public static function localFeePolicy(array $operation): array
    {
        try {
            $policy=[];
            foreach (['cCASHINComis'=>'percent','cCASHINComisMin'=>'min','cCASHINComisMax'=>'max','oComis'=>'commission'] as $field=>$name) {
                $value=$operation[$field] ?? null;
                if (!is_string($value) && !is_int($value)) throw new \InvalidArgumentException();
                $policy[$name]=RequestDecimal::normalize($value,true);
            }
            if ($policy['percent']!=='0' || $policy['min']!=='0' || $policy['commission']!=='0')
                throw new \InvalidArgumentException();
            return $policy;
        } catch (\InvalidArgumentException) {
            throw new CallbackValidationException('volet_local_fee_contract_unsupported');
        }
    }

    public static function feeBinding(array $operation, array $form): array
    {
        if (($form['volet_profile'] ?? null)!==self::profile($operation['cCID'])) throw new CallbackValidationException('callback_invoice_unavailable');
        $frozen=[];
        foreach (['percent','min','max','commission'] as $name) {
            $value=$form['volet_local_fee_'.$name] ?? null;
            if (!is_string($value)) throw new CallbackValidationException('callback_invoice_unavailable');
            try { $canonical=RequestDecimal::normalize($value,true); }
            catch (\InvalidArgumentException) { throw new CallbackValidationException('callback_invoice_unavailable'); }
            if ($canonical!==$value) throw new CallbackValidationException('callback_invoice_unavailable');
            $frozen[$name]=$value;
        }
        if ($frozen['percent']!=='0' || $frozen['min']!=='0' || $frozen['commission']!=='0')
            throw new CallbackValidationException('callback_invoice_unavailable');
        try { $current=self::localFeePolicy($operation); }
        catch (CallbackValidationException) { throw new CallbackValidationException('callback_local_fee_configuration_changed'); }
        if ($current!==$frozen) throw new CallbackValidationException('callback_local_fee_configuration_changed');
        return $frozen;
    }

    public static function sciTime(string $value): int
    {
        $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d H:i:s')!==$value || $date->getTimestamp()<1)
            throw new CallbackValidationException('callback_time_invalid');
        return $date->getTimestamp();
    }

    public static function apiTime(string $value): int
    {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,3}))?(Z|[+-](?:0[0-9]|1[0-3]):[0-5][0-9]|[+-]14:00)$/D',$value,$parts))
            throw new CallbackValidationException('callback_time_invalid');
        $canonical=$parts[1].'.'.str_pad($parts[2] ?? '',3,'0').($parts[3]==='Z' ? '+00:00' : $parts[3]);
        $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.vP',$canonical);
        if (!$date || $date->format('Y-m-d\TH:i:s.vP')!==$canonical || $date->getTimestamp()<1)
            throw new CallbackValidationException('callback_time_invalid');
        return $date->getTimestamp();
    }

    /** Require immutable metadata and current explicit credentials to agree. */
    public static function intent(array $operation, ?array $form, array $sci, array $api, int $scale, string $expected): array
    {
        if (!self::configured($sci,$api)) throw new CallbackValidationException('callback_api_configuration_missing');
        if ($form===null || ($form['url'] ?? null)!==self::CHECKOUT_URL || ($form['volet_profile'] ?? null)!==self::profile($operation['cCID']))
            throw new CallbackValidationException('callback_invoice_unavailable');
        foreach (['volet_api_email','volet_api_name','volet_api_secret','volet_wallet','volet_sci_key_hash','volet_amount','volet_scale','ac_account_email','ac_sci_name','ac_amount','ac_currency','ac_order_id','ac_sign'] as $field)
            if (!is_string($form[$field] ?? null)) throw new CallbackValidationException('callback_invoice_unavailable');
        if (!self::supports($operation['cCID']) || $operation['cCurrID']!==self::CURRENCIES[$operation['cCID']] || $form['ac_currency']!==$operation['cCurrID'])
            throw new CallbackValidationException('callback_currency_mismatch');
        if (!preg_match('/^[0-8]$/D',$form['volet_scale']) || (int)$form['volet_scale']!==$scale)
            throw new CallbackValidationException('callback_precision_configuration_changed');
        if ($form['ac_order_id']!==(string)$operation['oID']) throw new CallbackValidationException('callback_invoice_identity_mismatch');
        if (CallbackAmount::atScale($form['volet_amount'],self::wireScale($operation['cCurrID']))!==CallbackAmount::atScale($expected,self::wireScale($operation['cCurrID']))
            || CallbackAmount::atScale($form['ac_amount'],$scale)!==$expected
            || $form['volet_amount']!==$form['ac_amount']) throw new CallbackValidationException('callback_invoice_amount_mismatch');
        if ($form['ac_account_email']!==$sci['name'] || $form['ac_sci_name']!==$sci['sci'] || $form['volet_api_email']!==$api['name']
            || $form['volet_api_name']!==$api['api'] || $form['volet_api_secret']!==$api['apipass'] || $form['volet_wallet']!==$api['acc']
            || !hash_equals(hash('sha256',$sci['key']),$form['volet_sci_key_hash']))
            throw new CallbackValidationException('callback_merchant_configuration_changed');
        $sign=hash('sha256',implode(':',[$form['ac_account_email'],$form['ac_sci_name'],$form['ac_amount'],$operation['cCurrID'],$sci['key'],$form['ac_order_id']]));
        if (!hash_equals($sign,$form['ac_sign'])) throw new CallbackValidationException('callback_invoice_identity_mismatch');
        $fees=self::feeBinding($operation,$form);
        return ['local_fee_policy'=>$fees,'local_fee_policy_hash'=>IdempotencyRequest::fingerprint($fees),'profile'=>self::profile($operation['cCID']),'order'=>$form['ac_order_id'],'asset'=>$operation['cCurrID'],'amount'=>$expected,'scale'=>$scale,
            'merchant_hash'=>hash('sha256',$form['ac_account_email']),'sci_hash'=>hash('sha256',$form['ac_sci_name']),
            'destination_hash'=>hash('sha256',$form['volet_wallet']), 'api_name_hash'=>hash('sha256',$form['volet_api_name']),
            'api_key_hash'=>hash('sha256',$form['volet_api_secret']),'sci_key_hash'=>$form['volet_sci_key_hash']];
    }

    public static function proofHash(array $intent, array $verified): string
    {
        return IdempotencyRequest::fingerprint($intent+['transaction'=>$verified['batch'],
            'source_hash'=>hash('sha256',$verified['accfrom']),'signed_at'=>$verified['signed_at']]);
    }

    public static function validate(array $operation, array $verified, ?array $form, array $sci, array $api, int $scale, string $expected, int $now, bool $firstAcceptance): array
    {
        $intent=self::intent($operation,$form,$sci,$api,$scale,$expected);
        if (($verified['volet_profile'] ?? null)!==self::profile($operation['cCID']) || !is_string($verified['volet_proof_hash'] ?? null)
            || !is_string($verified['batch'] ?? null) || !preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$verified['batch'])
            || !is_string($verified['accfrom'] ?? null) || !preg_match('/^[A-Za-z0-9]{1,64}$/D',$verified['accfrom']))
            throw new CallbackValidationException('callback_volet_proof_invalid');
        if (($verified['accto'] ?? null)!==$form['volet_wallet']) throw new CallbackValidationException('callback_destination_mismatch');
        if (CallbackAmount::atScale($verified['sum2'] ?? null,$scale)!==$expected)
            throw new CallbackValidationException('callback_amount_mismatch');
        $signedAt=$verified['signed_at'] ?? null;
        $created=stampToTime($operation['oCTS']);
        if (!is_int($signedAt) || $signedAt<1 || !$created) throw new CallbackValidationException('callback_time_invalid');
        // Application skew policy; no provider retry/max-age guarantee is inferred.
        if ($firstAcceptance && ($signedAt>$now+CallbackContract::CLOCK_SKEW || $signedAt<$created-CallbackContract::CLOCK_SKEW))
            throw new CallbackValidationException('callback_time_outside_window');
        if (($verified['volet_fee_policy_hash'] ?? null)!==$intent['local_fee_policy_hash'])
            throw new CallbackValidationException('callback_volet_proof_mismatch');
        $proof=self::proofHash($intent,$verified);
        if (!hash_equals($proof,$verified['volet_proof_hash'])) throw new CallbackValidationException('callback_volet_proof_mismatch');
        return ['destination'=>$form['volet_wallet'],'volet_profile'=>self::profile($operation['cCID']),'volet_proof_hash'=>$proof,'volet_fee_policy_hash'=>$intent['local_fee_policy_hash'],'signed_at'=>$signedAt];
    }
}
