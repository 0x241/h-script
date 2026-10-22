<?php

declare(strict_types=1);

namespace HScript\Payment {
    // These test-only namespace doubles never create a network socket.
    function curl_init(): object|false {
        $wire =& $GLOBALS['voletWire']; $wire['init']++;
        if (!empty($wire['init_fail'])) return false;
        return (object)['options'=>[]];
    }
    function curl_setopt_array(object $handle, array $options): bool {
        $handle->options=$options; $GLOBALS['voletWire']['options'][]=$options;
        return empty($GLOBALS['voletWire']['set_fail']);
    }
    function curl_exec(object $handle): bool {
        $wire =& $GLOBALS['voletWire']; $wire['exec']++;
        if (!empty($wire['throw'])) throw new \RuntimeException('synthetic private token fault');
        if (!empty($wire['exec_fail'])) return false;
        $method=str_contains($handle->options[CURLOPT_POSTFIELDS],'<v:findPaymentByOrderId>') ? 'findPaymentByOrderId' : 'findTransaction';
        $body=$wire['body'] ?? voletXml($method,$GLOBALS[$method==='findTransaction' ? 'voletTransaction' : 'voletOrder']);
        foreach ($wire['chunks'] ?? [$body] as $chunk)
            if (($handle->options[CURLOPT_WRITEFUNCTION])($handle,$chunk)!==strlen($chunk)) { $wire['errno']=23; return false; }
        return true;
    }
    function curl_errno(object $handle): int { return $GLOBALS['voletWire']['errno'] ?? 0; }
    function curl_getinfo(object $handle, int $option): int { return $GLOBALS['voletWire']['status'] ?? 200; }
    function curl_close(object $handle): void { $GLOBALS['voletWire']['closed']++; }
}

namespace {
    if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
    require dirname(__DIR__).'/vendor/autoload.php';
    require dirname(__DIR__).'/module/balance/lib.php';

    use HScript\Database\Connection;
    use HScript\Finance\CallbackAmount;
    use HScript\Finance\CallbackValidationException;
    use HScript\Finance\VoletContract;
    use HScript\Payment\Gateways\AdvCash;
    use HScript\Payment\PaymentManager;
    use HScript\Payment\VoletMerchantClient;
    use HScript\Security\SecretCipher;

