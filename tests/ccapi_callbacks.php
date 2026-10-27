<?php

declare(strict_types=1);
namespace { if (PHP_SAPI!=='cli') { http_response_code(404); exit; } }
namespace HScript\Payment {
    // No fallback to native curl: these tests cannot reach a merchant endpoint.
    function curl_init() { return (object)[]; }
    function curl_setopt_array($handle,array $options): bool { $GLOBALS['ccapi_options']=$options; $GLOBALS['ccapi_calls']++; return true; }
    function curl_exec($handle): bool {
        $write=$GLOBALS['ccapi_options'][CURLOPT_WRITEFUNCTION];
        $raw=$GLOBALS['ccapi_reply'];
        return $write($handle,$raw)===strlen($raw) && !($GLOBALS['ccapi_transport_failure'] ?? false);
    }
    function curl_errno($handle): int { return 0; }
    function curl_getinfo($handle,$option): int { return $GLOBALS['ccapi_status'] ?? 200; }
    function curl_close($handle): void {}
}
namespace {
require dirname(__DIR__).'/vendor/autoload.php';
use HScript\Database\Connection;
use HScript\Payment\CcapiV3Client;
use HScript\Payment\Gateways\CryptoCurrencyApi;
use HScript\Payment\JsonCallbackPayload;
use HScript\Payment\PaymentManager;
use HScript\Security\SensitiveDataRedactor;
$checks=0; $ccapi_calls=0;
function ccaAssert(bool $ok,string $why): void { global $checks; if (!$ok) throw new RuntimeException($why); $checks++; }
function ccaThrows(callable $work,string $error=''): void {
    try { $work(); throw new LogicException('Expected CCAPI refusal'); }
    catch (InvalidArgumentException|RuntimeException $caught) { ccaAssert($error==='' || $caught->getMessage()===$error,'CCAPI refusal '.$error); }
}
$fixture=json_decode(file_get_contents(__DIR__.'/fixtures/ccapi_v3_native.json'),true,32,JSON_THROW_ON_ERROR);
$payload=$fixture['payload']; $config=['protocol'=>'3','v3_key'=>$fixture['secret']];
$manager=new PaymentManager(new Connection());
$sign=static function(array $data,string $key='fixture-ccapi-v3-secret'):array {
    unset($data['sign']); ksort($data,SORT_STRING);
    $values=array_map(static fn($v)=>$v===null?'':(is_array($v)||is_object($v)?'Array':(string)$v),array_values($data));
    $data['sign']=sha1(implode(':',$values).':'.md5($key)); return $data;
};
$send=static fn(array $data,string $id='CCAB',?array $cfg=null)=>$manager->handleCallback($id,['_raw_body'=>json_encode($data,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION)],$cfg ?? $config);
$rawSend=static fn(string $raw)=>$manager->handleCallback('CCAB',['_raw_body'=>$raw],$config);
$unsigned=$payload; unset($unsigned['sign']); ksort($unsigned,SORT_STRING);
ccaAssert(implode(':',array_map(static fn($v)=>(string)$v,array_values($unsigned))).':'.md5($fixture['secret'])===$fixture['canonical'],'Independent canonical bytes differ');
ccaAssert(sha1($fixture['canonical'])===$payload['sign'],'Independent fixed signature differs');
$result=$send($payload);
ccaAssert($result['correct'] && $result['outcome']==='verified' && $result['sum']==='1.23','Independent native vector rejected');
ccaAssert($result['batch']===str_repeat('b',64).'#0' && $result['signed_at']===1791459000,'Native position/time identity lost');
ccaAssert($result['ccapi_network_fee']==='0.00000001' && $result['sum2']==='1.23','Network fee replaced gross');
ccaAssert(!isset($result['sign']) && !isset($result['v3_key']),'Verification exposes secrets/signature');
ccaAssert(SensitiveDataRedactor::redact(['v3_key'=>'synthetic-secret'])['v3_key']==='[REDACTED]','New key is not redacted');
foreach (CryptoCurrencyApi::NATIVE as $id=>[$chain,$asset]) {
    $native=$payload; $native['chain']=$chain; $native['currency']=$asset;
    if ($chain==='ripple') $native['to']='rHb9CJAWyB4rj91VRWn96DkukG4bwdtyTh';
    if ($chain==='ton') $native['to']='0:'.str_repeat('a',64);
    ccaAssert($send($sign($native),$id)['correct'],$id.' native mapping');
    ccaAssert($manager->detectCallback($native)===$id,$id.' v3 routing');
    ccaAssert($manager->definitions()[$id][3]===1,$id.' v3 payout advertised');
}
foreach ([0=>'pending',1=>'pending',2=>'verified',99=>'verified',100=>'unsupported',101=>'unsupported',102=>'verified'] as $confirmation=>$outcome) {
    $changed=$payload; $changed['confirmation']=$confirmation;
    $reply=$send($sign($changed)); ccaAssert($reply['outcome']===$outcome,'Confirmation classification '.$confirmation);
    if ($confirmation===100 || $confirmation===101) ccaAssert($reply['authenticated'] && !$reply['correct'],'Manual confirmation can settle');
}
foreach (['amount'=>'2','fee'=>'0.01','to'=>str_repeat('a',40),'date'=>1791459001,'pos'=>1,'label'=>'43','from'=>'anotherSender'] as $field=>$value) {
    $changed=$payload; $changed[$field]=$value;
    ccaAssert($send($changed)['outcome']==='authentication_failed','Unsigned field accepted '.$field);
}
foreach ([['protocol'=>3,'v3_key'=>$fixture['secret']],['protocol'=>'2','v3_key'=>$fixture['secret']],['protocol'=>'3','apipass'=>$fixture['secret']],['apipass'=>$fixture['secret']],['protocol'=>'3','v3_key'=>' '],['protocol'=>'3','v3_key'=>"key\nHeader: value"]] as $bad) {
    ccaAssert(!$manager->gateway('CCAB')->validateConfig($bad),'Legacy/noncanonical config accepted');
    ccaAssert($send($payload,'CCAB',$bad)['outcome']==='authentication_failed','Unconfigured callback accepted');
}
ccaAssert($send($payload,'CCAB',['protocol'=>'3','v3_key'=>'wrong'])['outcome']==='authentication_failed','Wrong key accepted');
foreach ([1,2,4,30,'03',true,3.0] as $version) {
    $changed=$payload; $changed['cryptocurrencyapi.net']=$version;
    ccaAssert(!$send($sign($changed))['correct'],'Noncanonical marker accepted');
    ccaAssert($manager->detectCallback($changed)==='','Non-v3 route accepted');
}
foreach (['out','track','forward','fee','fees'] as $type) {
    $changed=$payload; $changed['type']=$type;
    ccaAssert($send($sign($changed))['outcome']==='unsupported','Unsupported type '.$type);
}
foreach (['token'=>'USDT','tokenContract'=>'contract'] as $field=>$value) {
    $changed=$payload; $changed[$field]=$value;
    $reply=$send($sign($changed)); ccaAssert($reply['outcome']==='unsupported' && $reply['authenticated'],'Native token accepted');
}
foreach (['chain'=>'unregistered','currency'=>'UNKNOWN'] as $field=>$value) {
    $changed=$payload; $changed[$field]=$value;
    ccaAssert($send($sign($changed))['outcome']==='invalid_payload','Other asset accepted');
    ccaAssert($manager->detectCallback($changed)==='','Other asset routed as native');
}
foreach (array_keys($payload) as $field) {
    $changed=$payload; unset($changed[$field]);
    ccaAssert(!$send($field==='sign'?$changed:$sign($changed))['correct'],'Missing required field accepted '.$field);
}
foreach (['new_field'=>'value','labels'=>['42'],'ids'=>[42]] as $field=>$value) {
    $changed=$payload; $changed[$field]=$value;
    ccaAssert($send($sign($changed))['outcome']==='invalid_payload','Unknown key accepted '.$field);
}
$renamed=$payload; $renamed['tokens']=$renamed['token']; unset($renamed['token']);
ccaAssert($send($sign($renamed))['outcome']==='invalid_payload','Unprotected key rename accepted');
foreach (['from','to','token','tokenContract','fee','label'] as $field) {
    $changed=$payload; $changed[$field]='left:right';
    ccaAssert($send($sign($changed))['outcome']==='invalid_payload','Unescaped delimiter accepted '.$field);
}
foreach (['amount'=>['1.23'],'label'=>[42],'pos'=>[],'token'=>(object)[],'confirmation'=>true,'date'=>null,'amount'=>'1e0','fee'=>'-1','label'=>'042','pos'=>'00','date'=>'0','txid'=>'uppercase','to'=>'bitcoincash:q'.str_repeat('q',40)] as $field=>$value) {
    $changed=$payload; $changed[$field]=$value;
    ccaAssert($send($sign($changed))['outcome']==='invalid_payload','Invalid scalar accepted '.$field);
}
$raw=json_encode($payload,JSON_THROW_ON_ERROR);
foreach ([substr($raw,0,-1).',"amount":"1.230000"}',str_replace('"amount":"1.230000"','"amount":"1.230000","\\u0061mount":"1.230000"',$raw),str_replace('"amount":"1.230000"','"amount":1.23',$raw),str_replace('"amount":"1.230000"','"amount":123e-2',$raw),str_replace('"pos":0','"pos":0.0',$raw),'[]','null','{"x":{"a":1,"\\u0061":2}}',str_repeat('a',65537)] as $badRaw) {
    ccaAssert($rawSend($badRaw)['outcome']==='invalid_payload','Ambiguous/raw float JSON accepted');
}
$integer=$payload; $integer['amount']=2; $integer['fee']=0; $integer['cryptocurrencyapi.net']='3';
ccaAssert($send($sign($integer))['sum']==='2','Safe integer scalar money rejected');
ccaAssert($manager->handleCallback('CCAB',['_json'=>$payload],$config)['outcome']==='invalid_payload','Decoded JSON without raw body accepted');
ccaAssert($manager->handleCallback('CCAB',['_raw_body'=>$raw,'_json'=>['amount'=>'999'],'amount'=>'999'],$config)['sum']==='1.23','Stale globals override signed raw body');
$options=CcapiV3Client::options('bitcoin','give',$config,['label'=>'42','uniqID'=>CcapiV3Client::requestId('https://fixture.invalid/callback','42'),'statusURL'=>'https://fixture.invalid/callback']);
ccaAssert($options[CURLOPT_URL]==='https://new.cryptocurrencyapi.net/api/bitcoin/.give','Fixed v3 endpoint');
ccaAssert($options[CURLOPT_POSTFIELDS]===http_build_query(['label'=>'42','uniqID'=>CcapiV3Client::requestId('https://fixture.invalid/callback','42'),'statusURL'=>'https://fixture.invalid/callback'],'','&',PHP_QUERY_RFC3986),'Single invoice request args');
ccaAssert(in_array('CCAPI-KEY: '.$fixture['secret'],$options[CURLOPT_HTTPHEADER],true),'Header authentication absent');
ccaAssert($options[CURLOPT_FOLLOWLOCATION]===false && $options[CURLOPT_SSL_VERIFYPEER]===true && $options[CURLOPT_SSL_VERIFYHOST]===2,'TLS/redirect boundary');
ccaAssert(CcapiV3Client::requestId('https://one.invalid/status','42')!==CcapiV3Client::requestId('https://two.invalid/status','42'),'Two installation callback URLs cannot share uniqID');
ccaAssert(CcapiV3Client::requestId('https://one.invalid/status','42')===CcapiV3Client::requestId('https://one.invalid/status','42'),'Same installation has stable uniqID');
ccaAssert($options[CURLOPT_TIMEOUT_MS]===3500 && $options[CURLOPT_CONNECTTIMEOUT_MS]===1500,'Bounded transport deadline');
foreach ([['label'=>'42','uniqID'=>'43','statusURL'=>'https://fixture.invalid/callback'],['label'=>'42','uniqID'=>CcapiV3Client::requestId('https://fixture.invalid/callback','42'),'statusURL'=>'https://u:p@fixture.invalid/callback'],['label'=>'42','uniqID'=>CcapiV3Client::requestId('https://fixture.invalid/callback','42'),'statusURL'=>'https://fixture.invalid/callback','reusable'=>true]] as $args)
    ccaThrows(fn()=>CcapiV3Client::options('bitcoin','give',$config,$args),'ccapi_request_invalid');
ccaThrows(fn()=>CcapiV3Client::options('https://evil.invalid','give',$config,[]),'ccapi_method_unsupported');
$ccapi_reply=json_encode(['result'=>['address'=>$payload['to'],'publicKey'=>'dummy-public','privateKey'=>'dummy-private']],JSON_THROW_ON_ERROR);
$gateway=$manager->gateway('CCAB');
$form=$gateway->processDeposit(['sci'=>$config,'tag'=>42,'sum'=>'1.23','callback_scale'=>6,'url_callback'=>'https://fixture.invalid/callback','url_ok'=>'http://fixture.invalid/operation?id=42&check']);
ccaAssert($form['formkind']==='native_address' && $form['payment_address']===$payload['to'] && $form['payment_amount']==='1.230000','Usable native checkout form');
ccaAssert(!str_contains(json_encode($form),'dummy-private') && !str_contains(json_encode($form),'dummy-public') && !str_contains(json_encode($form),$fixture['secret']),'Give retained key material');
ccaAssert($ccapi_calls===1,'Give transparently retries');
ccaAssert($gateway->processWithdrawal([])===['result'=>'ccapi_payout_reconciliation_required'] && $ccapi_calls===1,'Native payout made I/O');
$public=$manager->processDeposit('CCAB',['sci'=>$config,'tag'=>42,'sum'=>'1.23','callback_scale'=>6,'url_callback'=>'http://fixture.invalid/status','url_ok'=>'http://fixture.invalid/operation?id=42&check']);
ccaAssert(($public['url'] ?? '')==='/operation?id=42' && ($public['formkind'] ?? '')==='native_address','Own HTTP return URL passes unchanged shared payment action policy');
$ccapi_reply='{"result":"12.34000000"}';
ccaAssert($gateway->getBalance($config)===['result'=>'OK','sum'=>'12.34'],'Balance decimal string lost');
foreach (['{"result":12.34}','{"error":"dummy-private"}','{"result":{"address":"short"}}','{"result":{"address":"'.$payload['to'].'","privateKey":null}}','{"result":{"address":"'.$payload['to'].'","address":"'.$payload['to'].'"}}','{"result":{"address":"'.$payload['to'].'"},"unknown":1}',str_repeat('a',65537)] as $badReply) {
    $ccapi_reply=$badReply;
    ccaThrows(fn()=>CcapiV3Client::request('bitcoin','give',$config,['label'=>'42','uniqID'=>CcapiV3Client::requestId('https://fixture.invalid/callback','42'),'statusURL'=>'https://fixture.invalid/callback']),'ccapi_unavailable');
}
$ccapi_reply='{"result":{"address":"'.$payload['to'].'"}}';
foreach ([302,500] as $ccapi_status) ccaThrows(fn()=>CcapiV3Client::request('bitcoin','give',$config,['label'=>'42','uniqID'=>CcapiV3Client::requestId('https://fixture.invalid/callback','42'),'statusURL'=>'https://fixture.invalid/callback']),'ccapi_unavailable');
$ccapi_status=200; $ccapi_transport_failure=true;
ccaThrows(fn()=>CcapiV3Client::request('bitcoin','give',$config,['label'=>'42','uniqID'=>CcapiV3Client::requestId('https://fixture.invalid/callback','42'),'statusURL'=>'https://fixture.invalid/callback']),'ccapi_unavailable');
// Expanded native wire boundaries, independent address representation vectors.
$addresses=json_decode(file_get_contents(__DIR__.'/fixtures/ccapi_address_vectors.json'),true,16,JSON_THROW_ON_ERROR);
ccaAssert(CcapiV3Client::sameAddress('ton',$addresses['ton_raw'],$addresses['ton_bounceable']),'TON raw/friendly identity');
ccaAssert(CcapiV3Client::sameAddress('ton',$addresses['ton_nonbounceable'],$addresses['ton_bounceable']),'TON bounceability does not change receiver');
ccaAssert(CcapiV3Client::addressIdentity('ton',substr($addresses['ton_bounceable'],0,-1).'X')===null,'TON checksum invalid');
ccaAssert(CcapiV3Client::sameAddress('bitcoincash','bitcoincash:q'.str_repeat('q',41),'q'.str_repeat('q',41)),'BCH optional prefix');
foreach (['X'.str_repeat('a',46),'T'.str_repeat('a',46)] as $tagged) ccaAssert(CcapiV3Client::addressIdentity('ripple',$tagged)===null,'Tagged XRP rejected before invoice');
foreach (['0',0,false,[],['0']] as $tag) {
    $reply=json_encode(['result'=>['address'=>$payload['to'],'extraID'=>$tag]],JSON_THROW_ON_ERROR);
    ccaThrows(fn()=>CcapiV3Client::decode($reply,'give','bitcoin'),'ccapi_unavailable');
}
foreach (['0x'.str_repeat('a',64),str_repeat('A',88),str_repeat('b',64)] as $transaction) {
    ccaAssert(CcapiV3Client::transaction($transaction),'Bounded opaque transaction identifier');
    ccaAssert(strlen(HScript\Finance\CcapiV3Contract::batchAlias($transaction.'#0'))===39,'Full transaction identity has bounded legacy alias');
}
ccaAssert(HScript\Finance\CcapiV3Contract::batchAlias(str_repeat('A',88).'#0')!==HScript\Finance\CcapiV3Contract::batchAlias(str_repeat('a',88).'#0'),'Case-sensitive transaction IDs remain distinct');
ccaAssert(CcapiV3Client::networkFee('0.000000000000000001')==='0.000000000000000001','Network fee precision does not enter local money');
ccaAssert(CcapiV3Client::decode('{"result":"-10.000000"}','balance')===['balance'=>'-10'],'XRP available balance may subtract reserve');
$ccapi_transport_failure=false;$ccapi_reply='{"result":"0.00000000"}';$previousCalls=$ccapi_calls;
HScript\Payment\Provider\ProviderProbe::validate('CCAPI',['v3_key'=>$fixture['secret']],array_keys(HScript\Payment\Provider\ProviderSettings::choices('CCAPI')),[]);
ccaAssert($ccapi_calls===$previousCalls+1,'One shared-key authentication read respects provider rate budget');
foreach (['https://evil.invalid/?ref=1','javascript:alert(1)','https://new.cryptocurrencyapi.net.evil.invalid/','https://user:pass@new.cryptocurrencyapi.net/','https://new.cryptocurrencyapi.net:444/'] as $url) {
    putenv('CCAPI_PARTNER_URL='.$url);ccaAssert(HScript\Payment\Provider\ProviderReferral::ccapi()===null,'Unsafe partner URL refused');
}
putenv('CCAPI_PARTNER_URL=https://h-script.com/partners/ccapi');ccaAssert(HScript\Payment\Provider\ProviderReferral::ccapi()==='https://h-script.com/partners/ccapi','Deployment-owned stable referral link');putenv('CCAPI_PARTNER_URL');
ccaAssert(str_contains(HScript\Payment\Provider\ProviderReferral::link('PAW')??'','partner=92483577'),'Bundled partner link works without installation env');
// The ordinary currency form must offer only payout modes accepted by service.
$templates=new Twig\Environment(new Twig\Loader\ChainLoader([new Twig\Loader\ArrayLoader(['edit.twig'=>'{{ fields.cCASHOUTMode[3]|keys|join(",") }}']),new Twig\Loader\FilesystemLoader(dirname(__DIR__).'/tpl')]),['strict_variables'=>true]);
$templates->addFunction(new Twig\TwigFunction('merge_arrays',static fn($a,$b)=>array_replace($a,$b)));
$templates->addFunction(new Twig\TwigFunction('_t',[HScript\Template\View::class,'tplTranslate']));
foreach (HScript\Finance\VoletContract::CURRENCIES as $cid=>$asset) {
    $modes=trim($templates->render('balance/admin/curr.settings.twig',['fields'=>[],'values'=>['cCID'=>$cid],'provider_kind'=>'VOLET','currency_shared_provider'=>true]));
    ccaAssert($modes===($cid==='AC'?'0,1,2':'0,1'),'Volet UI payout capability matches supported currency');
}
foreach(['CCAE'=>'0,1,2','CCT'=>'0,1,2','CCTC'=>'0,1,2','CCTU'=>'0,1']as$cid=>$expected){
    $modes=trim($templates->render('balance/admin/curr.settings.twig',['fields'=>[],'values'=>['cCID'=>$cid],'provider_kind'=>'CCAPI','currency_shared_provider'=>true]));
    ccaAssert($modes===$expected,'CCAPI unambiguous payout modes '.$cid);
}
$outArgs=['to'=>'0x'.str_repeat('2',40),'amount'=>'0.12345678','label'=>'42','uniqID'=>'hscript-out:'.str_repeat('a',64),'statusURL'=>'https://fixture.invalid/status?its=CCAPI_OUT','from'=>'0x'.str_repeat('1',40)];
$ccapi_reply='{"result":"12345"}';$previousCalls=$ccapi_calls;
ccaAssert(CcapiV3Client::request('ethereum','send',$config,$outArgs)===['queue_id'=>'12345'] && $ccapi_calls===$previousCalls+1,'send returns queue id and makes one request');
parse_str($ccapi_options[CURLOPT_POSTFIELDS],$posted);
ccaAssert($posted===$outArgs && str_ends_with($ccapi_options[CURLOPT_URL],'/ethereum/.send'),'exact decimal and closed outgoing form body');
ccaAssert(!str_contains($ccapi_options[CURLOPT_URL],$config['v3_key']) && in_array('CCAPI-KEY: '.$config['v3_key'],$ccapi_options[CURLOPT_HTTPHEADER],true),'payout key stays in header');
foreach(['{"result":1.2}','{"result":true}','{"result":"0"}','{"result":{"id":"12345"}}','{"error":"secret"}','{"result":"12345","result":"12346"}']as$reply)ccaThrows(static fn()=>CcapiV3Client::decode($reply,'send'),'ccapi_unavailable');
foreach([['amount'=>'1e3'],['amount'=>'0'],['amount'=>0.1],['to'=>'https://evil.invalid'],['from'=>''],['uniqID'=>'42'],['token'=>[]],['tag'=>'123'],['statusURL'=>'https://user:pass@fixture.invalid/status']]as$change)
    ccaThrows(static fn()=>CcapiV3Client::options('ethereum','send',$config,array_replace($outArgs,$change)));
$out=$payload;$out['type']='out';$out['id']='12345';$out['from']=$payload['to'];
$out=$sign($out);$outConfig=$config+['_ccapi_payout_context'=>true];
$v=$send($out,'CCAB',$outConfig);ccaAssert($v['correct'] && $v['outbound'] && $v['ccapi_payout_id']==='12345','out only accepted with explicit frozen outgoing context');
ccaAssert($send($out)['outcome']==='unsupported','incoming path cannot route outgoing settlement');
foreach(['id'=>'12346','amount'=>'1.24','to'=>str_repeat('a',40)]as$field=>$value){$changed=$out;$changed[$field]=$value;ccaAssert(!$send($changed,'CCAB',$outConfig)['correct'],'out signature binds '.$field);}
$status=['id'=>'12345','from'=>$outArgs['from'],'to'=>$outArgs['to'],'token'=>'','amount'=>'0.12345678','fee'=>'0.000001','label'=>'42','state'=>'7','result'=>'','txid'=>str_repeat('b',64)];
ccaAssert(CcapiV3Client::decode(json_encode(['result'=>$status],JSON_THROW_ON_ERROR),'status')===$status,'exact status decimal strings');
$status['amount']=0.12345678;ccaThrows(static fn()=>CcapiV3Client::decode(json_encode(['result'=>$status],JSON_THROW_ON_ERROR),'status'),'ccapi_unavailable');
$ccapi_transport_failure=true;$previousCalls=$ccapi_calls;
ccaThrows(static fn()=>CcapiV3Client::request('ethereum','send',$config,$outArgs),'ccapi_unavailable');
ccaAssert($ccapi_calls===$previousCalls+1,'timed-out outgoing request never automatically retries');
echo "CryptoCurrencyAPI v3 component tests passed ($checks checks, no external transport).\n";
}
