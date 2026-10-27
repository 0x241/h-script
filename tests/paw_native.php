<?php

declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/vendor/autoload.php';
use HScript\Database\Connection;
use HScript\Finance\{PawContract,CallbackValidationException};
use HScript\Payment\{PaymentManager,PawInvoiceClient,PawPayload};
use HScript\Payment\Gateways\PawPayments;
$checks=0;
function pawNativeCheck(bool $ok,string $message):void {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function pawNativeReject(callable $f):void{try{$f();throw new LogicException('Unexpected acceptance');}catch(CallbackValidationException|InvalidArgumentException){pawNativeCheck(true,'Rejected');}}
$config=['key'=>'sk_sandbox_synthetic_native_key','_provider_enabled'=>true,'_provider_id'=>1,'_provider_public'=>str_repeat('a',32),'_provider_version'=>1,'_provider_environment'=>'sandbox'];
$o=['oID'=>9,'ouID'=>1,'ocID'=>4,'cCID'=>'PAW','cCurrID'=>'USD','cProviderID'=>1,'oSum'=>'25.000000','oComis'=>'2.500000','cNumDec'=>2];
$proof=PawContract::capture($o,$config);$now=time();
$invoice=['order_id'=>str_repeat('b',24),'billing_type'=>'VARY','fiat_currency'=>'USD','fiat_amount'=>'25','extra'=>'9','metadata'=>PawContract::metadata($proof),'fee_bearer'=>'client','status'=>'created','created_at'=>(string)$now,'expires_at'=>(string)($now+3600),'sandbox'=>true];
final class SyntheticPawInvoiceGateway extends PawPayments{
 public array $invoice=[];public array $fields=[];public bool $fail=false;
 protected function createInvoice(string $key,array $fields):array{$this->fields=$fields;if($this->fail)throw new RuntimeException('KEY_CANARY');return $this->invoice;}
}
$manager=new PaymentManager(new Connection());$gateway=new SyntheticPawInvoiceGateway('PAW',$manager->definitions('PAW'),[],new Connection());$gateway->invoice=$invoice;
$params=['sci'=>$config,'callback_fee_policy'=>$o,'tag'=>9,'sum'=>'25.00','url_ok'=>'https://fixture.invalid/oper?id=9&check','url_fail'=>'https://fixture.invalid/balance?fail','url_callback'=>'https://fixture.invalid/status'];
$form=$gateway->processDeposit($params);
$manager->register('PAW',$gateway);
$local=array_replace($params,['url_ok'=>'http://fixture.invalid/operation?id=9&check','url_fail'=>'http://fixture.invalid/balance?fail','url_callback'=>'http://fixture.invalid/balance/status']);
$managed=$manager->processDeposit('PAW',$local);
pawNativeCheck(($managed['url']??null)===$local['url_ok'] && ($managed['formkind']??null)==='paw_sandbox','real manager accepts proven local sandbox return after creation');
$fake=new class('PAW',[],[],new Connection()) extends HScript\Payment\AbstractGateway{public array $form=[];public function processDeposit(array $p):array{return $this->form;}};
$fake->form=$managed;$fake->form['url']='http://evil.invalid/';$manager->register('PAW',$fake);
pawNativeCheck(isset($manager->processDeposit('PAW',$local)['error']),'sandbox marker cannot authorize another return URL');
$fake->form=$managed;unset($fake->form['paw_proof']);
pawNativeCheck(isset($manager->processDeposit('PAW',$local)['error']),'sandbox marker requires original proof');
$fake->form=$managed;$manager->register('ST',$fake);
pawNativeCheck(isset($manager->processDeposit('ST',$local)['error']),'sandbox marker never relaxes HTTPS for another gateway');

$manager->register('PAW',$gateway);$gateway->processDeposit($params);
pawNativeCheck(($form['formkind']??null)==='paw_sandbox' && !str_contains($form['url'],'pawpayments.com'),'sandbox never invents hosted checkout');
pawNativeCheck(!isset($gateway->fields['asset']) && $gateway->fields['billing_type']==='VARY' && $gateway->fields['fee_bearer']==='client','provider chooses assets, explicit VARY fee contract');
pawNativeCheck(str_contains($gateway->fields['notify_url'],'its=PAW&provider=') && str_contains($gateway->fields['notify_url'],'version=1'),'explicit retained key callback endpoint');
$attempt=$proof['intent'];$form['inbound_intent']=$attempt;
$paid=$invoice+['initial_fiat_amount'=>'25','processed_at'=>(string)$now];$paid['status']='partially_paid';$paid['fiat_amount']='12.34';
function pawNativeSigned(string $raw,array $config):array{return ['_raw_body'=>$raw,'_server'=>['HTTP_X_PAW_SIGNATURE'=>hash_hmac('sha256',$raw,$config['key']),'HTTP_X_PAW_SANDBOX'=>'true']];}
function pawNativeVerified(array $i,array $config):array{return (new PaymentManager(new Connection()))->handleCallback('PAW',pawNativeSigned(json_encode($i,JSON_THROW_ON_ERROR),$config),$config);}
$verified=pawNativeVerified($paid,$config);
pawNativeCheck($verified['correct'] && $verified['sum']==='12.34','nested signed callback accepted');
$binding=PawContract::validate($o,$verified,$form,$attempt);
pawNativeCheck($binding['amount']==='12.34' && $binding['net']==='9.84','actual partial minus original fixed fee');
$numeric=str_replace('"fiat_amount":"12.34"','"fiat_amount":12.34',json_encode($paid,JSON_THROW_ON_ERROR));
$verifiedNumeric=$manager->handleCallback('PAW',pawNativeSigned($numeric,$config),$config);
pawNativeCheck(PawContract::validate($o,$verifiedNumeric,$form,$attempt)===$binding,'JSON money retains numeric lexeme exactly');
foreach(['0.000000000000000000001','1e2','999999999999','-1','0','2.50','12.341'] as $amount){$bad=$paid;$bad['fiat_amount']=$amount;pawNativeReject(static fn()=>PawContract::validate($o,pawNativeVerified($bad,$config),$form,$attempt));}
foreach([['fiat_currency'=>'EUR'],['initial_fiat_amount'=>'24.99'],['billing_type'=>'STATIC'],['order_id'=>str_repeat('c',24)],['fee_bearer'=>'merchant'],['created_at'=>(string)($now-1)],['expires_at'=>(string)($now+60)],['permanent_address_id'=>'id']] as $change){$bad=array_replace($paid,$change);pawNativeReject(static fn()=>PawContract::validate($o,pawNativeVerified($bad,$config),$form,$attempt));}
foreach(['success','paid_over'] as $status){$bad=$paid;$bad['status']=$status;pawNativeCheck(PawContract::validate($o,pawNativeVerified($bad,$config),$form,$attempt)['status']===$status,'terminal status');}
foreach(['created','processing','expired','cancelled','failed','refunded','high_risk'] as $status){$bad=$paid;$bad['status']=$status;$r=pawNativeVerified($bad,$config);pawNativeCheck($r['authenticated'] && !$r['correct'] && $r['response']==='OK','noncredit does not create financial claim');}
foreach(['{}','{broken',$numeric.' ',str_replace('12.34','12.35',$numeric)] as $raw){$request=pawNativeSigned($numeric,$config);$request['_raw_body']=$raw;pawNativeCheck(!$manager->handleCallback('PAW',$request,$config)['correct'],'raw bytes tampering rejected');}
$dupe=str_replace('"fiat_amount":12.34','"fiat_amount":12.34,"fiat_amount":25',$numeric);pawNativeCheck(!$manager->handleCallback('PAW',pawNativeSigned($dupe,$config),$config)['correct'],'signed duplicate key rejected');
$bad=$paid;$bad['sandbox']=false;pawNativeCheck(!pawNativeVerified($bad,$config)['correct'],'live payload cannot use sandbox credential');
$bad=$config;$bad['_provider_version']=2;pawNativeCheck(!pawNativeVerified($paid,$bad)['correct'],'wrong retained key version');
pawNativeCheck($manager->detectCallback($paid)==='' && !$manager->handleCallback('PAW',pawNativeSigned($numeric,$config),[])['correct'],'no detection from JSON or unsigned authentication');
$gateway->fail=true;pawNativeCheck($gateway->processDeposit($params)===['error'=>'paw_invoice_unavailable'],'provider timeout redacted and no retry');
foreach(['https://pay.pawpayments.com.evil.invalid/checkout/aaaa','https://evil.invalid/checkout/aaaa','http://pay.pawpayments.com/checkout/aaaa','https://u@pay.pawpayments.com/checkout/aaaa'] as $url)pawNativeCheck(!PawContract::checkoutUrl($url),'checkout origin exact');
$raw='{"ok":true,"result":{"amount":0.100000000000000001}}';
$client=new PawInvoiceClient($config['key'],static fn()=>['status'=>201,'content_type'=>'application/json','body'=>$raw]);
pawNativeCheck($client->create([])['amount']==='0.100000000000000001','transport never decodes money through float');
foreach([200,302,401,403,429,500,503] as $code){$client=new PawInvoiceClient($config['key'],static fn()=>['status'=>$code,'content_type'=>'application/json','body'=>$raw]);try{$client->create([]);throw new LogicException('Bad status');}catch(RuntimeException $e){if($e instanceof LogicException)throw $e;pawNativeCheck(true,'HTTP status rejected');}}
echo "PAW native components: $checks checks passed\n";
