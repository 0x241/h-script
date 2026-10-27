<?php

declare(strict_types=1);
namespace HScript\Payment\Gateways;

use HScript\Finance\CallbackAmount;
use HScript\Finance\PawContract;
use HScript\Payment\AbstractGateway;
use HScript\Payment\CallbackResult;
use HScript\Payment\PawInvoiceClient;
use HScript\Payment\PawPayload;
use HScript\Security\SafeUrl;
use Throwable;

/** One fiat accounting connection per currency; assets are selected at PAW. */
class PawPayments extends AbstractGateway
{
    public function processDeposit(array $params): array
    {
        try {
            $o=$params['callback_fee_policy']??[];$config=$o['_paw_config']??($params['sci']??[]);
            $proof=PawContract::capture($o,$config);
            if(($params['tag']??null)!=$proof['intent']['operation_id'] || CallbackAmount::atScale($params['sum']??null,$proof['intent']['scale'])!==$proof['intent']['gross'])throw new \RuntimeException();
            foreach(['url_ok','url_fail','url_callback'] as $field)if(!is_string($params[$field]??null) || !SafeUrl::redirect($params[$field]) || !in_array(parse_url($params[$field],PHP_URL_SCHEME),['http','https'],true))throw new \RuntimeException();
            $callback=$params['url_callback'].'?'.http_build_query(['its'=>'PAW','provider'=>$proof['public_id'],'version'=>$proof['key_version']],'','&',PHP_QUERY_RFC3986);
            $fields=['amount'=>$proof['intent']['gross'],'fiat_currency'=>$proof['intent']['asset'],
                'billing_type'=>'VARY','fee_bearer'=>'client','webhook_format'=>'native_v2','extra'=>(string)$proof['intent']['operation_id'],
                'metadata'=>PawContract::metadata($proof)];
            foreach(['notify_url'=>$callback,'on_paid_url'=>$params['url_ok'],'on_cancel_url'=>$params['url_fail']] as $field=>$url){
                if(parse_url($url,PHP_URL_SCHEME)==='https')$fields[$field]=$url;
                elseif($proof['environment']!=='sandbox')throw new \RuntimeException();
            }
            // PAW rejects HTTP callbacks. Local sandbox can simulate without delivery.
            $invoice=$this->createInvoice($config['key'],$fields);
            PawContract::invoice($invoice,$proof);
            if(($invoice['status']??null)!=='created' || CallbackAmount::atScale($invoice['fiat_amount']??null,$proof['intent']['scale'])!==$proof['intent']['gross']
                || ($invoice['sandbox']??false)!==($proof['environment']==='sandbox'))throw new \RuntimeException();
            foreach(['created_at','expires_at'] as $field)if(!is_string($invoice[$field]??null) || !preg_match('/^[1-9][0-9]{0,9}$/D',$invoice[$field]))throw new \RuntimeException();
            if(abs(time()-(int)$invoice['created_at'])>300)throw new \RuntimeException();
            $proof+=['order_id'=>$invoice['order_id'],'created_at'=>(int)$invoice['created_at'],'expires_at'=>(int)$invoice['expires_at']];
            $sandbox=$proof['environment']==='sandbox';
            if(!$sandbox && !PawContract::checkoutUrl($invoice['payment_url']??null))throw new \RuntimeException();
            $form=['url'=>$sandbox?$params['url_ok']:$invoice['payment_url'],'get'=>true,'psysorder'=>$invoice['order_id'],
                'paw_order'=>$invoice['order_id'],'paw_proof'=>json_encode($proof,JSON_THROW_ON_ERROR)];
            if($sandbox)$form['formkind']='paw_sandbox';
            PawContract::form($o,$form);
            return $form;
        } catch(Throwable){return ['error'=>'paw_invoice_unavailable'];}
    }
    protected function createInvoice(string $key,array $fields): array {return (new PawInvoiceClient($key))->create($fields);}
    public function getFields(string $type): array
    {
        return $type==='pay' ? [
            'usdt_tron'=>['USDT · TRON (TRC-20)','T[1-9A-HJ-NP-Za-km-z]{33}','Адрес для получения USDT только в сети TRON'],
            'usdc_eth'=>['USDC · Ethereum (ERC-20)','0x[a-fA-F0-9]{40}','Адрес для получения USDC только в сети Ethereum'],
        ] : [];
    }
    public function processWithdrawal(array $params): array
    {
        try {
            $capsule=$params['paw_capsule']??[];
            if(!\HScript\Finance\PawPayout::validRequest($capsule))throw new \RuntimeException();
            return (new PawInvoiceClient($capsule['key']))->payout($capsule['fields'],$capsule['contract']['uniq_id']);
        } catch(\Throwable) {return ['result'=>'paw_payout_reconciliation_required'];}
    }
    public function getBalance(array $config): array {return ['result'=>'paw_balance_unavailable','sum'=>-1];}
    public function handleCallback(array $request): array
    {
        try {
            $raw=$request['_raw_body']??null;$signature=$request['_server']['HTTP_X_PAW_SIGNATURE']??null;$config=$request['_config']??[];
            if(!is_string($raw) || !is_string($signature) || !preg_match('/^[a-f0-9]{64}$/D',$signature) || !is_string($config['key']??null) || $config['key']===''
                || !hash_equals(hash_hmac('sha256',$raw,$config['key']),$signature))return CallbackResult::failure('authentication_failed','callback_signature_invalid');
            $invoice=PawPayload::decode($raw);$sandbox=($config['_provider_environment']??null)==='sandbox';
            if(!in_array($config['_provider_environment']??null,['sandbox','live'],true) || ($invoice['sandbox']??false)!==$sandbox
                || ($sandbox ? ($request['_server']['HTTP_X_PAW_SANDBOX']??null)!=='true' : !in_array($request['_server']['HTTP_X_PAW_SANDBOX']??null,[null,'false'],true)))
                return CallbackResult::failure('invalid_payload','paw_environment_mismatch',true);
            $status=$invoice['status']??null;
            if(!in_array($status,['success','partially_paid','paid_over'],true)){
                if(!in_array($status,['created','processing','expired','cancelled','failed','refunded','high_risk'],true))return CallbackResult::failure('invalid_payload','paw_status_invalid',true);
                return CallbackResult::failure('rejected',$status==='refunded'?'paw_reconciliation_required':'paw_payment_unsettled',true)+['response'=>'OK'];
            }
            $metadata=$invoice['metadata']??[];
            foreach(['operation','owner','currency','version'] as $f)if(!is_string($metadata[$f]??null) || !preg_match('/^[1-9][0-9]{0,9}$/D',$metadata[$f]))throw new \RuntimeException();
            if(($metadata['provider']??null)!==($config['_provider_public']??null) || $metadata['version']!==(string)($config['_provider_version']??''))throw new \RuntimeException();
            return ['correct'=>true,'authenticated'=>true,'outcome'=>'verified','reason'=>'signature_verified','response'=>'OK',
                'tag'=>$metadata['operation'],'curr'=>$invoice['fiat_currency']??null,'sum'=>$invoice['fiat_amount']??null,'batch'=>$invoice['order_id']??null,
                'paw_invoice'=>$invoice,'paw_profile'=>['version'=>1,'provider_id'=>$config['_provider_id'],'public_id'=>$config['_provider_public'],
                    'key_version'=>$config['_provider_version'],'environment'=>$config['_provider_environment'],'key_hash'=>hash('sha256',$config['key'])]];
        } catch(Throwable){return CallbackResult::failure('invalid_payload','paw_callback_invalid');}
    }
    public function validateConfig(array $config): bool {return is_string($config['key']??null) && $config['key']!=='';}
}
