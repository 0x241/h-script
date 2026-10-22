<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Native curl is removed before defining a closed transport. No fallback is possible.
$disabled='curl_init,curl_setopt,curl_exec,curl_getinfo,curl_close,curl_error';
if (!in_array('curl_exec',explode(',',(string)ini_get('disable_functions')),true)) {
    $runner='try { require '.var_export(__FILE__,true).'; } catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n");exit(1); }';
    $p=proc_open([PHP_BINARY,'-d','disable_functions='.$disabled,'-r',$runner],[0=>['file','/dev/null','r'],1=>STDOUT,2=>STDERR],$pipes);
    exit(is_resource($p) ? proc_close($p) : 1);
}
error_reporting(E_ALL & ~E_DEPRECATED);
require dirname(__DIR__).'/vendor/autoload.php';
use HScript\Database\Connection;
use HScript\Payment\{GatewayAmount,PaymentManager};
$captures=[];$ymFailure=false;$checks=0;
function wireAssert(bool $ok,string $message): void { global $checks;if (!$ok) throw new RuntimeException($message);$checks++; }
if (!function_exists('curl_init')) {
function curl_init(string $url): object {
    if (!in_array($url,['https://www.coinpayments.net/api.php','https://yoomoney.ru/api/request-payment','https://yoomoney.ru/api/process-payment'],true)) throw new RuntimeException('Unexpected SDK URL');
    return (object)['url'=>$url,'opts'=>[]];
}
function curl_setopt(object $h,int $option,mixed $value): bool { $h->opts[$option]=$value;return true; }
function curl_exec(object $h): string {
    global $captures,$ymFailure;
    $captures[]=['url'=>$h->url,'body'=>$h->opts[CURLOPT_POSTFIELDS],'headers'=>$h->opts[CURLOPT_HTTPHEADER]??[]];
    return match ($h->url) {
        'https://www.coinpayments.net/api.php'=>'{"error":"ok","result":{"id":"synthetic"}}',
        'https://yoomoney.ru/api/request-payment'=>$ymFailure ? '{"status":"refused"}' : '{"status":"success","request_id":"synthetic"}',
        'https://yoomoney.ru/api/process-payment'=>'{"status":"success","payment_id":"synthetic"}',
    };
}
function curl_getinfo(object $h,int $option): int { return 200; }
function curl_close(object $h): void {}
function curl_error(object $h): string { return 'offline'; }
}
final class DecimalOfflineHttps {
    public $context;
    private string $body='{"error":false,"data":{"transaction":"synthetic"}}';
    private int $position=0;
    public function stream_open(string $url,string $mode,int $options,?string &$opened): bool {
        global $captures;
        if ($url!=='https://paykassa.pro/api/0.4/index.php') throw new RuntimeException('Unexpected stream URL');
        $http=stream_context_get_options($this->context)['http'];
        $captures[]=['url'=>$url,'body'=>$http['content'],'headers'=>[$http['header']]];return true;
    }
    public function stream_read(int $count): string { $v=substr($this->body,$this->position,$count);$this->position+=strlen($v);return $v; }
    public function stream_eof(): bool { return $this->position>=strlen($this->body); }
    public function stream_stat(): array { return []; }
}
stream_wrapper_unregister('https');stream_wrapper_register('https',DecimalOfflineHttps::class);
$_SERVER['SERVER_NAME']='fixture.invalid';
$manager=new PaymentManager(new Connection());
foreach (['CPE'=>8,'PKB'=>8,'YM'=>2] as $id=>$scale) {
    foreach (['0.01','123456789012.12','999999999999.99'] as $amount) {
        $before=count($captures);
        $result=$manager->processWithdrawal($id,['sum'=>$amount,'from'=>['apipass'=>'synthetic-private','publicpass'=>'synthetic-public','id'=>'synthetic-id','shop_id'=>'synthetic-shop'],'to'=>['acc'=>'synthetic-address','tag'=>'synthetic-tag'],'memo'=>'synthetic']);
        wireAssert(($result['result']??null)==='OK','Actual gateway SDK outcome');
        $capture=$captures[$before];parse_str($capture['body'],$form);
        wireAssert($form[$id==='YM'?'amount_due':'amount']===GatewayAmount::exact($amount,$id,$scale),'Actual SDK wire fixed decimal');
        if ($id==='CPE') wireAssert($capture['headers']===['HMAC: '.hash_hmac('sha512',$capture['body'],'synthetic-private')],'HMAC over actual serialized bytes');
        if ($id==='YM') wireAssert(count($captures)===$before+2,'Two-stage successful YooMoney request');
    }
    foreach (['0','-1','1e-6',0.5,'1000000000000','0.000000001',null] as $bad) {
        $before=count($captures);
        try { $r=$manager->processWithdrawal($id,['sum'=>$bad]); }
        catch (InvalidArgumentException $e) { $r=['result'=>$e->getMessage()]; }
        wireAssert(($r['result']??null)==='payment_amount_invalid' && count($captures)===$before,'Invalid money refuses before SDK/credential I/O');
    }
}
$ymFailure=true;$before=count($captures);
wireAssert($manager->processWithdrawal('YM',['sum'=>'1.23','from'=>['apipass'=>'synthetic']])===['result'=>'payment_request_failed'] && count($captures)===$before+1,'Refused YooMoney request cannot call process-payment');

