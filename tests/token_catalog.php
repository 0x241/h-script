<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/vendor/autoload.php';
use HScript\Payment\Routing\{TokenCatalog as T,PaymentRoutes as R};
use HScript\Payment\{CcapiV3Client as C,JsonCallbackPayload};
$n=0;function catalogAssert(bool $ok):void{global $n;if(!$ok)throw new RuntimeException('Token catalog boundary failed');$n++;}
foreach(T::NETWORKS as $chain=>[$label,$wire]){
 $contract=$chain==='tron'?'T'.str_repeat('1',33):'0x'.str_repeat('A',40);
 $route=T::route($chain,'1INCH',$contract);catalogAssert(R::assertSnapshot('CCT',$route)===$route);catalogAssert(R::ccapi('CCT',['chain'=>$chain,'currency'=>$wire,'token'=>'1INCH','tokenContract'=>$contract])===$route);
 $currency=['cCID'=>'CCT','cCurrID'=>'1INCH','cTokenRoute'=>json_encode($route),'cPaymentRoutes'=>json_encode([$route['code']])];catalogAssert(R::select($currency,$route['code'])===$route);
 $currency['cPaymentRoutes']='[]';try{R::select($currency,$route['code']);throw new LogicException('disabled route');}catch(HScript\Finance\CallbackValidationException){catalogAssert(true);}
 $data=C::decode(json_encode(['result'=>['1INCH'=>$contract,'<script>'=>$contract,'BAD'=>'invalid']]),'tokens',$chain);catalogAssert(count($data['tokens'])===1);
 foreach(['asset'=>'OTHER','contract'=>$chain==='tron'?'T'.str_repeat('2',33):'0x'.str_repeat('b',40),'version'=>1] as $field=>$value){$bad=$route;$bad[$field]=$value;try{R::assertSnapshot('CCT',$bad);throw new LogicException('tampered route');}catch(HScript\Finance\CallbackValidationException){catalogAssert(true);}}
 $options=C::options($chain,'tokens',['protocol'=>'3','v3_key'=>'synthetic'],[]);catalogAssert(str_ends_with($options[CURLOPT_URL],'/'.$chain.'/.tokens') && !$options[CURLOPT_FOLLOWLOCATION]);
}
foreach(['',str_repeat('A',33),'a:b','a b','<script>'] as $bad)catalogAssert(!T::symbol($bad));
catalogAssert(T::symbol(str_repeat('A',32)) && T::symbol('aBAT'));
try{T::normalize('ethereum',['A'=>'0x'.str_repeat('a',40),'B'=>'0x'.str_repeat('A',40)]);throw new LogicException('aliases');}catch(HScript\Finance\CallbackValidationException){catalogAssert(true);}
try{C::decode('{"result":{"A":"0x'.str_repeat('a',40).'","A":"0x'.str_repeat('b',40).'"}}','tokens','ethereum');throw new LogicException('duplicate JSON');}catch(RuntimeException){catalogAssert(true);}
try{C::options('solana','tokens',['protocol'=>'3','v3_key'=>'synthetic'],[]);throw new LogicException('unsupported catalog');}catch(RuntimeException){catalogAssert(true);}
try{JsonCallbackPayload::object('{"a":"'.str_repeat('a',65536).'"}');throw new LogicException('callback bound');}catch(InvalidArgumentException){catalogAssert(true);}
catalogAssert(JsonCallbackPayload::object('{"a":"'.str_repeat('a',65536).'"}',1048576)->a===str_repeat('a',65536));
foreach(['ethereum','polygon','bsc','tron'] as $chain)catalogAssert(!HScript\Payment\Routing\CcapiWallets::validAddress($chain,'1'.str_repeat('A',33)));
foreach(['ethereum'=>'CCAET','tron'=>'CCATT','bsc'=>'CCANT','polygon'=>'CCAMT'] as $chain=>$code){
 $r=T::route($chain,'USD',$chain==='tron'?'T'.str_repeat('1',33):'0x'.str_repeat('a',40));$currency=['cCID'=>'CCT','cID'=>9,'cCurrID'=>'USD','cTokenRoute'=>json_encode($r)];$original=$currency;
 $p=HScript\Payment\Provider\CurrencyPresentation::describe($currency);catalogAssert($p['code']===$code && $p['contract']===$r['contract'] && $currency===$original && R::assertSnapshot('CCT',$r)===$r);
}
catalogAssert(HScript\Payment\Provider\CurrencyPresentation::describe(['cCID'=>'CCT','cTokenRoute'=>'{bad'])['code']==='CCT');
echo "Token catalog component checks passed ($n assertions).\n";