    $fixture=json_decode(file_get_contents(__DIR__.'/fixtures/volet_sci.json'),true,32,JSON_THROW_ON_ERROR);
    $checks=0; $_cfg=['Const_Salt'=>'synthetic-volet-checkout-salt'];
    putenv('APP_DATA_KEY=synthetic-volet-component-key');
    function voletAssert(bool $condition,string $message): void { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; }
    function voletWire(array $change=[]): void {
        global $fixture;
        $GLOBALS['voletOrder']=$fixture['order']; $GLOBALS['voletTransaction']=$fixture['transaction'];
        $GLOBALS['voletWire']=$change+['status'=>200,'errno'=>0,'init'=>0,'exec'=>0,'closed'=>0,'options'=>[]];
    }
    function voletXml(string $method,array $fields): string {
        $xml='<s:Envelope xmlns:s="'.VoletMerchantClient::SOAP_NS.'"><s:Body><v:'.$method.'Response xmlns:v="'.VoletMerchantClient::API_NS.'"><return>';
        foreach ($fields as $key=>$value) $xml.='<'.$key.'>'.htmlspecialchars($value,ENT_XML1|ENT_QUOTES,'UTF-8').'</'.$key.'>';
        return $xml.'</return></v:'.$method.'Response></s:Body></s:Envelope>';
    }
    function voletSigned(array $fields): string {
        global $fixture;
        $keys=['ac_transfer','ac_start_date','ac_sci_name','ac_src_wallet','ac_dest_wallet','ac_order_id','ac_amount','ac_merchant_currency'];
        $fields['ac_hash']=hash('sha256',implode(':',array_map(static fn($key)=>$fields[$key],$keys)).':'.$fixture['sci']['key']);
        return http_build_query($fields,'','&',PHP_QUERY_RFC3986);
    }
    function voletRejected(callable $call,string $reason): void {
        try { $result=$call(); voletAssert(!$result['correct'] && $result['reason']===$reason,'fixed rejection '.$reason); }
        catch (CallbackValidationException $error) { voletAssert($error->reason===$reason,'fixed validation '.$reason.', got '.$error->reason); }
        catch (RuntimeException $error) { voletAssert($error->getMessage()===$reason,'fixed runtime '.$reason); }
    }
    final class VoletFixtureGateway extends AdvCash {
        public array|false $operation=false;
        protected function callbackOperation(int $id): array|false { return $this->operation; }
    }
    $gateway=new VoletFixtureGateway('AC',['Volet USD','USD',1,1,'USD','ac_transfer'],[],new Connection());
    $params=['sci'=>$fixture['sci'],'api'=>$fixture['api'],'sum'=>'1.23','tag'=>42,'callback_scale'=>2,'memo'=>'Synthetic Volet checkout',
        'callback_fee_policy'=>['cCASHINComis'=>'0','cCASHINComisMin'=>'0','cCASHINComisMax'=>'0','oComis'=>'0']];
    $form=$gateway->processDeposit($params);
    voletAssert($form['url']===VoletContract::CHECKOUT_URL && $form['ac_ps']==='VOLET' && $form['ac_amount']==='1.23','Current full USD checkout');
    voletAssert($form['ac_status_url_method']==='POST' && $form['ac_success_url_method']==='POST' && $form['ac_fail_url_method']==='POST','Explicit POST SCI endpoints');
    voletAssert($form['volet_api_secret']===$fixture['api']['apipass'] && $form['volet_wallet']===$fixture['api']['acc'],'Stored explicit API intent');
    $stamp=VoletContract::sciTime($fixture['callback']['ac_start_date']);
    $operation=['oID'=>42,'ouID'=>1,'ocID'=>1,'oOper'=>'CASHIN','oState'=>1,'oSum'=>'1.230000','oCTS'=>timeToStamp($stamp-60),
        'oComis'=>'0','cCASHINComis'=>'0','cCASHINComisMin'=>'0','cCASHINComisMax'=>'0',
        'cID'=>1,'cCID'=>'AC','cCurrID'=>'USD','cNumDec'=>2,'cMTS'=>timeToStamp($stamp),
        'cParamsSCI'=>encodeArrayToStr($fixture['sci'],'1AC'.$stamp,2),'cParamsAPI'=>encodeArrayToStr($fixture['api'],'1AC'.$stamp,3)];
    $operation['oParams']=arrayToStr(['checkout'=>SecretCipher::encrypt(json_encode($form,JSON_THROW_ON_ERROR),hash('sha256','checkout:42:1:'.$_cfg['Const_Salt']))]);
    $gateway->operation=$operation;
    voletAssert($form['volet_local_fee_percent']==='0' && $form['volet_local_fee_commission']==='0','Frozen local fee-zero policy and original commission');
    $manager=new PaymentManager(new Connection()); $manager->register('AC',$gateway);
    $run=static fn(?string $raw=null)=>$manager->handleCallback('AC',['_raw_body'=>$raw ?? voletSigned($fixture['callback']), 'ac_order_id'=>'999','ac_transfer'=>'forged'],$fixture['sci']);
    $_REQUEST=$_POST=$_GET=['ac_order_id'=>'999','ac_amount'=>'999','ac_transaction_status'=>'COMPLETED'];
    voletWire(); $verified=$run();
    voletAssert($verified['outcome']==='verified' && $verified['correct'] && $verified['sum']==='1.23' && $verified['sum2']==='1.23','Two authenticated replies authorize exact USD');
    voletAssert($verified['accfrom']===$fixture['callback']['ac_src_wallet'] && $verified['batch']===$fixture['callback']['ac_transfer'],'Signed source and UUID identity');
    voletAssert($verified['volet_profile']===VoletContract::PROFILE && preg_match('/^[a-f0-9]{64}$/D',$verified['volet_proof_hash'])===1,'Durable proof digest');
    foreach (['hash','cc','ac_fee','ac_transaction_status','ac_merchant_amount','ac_buyer_email','volet_api_secret'] as $field)
        voletAssert(!array_key_exists($field,$verified),'No unsigned/private field propagated');
    voletAssert($GLOBALS['voletWire']['exec']===2 && $GLOBALS['voletWire']['closed']===2,'Exactly API19 then API13 without leaked handles');
    foreach ($GLOBALS['voletWire']['options'] as $options) {
        voletAssert($options[CURLOPT_URL]===VoletMerchantClient::ENDPOINT,'Fixed SOAP endpoint');
        voletAssert($options[CURLOPT_SSL_VERIFYPEER]===true && $options[CURLOPT_SSL_VERIFYHOST]===2 && !$options[CURLOPT_FOLLOWLOCATION] && $options[CURLOPT_MAXREDIRS]===0,'Fixed verified TLS/no redirects');
        voletAssert($options[CURLOPT_CONNECTTIMEOUT_MS]===1500 && $options[CURLOPT_TIMEOUT_MS]===3500 && $options[CURLOPT_RETURNTRANSFER]===false,'Bounded time/response');
        voletAssert(in_array('SOAPAction: ""',$options[CURLOPT_HTTPHEADER],true),'Actual WSDL empty SOAP action');
        voletAssert(defined('CURLOPT_PROTOCOLS_STR') ? $options[CURLOPT_PROTOCOLS_STR]==='https' : $options[CURLOPT_PROTOCOLS]===CURLPROTO_HTTPS,'Only HTTPS');
    }
    $sent=$GLOBALS['voletWire']['options'];
    voletAssert(str_contains($sent[0][CURLOPT_POSTFIELDS],'<v:findPaymentByOrderId>') && str_contains($sent[0][CURLOPT_POSTFIELDS],'<orderId>42</orderId><sciName>Synthetic Store</sciName>'),'Saved order/SCI lookup precedes transaction lookup');
    voletAssert(str_contains($sent[1][CURLOPT_POSTFIELDS],'<arg1>'.$verified['batch'].'</arg1>'),'API13 uses confirmed API19 transaction');
    voletAssert(str_contains($sent[0][CURLOPT_POSTFIELDS],'<accountEmail>merchant@fixture.invalid</accountEmail><apiName>Synthetic API &amp; &lt;special&gt;</apiName>'),'Schema auth order and XML escaping');
    $authXml=VoletMerchantClient::envelope($fixture['api'],'findTransaction',['transaction'=>$verified['batch']],$stamp);
    voletAssert(str_contains($authXml,hash('sha256',$fixture['api']['apipass'].':20260623:12')) && !str_contains($authXml,$fixture['api']['apipass']),'UTC hourly token without raw password');
    foreach (['ac_transaction_status'=>'CANCELED','ac_fee'=>'999.99','ac_merchant_amount'=>'0.01','ac_buyer_email'=>'attacker@fixture.invalid'] as $field=>$value) {
        voletWire(); voletAssert($run(voletSigned(array_replace($fixture['callback'],[$field=>$value])))===$verified,'Unsigned mutation cannot alter proof/outcome');
    }
    foreach (['orderId'=>'43','sciName'=>'Other Store','transactionId'=>'235f9d0b-b48f-462c-9949-621c4930490d','transactionCurrency'=>'EUR','transactionAmount'=>'1.24'] as $field=>$value) {
        voletWire(); $GLOBALS['voletOrder'][$field]=$value; voletRejected($run,'callback_volet_order_mismatch');
        voletAssert($GLOBALS['voletWire']['exec']===1,'Rejected order skips API13');
    }
    foreach (['NEW','PENDING_REFUND','REFUNDED','COMPLETED_OVERPAYMENT_REFUND','COMPLETED_OVERPAYMENT_REFUNDED','COMPLETED_EXTRA'] as $status) {
        voletWire(); $GLOBALS['voletOrder']['paymentStatus']=$status; voletRejected($run,'volet_payment_not_completed');
        voletAssert($GLOBALS['voletWire']['exec']===1,'Incomplete/unsupported order cannot credit or perform API13');
    }
    foreach (['id'=>'235f9d0b-b48f-462c-9949-621c4930490d','orderId'=>'43','walletDestId'=>'USD999999999999','walletSrcId'=>'USD999999999999','currency'=>'EUR','receiverEmail'=>'other@fixture.invalid'] as $field=>$value) {
        voletWire(); $GLOBALS['voletTransaction'][$field]=$value; voletRejected($run,'callback_volet_transaction_mismatch');
    }
    foreach ([['status','PENDING'],['status','CONFIRMED'],['transactionType','DIRECT_PAYMENT'],['direction','OUTGOING'],['sci','false'],['api','true']] as [$field,$value]) {
        voletWire(); $GLOBALS['voletTransaction'][$field]=$value; voletRejected($run,'volet_transaction_contract_unsupported');
    }
    foreach (['0.01','NaN','1e-2','-0',' 0.00','0.00 '] as $fee) {
        voletWire(); $GLOBALS['voletTransaction']['fullCommission']=$fee; voletRejected($run,'volet_fee_contract_unsupported');
    }
    foreach (['amountIncome'=>'1.22','currencyIncome'=>'EUR'] as $field=>$value) {
        voletWire(); $GLOBALS['voletTransaction'][$field]=$value; voletRejected($run,'volet_income_contract_unsupported');
    }
    foreach ([['amount','1.24'],['amount','1.234'],['startTime','2026-06-23T12:30:01.000Z']] as [$field,$value]) {
        voletWire(); $GLOBALS['voletTransaction'][$field]=$value; voletRejected($run,$field==='startTime'?'callback_time_conflict':($value==='1.24'?'callback_amount_mismatch':'callback_amount_precision'));
    }
    foreach (['id','amount','amountIncome','api','currency','currencyIncome','direction','fullCommission','orderId','sci','startTime','status','transactionType','walletDestId','walletSrcId'] as $field) {
        voletWire(); unset($GLOBALS['voletTransaction'][$field]); voletRejected($run,'volet_confirmation_unavailable');
    }
    foreach (['&ac_transfer=duplicate','&ac%5Forder_id=42','&ac_hash%5B%5D=x','&extra=%GG'] as $extra) {
        voletWire(); voletRejected(fn()=>$run(voletSigned($fixture['callback']).$extra),'callback_fields_invalid');
        voletAssert($GLOBALS['voletWire']['init']===0,'Ambiguous raw input creates no transport');
    }
    $bad=$fixture['callback']; $raw=voletSigned($bad); $raw=preg_replace('/ac_hash=[a-f0-9]+/','ac_hash='.str_repeat('0',64),$raw);
    voletWire(); voletRejected(fn()=>$run($raw),'signature_invalid'); voletAssert($GLOBALS['voletWire']['init']===0,'Bad SCI signature skips API');
    foreach (['name','api','apipass','acc'] as $field) {
        $api=$fixture['api']; unset($api[$field]); $gateway->operation=$operation;
        $gateway->operation['cParamsAPI']=encodeArrayToStr($api,'1AC'.$stamp,3);
        voletWire(); voletRejected($run,'volet_api_configuration_required'); voletAssert($GLOBALS['voletWire']['init']===0,'Missing API profile cannot perform I/O');
    }
    $gateway->operation=$operation;
    foreach ([['status'=>301],['status'=>500],['errno'=>60],['errno'=>28],['exec_fail'=>true],['init_fail'=>true],['set_fail'=>true],['throw'=>true],['body'=>'synthetic private fault'],['chunks'=>[str_repeat('x',32768),str_repeat('x',32769)]]] as $fault) {
        voletWire($fault); voletRejected($run,'volet_confirmation_unavailable');
        voletAssert($GLOBALS['voletWire']['closed']===(!empty($fault['init_fail'])?0:1),'Transport failure closes handle');
    }
    $xml=voletXml('findTransaction',$fixture['transaction']);
    foreach ([str_replace('<return>','<return><amount>999.99</amount>',$xml),str_replace('<amount>1.23</amount>','<amount><nested>1.23</nested></amount>',$xml),str_replace('http://api.volet.com/','http://attacker.invalid/',$xml),str_replace('<return>','<return xsi:nil="true" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">',$xml),'<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]>'.$xml,'<?bad private?>'.$xml,str_replace('<return>','<return><unknown>bad</unknown>',$xml),str_replace('<return>','text<return>',$xml),str_repeat('x',65537)] as $bad) {
        voletWire(['body'=>$bad]); voletRejected($run,'volet_confirmation_unavailable');
    }
    voletAssert(VoletMerchantClient::decode(voletXml('findPaymentByOrderId',$fixture['order']),'findPaymentByOrderId')['transactionAmount']==='1.23','Actual WSDL19 direct scalar holder preserves amount');
    foreach (['2026-02-30 12:30:00','2026-23-06 12:30:00','2026-06-23 24:00:00'] as $date) voletRejected(fn()=>VoletContract::sciTime($date),'callback_time_invalid');
    foreach (['2026-02-30T12:30:00.000Z','2026-06-23T12:30:00','2026-06-23T12:30:60.000Z','2026-06-23T12:30:00+15:00'] as $date) voletRejected(fn()=>VoletContract::apiTime($date),'callback_time_invalid');
    voletAssert(VoletContract::apiTime('2026-06-23T15:30:00.1+03:00')===$stamp,'Explicit API offset normalizes to UTC second');
    foreach ([['api'=>[]],['sum'=>1.23],['sum'=>'1e2'],['sum'=>'1.234'],['tag'=>'042'],['callback_scale'=>9]] as $change)
        voletAssert(!isset($gateway->processDeposit(array_replace($params,$change))['url']),'Unsupported/malformed checkout cannot redirect');
    foreach (['cCASHINComis'=>'20','cCASHINComisMin'=>'0.01','cCASHINComisMax'=>'-1','oComis'=>'0.01'] as $field=>$value) {
        $bad=$params; $bad['callback_fee_policy'][$field]=$value;
        voletAssert(($gateway->processDeposit($bad)['error'] ?? '')==='volet_local_fee_contract_unsupported','Nonzero or malformed local fee cannot create checkout');
        $gateway->operation=array_replace($operation,[$field=>$value]);
        voletWire(); voletRejected($run,'callback_local_fee_configuration_changed');
        voletAssert($GLOBALS['voletWire']['init']===0,'Local fee drift creates no provider I/O');
    }
    $gateway->operation=$operation;
    foreach ([['sci'=>array_replace($params['sci'],['sci'=>str_repeat('s',51)])],['memo'=>str_repeat('x',256)],['url_ok'=>str_repeat('x',101)],['url_fail'=>str_repeat('x',101)],['url_callback'=>str_repeat('x',101)]] as $change)
        voletAssert(!isset($gateway->processDeposit(array_replace($params,$change))['url']),'Provider documented request bounds reject excess without truncation');
    $utf=$params; $utf['sci']['sci']=str_repeat('Я',50);
    voletAssert(isset($gateway->processDeposit($utf)['url']),'SCI limit counts UTF-8 characters');
    $utf['sci']['sci'].='Я'; voletAssert(!isset($gateway->processDeposit($utf)['url']),'SCI UTF-8 excess rejected');
    $boundary=$params; $boundary['sci']['sci']=str_repeat('s',50); $boundary['memo']=str_repeat('x',255); $boundary['url_callback']='https://example.invalid/'.str_repeat('x',76);
    voletAssert(isset($gateway->processDeposit($boundary)['url']),'Documented request field bounds accepted');
    $other=new AdvCash('ACR',['Volet RUB','RUB',1,1,'RUR','ac_transfer'],[],new Connection());
    voletAssert($other->handleCallback(['_raw_body'=>voletSigned($fixture['callback'])])['outcome']==='unsupported','ACR is explicitly unsupported');
    voletAssert(!isset($other->processDeposit($params)['url']),'Unverified ACR checkout is disabled');
    // Current public WSDL: getBalances(arg0:authDTO), repeated walletBalanceDTO with xs:decimal amount.
    $balancesXml=voletXml('getBalances',['amount'=>'0.00000001','id'=>'USD123456']);
    voletAssert(VoletMerchantClient::decode($balancesXml,'getBalances')===['USD123456'=>'0.00000001'],'Connection balance probe preserves xs:decimal strings');
    $balanceEnvelope=VoletMerchantClient::envelope($fixture['api'],'getBalances',[],time());
    voletAssert(str_contains($balanceEnvelope,'<v:getBalances><arg0>') && !str_contains($balanceEnvelope,'<arg1>'),'Connection probe matches current no-arg1 WSDL');
    foreach ([str_replace('0.00000001','NaN',$balancesXml),str_replace('</return>','<id>USD999</id></return>',$balancesXml),str_replace('</v:getBalancesResponse>','<return><amount>2</amount><id>USD123456</id></return></v:getBalancesResponse>',$balancesXml)] as $bad)
        voletRejected(static fn()=>VoletMerchantClient::decode($bad,'getBalances'),'volet_response_invalid');
    echo "Volet offline adapter/transport checks passed ($checks checks).\n";
}
