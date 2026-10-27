<?php

declare(strict_types=1);
namespace HScript\Finance;

use HScript\Security\SafeUrl;
use HScript\Database\Connection;

/** PAW VARY invoices bind requested and actually received fiat separately. */
final class PawContract
{
    public static function capture(array $o,array $sci): array
    {
        if(($sci['_provider_enabled']??null)!==true || ($o['cCID']??null)!=='PAW' || (int)($o['cProviderID']??0)<1
            || ($sci['_provider_id']??null)!==(int)$o['cProviderID'] || !is_int($sci['_provider_version']??null) || $sci['_provider_version']<1
            || !is_string($sci['_provider_public']??null) || !preg_match('/^[a-f0-9]{32}$/D',$sci['_provider_public'])
            || !in_array($sci['_provider_environment']??null,['sandbox','live'],true) || !is_string($sci['key']??null) || $sci['key']===''
            || str_starts_with($sci['key'],'sk_sandbox_')!==($sci['_provider_environment']==='sandbox'))throw new CallbackValidationException('paw_profile_invalid');
        return ['version'=>1,'intent'=>InboundIntent::capture($o),'provider_id'=>$sci['_provider_id'],
            'public_id'=>$sci['_provider_public'],'key_version'=>$sci['_provider_version'],'environment'=>$sci['_provider_environment'],
            'key_hash'=>hash('sha256',$sci['key'])];
    }
    public static function metadata(array $proof): array
    {
        return ['operation'=>(string)$proof['intent']['operation_id'],'owner'=>(string)$proof['intent']['owner_id'],
            'currency'=>(string)$proof['intent']['currency_id'],'provider'=>$proof['public_id'],'version'=>(string)$proof['key_version']];
    }
    public static function checkoutUrl(mixed $url): bool
    {
        return is_string($url) && strlen($url)<=512 && SafeUrl::paymentAction($url)
            && preg_match('~^https://pay\.pawpayments\.com/checkout/[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$~D',$url)===1;
    }
    public static function invoice(array $invoice,array $proof): void
    {
        $metadata=$invoice['metadata']??null;$expected=self::metadata($proof);
        if(is_array($metadata)){ksort($metadata);ksort($expected);}
        if(!is_string($invoice['order_id']??null) || !preg_match('/^[a-f0-9]{24}$/D',$invoice['order_id'])
            || ($invoice['billing_type']??null)!=='VARY' || ($invoice['fiat_currency']??null)!==$proof['intent']['asset']
            || ($invoice['extra']??null)!==(string)$proof['intent']['operation_id'] || $metadata!==$expected
            || ($invoice['fee_bearer']??null)!=='client' || !empty($invoice['permanent_address_id']) || !empty($invoice['permanent']))
            throw new CallbackValidationException('paw_invoice_mismatch');
    }
    public static function form(array $o,array $form): array
    {
        try {$proof=json_decode($form['paw_proof']??'',true,8,JSON_THROW_ON_ERROR);}
        catch(\Throwable){throw new CallbackValidationException('paw_invoice_missing');}
        if(!is_array($proof) || ($proof['version']??null)!==1 || ($proof['provider_id']??null)!==(int)$o['cProviderID']
            || !is_int($proof['key_version']??null) || $proof['key_version']<1 || !is_string($proof['public_id']??null)
            || !preg_match('/^[a-f0-9]{32}$/D',$proof['public_id']) || !is_string($proof['key_hash']??null) || !preg_match('/^[a-f0-9]{64}$/D',$proof['key_hash'])
            || !in_array($proof['environment']??null,['live','sandbox'],true) || !is_int($proof['created_at']??null) || !is_int($proof['expires_at']??null)
            || $proof['created_at']<1 || $proof['expires_at']<=$proof['created_at'] || $proof['expires_at']-$proof['created_at']>86400
            || !is_string($form['paw_order']??null) || !preg_match('/^[a-f0-9]{24}$/D',$form['paw_order'])
            || ($proof['order_id']??null)!==$form['paw_order'] || ($proof['environment']==='live' && !self::checkoutUrl($form['url']??null))
            || ($proof['environment']==='sandbox' && (($form['formkind']??null)!=='paw_sandbox' || !SafeUrl::redirect($form['url']??''))))
            throw new CallbackValidationException('paw_invoice_mismatch');
        InboundIntent::assertProof($proof['intent']??null);
        $current=InboundIntent::capture(array_replace($o,['oSum'=>$proof['intent']['gross']]));
        if($current!==$proof['intent'])throw new CallbackValidationException('paw_invoice_mismatch');
        return $proof;
    }
    /** Original merchant/key snapshot was committed before provider I/O. */
    public static function attempt(Connection $db,array $o,array $form): array
    {
        $intent=InboundIntent::attemptProof($db,$o);
        if($intent===null)throw new CallbackValidationException('paw_invoice_missing');
        $q=$db->link->prepare("SELECT ikResponse FROM IdempotencyKeys WHERE ikNamespace='invoice:attempt' AND ikKeyHash=? FOR UPDATE");
        $q->execute([hash('sha256',(int)$o['ouID']."\0".(int)$o['oID'])]);
        try{$data=json_decode($q->fetchColumn(),true,32,JSON_THROW_ON_ERROR);}
        catch(\Throwable){throw new CallbackValidationException('paw_invoice_missing');}
        $proof=self::form($o,$form);unset($proof['order_id'],$proof['created_at'],$proof['expires_at']);
        if(($data['data']['paw_profile']??null)!==$proof || $proof['intent']!==$intent)throw new CallbackValidationException('paw_profile_mismatch');
        return $intent;
    }
    public static function validate(array $o,array $verified,array $form,array $attempt): array
    {
        $proof=self::form($o,$form);
        if($attempt!==$proof['intent'] || ($form['inbound_intent']??null)!==$attempt)throw new CallbackValidationException('paw_invoice_missing');
        $expected=$proof;unset($expected['intent'],$expected['order_id'],$expected['created_at'],$expected['expires_at']);
        if(($verified['paw_profile']??null)!==$expected)throw new CallbackValidationException('paw_profile_mismatch');
        $invoice=$verified['paw_invoice']??null;
        if(!is_array($invoice))throw new CallbackValidationException('paw_invoice_mismatch');
        self::invoice($invoice,$proof);
        $scale=$attempt['scale'];$amount=CallbackAmount::atScale($invoice['fiat_amount']??null,$scale);
        if(($invoice['order_id']??null)!==$proof['order_id'] || ($verified['batch']??null)!==$proof['order_id']
            || CallbackAmount::atScale($invoice['initial_fiat_amount']??null,$scale)!==$attempt['gross']
            || ($invoice['created_at']??null)!==(string)$proof['created_at'] || ($invoice['expires_at']??null)!==(string)$proof['expires_at']
            || !is_string($invoice['processed_at']??null) || !preg_match('/^[1-9][0-9]{0,9}$/D',$invoice['processed_at'])
            || (int)$invoice['processed_at']<$proof['created_at'] || (int)$invoice['processed_at']>time()+300
            || !in_array($invoice['status']??null,['success','partially_paid','paid_over'],true)
            || ($invoice['sandbox']??false)!==($proof['environment']==='sandbox'))throw new CallbackValidationException('paw_invoice_mismatch');
        if(Decimal::of($amount)->compareTo(Decimal::of($attempt['commission']))<=0)throw new CallbackValidationException('paw_amount_below_commission');
        // SQL Opers/Wallets use DECIMAL(13,6); no overflow or rounding is permitted.
        try {AdministrativeDecimal::stored(Decimal::of($amount),13,6);}
        catch(\Throwable){throw new CallbackValidationException('paw_amount_invalid');}
        return ['profile'=>$proof,'amount'=>$amount,'status'=>$invoice['status'],'processed_at'=>(int)$invoice['processed_at'],
            'net'=>(string)Decimal::of($amount)->minus(Decimal::of($attempt['commission']))];
    }
}
