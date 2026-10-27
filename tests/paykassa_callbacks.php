<?php

declare(strict_types=1);

namespace HScript\Payment {
    // Namespaced doubles exercise the real bounded transport without a socket.
    function curl_init(): object|false {
        $wire =& $GLOBALS['paykassaWire'];
        $wire['init'] = ($wire['init'] ?? 0) + 1;
        if (!empty($wire['init_fail'])) return false;
        return (object)['options'=>[]];
    }
    function curl_setopt_array(object $handle, array $options): bool {
        $handle->options = $options;
        $GLOBALS['paykassaWire']['options'] = $options;
        return empty($GLOBALS['paykassaWire']['set_fail']);
    }
    function curl_exec(object $handle): bool {
        $wire =& $GLOBALS['paykassaWire'];
        $wire['exec'] = ($wire['exec'] ?? 0) + 1;
        if (!empty($wire['throw'])) throw new \RuntimeException('synthetic remote text '.$wire['body']);
        if (!empty($wire['exec_fail'])) return false;
        foreach ($wire['chunks'] ?? [$wire['body']] as $chunk)
            if (($handle->options[CURLOPT_WRITEFUNCTION])($handle,$chunk) !== strlen($chunk)) {
                $wire['errno'] = 23;
                return false;
            }
        return true;
    }
    function curl_errno(object $handle): int { return $GLOBALS['paykassaWire']['errno'] ?? 0; }
    function curl_getinfo(object $handle, int $option): int { return $GLOBALS['paykassaWire']['status'] ?? 200; }
    function curl_close(object $handle): void {
        $GLOBALS['paykassaWire']['closed'] = ($GLOBALS['paykassaWire']['closed'] ?? 0) + 1;
    }
}

namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    require dirname(__DIR__).'/vendor/autoload.php';

    use HScript\Database\Connection;
    use HScript\Payment\Gateways\Paykassa;
    use HScript\Payment\Gateways\AdvCash;
    use HScript\Payment\PaykassaSciClient;

    // Parser/transport units isolate the SQL original-invoice/quota contract.
    // The installed financial matrix exercises the concrete implementation.
    final class OfflinePaykassa extends Paykassa {
        protected function callbackIntent(int $order,array $config): array { return []; }
        protected function verificationClaim(array $context,string $token): array { return ['cached'=>false]; }
        protected function verificationComplete(array $context,array $claim,array $verified): void {}
    }

    $GLOBALS['paykassaFixture'] = json_decode(file_get_contents(__DIR__.'/fixtures/paykassa_order.json'),true,32,JSON_THROW_ON_ERROR);
    $checks = 0;
    function pkAssert(bool $ok, string $message): void {
        global $checks;
        if (!$ok) throw new RuntimeException($message);
        $checks++;
    }
    function pkWire(string $body, array $change = []): void {
        $GLOBALS['paykassaWire'] = $change + ['body'=>$body,'status'=>200,'errno'=>0,'init'=>0,'exec'=>0,'closed'=>0];
    }
    function pkData(array $change = []): array {
        return $change + $GLOBALS['paykassaFixture']['verified_order'];
    }
    function pkResponse(array $data, bool $error = false): string {
        return json_encode(['error'=>$error,'message'=>'synthetic provider response','data'=>$data],JSON_THROW_ON_ERROR);
    }
    $config = $GLOBALS['paykassaFixture']['config'];
    $gateway = new OfflinePaykassa('PKB',['Bitcoin','BTC',1,1,'BTC','private_hash'],[],new Connection());
    $raw = $GLOBALS['paykassaFixture']['request_body'];
    $request = ['_raw_body'=>$raw,'_config'=>$config,'private_hash'=>'outer-override','order_id'=>'999'];
    $_REQUEST = ['private_hash'=>'global-override'];
    $_POST = ['private_hash'=>'post-override'];
    $_GET = ['private_hash'=>'get-override'];
    pkWire(pkResponse(pkData()));
    $result = $gateway->handleCallback($request);
    pkAssert($result['outcome']==='verified' && $result['correct'] && $result['authenticated'],'credentialed order IPN not verified');
    pkAssert($result['sum']==='1.23' && $result['curr']==='BTC' && $result['tag']==='42','authoritative money/order changed');
    pkAssert($result['batch']==='579205' && $result['accto']===pkData()['address'],'authoritative invoice/receiver lost');
    pkAssert($result['merchant_id']==='122' && $result['provider_invoice_hash']===hash('sha256',pkData()['hash']),'merchant/payment-link proof lost');
    pkAssert($result['destination_tag']==='' && $result['response']==='42|success','unsupported tag or premature provider ACK');
    foreach (['private_hash','hash','cc','sign','message','data','signed_at'] as $field)
        pkAssert(!array_key_exists($field,$result),'raw or fabricated callback field exposed: '.$field);
    $options = $GLOBALS['paykassaWire']['options'];
    parse_str($options[CURLOPT_POSTFIELDS],$sent);
    pkAssert($sent===['func'=>'sci_confirm_order','private_hash'=>'exact+token:42','sci_id'=>'122','sci_key'=>$config['key'],'test'=>'false'],'request/global fields can replace exact token or credentials');
    pkAssert($GLOBALS['paykassaWire']['exec']===1 && $GLOBALS['paykassaWire']['closed']===1,'callback performs extra lookup or leaks curl handle');
    pkAssert($options[CURLOPT_URL]===PaykassaSciClient::ENDPOINT,'SCI endpoint not fixed/current');
    pkAssert($options[CURLOPT_SSL_VERIFYPEER]===true && $options[CURLOPT_SSL_VERIFYHOST]===2,'verified TLS disabled');
    pkAssert($options[CURLOPT_FOLLOWLOCATION]===false && $options[CURLOPT_MAXREDIRS]===0,'credential-bearing redirect enabled');
    pkAssert($options[CURLOPT_TIMEOUT_MS]===3500 && $options[CURLOPT_CONNECTTIMEOUT_MS]===1500,'SCI time budget not bounded');
    pkAssert(isset($options[CURLOPT_WRITEFUNCTION]) && $options[CURLOPT_RETURNTRANSFER]===false,'unbounded curl return buffering enabled');
    pkAssert(defined('CURLOPT_PROTOCOLS_STR') ? $options[CURLOPT_PROTOCOLS_STR]==='https' : $options[CURLOPT_PROTOCOLS]===CURLPROTO_HTTPS,'non-HTTPS curl protocol enabled');

    foreach (['','   ',null,[]] as $secret) {
        pkWire(pkResponse(pkData()));
        $bad = $request; $bad['_config']['key']=$secret;
        pkAssert($gateway->handleCallback($bad)['outcome']==='authentication_failed','missing/invalid secret accepted');
        pkAssert($GLOBALS['paykassaWire']['init']===0,'missing secret triggers I/O');
    }
    foreach (['0','0122','abc','',null,122] as $merchant) {
        pkWire(pkResponse(pkData()));
        $bad = $request; $bad['_config']['id']=$merchant;
        pkAssert($gateway->handleCallback($bad)['outcome']==='authentication_failed','invalid merchant config accepted');
        pkAssert($GLOBALS['paykassaWire']['init']===0,'invalid merchant config triggers I/O');
    }
    foreach (['',str_repeat('x',65537),$raw.'&private_hash=duplicate',$raw.'&order_id=43',
        'private_hash[]=x&system=BitCoin&currency=BTC&order_id=42',
        'private_hash=%GG&system=BitCoin&currency=BTC&order_id=42',
        'private_hash=%00x&system=BitCoin&currency=BTC&order_id=42',
        'private_hash=has+space&system=BitCoin&currency=BTC&order_id=42',
        'private_hash=x&system=BitCoin&currency=BTC&order_id=0',
        'private_hash=x&system=BitCoin&currency=BTC&order_id=042',
        'private_hash=x&system=BitCoin&currency=BTC&order_id=1e2',
        'private_hash=x&system=BitCoin&currency=ETH&order_id=42',
        'private_hash=x&system=Ethereum&currency=BTC&order_id=42',
        '{"private_hash":"x","system":"BitCoin","currency":"BTC","order_id":"42"}',
    ] as $body) {
        pkWire(pkResponse(pkData()));
        $bad = $request; $bad['_raw_body']=$body;
        pkAssert($gateway->handleCallback($bad)['outcome']==='invalid_payload','ambiguous or invalid form accepted');
        pkAssert($GLOBALS['paykassaWire']['init']===0,'invalid notification triggers I/O');
    }
    foreach ([
        ['shop_id'=>'123'],['shop_id'=>'0'],['order_id'=>'43'],['order_id'=>'0'],
        ['currency'=>'ETH'],['currency'=>'btc'],['system'=>'Ethereum'],['system'=>'bitcoin_btc'],
        ['amount'=>'0'],['amount'=>'-1'],['amount'=>'1e2'],['amount'=>'1.23 '],['amount'=>'1,23'],
        ['amount'=>1.23],['amount'=>'1.230000001'],['amount'=>'1234567890123'],
        ['transaction'=>''],['transaction'=>'0'],['transaction'=>'1e2'],['transaction'=>'579205\n'],
        ['address'=>''],['address'=>'javascript:alert(1)'],['tag'=>'100'],['tag'=>0],
        ['hash'=>''],['hash'=>'raw secret / token'],['partial'=>'yes'],['partial'=>true],['partial'=>null],
    ] as $change) {
        pkWire(pkResponse(pkData($change)));
        $bad = $gateway->handleCallback($request);
        pkAssert(!$bad['correct'] && $bad['outcome']!=='verified','invalid confirmation accepted: '.json_encode(array_keys($change)));
        pkAssert(!isset($bad['response']),'invalid confirmation acknowledges delivery');
    }
    foreach (['shop_id','order_id','transaction','amount','currency','system','address','tag','hash','partial'] as $field) {
        $data=pkData();unset($data[$field]);pkWire(pkResponse($data));
        pkAssert(!$gateway->handleCallback($request)['correct'],'missing confirmation field accepted: '.$field);
    }
    pkWire(pkResponse([],true));
    pkAssert($gateway->handleCallback($request)===['outcome'=>'authentication_failed','reason'=>'paykassa_confirmation_failed','authenticated'=>false,'correct'=>false],'provider error reflected or accepted');

    foreach ([['status'=>301],['status'=>400],['status'=>500],['status'=>204],['errno'=>60],['errno'=>28],
        ['exec_fail'=>true],['set_fail'=>true],['init_fail'=>true],['throw'=>true],
        ['body'=>'not-json synthetic-private-token'],['body'=>'[]'],
        ['body'=>'{"error":"false","data":{}}'],['body'=>'{"error":false,"data":{"amount":[]}}'],
        ['body'=>str_repeat('x',65537)],['chunks'=>[str_repeat('x',32768),str_repeat('x',32769)]],
    ] as $fault) {
        pkWire(pkResponse(pkData()),$fault);
        try { $gateway->handleCallback($request);throw new LogicException('transport fault accepted'); }
        catch (RuntimeException $e) {
            pkAssert($e->getMessage()==='paykassa_confirmation_unavailable','transport exposes provider/credential text');
        }
        pkAssert($GLOBALS['paykassaWire']['closed']===(!empty($fault['init_fail']) ? 0 : 1),'fault leaks curl handle');
    }

    $created = $GLOBALS['paykassaFixture']['created_invoice'];
    $params = ['sci'=>$config]+$GLOBALS['paykassaFixture']['creation_intent'];
    pkWire(pkResponse($created));
    $form = $gateway->processDeposit($params);
    pkAssert($form['url']===$created['url'] && $form['get']===true && $form['hash']===pkData()['hash'],'checkout link/method lost');
    pkAssert($form['paykassa_amount']==='1.23' && $form['paykassa_merchant_id']==='122' && $form['paykassa_currency']==='BTC','immutable money/merchant not saved');
    pkAssert($form['paykassa_address']===$created['wallet'] && $form['paykassa_tag']==='' && $form['paykassa_invoice']==='579205','immutable receiver/invoice not saved');
    pkAssert($form['paykassa_hash']===hash('sha256',pkData()['hash']) && $form['psysorder']==='579205','payment-link hash/invoice proof not saved');
    parse_str($GLOBALS['paykassaWire']['options'][CURLOPT_POSTFIELDS],$sent);
    pkAssert($sent['func']==='sci_create_order_get_data' && $sent['amount']==='1.23' && $sent['system']==='11','checkout does not use a single exact-amount address request');
    pkAssert($GLOBALS['paykassaWire']['exec']===1,'checkout creates more than one provider intent');
    foreach ([['order_id'=>'43'],['amount'=>'1.24'],['amount'=>1.23],['currency'=>'ETH'],['system'=>'Ethereum'],
        ['invoice'=>'0'],['wallet'=>''],['tag'=>'123'],['url'=>'https://evil.example/sci/index.php?hash=x'],
        ['url'=>'http://crypto.paykassa.pro/sci/index.php?hash=x'],
        ['url'=>'https://crypto.paykassa.pro:443/sci/index.php?hash=x'],
        ['url'=>'https://u:p@crypto.paykassa.pro/sci/index.php?hash=x'],
        ['url'=>'https://crypto.paykassa.pro/sci/index.php?hash=x&hash=y'],
        ['url'=>'https://crypto.paykassa.pro/sci/index.php?hash=x&amount=999'],
        ['url'=>'https://crypto.paykassa.pro/sci/index.php?hash=x#fragment'],
    ] as $change) {
        pkWire(pkResponse($change+$created));
        pkAssert(!isset($gateway->processDeposit($params)['url']),'invalid invoice exposed to browser');
    }
    foreach (['invoice','order_id','wallet','amount','system','currency','url','tag'] as $field) {
        $data=$created;unset($data[$field]);pkWire(pkResponse($data));
        pkAssert(!isset($gateway->processDeposit($params)['url']),'missing invoice proof accepted: '.$field);
    }
    foreach ([['sum'=>1.23],['sum'=>'1e2'],['sum'=>'0'],['tag'=>0],['tag'=>'042']] as $change) {
        pkWire(pkResponse($created));
        pkAssert(!isset($gateway->processDeposit($change+$params)['url']),'invalid creation intent accepted');
        pkAssert($GLOBALS['paykassaWire']['init']===0,'invalid creation intent reaches provider');
    }

    foreach (['PKE'=>['Ethereum','ETH',12],'PKL'=>['LiteCoin','LTC',14]] as $id=>[$system,$currency,$systemId]) {
        $other = new OfflinePaykassa($id,[$system,$currency,1,1,$currency,'private_hash'],[],new Connection());
        $req=['_raw_body'=>http_build_query(['private_hash'=>'synthetic-token','system'=>$system,'currency'=>$currency,'order_id'=>'42']),'_config'=>$config];
        pkWire(pkResponse(pkData(['currency'=>$currency,'system'=>$system])));
        pkAssert($other->handleCallback($req)['curr']===$currency,'crypto profile mapped to wrong asset');
        pkWire(pkResponse(['currency'=>$currency,'system'=>$system]+$created));
        pkAssert($other->processDeposit($params)['paykassa_currency']===$currency,'crypto creation mapped to wrong asset');
        parse_str($GLOBALS['paykassaWire']['options'][CURLOPT_POSTFIELDS],$sent);
        pkAssert($sent['system']===(string)$systemId,'crypto creation mapped to wrong provider system');
    }
    foreach (['PKAU'=>'USD','PKAR'=>'RUB'] as $id=>$asset) {
        $fiat=new AdvCash($id,['Unsupported fiat',$asset,1,1,$asset,'private_hash'],[],new Connection());
        pkWire(pkResponse(pkData()));
        $unsupported=$fiat->handleCallback($request);
        pkAssert($unsupported['outcome']==='unsupported' && !$unsupported['correct'],'unconfirmed Paykassa fiat profile can settle');
        pkAssert($GLOBALS['paykassaWire']['init']===0,'unsupported fiat callback reaches provider');
    }
    pkWire(pkResponse(pkData()));
    PaykassaSciClient::request('122',$config['key'],['func'=>'sci_confirm_order','private_hash'=>'x','sci_id'=>'999','sci_key'=>'override','test'=>'true']);
    parse_str($GLOBALS['paykassaWire']['options'][CURLOPT_POSTFIELDS],$sent);
    pkAssert($sent['sci_id']==='122' && $sent['sci_key']===$config['key'] && $sent['test']==='false','caller fields override credentials or enable test mode');
    foreach([['test'=>'1'],['test_mode'=>'1'],['_provider_environment'=>'sandbox'],['environment'=>'sandbox']] as $flags){
        pkWire(pkResponse(pkData()));
        pkAssert($gateway->getBalance($flags)['result']==='paykassa_sandbox_removed','legacy balance silently switched to live');
        pkAssert($gateway->processWithdrawal(['from'=>$flags])['result']==='paykassa_sandbox_removed','legacy outgoing silently switched to live');
        pkAssert($GLOBALS['paykassaWire']['init']===0,'removed sandbox reached provider');
    }
    pkWire(pkResponse(pkData()));
    try{PaykassaSciClient::request('122',$config['key'],['func'=>'sci_confirm_order','private_hash'=>'x'],true);throw new LogicException('Removed positional SCI test flag silently enabled live');}catch(InvalidArgumentException){pkAssert($GLOBALS['paykassaWire']['init']===0,'removed positional flag reached SCI API');}
    require_once dirname(__DIR__).'/lib/paykassa/paykassa_api.class.php';
    try{new \PayKassaAPI('123','synthetic-key',true);throw new LogicException('Removed positional API test flag silently enabled live');}catch(InvalidArgumentException){pkAssert(true,'removed legacy API flag rejected');}
    // The real unpaid sandbox capture is retained as a negative production fixture.
    $sandboxFixture=json_decode(file_get_contents(__DIR__.'/fixtures/paykassa_sandbox_invoice.json'),true,16,JSON_THROW_ON_ERROR)['data'];
    $sandboxParams=$params;$sandboxParams['sci']=$config+['test'=>'1'];
    pkWire(pkResponse($sandboxFixture));pkAssert(!isset($gateway->processDeposit($sandboxParams)['url']) && $GLOBALS['paykassaWire']['init']===0,'removed sandbox must fail before I/O');
    pkWire(pkResponse($sandboxFixture));pkAssert(!isset($gateway->processDeposit($params)['url']),'sandbox invoice accepted by live configuration');
    foreach([true,null,'true',1] as $flag){pkWire(pkResponse(pkData()+['test_mode'=>$flag]));pkAssert(!$gateway->handleCallback($request)['correct'],'sandbox/ambiguous confirmation accepted by live profile');}
    pkWire(pkResponse(pkData()+['test_mode'=>false]));pkAssert($gateway->handleCallback($request)['correct'],'explicit live flag rejected');
    foreach(\HScript\Payment\PaykassaAssets::ASSETS as $id=>[$currency,$systemId,$system,$network,$hasTag]) {
        $other=new OfflinePaykassa($id,[$network,$currency,1,0,$currency,'private_hash'],[],new Connection());
        $tag=$hasTag?'123456':'';
        pkWire(pkResponse(array_replace($created,['currency'=>$currency,'system'=>$system,'tag'=>$tag])));
        $form=$other->processDeposit($params);
        pkAssert(($form['paykassa_currency']??null)===$currency && $form['paykassa_tag']===$tag,'catalog invoice currency/network/tag mismatch: '.$id);
        parse_str($GLOBALS['paykassaWire']['options'][CURLOPT_POSTFIELDS],$sent);
        pkAssert($sent['system']===(string)$systemId && $sent['test']==='false','catalog SCI system/test mismatch: '.$id);
        $req=['_raw_body'=>http_build_query(['private_hash'=>'synthetic-token','system'=>$system,'currency'=>$currency,'order_id'=>'42']),'_config'=>$config];
        pkWire(pkResponse(pkData(['currency'=>$currency,'system'=>$system,'tag'=>$tag])));
        $result=$other->handleCallback($req);
        pkAssert($result['correct'] && $result['destination_tag']===$tag,'catalog authenticated callback/tag mismatch: '.$id);
        if($hasTag)foreach(['',false,null,0] as $missingTag){
            pkWire(pkResponse(array_replace($created,['currency'=>$currency,'system'=>$system,'tag'=>$missingTag])));
            pkAssert(!isset($other->processDeposit($params)['url']),'tag-required invoice omitted payment tag: '.$id);
            pkWire(pkResponse(pkData(['currency'=>$currency,'system'=>$system,'tag'=>$missingTag])));
            pkAssert(!$other->handleCallback($req)['correct'],'tag-required callback omitted payment tag: '.$id);
        }
        $wrong=$system==='bitcoin'?'binancesmartchain_bep20':'bitcoin';
        pkWire(pkResponse(pkData(['currency'=>$currency,'system'=>$wrong,'tag'=>$tag])));
        pkAssert(!$other->handleCallback($req)['correct'],'cross-network confirmation accepted: '.$id);
        $detected=(new \HScript\Payment\PaymentManager(new Connection()))->detectCallback(['private_hash'=>'x','system'=>$system,'currency'=>$currency],[]);
        pkAssert($detected===$id,'catalog callback detection mismatch: '.$id);
        pkAssert(isset(\HScript\Finance\PaykassaContract::SYSTEMS[$id]) && \HScript\Payment\CallbackCapabilities::allows($id),'catalog is missing original invoice/receiver proof: '.$id);
        if(!in_array($id,['PKB','PKE','PKL'],true))pkAssert($other->processWithdrawal([])['result']==='automatic_payout_unsupported','new incoming catalog enabled unreviewed outgoing: '.$id);
    }
    pkWire('{"error":false,"data":{"amount":"1.23","amount":"1.24"}}');
    try{PaykassaSciClient::request('122',$config['key'],['func'=>'sci_confirm_order','private_hash'=>'x']);throw new LogicException('Duplicate JSON accepted');}
    catch(RuntimeException $e){if($e instanceof LogicException)throw $e;pkAssert($e->getMessage()==='paykassa_confirmation_unavailable','duplicate JSON is not reflected');}
    echo "Paykassa offline adapter/transport checks passed ($checks checks).\n";
}
