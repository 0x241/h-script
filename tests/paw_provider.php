<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/vendor/autoload.php';

use HScript\Payment\Provider\PawApiClient;
use HScript\Database\Connection;
use HScript\Payment\AbstractGateway;
use HScript\Payment\PaymentManager;
use HScript\Payment\Provider\PawProviderException;
use HScript\Payment\Provider\PawProviderService;
use HScript\Template\View;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

$checks=0;
function pawCheck(bool $condition,string $name): void { global $checks;if (!$condition) throw new RuntimeException($name);$checks++; }
function pawRejected(callable $call,string $reason='provider_unavailable'): void {
    try { $call();throw new LogicException('Unsafe PAW response accepted'); }
    catch (PawProviderException $e) { pawCheck($e->reason===$reason && $e->getMessage()===$reason,'fixed safe error'); }
}
$balance='{"ok":true,"result":{"available":0,"total_income":12.5,"total_payouts":0}}';
$requests=[];
$fake=static function(string $url,array $headers,int $timeout) use (&$requests,$balance): array {
    $requests[]=[$url,$headers,$timeout];
    return ['status'=>200,'content_type'=>'application/json; charset=utf-8','body'=>str_contains($url,'rates') ? '{"ok":true,"result":{"base":"EUR","rates":{"btc":"65000.01","old":null}}}' : $balance];
};
$client=new PawApiClient('sk_sandbox_synthetic-key',99,$fake);
pawCheck($client->getBalance()['available']===0,'zero balance is valid for connectivity');
pawCheck($client->getRates('EUR')['rates']['btc']==='65000.01','decimal rate remains a string');
pawCheck(array_column($requests,0)===['https://api.pawpayments.com/api/v2/balance','https://api.pawpayments.com/api/v2/rates?base=EUR'],'fixed origin/read-only canonical paths');
pawCheck($requests[0][2]===5 && $requests[1][2]===5,'bounded timeout');
pawCheck(in_array('x-api-key: sk_sandbox_synthetic-key',$requests[0][1],true),'direct authenticated request');
pawRejected(static fn()=>$client->getRates('RUB'),'fiat_unsupported');
pawCheck(count($requests)===2,'unsupported fiat never sends a request');
$page='{"ok":true,"result":[],"pagination":{"page":1,"per_page":1,"total":0,"pages":0}}';
$sandboxRequests=[];
$sandbox=new PawApiClient('sk_sandbox_synthetic-key',5,static function(string $url) use (&$sandboxRequests,$page): array {
    $sandboxRequests[]=$url;return ['status'=>200,'content_type'=>'application/json','body'=>$page];
});
$sandbox->validateSandbox();
pawCheck($sandboxRequests===['https://api.pawpayments.com/api/v2/invoices?page=1&per_page=1'],'sandbox checks a bounded authenticated read instead of forbidden balance');
foreach ([
    str_replace('"result":[]','"result":{}',$page),
    str_replace('"page":1','"page":2',$page),
    str_replace('"per_page":1','"per_page":100',$page),
    str_replace('"total":0','"total":"0"',$page),
    str_replace('"pages":0','"pages":-1',$page),
    str_replace('"result":[]','"result":[{"order_id":"bad"}]',$page),
    str_replace('"result":[]','"result":[{"order_id":"000000000000000000000001"},{"order_id":"000000000000000000000002"}]',$page),
    str_replace('"total":0','"total":0,"t\\u006ftal":0',$page),
    str_replace('"ok":true','"ok":true,"extra":true',$page),
    $balance
] as $raw) {
    $bad=new PawApiClient('sk_sandbox_synthetic-key',5,static fn()=>['status'=>200,'content_type'=>'application/json','body'=>$raw]);
    pawRejected(static fn()=>$bad->validateSandbox());
}
$one=str_replace('"result":[]','"result":[{"order_id":"000000000000000000000001","sandbox":true}]',str_replace('"total":0,"pages":0','"total":1,"pages":1',$page));
(new PawApiClient('sk_sandbox_synthetic-key',5,static fn()=>['status'=>200,'content_type'=>'application/json','body'=>$one]))->validateSandbox();
pawCheck(true,'one existing sandbox invoice validates without interpreting monetary values');
pawRejected(static fn()=>new PawApiClient("sk_sandbox_bad\nheader",5,$fake),'invalid_api_key');
foreach ([
    '{}','[]','{"result":{"available":0}}','{"ok":"true","result":{}}','{"ok":1,"result":{}}',
    '{"ok":false,"result":{}}','{"ok":true,"result":[]}','{"ok":true,"data":{}}',
    '{"ok":true,"ok":true,"result":{}}','{"ok":false,"o\\u006b":true,"result":{}}',
    '{"ok":true,"result":{"available":1,"available":2}}',
    '{"ok":true,"result":{"rates":{"btc":"1","b\\u0074c":"2"}}}',
    '{"ok":true,"result":{"rates":["1"]}}',
    '{"ok":true,"result":{"nested":{"a":1,"a":2}}}',
    '{"ok":true,"result":{},"extra":1}', '{"ok":true,"result":{}} trailing',
    str_repeat(' ',PawApiClient::MAX_BODY+1), '{"ok":true,"result":{"x":'.str_repeat('[',18).'0'.str_repeat(']',18).'}}'
] as $raw) {
    $bad=new PawApiClient('synthetic-key',5,static fn()=>['status'=>200,'content_type'=>'application/json','body'=>$raw]);
    pawRejected(static fn()=>$bad->getBalance());
}
foreach ([0,101,199,300,302,307,401,403,422,429,500,503] as $status) {
    $bad=new PawApiClient('synthetic-key',5,static fn()=>['status'=>$status,'content_type'=>'application/json','body'=>$balance]);
    pawRejected(static fn()=>$bad->getBalance(),$status===401?'invalid_api_key':($status===403?'merchant_restricted':'provider_unavailable'));
}
foreach (['text/html','application/json\r\ninjected',''] as $type) {
    $bad=new PawApiClient('synthetic-key',5,static fn()=>['status'=>200,'content_type'=>$type,'body'=>$balance]);
    pawRejected(static fn()=>$bad->getBalance());
}
$failed=new PawApiClient('synthetic-key',5,static fn()=>throw new RuntimeException('SENSITIVE synthetic header'));
pawRejected(static fn()=>$failed->getBalance());
foreach ([null,[],1,'01','-1','4294967296','1e2',' 1'] as $id) pawRejected(static fn()=>PawProviderService::number($id),'input_invalid');
pawCheck(PawProviderService::number('0')===0 && PawProviderService::number('4294967295')===4294967295,'strict bounded identity');

