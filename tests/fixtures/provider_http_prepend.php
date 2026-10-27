<?php

declare(strict_types=1);
namespace {
    $private=__FILE__==='/fixture/provider_http_prepend.php';
    $enabled=$private && getenv('H_SCRIPT_PROVIDER_HTTP_FIXTURE')==='1' && getenv('H_SCRIPT_FINANCIAL_TEST')==='1'
        && getenv('APP_ENV')==='development' && getenv('APP_DOMAIN')==='fixture.invalid' && getenv('DB_NAME')==='fixture';
    if (PHP_SAPI==='cli') {
        if (!$enabled || !in_array($argv[1]??'',['--setup','--public','--verify'],true)) exit(64);
        require '/var/www/html/vendor/autoload.php';$db=new \HScript\Database\Connection();
        if (!$db->open(getenv('DB_HOST'),'fixture',getenv('DB_USER'),getenv('DB_PASSWORD'))) exit(1);$pdo=$db->link;
        if ($argv[1]==='--setup') {
            $id=$db->insert('Users',['uLogin'=>'provider-admin','uPass'=>hashPassword('synthetic-financial-password'),'uMail'=>'provider-admin@fixture.invalid','uState'=>1,'uLevel'=>90,'uLang'=>'ru']);$db->insert('AddInfo',['auID'=>$id,'aNeedReConfig'=>0]);
            $ep=$db->insert('Currs',['cCID'=>'EPCU','cCurrID'=>'USD','cName'=>'Retired ePayCore','cDisabled'=>1,'cHidden'=>1]);
            $db->insert('Wallets',['wuID'=>2,'wcID'=>$ep,'wBal'=>'0','wLock'=>'0','wOut'=>'0']);
            // More than one page exercises provider filtering together with pagination.
            $db->update('Users',['uPIN'=>hashPassword('123456')],'',"uLogin='financial-user'");
            // Account settings retain adapter extension dictionaries across ordinary currency saves.
            foreach([3,4]as$currencyId){
                $currency=$db->fetch1Row($db->select('Currs','*','cID=?d',[$currencyId]));
                $context=$currencyId.$currency['cCID'].stampToTime($currency['cMTS']);
                $db->update('Currs',['cParamsAPI'=>encodeArrayToStr(['extension'=>['nested'=>'preserve']],$context,3)],'','cID=?d',[$currencyId]);
            }
            for ($index=1;$index<=23;$index++) $db->insert('Currs',['cCID'=>'BW','cCurrID'=>'USD','cName'=>'Synthetic wire '.$index,'cDisabled'=>1,'cHidden'=>1]);
            file_put_contents('/tmp/provider-ep-id',(string)$ep);echo "Shared provider HTTP fixture ready\n";exit;
        }
        if ($argv[1]==='--public') {
            $p=$pdo->query("SELECT ppID,ppPublicID FROM PaymentProviders WHERE ppSlot='STRIPE'")->fetch(\PDO::FETCH_ASSOC);
            $currencies=$pdo->query("SELECT cID,cCurrID FROM Currs WHERE cCID='ST' ORDER BY cID")->fetchAll(\PDO::FETCH_ASSOC);
            echo json_encode(['profile'=>$p,'currencies'=>$currencies,'retired_id'=>(int)file_get_contents('/tmp/provider-ep-id'),'catalog_tokens'=>$pdo->query("SELECT cID,cCID,cCurrID,cTokenRoute FROM Currs WHERE cCID='CCT' ORDER BY cID")->fetchAll(\PDO::FETCH_ASSOC),'tokens'=>$pdo->query("SELECT cID,cCID,cCurrID FROM Currs WHERE cCID IN ('CCTU','PKTU') ORDER BY cID")->fetchAll(\PDO::FETCH_ASSOC)],JSON_THROW_ON_ERROR);exit;
        }
        $check=static function(bool $ok,string $message): void {if (!$ok) throw new \RuntimeException($message);};
        $check((int)$pdo->query("SELECT COUNT(*) FROM Currs WHERE cCID='CPN' AND (cDisabled<>1 OR cHidden<>1 OR cCASHINMode<>0 OR cCASHOUTMode<>0 OR cEXMode<>0 OR cTRMode<>0)")->fetchColumn()===0,'NEW catalog cannot move money');
        foreach(glob('/tmp/pk-create-count-*')as$counter)$check(file_get_contents($counter)==='1','invoice creation is never repeated');
        $check((int)$pdo->query("SELECT COUNT(*) FROM PaymentProviders WHERE ppKind='STRIPE'")->fetchColumn()===1,'one Stripe connection');
        $check((int)$pdo->query("SELECT COUNT(*) FROM Currs WHERE cCID='ST'")->fetchColumn()===2,'two currencies one provider');
        $check((int)$pdo->query("SELECT COUNT(*) FROM Opers JOIN Currs ON cID=ocID WHERE cCID='ST' AND oState=3")->fetchColumn()===1,'one paid operation');
        $check((string)$pdo->query("SELECT SUM(wBal) FROM Wallets JOIN Currs ON cID=wcID WHERE cCID='ST'")->fetchColumn()==='1.010000','exact credited cents');
        $check((int)$pdo->query("SELECT COUNT(*) FROM IdempotencyKeys WHERE ikNamespace LIKE 'gateway:st:%:settled'")->fetchColumn()===1,'one durable settlement');
        $check((int)$pdo->query("SELECT COUNT(*) FROM Currs WHERE cCID='EPCU' AND cDisabled=1")->fetchColumn()===1,'retired currency preserved');
        foreach(['PKTU'] as $g){$check((string)$pdo->query("SELECT SUM(wBal) FROM Wallets JOIN Currs ON cID=wcID WHERE cCID='".$g."'")->fetchColumn()==='9.000000','one exact token credit '.$g);$check((int)$pdo->query("SELECT COUNT(*) FROM Opers JOIN Currs ON cID=ocID WHERE cCID='".$g."' AND oState=3")->fetchColumn()===1,'one paid token invoice '.$g);}
        $check((string)$pdo->query("SELECT SUM(wBal) FROM Wallets JOIN Currs ON cID=wcID WHERE cCID='CCT'")->fetchColumn()==='27.000000','three separate token credits once');
        $check(strlen((string)file_get_contents('/tmp/token-catalog-fetch-count'))===2,'page views and refused refresh never call catalog');
        echo "Shared providers native HTTP SQL checks passed\n";exit;
    }
    $remote=$_SERVER['REMOTE_ADDR']??'';
    if (!$enabled || PHP_SAPI!=='fpm-fcgi' || ($_SERVER['HTTP_HOST']??'')!=='fixture.invalid'
        || !filter_var($remote,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4) || !preg_match('/^(?:127\.|10\.|192\.168\.|172\.(?:1[6-9]|2[0-9]|3[01])\.)/D',$remote)
        || !in_array($_SERVER['SCRIPT_FILENAME']??'',['/var/www/html/rw.php','/var/www/html/index.php'],true)) {http_response_code(404);exit;}
}
namespace HScript\Payment\Provider {
    final class ProviderProbe {
        public static function validate(string $kind,array $key,array $currencies,array $wallets): array {
            if(($kind==='CCAPI' && ($key['v3_key']??null)==='synthetic-token-secret') || ($kind==='PAYKASSA' && ($key['merchant_secret']??null)==='synthetic-token-sci'))return [];
            if ($kind!=='STRIPE' || ($key['secret_key']??null)!=='sk_test_HTTPfixture123' || ($key['webhook_secret']??null)!=='whsec_HTTPfixture123') throw new ProviderException('provider_unavailable');
            file_put_contents('/tmp/provider-probe-count','1',FILE_APPEND|LOCK_EX);return ['account_id'=>'acct_HTTPfixture'];
        }
    }
}
namespace HScript\Payment {
    // Private internal-network fixture: real clients, only HTTP transport replaced.
    function curl_init(): object{return(object)['options'=>[],'status'=>200,'errno'=>0];}
    function curl_setopt_array(object $h,array $o):bool{$h->options=$o;return true;}
    function curl_exec(object $h):bool{
        $o=$h->options;parse_str($o[CURLOPT_POSTFIELDS]??'',$f);
        if(($o[CURLOPT_SSL_VERIFYPEER]??false)!==true||($o[CURLOPT_SSL_VERIFYHOST]??0)!==2||($o[CURLOPT_FOLLOWLOCATION]??true)!==false)throw new \RuntimeException('fixture_tls_rejected');
        if(str_starts_with($o[CURLOPT_URL]??'','https://a-api.coinpayments.net/api/v2/')){
            if(isset($o[CURLOPT_POSTFIELDS])||!empty($o[CURLOPT_POST]))throw new \RuntimeException('coinnew_mutation_forbidden');
            if(($o[CURLOPT_URL]??'')==='https://a-api.coinpayments.net/api/v2/currencies?types=crypto%2Ctoken&capabilities=payments'){
                $entry=static fn($id,$symbol,$type='crypto',$status='active')=>['id'=>$id,'type'=>$type,'symbol'=>$symbol,'name'=>$symbol.' network','decimalPlaces'=>18,'status'=>$status,'capabilities'=>['payments'],'requiredConfirmations'=>3,'isEnabledForPayment'=>true];
                $response=[$entry('4','ETH'),$entry('4:0x'.str_repeat('1',40),'USDT.ERC20','token'),$entry('9','TRX'),$entry('9:T'.str_repeat('1',33),'USDT.TRC20','token'),$entry('36','XRP','crypto','underMaintenance')];
            }elseif(($o[CURLOPT_URL]??'')==='https://a-api.coinpayments.net/api/v2/merchant/wallets?take=1'){
                $headers=[];foreach($o[CURLOPT_HTTPHEADER]as$line){$part=explode(': ',$line,2);if(count($part)===2)$headers[$part[0]]=$part[1];}
                $client='00000000-0000-0000-0000-000000000123';$timestamp=$headers['X-CoinPayments-Timestamp']??'';
                $signature=base64_encode(hash_hmac('sha256',"\xef\xbb\xbf".'GET'.$o[CURLOPT_URL].$client.$timestamp,'synthetic-new-secret',true));
                if(($headers['X-CoinPayments-Client']??null)!==$client||!hash_equals($signature,$headers['X-CoinPayments-Signature']??'')||!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d$/D',$timestamp))throw new \RuntimeException('coinnew_auth_invalid');
                $response=[];
            }else throw new \RuntimeException('coinnew_endpoint_forbidden');
            $body=json_encode($response,JSON_THROW_ON_ERROR);return ($o[CURLOPT_WRITEFUNCTION])($h,$body)===strlen($body);
        }
        if(preg_match('~^https://new\.cryptocurrencyapi\.net/api/(tron|ethereum)/\.tokens$~D',$o[CURLOPT_URL]??'',$m)){
            if(!in_array('CCAPI-KEY: synthetic-token-secret',$o[CURLOPT_HTTPHEADER]??[],true))throw new \RuntimeException('fixture_key_rejected');
            file_put_contents('/tmp/token-catalog-fetch-count','1',FILE_APPEND|LOCK_EX);
            $response=['result'=>$m[1]==='tron'?['USDT'=>'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t']:['USDT'=>'0xdac17f958d2ee523a2206206994597c13d831ec7','DAI'=>'0x'.str_repeat('d',40)]];
        }elseif(preg_match('~^https://new\.cryptocurrencyapi\.net/api/(tron|ethereum)/\.give$~D',$o[CURLOPT_URL]??'',$m)){
            if(!in_array('CCAPI-KEY: synthetic-token-secret',$o[CURLOPT_HTTPHEADER]??[],true)||!in_array($f['token']??null,['USDT','DAI'],true))throw new \RuntimeException('fixture_token_transport_rejected');
            file_put_contents('/tmp/token-give-'.$f['label'].'.json',json_encode($f,JSON_THROW_ON_ERROR),LOCK_EX);
            $response=['result'=>['address'=>$m[1]==='tron'?'T'.str_repeat('1',33):'0x'.str_repeat('1',40)]];
        }elseif(($o[CURLOPT_URL]??'')==='https://paykassa.app/sci/0.4/index.php'){
            if(($f['sci_key']??null)!=='synthetic-token-sci'||($f['sci_id']??null)!=='100')throw new \RuntimeException('fixture_sci_rejected');
            if(($f['func']??null)==='sci_create_order_get_data'){
                $id=$f['order_id'];file_put_contents('/tmp/pk-create-count-'.$id,'1',FILE_APPEND|LOCK_EX);$r=['shop_id'=>'100','order_id'=>$id,'amount'=>$f['amount'],'currency'=>$f['currency'],'system'=>(int)$f['system']===30?'TRON_TRC20':'Ethereum_ERC20','invoice'=>(string)(10000+(int)$id),'wallet'=>'T'.str_repeat('1',33),'tag'=>'','url'=>'https://crypto.paykassa.pro/sci/index.php?hash=synthetic'.$id];
                file_put_contents('/tmp/token-invoice-'.$id.'.json',json_encode($r,JSON_THROW_ON_ERROR),LOCK_EX);$response=['error'=>false,'data'=>$r];
            }elseif(($f['func']??null)==='sci_confirm_order' && preg_match('/^synthetic-token-([1-9][0-9]*)$/D',$f['private_hash']??'',$m)){
                $r=json_decode(file_get_contents('/tmp/token-invoice-'.$m[1].'.json'),true,16,JSON_THROW_ON_ERROR);$response=['error'=>false,'data'=>$r+['partial'=>'no','transaction'=>$r['invoice'],'address'=>$r['wallet'],'hash'=>'synthetic'.$m[1]]];
            }else throw new \RuntimeException('fixture_sci_method_rejected');
        }else throw new \RuntimeException('fixture_endpoint_rejected');
        $body=json_encode($response,JSON_THROW_ON_ERROR);return ($o[CURLOPT_WRITEFUNCTION])($h,$body)===strlen($body);
    }
    function curl_errno(object $h):int{return $h->errno;}
    function curl_getinfo(object $h,int $option):int{return $h->status;}
    function curl_close(object $h):void{}
    final class StripeClient {
        public static function request(string $key,string $method,array $fields=[],?string $idempotency=null): array {
            if ($key!=='sk_test_HTTPfixture123' || $method!=='checkout/sessions' || !is_string($idempotency)) throw new \RuntimeException('synthetic_transport_rejected');
            file_put_contents('/tmp/provider-invoice-count','1',FILE_APPEND|LOCK_EX);
            return ['id'=>'cs_test_HTTP'.$fields['client_reference_id'],'object'=>'checkout.session','mode'=>'payment','livemode'=>false,
                'client_reference_id'=>$fields['client_reference_id'],'metadata'=>$fields['metadata'],
                'currency'=>$fields['line_items'][0]['price_data']['currency'],'amount_total'=>$fields['line_items'][0]['price_data']['unit_amount'],
                'status'=>'open','payment_status'=>'unpaid','url'=>'https://checkout.stripe.com/c/pay/cs_test_HTTP'.$fields['client_reference_id']];
        }
    }
}