require dirname(__DIR__).'/lib/advcash/MerchantWebService.php';
final class DecimalNativeSoap extends MerchantWebService {
    public static int $constructed=0;
    public function __construct() { self::$constructed++;parent::__construct(__DIR__.'/fixtures/volet_contract.wsdl',['cache_wsdl'=>WSDL_CACHE_NONE,'exceptions'=>true]); }
    public function __doRequest(string $xml,string $location,string $action,int $version,bool $oneWay=false): ?string {
        global $captures;
        if ($location!=='https://account.volet.com/wsm/apiWebService' || $oneWay) throw new RuntimeException('Unexpected SOAP transport');
        $doc=new DOMDocument();if (!$doc->loadXML($xml,LIBXML_NONET)) throw new RuntimeException('Invalid SOAP');
        $x=new DOMXPath($doc);$method=$x->query('/*[local-name()="Envelope"]/*[local-name()="Body"]/*')->item(0)->localName;
        if (!in_array($method,['validationSendMoney','sendMoney','getBalances'],true)) throw new RuntimeException('Unexpected SOAP operation');
        $amount=[];foreach ($x->query('//*[local-name()="amount"]') as $node) $amount[]=$node->textContent;
        $captures[]=['method'=>$method,'amount'=>$amount];
        $body=match ($method) {'validationSendMoney'=>'','sendMoney'=>'<return>synthetic</return>','getBalances'=>'<return><amount>123456789012.123456</amount><id>synthetic-wallet</id></return>'};
        return '<?xml version="1.0"?><s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><v:'.$method.'Response xmlns:v="http://api.volet.com/">'.$body.'</v:'.$method.'Response></s:Body></s:Envelope>';
    }
}
$gateway=new class('AC',['Volet','USD',true,'USD','USD'],[],new Connection()) extends HScript\Payment\Gateways\AdvCash {
    protected function merchantService(): MerchantWebService { return new DecimalNativeSoap(); }
};
$manager->register('AC',$gateway);
foreach (['0.01','123456789012.12','999999999999.99'] as $amount) {
    $before=count($captures);$r=$manager->processWithdrawal('AC',['sum'=>$amount,'to'=>['acc'=>'synthetic@fixture.invalid']]);
    wireAssert($r===['result'=>'OK','batch'=>'synthetic'],'Actual Volet native SOAP gateway');
    wireAssert(count($captures)===$before+2 && $captures[$before]['amount']===[$amount] && $captures[$before+1]['amount']===[$amount],'Validation and send native SOAP preserve exact xs:decimal');
}
foreach (['0','1.001',0.5,'1e0',null] as $bad) {
    $before=DecimalNativeSoap::$constructed;$r=$manager->processWithdrawal('AC',['sum'=>$bad]);
    wireAssert(($r['result']??null)==='payment_amount_invalid' && DecimalNativeSoap::$constructed===$before,'Invalid Volet money precedes WSDL/SDK factory');
}
$balance=$manager->getBalance('AC',['acc'=>'synthetic-wallet']);
wireAssert($balance['result']==='OK' && $balance['sum']==='123456789012.123456','Single native SOAP decimal response is exact string');
echo json_encode(['status'=>'passed','checks'=>$checks,'native_sdk_captures'=>count($captures),'soap'=>extension_loaded('soap')],JSON_THROW_ON_ERROR)."\n";