$manager=new PaymentManager(new Connection());
pawCheck($manager->gateway('PAW') instanceof AbstractGateway,'PAW uses the ordinary gateway interface');
pawCheck($manager->definitions('PAW')===['PAW Payments','',2,1,'','?'],'PAW registry advertises controlled outgoing capability');
pawCheck(array_keys($manager->fields('PAW'))===['usdt_tron','usdc_eth'] && $manager->fields('PAW','sci')===[] && $manager->fields('PAW','api')===[],'two recipient fields do not duplicate shared keys in legacy Currs fields');
pawCheck($manager->processDeposit('PAW',['sum'=>'25.00','api_key'=>'KEY_CANARY'])===['error'=>'paw_invoice_unavailable'],'deposit explicitly unavailable without I/O');
pawCheck($manager->processWithdrawal('PAW',['sum'=>'25.00'])===['result'=>'paw_payout_reconciliation_required'],'withdrawal without frozen request remains unavailable without I/O');
pawCheck($manager->getBalance('PAW',['api_key'=>'KEY_CANARY'])===['result'=>'paw_balance_unavailable','sum'=>-1],'GET balance explicitly unavailable without I/O');
foreach ([[],['key'=>'KEY_CANARY'],['api_key'=>'KEY_CANARY']] as $config)
    pawCheck(!$manager->handleCallback('PAW',['_raw_body'=>'{broken','amount'=>['untrusted']],$config)['correct'],'unauthenticated or malformed callback fails closed');
pawCheck($manager->detectCallback(['order_id'=>'untrusted','fiat_amount'=>'25','status'=>'success','X-Paw-Signature'=>'untrusted'])==='','PAW is never auto-detected from untrusted JSON fields');
pawCheck(!$manager->gateway('PAW')->validateConfig(['api_key'=>'KEY_CANARY']),'configuration does not imply finance enabled');

$_SESSION=[];$_GS=['module'=>'balance/admin/curr'];$_IN=[];
View::getFormCert('paw_profile_save');$save=$_SESSION['_cert']['paw_profile_save'][0];
View::getFormCert('paw_profile_manage');$manage=$_SESSION['_cert']['paw_profile_manage'][0];
pawCheck(!View::chkFormCert($save,'paw_profile_manage'),'CSRF token is form-scoped');
pawCheck(!View::chkFormCert('','paw_profile_save'),'missing CSRF rejected');
pawCheck(View::chkFormCert($save,'paw_profile_save') && !View::chkFormCert($save,'paw_profile_save'),'CSRF token is consumed once');
pawCheck(View::chkFormCert($manage,'paw_profile_manage'),'separate management token');
$_SERVER['REQUEST_METHOD']='GET';$_IN=['paw_profile_save_btn'=>'1'];
pawCheck(!View::sendedForm('','paw_profile_save'),'GET cannot submit provider form');
$_SERVER['REQUEST_METHOD']='POST';pawCheck(View::sendedForm('','paw_profile_save'),'POST button matches guarded form');

