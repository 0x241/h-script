<?php

declare(strict_types=1);
namespace HScript\Payment\Gateways;

use HScript\Finance\StripeContract;
use HScript\Payment\AbstractGateway;
use HScript\Payment\CallbackResult;
use HScript\Payment\JsonCallbackPayload;
use HScript\Payment\StripeClient;
use Throwable;

/** One provider, separate fiat balances; hosted Checkout and signed paid events. */
class Stripe extends AbstractGateway
{
    public function processDeposit(array $params): array
    {
        try {
            $sci=(array)($params['sci']??[]);$o=(array)($params['callback_fee_policy']??[]);
            $proof=StripeContract::capture($o,$sci);
            $amount=StripeContract::minor($params['sum']??null,$o['cCurrID']);
            if (($params['tag']??null)!=$o['oID']) throw new \RuntimeException();
            $fields=['mode'=>'payment','payment_method_types'=>['card'],'client_reference_id'=>(string)$o['oID'],
                'line_items'=>[['price_data'=>['currency'=>strtolower($o['cCurrID']),'unit_amount'=>$amount,
                    'product_data'=>['name'=>'H-Script balance deposit']],'quantity'=>1]],
                'metadata'=>StripeContract::metadata($o,$sci),
                'success_url'=>$params['url_ok'],'cancel_url'=>$params['url_fail']];
            foreach (['success_url','cancel_url'] as $field) if (!\HScript\Security\SafeUrl::redirect($fields[$field]) || !in_array(parse_url($fields[$field],PHP_URL_SCHEME),['http','https'],true)) throw new \RuntimeException();
            $idempotency='hscript:'.hash('sha256',json_encode([$params['url_callback'],$proof],JSON_THROW_ON_ERROR));
            $session=$this->createSession($sci['secret_key'],$fields,$idempotency);
            StripeContract::session($session,$o,$sci,false);
            if (!StripeContract::checkoutUrl($session['url']??null)) throw new \RuntimeException();
            return ['url'=>$session['url'],'get'=>true,'stripe_session'=>$session['id'],'stripe_proof'=>json_encode($proof,JSON_THROW_ON_ERROR)];
        } catch (Throwable) { return ['error'=>'stripe_invoice_unavailable']; }
    }
    protected function createSession(string $key,array $fields,string $idempotency): array { return StripeClient::request($key,'checkout/sessions',$fields,$idempotency); }
    public function processWithdrawal(array $params): array { return ['result'=>'automatic_payment_unsupported']; }
    public function handleCallback(array $request): array
    {
        try {
            $raw=$request['_raw_body']??null;$signature=$request['_server']['HTTP_STRIPE_SIGNATURE']??null;$config=$request['_config']??[];
            if (!is_string($raw) || !is_string($signature) || strlen($signature)>2048 || !is_string($config['webhook_secret']??null)) return CallbackResult::failure('authentication_failed','callback_signature_invalid');
            \Stripe\WebhookSignature::verifyHeader($raw,$signature,$config['webhook_secret'],300);
            $event=JsonCallbackPayload::object($raw);
            if (!is_string($event->id??null) || !preg_match('/^evt_[A-Za-z0-9]{1,128}$/D',$event->id)
                || ($event->object??null)!=='event' || !is_int($event->created??null) || !is_bool($event->livemode??null)
                || $event->livemode!==($config['_provider_environment']==='live') || isset($event->account))
                return CallbackResult::failure('invalid_payload','stripe_event_invalid',true);
            // Unrelated authentic events are acknowledged without a financial effect.
            if (!in_array($event->type??null,['checkout.session.completed','checkout.session.async_payment_succeeded'],true)) return CallbackResult::failure('rejected','stripe_event_ignored',true)+['response'=>'OK'];
            $session=isset($event->data->object) ? json_decode(json_encode($event->data->object,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION),true,8,JSON_THROW_ON_ERROR) : null;
            if (!is_array($session)) return CallbackResult::failure('invalid_payload','stripe_session_invalid',true);
            if (($session['payment_status']??null)!=='paid') return CallbackResult::failure('rejected','stripe_payment_unpaid',true)+['response'=>'OK'];
            $metadata=$session['metadata']??[];
            foreach (['operation','owner','currency','provider','version'] as $field) if (!is_string($metadata[$field]??null)) return CallbackResult::failure('invalid_payload','stripe_metadata_invalid',true);
            $asset=strtoupper($session['currency']??'');
            $amount=StripeContract::major($session['amount_total']??null,$asset);
            return ['correct'=>true,'authenticated'=>true,'outcome'=>'verified','reason'=>'signature_verified','response'=>'OK',
                'tag'=>$metadata['operation'],'curr'=>$asset,'sum'=>$amount,'accto'=>$config['account_id'],
                'batch'=>$session['id'],'stripe_session'=>json_encode($session,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION),
                'stripe_provider'=>$config['_provider_public'],'stripe_version'=>(int)$config['_provider_version'],
                'stripe_key_hash'=>hash('sha256',$config['webhook_secret'])];
        } catch (\Stripe\Exception\SignatureVerificationException) { return CallbackResult::failure('authentication_failed','callback_signature_invalid'); }
        catch (Throwable) { return CallbackResult::failure('invalid_payload','stripe_event_invalid'); }
    }
    public function validateConfig(array $config): bool { return is_string($config['secret_key']??null) && is_string($config['webhook_secret']??null); }
}
