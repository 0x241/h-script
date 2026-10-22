<?php

declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
require dirname(__DIR__).'/vendor/autoload.php';
use HScript\Database\Connection;
use HScript\Finance\StripeContract;
use HScript\Payment\CallbackResult;
use HScript\Payment\PaymentManager;
use HScript\Payment\Gateways\Stripe;

$checks=0;
function stripeCheck(bool $condition,string $message): void { global $checks;if (!$condition) throw new RuntimeException($message);$checks++; }
function stripeReject(callable $f): void { try {$f();throw new LogicException('accepted');} catch (HScript\Finance\CallbackValidationException) { stripeCheck(true,'rejected'); } }
stripeCheck(StripeContract::minor('1.01','USD')===101,'USD cents');stripeCheck(StripeContract::minor('1','JPY')===1,'JPY unit');
foreach (['0','-1','0.01','1.1','999999999'] as $bad) stripeReject(static fn()=>StripeContract::minor($bad,'JPY'));
foreach ([1.01,[],true,'1e2','1000000.00','1.001'] as $bad) stripeReject(static fn()=>StripeContract::minor($bad,'USD'));
stripeCheck(StripeContract::major(101,'USD')==='1.01','major units');stripeCheck(StripeContract::major(1,'JPY')==='1','JPY major');
foreach (['https://evil.invalid/c/pay/x','https://checkout.stripe.com.evil.invalid/c/pay/x','https://u@checkout.stripe.com/c/pay/x','http://checkout.stripe.com/c/pay/x'] as $url) stripeCheck(!StripeContract::checkoutUrl($url),'unsafe checkout URL');
$config=['secret_key'=>'sk_test_synthetickey123','webhook_secret'=>'whsec_syntheticsecret123','account_id'=>'acct_fixture',
    '_provider_id'=>1,'_provider_public'=>str_repeat('a',32),'_provider_version'=>1,'_provider_environment'=>'sandbox'];
$o=['oID'=>9,'ouID'=>1,'ocID'=>4,'cCID'=>'ST','cCurrID'=>'USD','cProviderID'=>1,'cProviderKeyVersion'=>1,'oSum'=>'1.01'];
$session=['id'=>'cs_test_fixture','object'=>'checkout.session','mode'=>'payment','livemode'=>false,'client_reference_id'=>'9',
    'currency'=>'usd','amount_total'=>101,'metadata'=>StripeContract::metadata($o,$config),'payment_status'=>'paid','status'=>'complete'];
$gateway=(new PaymentManager(new Connection()))->gateway('ST');
function stripeEvent(array $session,array $config,array $changes=[]): array {
    $event=array_replace(['id'=>'evt_fixture','object'=>'event','created'=>time(),'livemode'=>false,'type'=>'checkout.session.completed','data'=>['object'=>$session]],$changes);
    $raw=json_encode($event,JSON_THROW_ON_ERROR);$t=time();return ['_raw_body'=>$raw,'_server'=>['HTTP_STRIPE_SIGNATURE'=>'t='.$t.',v1='.hash_hmac('sha256',$t.'.'.$raw,$config['webhook_secret'])],'_config'=>$config];
}
$request=stripeEvent($session,$config);$res=$gateway->handleCallback($request);
stripeCheck($res['correct']===true && $res['sum']==='1.01' && $res['batch']===$session['id'],'authentic paid event');
$manager=new PaymentManager(new Connection());$managed=$manager->handleCallback('ST',$request,$config);stripeCheck($managed['correct'] && isset($managed['stripe_session']),'manager supports authenticated nested payload');
$form=['url'=>'https://checkout.stripe.com/c/pay/cs_test_fixture','stripe_session'=>$session['id'],'stripe_proof'=>json_encode(StripeContract::capture($o,$config))];
$proof=StripeContract::validate($o,$res,$form);stripeCheck(isset($proof['stripe_proof_hash']),'original checkout binding');
foreach (['amount_total'=>102,'currency'=>'eur','status'=>'open','client_reference_id'=>'8','livemode'=>true] as $key=>$bad) {
    $badRes=$gateway->handleCallback(stripeEvent(array_replace($session,[$key=>$bad]),$config));
    if (!$badRes['correct']) stripeCheck(true,'event rejected');else stripeReject(static fn()=>StripeContract::validate($o,$badRes,$form));
}
foreach (['unpaid','no_payment_required'] as $state) stripeCheck(!$gateway->handleCallback(stripeEvent(array_replace($session,['payment_status'=>$state]),$config))['correct'],'unpaid never credits');
$request['_server']['HTTP_STRIPE_SIGNATURE'].='x';stripeCheck(!$gateway->handleCallback($request)['correct'],'invalid HMAC');
$request=stripeEvent($session,$config);$old=time()-301;$request['_server']['HTTP_STRIPE_SIGNATURE']='t='.$old.',v1='.hash_hmac('sha256',$old.'.'.$request['_raw_body'],$config['webhook_secret']);stripeCheck(!$gateway->handleCallback($request)['correct'],'old signed header');
foreach (['101.0','1.01e2','100.999999999999999999'] as $number) {
    $wire=str_replace('"amount_total":101','"amount_total":'.$number,stripeEvent($session,$config)['_raw_body']);$now=time();
    $bad=$gateway->handleCallback(['_raw_body'=>$wire,'_server'=>['HTTP_STRIPE_SIGNATURE'=>'t='.$now.',v1='.hash_hmac('sha256',$now.'.'.$wire,$config['webhook_secret'])],'_config'=>$config]);
    stripeCheck(!$bad['correct'],'Stripe integer money retains original JSON numeric type');
}
$duplicate=str_replace('"amount_total":101','"amount_total":101,"amount_total":102',stripeEvent($session,$config)['_raw_body']);$now=time();
stripeCheck(!$gateway->handleCallback(['_raw_body'=>$duplicate,'_server'=>['HTTP_STRIPE_SIGNATURE'=>'t='.$now.',v1='.hash_hmac('sha256',$now.'.'.$duplicate,$config['webhook_secret'])],'_config'=>$config])['correct'],'duplicate JSON field');
$ignored=$gateway->handleCallback(stripeEvent([], $config,['type'=>'balance.available']));stripeCheck(!$ignored['correct'] && $ignored['authenticated'] && $ignored['response']==='OK','authentic unrelated event ACK');
echo "Stripe provider: $checks checks passed\n";
