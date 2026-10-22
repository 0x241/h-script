<?php

declare(strict_types=1);
namespace HScript\Finance;

use Brick\Math\RoundingMode;
use HScript\Database\Connection;
use HScript\Database\TransactionScope;
use HScript\Payment\Provider\ProviderSettings;
use HScript\Security\SafeUrl;
use Throwable;

/** Checkout identity, original receiver and exact minor units are durable authority. */
final class StripeContract
{
    public static function minor(mixed $amount,string $asset): int
    {
        if (!isset(ProviderSettings::choices('STRIPE')[$asset]) || (!is_int($amount) && !is_string($amount)) || !preg_match('/^[0-9]{1,12}(?:\.[0-9]{1,8})?$/D',(string)$amount)) throw new CallbackValidationException('stripe_amount_invalid');
        try { $minor=(string)Decimal::of($amount)->multipliedBy(Decimal::of($asset==='JPY'?'1':'100'))->rounded(0,RoundingMode::Unnecessary); }
        catch (Throwable) { throw new CallbackValidationException('stripe_amount_invalid'); }
        if (!preg_match('/^[1-9][0-9]{0,7}$/D',$minor)) throw new CallbackValidationException('stripe_amount_invalid');
        return (int)$minor;
    }
    public static function major(mixed $minor,string $asset): string
    {
        if (!is_int($minor) || $minor<1 || $minor>99999999 || !isset(ProviderSettings::choices('STRIPE')[$asset])) throw new CallbackValidationException('stripe_amount_invalid');
        if ($asset==='JPY') return (string)$minor;
        return intdiv($minor,100).'.'.str_pad((string)($minor%100),2,'0',STR_PAD_LEFT);
    }
    public static function checkoutUrl(mixed $url): bool
    {
        return is_string($url) && strlen($url)<=8192 && SafeUrl::paymentAction($url) && parse_url($url,PHP_URL_SCHEME)==='https'
            && parse_url($url,PHP_URL_HOST)==='checkout.stripe.com' && parse_url($url,PHP_URL_PORT)===null
            && str_starts_with(parse_url($url,PHP_URL_PATH)??'','/c/pay/');
    }
    public static function metadata(array $o,array $sci): array
    {
        return ['operation'=>(string)$o['oID'],'owner'=>(string)$o['ouID'],'currency'=>(string)$o['ocID'],
            'provider'=>$sci['_provider_public'],'version'=>(string)$sci['_provider_version']];
    }
    public static function capture(array $o,array $sci): array
    {
        if (($o['cCID']??null)!=='ST' || (int)($o['cProviderID']??0)<1 || (int)($o['cProviderKeyVersion']??0)<1
            || (int)$o['cProviderID']!==($sci['_provider_id']??null) || (int)$o['cProviderKeyVersion']!==($sci['_provider_version']??null)
            || !is_string($sci['_provider_public']??null) || !preg_match('/^[a-f0-9]{32}$/D',$sci['_provider_public'])
            || !in_array($sci['_provider_environment']??null,['live','sandbox'],true) || !is_string($sci['account_id']??null)
            || !preg_match('/^acct_[A-Za-z0-9]{1,80}$/D',$sci['account_id']) || !is_string($sci['webhook_secret']??null))
            throw new CallbackValidationException('stripe_profile_invalid');
        return ['profile'=>1,'operation_id'=>(int)$o['oID'],'owner_id'=>(int)$o['ouID'],'currency_id'=>(int)$o['ocID'],
            'provider_id'=>(int)$o['cProviderID'],'public_id'=>$sci['_provider_public'],'key_version'=>$sci['_provider_version'],
            'environment'=>$sci['_provider_environment'],'account_id'=>$sci['account_id'],'secret_hash'=>hash('sha256',$sci['webhook_secret']),
            'asset'=>$o['cCurrID'],'minor'=>self::minor($o['oSum'],$o['cCurrID'])];
    }
    public static function session(array $session,array $o,array $sci,bool $paid): void
    {
        $metadata=$session['metadata']??null;$expected=self::metadata($o,$sci);
        if (is_array($metadata)) { ksort($metadata);ksort($expected); }
        if (!is_string($session['id']??null) || !preg_match('/^cs_(?:test|live)_[A-Za-z0-9]{1,200}$/D',$session['id'])
            || !str_starts_with($session['id'],$sci['_provider_environment']==='sandbox'?'cs_test_':'cs_live_')
            || ($session['object']??null)!=='checkout.session' || ($session['mode']??null)!=='payment'
            || ($session['livemode']??null)!==($sci['_provider_environment']==='live')
            || ($session['client_reference_id']??null)!==(string)$o['oID'] || $metadata!==$expected
            || ($session['currency']??null)!==strtolower($o['cCurrID']) || ($session['amount_total']??null)!==self::minor($o['oSum'],$o['cCurrID'])
            || ($paid && (($session['payment_status']??null)!=='paid' || ($session['status']??null)!=='complete'))
            || (!$paid && (($session['payment_status']??null)!=='unpaid' || ($session['status']??null)!=='open')))
            throw new CallbackValidationException('stripe_session_mismatch');
    }
    public static function invoice(array $o,array $form): array
    {
        try { $proof=json_decode($form['stripe_proof']??'',true,8,JSON_THROW_ON_ERROR); }
        catch (Throwable) { throw new CallbackValidationException('stripe_invoice_missing'); }
        if (!is_array($proof) || ($proof['profile']??null)!==1 || ($proof['operation_id']??null)!==(int)$o['oID'] || ($proof['owner_id']??null)!==(int)$o['ouID']
            || ($proof['currency_id']??null)!==(int)$o['ocID'] || ($proof['provider_id']??null)!==(int)$o['cProviderID'] || ($proof['asset']??null)!==$o['cCurrID']
            || ($proof['minor']??null)!==self::minor($o['oSum'],$o['cCurrID']) || !is_int($proof['key_version']??null) || $proof['key_version']<1
            || !is_string($proof['public_id']??null) || !preg_match('/^[a-f0-9]{32}$/D',$proof['public_id'])
            || !is_string($proof['secret_hash']??null) || !preg_match('/^[a-f0-9]{64}$/D',$proof['secret_hash'])
            || !is_string($form['stripe_session']??null) || !preg_match('/^cs_(?:test|live)_[A-Za-z0-9]{1,200}$/D',$form['stripe_session'])
            || !self::checkoutUrl($form['url']??null)) throw new CallbackValidationException('stripe_invoice_mismatch');
        return $proof;
    }
    public static function validate(array $o,array $verified,?array $form): array
    {
        if ($form===null) throw new CallbackValidationException('stripe_invoice_missing');
        $proof=self::invoice($o,$form);
        if (($verified['stripe_provider']??null)!==$proof['public_id'] || ($verified['stripe_version']??null)!==$proof['key_version']
            || ($verified['stripe_key_hash']??null)!==$proof['secret_hash'] || ($verified['accto']??null)!==$proof['account_id']
            || ($verified['batch']??null)!==$form['stripe_session']) throw new CallbackValidationException('stripe_profile_mismatch');
        try { $session=json_decode($verified['stripe_session']??'',true,8,JSON_THROW_ON_ERROR); }
        catch (Throwable) { throw new CallbackValidationException('stripe_session_invalid'); }
        self::session($session,$o,['_provider_public'=>$proof['public_id'],'_provider_version'=>$proof['key_version'],'_provider_environment'=>$proof['environment']],true);
        return ['stripe_proof_hash'=>hash('sha256',json_encode([$proof,$form['stripe_session']],JSON_THROW_ON_ERROR)),'destination'=>$proof['account_id']];
    }
    public static function assertReplay(Connection $db,int $operationId,array $identity,array $verified): void
    {
        TransactionScope::run($db->link,static function() use ($db,$operationId,$identity,$verified): void {
            $o=$db->fetch1Row($db->query('SELECT Opers.*,Currs.* FROM Opers JOIN Currs ON cID=ocID WHERE oID=?d FOR UPDATE',[$operationId]));
            if (!$o) throw new CallbackValidationException('stripe_invoice_missing');
            $proof=self::validate($o,$verified,DepositCheckout::storedForm($o));
            if (($identity['stripe_proof_hash']??null)!==$proof['stripe_proof_hash']) throw new CallbackValidationException('stripe_identity_mismatch');
        });
    }
    public static function batchAlias(string $batch): string { return 'ST:'.substr(hash('sha256',$batch),0,36); }
}
