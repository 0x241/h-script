<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/vendor/autoload.php';
use HScript\Payment\Routing\PaymentRoutes as R;
use HScript\Finance\CallbackValidationException;
$n=0;$check=static function(bool $v)use(&$n):void{if(!$v)throw new RuntimeException('Route check '.($n+1));++$n;};
foreach(R::GATEWAYS as $gateway=>$asset){$catalog=R::catalog($gateway);if($gateway==='CCT'){$check($catalog===[]);continue;}$check(count($catalog)>=1);foreach($catalog as $code=>$route){$check(R::assertSnapshot($gateway,$route)===$route);$check($route['asset']===$asset);$check(strlen(R::hash($route))===64);$c=['cCID'=>$gateway,'cCurrID'=>$asset,'cPaymentRoutes'=>json_encode([$code])];$check(R::select($c,$code)===$route);$check(count(R::enabled($c))===1);foreach([null,'[]','{}'] as $raw){$c['cPaymentRoutes']=$raw;try{R::select($c,$code);throw new LogicException('Disabled route accepted');}catch(CallbackValidationException){++$n;}}}}
foreach([['ccapi-usdt-tron','ccapi-usdt-tron'],['unknown'],[null],[['code'=>'ccapi-usdt-tron']]] as $bad){try{R::codes('CCTU',$bad);throw new LogicException('Malformed codes accepted');}catch(CallbackValidationException){++$n;}}
$r=R::known('CCTU','ccapi-usdt-tron');$p=['chain'=>'tron','currency'=>'TRX','token'=>'USDT','tokenContract'=>$r['contract']];$check(R::ccapi('CCTU',$p)===$r);
foreach(['chain'=>'ethereum','currency'=>'USDT','token'=>'USDC','tokenContract'=>'T'.str_repeat('1',33)] as $key=>$bad){try{R::ccapi('CCTU',array_replace($p,[$key=>$bad]));throw new LogicException('Cross-route accepted');}catch(CallbackValidationException){++$n;}}
$check(R::select(['cCID'=>'CCAT'],null)===null);
echo "Payment route component checks passed ($n assertions).\n";