$twig=new Environment(new ChainLoader([new ArrayLoader(['admin/header.twig'=>'','admin/footer.twig'=>'','balance/admin/curr.settings.twig'=>'CURRENCY_FORM']),new FilesystemLoader(dirname(__DIR__).'/tpl')]),['autoescape'=>'html','strict_variables'=>true]);
$_GS['lang']='ru';
$twig->addFunction(new TwigFunction('_t',[View::class,'tplTranslate']));
$twig->addFunction(new TwigFunction('_link',static fn()=>'/admin/curr'));
$twig->addFunction(new TwigFunction('_getFormSecurity',static fn()=>'<input name="__Cert" value="synthetic" type="hidden">',['is_safe'=>['html']]));
$base=['paw_referral_url'=>HScript\Payment\Provider\ProviderReferral::link('PAW'),'paw_url'=>'/admin/curr?provider=PAW','paw_connection_url'=>'/admin/curr?provider=PAW','paw_currency'=>'','paw_schema_ready'=>true,'paw_profile'=>['id'=>1,'revision'=>3,'state'=>'enabled','has_key'=>true,'environment'=>'sandbox','validated_at'=>null,'connections'=>[['cID'=>6,'cCurrID'=>'USD','cProviderSelected'=>1],['cID'=>7,'cCurrID'=>'<script>alert(1)</script>','cProviderSelected'=>1]],'api_key'=>'KEY_CANARY','ppcEncryptedKey'=>'CIPHER_CANARY'],'paw_selected'=>['USD'],'paw_fiats'=>['USD','EUR'],'paw_error'=>null];
foreach (['setup','currencies','info'] as $tab) {
    $html=$twig->render('balance/admin/curr.paw.twig',$base+['paw_tab'=>$tab]);
    pawCheck(!str_contains($html,'KEY_CANARY') && !str_contains($html,'CIPHER_CANARY'),'view does not reveal key/ciphertext');
    pawCheck(!str_contains($html,'<script>alert'),'connection labels escaped');
    pawCheck(!str_contains($html,'name="cCASHINMode"'),'main connection has no duplicate currency modes');
    pawCheck(!str_contains($html,'/admin/paw') && str_contains($html,'/admin/curr?id=6'),'profile actions and connections use the common route');
    if ($tab!=='currencies') {
        pawCheck(str_contains($html,'Кешбек выплачивается вручную после сверки транзакций в PAW.'),'same authorized manual-cashback notice');
        pawCheck(str_contains($html,'rel="noopener noreferrer"') && str_contains($html,'partner=92483577-b00d-4cdb-b401-d33e800d908b'),'fixed safe partner link');
    }
    if ($tab==='setup') pawCheck(str_contains($html,'name="api_key" value=""'),'saved key never repopulates');
}
foreach (['setup','currencies','info'] as $tab) {
    $html=$twig->render('balance/admin/curr.paw.twig',['paw_referral_url'=>HScript\Payment\Provider\ProviderReferral::link('PAW'),'paw_url'=>'/admin/curr?provider=PAW','paw_currency'=>'','paw_schema_ready'=>false,'paw_profile'=>['id'=>0,'state'=>'new'],
        'paw_error'=>'Требуется обновление базы данных до версии 1.0.5.','paw_tab'=>$tab]);
    pawCheck(str_contains($html,'Требуется обновление базы данных'),'pre-migration explanation visible');
    pawCheck(!str_contains($html,'<form') && !str_contains($html,'name="api_key"'),'pre-migration page has no provider form');
    pawCheck(!str_contains($html,'__Cert'),'pre-migration page issues no form token');
}
$child=$twig->render('balance/admin/curr.paw.twig',array_replace($base,['paw_currency'=>'USD','paw_currency_values'=>['cDisabled'=>1,'cCurrID'=>'USD','cMTS'=>'']]));
pawCheck(!str_contains($child,'name="api_key"') && !str_contains($child,'Регистрация PAW Payments'),'currency page contains neither shared key nor duplicate referral');
pawCheck(str_contains($child,'CURRENCY_FORM') && str_contains($child,'странице подключения'),'currency page links to shared connection and renders separate settings');
echo "PAW provider component tests passed ($checks checks).\n";
