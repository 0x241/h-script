<?php

declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/vendor/autoload.php';
use HScript\Finance\{PawPayout,PawPayoutTarget,CallbackValidationException,IdempotencyRequest};
use HScript\Payment\PawInvoiceClient;
$count=0;
function payoutCheck(bool $condition,string $label):void{global $count;if(!$condition)throw new RuntimeException($label);++$count;}
function payoutReject(callable $call,string $label):void{try{$call();}catch(RuntimeException|InvalidArgumentException){payoutCheck(true,$label);return;}throw new LogicException($label);}
$tron='TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';$eth='0x'.str_repeat('a',40);
payoutCheck(PawPayoutTarget::valid('usdt_tron',$tron),'Tron base58check address');
payoutCheck(!PawPayoutTarget::valid('usdt_tron',substr($tron,0,-1).'u'),'Tron checksum rejection');
payoutCheck(PawPayoutTarget::valid('usdc_eth',$eth) && !PawPayoutTarget::valid('usdc_eth','0x'.str_repeat('0',40)),'Ethereum nonzero exact address');
foreach([['usdc_eth',$tron],['usdt_tron',$eth],['USDT',$tron],['usdt_tron',[]]] as [$asset,$address])payoutCheck(!PawPayoutTarget::valid($asset,$address),'asset/network identity');
payoutCheck(PawPayoutTarget::select('usdt_tron',['usdt_tron'=>$tron,'usdc_eth'=>$eth])===['payout_asset'=>'usdt_tron','acc'=>$tron],'one immutable destination');
payoutReject(static fn()=>PawPayoutTarget::select('usdc_eth',['usdt_tron'=>$tron]),'missing selected address');
$assets=[['asset'=>'usdt_tron','symbol'=>'USDT','chain'=>'tron','enabled'=>true,'type'=>'token']];
payoutCheck(PawPayoutTarget::catalog($assets,'usdt_tron')['chain']==='tron','authenticated explicit catalog identity');
foreach([['chain'=>'ethereum'],['enabled'=>false],['symbol'=>'USDC'],['type'=>'native']] as $change)payoutReject(static fn()=>PawPayoutTarget::catalog([array_replace($assets[0],$change)],'usdt_tron'),'catalog mismatch');
payoutReject(static fn()=>PawPayoutTarget::catalog([$assets[0],$assets[0]],'usdt_tron'),'ambiguous duplicate catalog');
$now=time();$original=['contract'=>['asset'=>'usdt_tron','ref'=>'synthetic-reference','chain'=>'tron','issued_at'=>$now], 'fields'=>['address'=>$tron]];
$status=['order_id'=>str_repeat('a',24),'ref'=>'synthetic-reference','currency'=>'usdt_tron','address'=>$tron,'fee_bearer'=>'merchant','status'=>'success',
 'amount'=>'12.345678','fiat_amount_usd'=>'12.35','debit_usd'=>'13.60','fee'=>'1','fee_usd'=>'1','energy_fee_usd'=>'0.25',
 'created_at'=>(string)$now,'processed_at'=>(string)$now,'txid'=>str_repeat('b',64),'tx_hashes'=>null,'voided'=>false,'voided_at'=>null,'voided_reason'=>null];
$receipt=PawPayout::validate($original,str_repeat('a',24),$status);
payoutCheck($receipt['crypto_amount']==='12.345678' && $receipt['provider_values']['debit_usd']==='13.60','provider quantity retained exactly, no fiat parity assumption');
foreach(['first_payout_review','pending','processing','failed','cancelled'] as $state)payoutCheck(PawPayout::validate($original,str_repeat('a',24),array_replace($status,['status'=>$state]))===null,'nonfinal never completes or refunds');
foreach(['order_id','ref','currency','address','fee_bearer','status','amount','fiat_amount_usd','debit_usd','fee','fee_usd','energy_fee_usd','created_at','processed_at','txid','voided'] as $field){$bad=$status;unset($bad[$field]);payoutReject(static fn()=>PawPayout::validate($original,str_repeat('a',24),$bad),'missing required '.$field);}
foreach([['voided'=>true],['voided_at'=>(string)$now],['voided_reason'=>'operator'],['tx_hashes'=>[str_repeat('b',64)]],['processed_at'=>(string)($now-1)],['created_at'=>(string)($now-301)],['amount'=>'1e2'],['amount'=>1.2],['fee'=>'-1'],['amount'=>'0'],['fee_bearer'=>'client'],['currency'=>'usdc_eth']] as $change)payoutReject(static fn()=>PawPayout::validate($original,str_repeat('a',24),array_replace($status,$change)),'contradictory success');
$calls=0;$payload=[];
$transport=static function($url,$headers,$body)use(&$calls,&$payload){++$calls;$payload=[$url,$headers,$body];return ['status'=>201,'content_type'=>'application/json','body'=>'{"ok":true,"result":{"order_id":"aaaaaaaaaaaaaaaaaaaaaaaa"}}'];};
$client=new PawInvoiceClient('synthetic_live_key',$transport);
$fields=['address'=>$tron,'amount'=>'10.12','fiat_currency'=>'EUR','asset'=>'usdt_tron','fee_bearer'=>'merchant','ref'=>'synthetic'];
$client->payout($fields,'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee');
payoutCheck($calls===1 && str_contains($payload[2],'"amount":"10.12"') && str_contains($payload[2],'"fiat_currency":"EUR"'),'original fiat exact string sent once');
payoutCheck(in_array('X-Uniq-Id: aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',$payload[1],true),'explicit UUIDv4 header');
$before=$calls;$sandbox=new PawInvoiceClient('sk_sandbox_synthetic',$transport);payoutReject(static fn()=>$sandbox->payout($fields,'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'),'sandbox fails before I/O');payoutCheck($calls===$before,'sandbox transport not called');
echo "PAW payout components: $count checks passed\n";
