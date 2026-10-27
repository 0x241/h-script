<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/vendor/autoload.php';

use HScript\Database\Connection;
use HScript\Payment\PaymentManager;
use HScript\Payment\CallbackAudit;
use HScript\Payment\FormCallbackPayload;
use HScript\Observability\StructuredLogger;

$checks=0;
function webhookAssert(bool $ok, string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
$manager=new PaymentManager(new Connection());
foreach ($manager->definitions() as $id=>$definition) {
    $result=$manager->handleCallback($id, [], []);
    webhookAssert($result['outcome'] === (in_array($id,['BW','SB','CPN','PKAU','PKAR','ACR','EA','EAT','EAT1','BSC','BSCT','XRPA','TRA','TRAT'],true) ? 'unsupported' : 'authentication_failed'), $id.' accepts missing config');
    foreach (['','   '] as $secret) {
        $result=$manager->handleCallback($id, [], ['key'=>$secret,'apipass'=>$secret]);
        webhookAssert(!$result['correct'], $id.' accepts empty secret');
    }
    if (!in_array($id,['ST','PAW'],true) && !in_array($id,['BW','SB','CPN','PKAU','PKAR','ACR','EA','EAT','EAT1','BSC','BSCT','XRPA','TRA','TRAT'],true))
        webhookAssert($manager->handleCallback($id, ['amount'=>['nested']], ['key'=>'fixture','apipass'=>'fixture','protocol'=>'3','v3_key'=>'fixture'])['outcome']==='invalid_payload', $id.' accepts nested fields');
}
$fixture=json_decode(file_get_contents(__DIR__.'/fixtures/gateway_callbacks.json'),true,32,JSON_THROW_ON_ERROR);
$payload=$fixture['payload']; $secret=$fixture['secret'];
$unsigned=$payload; unset($unsigned['sign']); ksort($unsigned,SORT_STRING);
webhookAssert(http_build_query($unsigned,'','&',PHP_QUERY_RFC3986)===$fixture['canonical'],'RFC3986 canonical bytes differ from independent fixture');
$raw=http_build_query(array_reverse($payload,true),'','&',PHP_QUERY_RFC3986);
$send=static fn(string $body, string $id='YM', string $key='phase3-contract-secret')=>$manager->handleCallback($id,['_raw_body'=>$body],['key'=>$key]);
$result=$send($raw);
webhookAssert($result['outcome']==='verified' && $result['authenticated'],'current YooMoney HMAC rejected');
webhookAssert($result['sum']==='100' && $result['curr']==='RUB','signed gross amount/currency normalization');
webhookAssert($result['tag']==='42' && $result['batch']==='fixture-yoo-operation','signed transaction identity');
webhookAssert(array_intersect_key($result,array_flip(['hash','cc','sign']))===[],'verification result exposes signatures');
webhookAssert($send($raw,'YM','wrong-secret')['outcome']==='authentication_failed','wrong key accepted');
foreach (['withdraw_amount'=>'101.00','amount'=>'99.00','currency'=>'840','label'=>'43','sender'=>'different-wallet','fixture_note'=>'trimmed'] as $key=>$value) {
    $changed=$payload; $changed[$key]=$value;
    webhookAssert($send(http_build_query($changed,'','&',PHP_QUERY_RFC3986))['outcome']==='authentication_failed','unsigned mutation accepted: '.$key);
}
foreach ([$raw.'&label=43',$raw.'&amount%5B%5D=100',$raw.'&broken=%GG'] as $bad)
    webhookAssert($send($bad)['outcome']==='invalid_payload','ambiguous form accepted');
$old=$payload; unset($old['sign']); $old['sha1_hash']=str_repeat('a',40);
webhookAssert($send(http_build_query($old))['outcome']==='authentication_failed','obsolete SHA1 fallback accepted');
// Published protocol example, with its public example secret; no live credentials.
// https://yoomoney.ru/docs/payment-buttons/using-api/notifications
$publicVector=[
    'notification_type'=>'p2p-incoming','operation_id'=>'441361714955017004',
    'amount'=>'98.00','withdraw_amount'=>'100.00','currency'=>'643',
    'datetime'=>'2013-12-26T08:28:34Z','sender'=>'41000000000','codepro'=>'false',
    'label'=>'ML23045','unaccepted'=>'false','sha1_hash'=>'ac13833bd6ba9eff1fa9e4bed76f3d6ebb57f6c0',
    'sign'=>'a452af731650e2c5b39abcdc7c28dd27db7b3b654c2230ad2c386e64afb98605',
];
$publicResult=$send(http_build_query($publicVector,'','&',PHP_QUERY_RFC3986),'YM','secret123');
webhookAssert($publicResult['outcome']==='verified' && $publicResult['sum']==='100' && $publicResult['tag']==='ML23045','published YooMoney HMAC vector rejected');
$sign=static function (array $data) use ($secret): string { unset($data['sign']); ksort($data,SORT_STRING); $data['sign']=hash_hmac('sha256',http_build_query($data,'','&',PHP_QUERY_RFC3986),$secret); return http_build_query($data,'','&',PHP_QUERY_RFC3986); };
foreach (['currency'=>'840','datetime'=>'2026-02-30T10:20:30Z','amount'=>'1e2','withdraw_amount'=>'100.001','notification_type'=>'unknown'] as $key=>$value) {
    $changed=$payload; $changed[$key]=$value;
    webhookAssert($send($sign($changed))['outcome']==='invalid_payload','signed invalid field accepted: '.$key);
}
foreach (['codepro','test_notification'] as $flag) {
    $changed=$payload; $changed[$flag]='true';
    webhookAssert($send($sign($changed))['outcome']==='rejected','nonsettling payment accepted: '.$flag);
}
$changed=$payload; $changed['unaccepted']='true';
webhookAssert($send($sign($changed))['outcome']==='pending','held notification lost');
$changed=$payload; $changed['notification_type']='card-incoming'; $changed['sender']='';
webhookAssert($send($sign($changed),'YMC')['outcome']==='verified','card notification rejected');
unset($changed['withdraw_amount']);
webhookAssert($send($sign($changed),'YMC')['outcome']==='invalid_payload','missing signed gross amount accepted');

$coin=['ipn_version'=>'1.0','ipn_id'=>'synthetic-contract-ipn','ipn_mode'=>'hmac','ipn_type'=>'simple','merchant'=>'fixture-merchant','txn_id'=>'fixture-cp-tx','invoice'=>'47','amount1'=>'1.25','currency1'=>'BTC','status'=>'100'];
$coinSend=static function(array $data, ?array $overrides=null) use($manager):array {
    $body=http_build_query($data,'','&');
    return $manager->handleCallback('CP',($overrides ?? $data)+['_raw_body'=>$body,'_server'=>['HTTP_HMAC'=>hash_hmac('sha512',$body,'fixture-secret')]],['id'=>'fixture-merchant','key'=>'fixture-secret']);
};
$result=$coinSend($coin,['invoice'=>'999','amount1'=>'900']);
webhookAssert($result['tag']==='47' && $result['sum']==='1.25','CoinPayments uses fields outside signed body');
foreach (['0'=>'pending','1'=>'pending','2'=>'verified','100'=>'verified','-1'=>'rejected','abc'=>'invalid_payload'] as $status=>$outcome) {
    $changed=$coin; $changed['status']=(string)$status;
    webhookAssert($coinSend($changed)['outcome']===$outcome,'CoinPayments status '.$status);
}
foreach (['merchant'=>'other-merchant','currency1'=>'ETH','ipn_mode'=>'unsigned'] as $field=>$value) {
    $changed=$coin; $changed[$field]=$value;
    webhookAssert(!$coinSend($changed)['correct'],'CoinPayments field accepted: '.$field);
}
$body=http_build_query($coin).'&invoice=999';
webhookAssert($manager->handleCallback('CP',['_raw_body'=>$body,'_server'=>['HTTP_HMAC'=>hash_hmac('sha512',$body,'fixture-secret')]],['id'=>'fixture-merchant','key'=>'fixture-secret'])['outcome']==='invalid_payload','CoinPayments signed duplicate accepted');
foreach (['a=1&a=2','a[]=1','a=%GG',str_repeat('a',65537)] as $invalid) {
    try { FormCallbackPayload::decode($invalid); throw new LogicException('Invalid form accepted'); }
    catch (InvalidArgumentException) { $checks++; }
}
webhookAssert($manager->detectCallback(['private_hash'=>['nested']])==='','nested detector input accepted');

// Synthetic SHA256 WebMoney contract vectors; independent fixed values are
// generated with Python, not copied from live payment/account data.
$wmFixture=json_decode(file_get_contents(__DIR__.'/fixtures/webmoney_callbacks.json'),true,32,JSON_THROW_ON_ERROR);
$wm=$wmFixture['payload'];
$wmSend=static fn(array $data)=>$manager->handleCallback('WM',['_raw_body'=>http_build_query($data)],['key'=>$wmFixture['secret'],'timezone'=>'UTC']);
webhookAssert($wmSend($wm)['outcome']==='verified','WebMoney HASH2 fixed vector rejected');
webhookAssert($wmSend($wm)['accto']===$wm['LMI_PAYEE_PURSE'],'WebMoney loses signed receiver');
webhookAssert($wmSend($wm)['signed_at']===strtotime('2026-10-03T12:30:00Z'),'WebMoney strict date interpretation');
foreach (['LMI_PAYMENT_AMOUNT'=>'51.00','LMI_PAYMENT_NO'=>'4012','LMI_PAYEE_PURSE'=>'Z111111111111','LMI_SYS_TRANS_DATE'=>'20261004 12:30:00'] as $field=>$value) {
    $bad=$wm; $bad[$field]=$value;
    webhookAssert($wmSend($bad)['outcome']==='authentication_failed','WebMoney unsigned mutation accepted: '.$field);
}
$wmSign=static function(array $data,bool $legacy=false) use($wmFixture):array {
    unset($data['LMI_HASH'],$data['LMI_HASH2']);
    $before=[$data['LMI_PAYEE_PURSE'],$data['LMI_PAYMENT_AMOUNT']];
    $after=[$data['LMI_PAYMENT_NO'],$data['LMI_MODE'],$data['LMI_SYS_INVS_NO'],$data['LMI_SYS_TRANS_NO'],
        $data['LMI_SYS_TRANS_DATE'],$wmFixture['secret'],$data['LMI_PAYER_PURSE'],$data['LMI_PAYER_WM']];
    $hold=isset($data['LMI_HOLD'])?[$data['LMI_HOLD']]:[];
    $data[$legacy?'LMI_HASH':'LMI_HASH2']=strtoupper(hash('sha256',$legacy
        ? implode('',$before).($hold?';'.$hold[0].';':'').implode('',$after)
        : implode(';',array_merge($before,$hold,$after))));
    return $data;
};
$legacy=$wmFixture['legacy_payload'];
webhookAssert($wmSend($legacy)['outcome']==='unsupported' && !$wmSend($legacy)['correct'],'delimiter-free WebMoney HASH can settle');
$boundary=$legacy; $boundary['LMI_PAYMENT_NO']='4012'; $boundary['LMI_SYS_INVS_NO']='45'; $boundary['LMI_SYS_TRANS_NO']='678';
webhookAssert($wmSign($boundary,true)['LMI_HASH']===$legacy['LMI_HASH'],'legacy numeric boundary counterexample not reproduced');
webhookAssert($wmSend($boundary)['outcome']==='unsupported','ambiguous legacy order can settle');
$both=$wm; $both['LMI_HASH']=$wmSign($wm,true)['LMI_HASH'];
webhookAssert($wmSend($both)['outcome']==='verified','both matching WebMoney hashes rejected');
$both['LMI_HASH2']=str_repeat('0',64);
webhookAssert($wmSend($both)['outcome']==='authentication_failed','WebMoney falls back after invalid HASH2');
$both=$wm; $both['LMI_HASH']=str_repeat('0',64);
webhookAssert($wmSend($both)['outcome']==='authentication_failed','WebMoney ignores contradictory HASH');
$sign=$wm; unset($sign['LMI_HASH2']); $sign['LMI_HASH']=str_repeat('a',132);
webhookAssert($wmSend($sign)['outcome']==='unsupported','WebMoney SIGN is treated as SHA256');
$unsigned=$wm; unset($unsigned['LMI_HASH2']); $unsigned['LMI_SECRET_KEY']=$wmFixture['secret'];
webhookAssert($wmSend($unsigned)['outcome']==='authentication_failed','WebMoney accepts exposed secret instead of hash');
$test=$wm; $test['LMI_MODE']='1';
webhookAssert($wmSend($wmSign($test))['outcome']==='rejected','WebMoney signed test payment settles');
foreach (['0'=>'verified','1'=>'unsupported','365'=>'unsupported'] as $hold=>$outcome) {
    $held=$wm; $held['LMI_HOLD']=(string)$hold;
    webhookAssert($wmSend($wmSign($held))['outcome']===$outcome,'WebMoney HOLD '.$hold.' classification');
}
$invalidDate=$wm; $invalidDate['LMI_SYS_TRANS_DATE']='20260230 12:30:00';
webhookAssert($wmSend($wmSign($invalidDate))['outcome']==='invalid_payload','WebMoney normalizes an invalid calendar date');
$pre=['LMI_PREREQUEST'=>'1','LMI_PAYEE_PURSE'=>$wm['LMI_PAYEE_PURSE'],'LMI_PAYMENT_AMOUNT'=>'50.00','LMI_PAYMENT_NO'=>'4','LMI_MODE'=>'0','LMI_PAYMENT_DESC'=>$wm['LMI_PAYMENT_DESC']];
$preResult=$wmSend($pre);
webhookAssert($preResult['outcome']==='preflight' && !$preResult['correct'] && !$preResult['authenticated'] && !isset($preResult['response']),'WebMoney prerequest authenticates or replies YES before local validation');
$pre['LMI_PREREQUEST']='2';
webhookAssert($wmSend($pre)['outcome']==='invalid_payload','WebMoney accepts truthy prerequest other than 1');
$duplicate=http_build_query($wm).'&LMI_PAYMENT_NO=4012';
webhookAssert($manager->handleCallback('WM',['_raw_body'=>$duplicate],['key'=>$wmFixture['secret'],'timezone'=>'UTC'])['outcome']==='invalid_payload','WebMoney accepts duplicate signed fields');
webhookAssert($wmSend($wm)['description_hash']===hash('sha256',$wm['LMI_PAYMENT_DESC']), 'WebMoney once-decoded UTF-8/percent/plus description differs');
webhookAssert(!isset($wmSend($wm)['LMI_PAYMENT_DESC']), 'WebMoney exposes raw description in normalized result');
$missingDescription=$wm; unset($missingDescription['LMI_PAYMENT_DESC']);
webhookAssert($wmSend($missingDescription)['outcome']==='invalid_payload', 'WebMoney missing financial description accepted');
$longDescription=$wm; $longDescription['LMI_PAYMENT_DESC']=str_repeat('я',256);
webhookAssert($wmSend($longDescription)['outcome']==='invalid_payload', 'WebMoney description exceeds 255 characters');
$tooLargeOrder=$wm; $tooLargeOrder['LMI_PAYMENT_NO']='1000000000000000';
webhookAssert($wmSend($tooLargeOrder)['outcome']==='invalid_payload','WebMoney 16-digit order exceeds provider range');
$excludedOrder=$wm; $excludedOrder['LMI_PAYMENT_NO']='999999999999999';
webhookAssert($wmSend($excludedOrder)['outcome']==='invalid_payload','WebMoney exclusive maximum order accepted');
webhookAssert($wmSend($wmSign(array_replace($wm,['LMI_PAYMENT_NO'=>'999999999999998'])))['outcome']==='verified','WebMoney supported 15-digit upper order rejected');
webhookAssert($manager->processDeposit('WM',['sci'=>['key'=>'fixture-key','timezone'=>'UTC'],'sum'=>'5','memo'=>'Order','tag'=>'999999999999999'])===['error'=>'payment_order_invalid'],'WebMoney invalid checkout order accepted');
$unicodeForm=$manager->processDeposit('WM',['sci'=>['key'=>'fixture-key','timezone'=>'UTC'],'pay'=>['acc'=>'Z123456789012'],'sum'=>'5','tag'=>4,'memo'=>str_repeat('я',255)]);
webhookAssert(isset($unicodeForm['LMI_PAYMENT_DESC_BASE64']) && base64_decode($unicodeForm['LMI_PAYMENT_DESC_BASE64'],true)===str_repeat('я',255),'WebMoney counts UTF-8 description characters as bytes');
webhookAssert($manager->processDeposit('WM',['sci'=>['key'=>'fixture-key','timezone'=>'UTC'],'sum'=>'5','memo'=>str_repeat('я',256)])===['error'=>'payment_description_invalid'],'WebMoney oversized checkout description not rejected');

$directory=sys_get_temp_dir().'/hscript-callback-audit-'.bin2hex(random_bytes(8)); mkdir($directory,0700);
putenv('OBSERVABILITY_LOG_PATH='.$directory.'/audit.ndjson'); StructuredLogger::reset();
try {
    $fingerprint=hash('sha256',$raw);
    webhookAssert(CallbackAudit::record('YM',$fingerprint,'verified','signature_verified'),'callback audit not persisted');
    $record=json_decode(trim(file_get_contents($directory.'/audit.ndjson')),true,32,JSON_THROW_ON_ERROR);
    webhookAssert($record['resource_id']==='callback:'.$fingerprint,'callback fingerprint omitted');
    webhookAssert($record['context']['state']==='verified' && $record['context']['reason']==='signature_verified','callback outcome omitted');
    $text=file_get_contents($directory.'/audit.ndjson');
    foreach ([$secret,$payload['sender'],$payload['operation_id'],$payload['sign'],$payload['fixture_note']] as $sensitive)
        webhookAssert(!str_contains($text,$sensitive),'sensitive callback data logged');
    webhookAssert((fileperms($directory.'/audit.ndjson')&0777)===0600,'callback audit file not private');
} finally { foreach (glob($directory.'/*') as $file) unlink($file); rmdir($directory); putenv('OBSERVABILITY_LOG_PATH'); StructuredLogger::reset(); }
echo "Gateway webhook verification tests passed ($checks checks, ".count($manager->definitions())." IDs).\n";
