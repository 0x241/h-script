<?php
declare(strict_types=1);
namespace {
    $private=__FILE__==='/fixture/ccapi_payout_http_prepend.php';
    $enabled=getenv('H_SCRIPT_CCAPI_HTTP_FIXTURE')==='1' && getenv('H_SCRIPT_FINANCIAL_TEST')==='1' && getenv('APP_ENV')==='development' && getenv('APP_DOMAIN')==='fixture.invalid' && getenv('DB_NAME')==='fixture';
    if(PHP_SAPI==='cli') {
        if(!$private || !$enabled || !in_array($argv[1]??'',['--setup','--dispatch','--verify','--mutate-source'],true))exit(64);
        require '/var/www/html/tests/fixtures/financial_runtime.php';
        $_GS=['client_ip'=>'127.0.0.1','https'=>false,'domain'=>'fixture.invalid','root_dir'=>'','uri'=>'/'];$_cfg=[];
        foreach($pdo->query('SELECT Module,Prop,Val FROM Cfg')->fetchAll() as $r)$_cfg[($r['Module']!==''?$r['Module'].'_':'').$r['Prop']]=$r['Val'];
        if($argv[1]==='--setup') {
            $pid=(new \HScript\Payment\Provider\ProviderService($db,static fn()=>[]))->save(99,'CCAPI',['form_complete'=>'1','profile_id'=>'0','revision'=>'0','credentials'=>['v3_key'=>'synthetic-ccapi-out-secret'],'currencies'=>['ETH'],'enabled'=>'1']);
            $eth=(new \HScript\Payment\Provider\ProviderRepository($db))->connections($pid)[0]['cID'];
            $route=\HScript\Payment\Routing\TokenCatalog::route('ethereum','DAI','0x'.str_repeat('3',40));
            $token=$db->insert('Currs',['cCID'=>'CCT','cCurrID'=>'DAI','cName'=>'Synthetic DAI Ethereum','cCurr'=>'DAI','cNumDec'=>6,'cProviderID'=>$pid,'cProviderKeyVersion'=>1,'cProviderSelected'=>1,'cAssetKey'=>\HScript\Payment\Routing\TokenCatalog::assetKey($route),'cTokenRoute'=>json_encode($route,JSON_THROW_ON_ERROR),'cPaymentRoutes'=>json_encode([$route['code']]),'cDisabled'=>0,'cCASHOUTMode'=>2,'cCASHOUTComisMin'=>'1','cCASHOUTComisMax'=>'1']);
            $pdo->prepare('INSERT INTO ProviderNetworkWallets(pnProviderID,pnNetwork,pnForwardTo,pnPayoutFrom) VALUES(?,?,?,?)')->execute([$pid,'ethereum','','0x'.str_repeat('1',40)]);
            $pdo->exec("UPDATE Currs SET cDisabled=0,cCASHOUTMode=2,cNumDec=6,cCASHOUTComisMin=1,cCASHOUTComisMax=1 WHERE cID=$eth");
            foreach([$eth,$token]as$cid){
                if(opChangeBalance(2,$cid,'100',0,0)!==true)throw new \RuntimeException('Fixture balance');
                $t=time();$db->update('Wallets',['wMTS'=>timeToStamp($t),'wParams'=>encodeArrayToStr(['acc'=>'0x'.str_repeat('2',40)],$cid.'2'.$t)],'','wuID=2 AND wcID=?d',[$cid]);
            }
            $profile=(new \HScript\Payment\Provider\ProviderRepository($db))->find($pid);
            file_put_contents('/tmp/ccapi-payout-ids.json',json_encode(['native'=>$eth,'token'=>$token,'public'=>$profile['ppPublicID']],JSON_THROW_ON_ERROR));
            echo "Outgoing HTTP fixture ready.\n";exit;
        }
        if($argv[1]==='--mutate-source'){$pdo->exec("UPDATE ProviderNetworkWallets SET pnPayoutFrom='0x".str_repeat('9',40)."' WHERE pnNetwork='ethereum'");exit;}
        $_currs=$db->fetchIDRows($db->select('Currs'),false,'cID');$_onload=[];
        if($argv[1]==='--dispatch'){
            foreach($pdo->query("SELECT oID FROM Opers JOIN Currs ON cID=ocID WHERE cCID IN ('CCAE','CCT') AND oOper='CASHOUT' ORDER BY oID")->fetchAll()as$r)
                \HScript\Finance\WithdrawalDispatcher::dispatch($db,(int)$r['oID']);
            if(is_file('/tmp/ccapi-payout-wire.jsonl')){chmod('/tmp/ccapi-payout-wire.jsonl',0600);chown('/tmp/ccapi-payout-wire.jsonl','www-data');}
            foreach(glob('/tmp/ccapi-out-*.json')as$file){chmod($file,0600);chown($file,'www-data');}
            echo "Committed outgoing intents dispatched/reconciled without resending.\n";exit;
        }
        $done=($argv[2]??'')==='completed';$rows=$pdo->query("SELECT Opers.* FROM Opers JOIN Currs ON cID=ocID WHERE cCID IN ('CCAE','CCT') AND oOper='CASHOUT' ORDER BY oID")->fetchAll();
        if(count($rows)!==2)throw new \RuntimeException('Outgoing operation count');
        foreach($rows as$o){
            if((int)$o['oState']!==($done?3:2)||$o['oSum']!=='10.000000'||$o['oComis']!=='1.000000')throw new \RuntimeException('Outgoing immutable money/state');
            $s=$pdo->prepare('SELECT wBal,wLock,wOut FROM Wallets WHERE wuID=2 AND wcID=?');$s->execute([$o['ocID']]);
            if($s->fetch()!==['wBal'=>'90.000000','wLock'=>'0.000000','wOut'=>$done?'0.000000':'10.000000'])throw new \RuntimeException('Outgoing balance invariant');
            if($done)\HScript\Finance\CcapiPayout::assertCompletion($db,(int)$o['oID']);
        }
        $wire=file('/tmp/ccapi-payout-wire.jsonl',FILE_IGNORE_NEW_LINES);$sends=array_filter($wire,static fn($s)=>json_decode($s,true)['method']==='send');
        if(count($sends)!==2)throw new \RuntimeException('Outgoing duplicate send');
        echo "Outgoing HTTP SQL verification passed: ".($done?'completed':'pending')."; exactly two sends.\n";exit;
    }
    $remote=$_SERVER['REMOTE_ADDR']??'';
    if(!$private || !$enabled || PHP_SAPI!=='fpm-fcgi' || ($_SERVER['HTTP_HOST']??'')!=='fixture.invalid' || !preg_match('/^(?:127\.|10\.|192\.168\.|172\.(?:1[6-9]|2[0-9]|3[01])\.)/',$remote) || !in_array($_SERVER['SCRIPT_FILENAME']??'',['/var/www/html/rw.php','/var/www/html/index.php'],true)){http_response_code(404);exit;}
    require '/fixture/gateway_http_audit_prepend.php';
}
namespace HScript\Payment {
    function curl_init():object{return(object)['options'=>[],'errno'=>0];}
    function curl_setopt_array(object $h,array $o):bool{$h->options=$o;return true;}
    function curl_exec(object $h):bool{
        $o=$h->options;
        if(!preg_match('~^https://new\.cryptocurrencyapi\.net/api/ethereum/\.(send|status|tokens)$~D',$o[CURLOPT_URL]??'',$m) || ($o[CURLOPT_POST]??false)!==true || ($o[CURLOPT_SSL_VERIFYPEER]??false)!==true || ($o[CURLOPT_SSL_VERIFYHOST]??0)!==2 || ($o[CURLOPT_FOLLOWLOCATION]??true)!==false || !in_array('CCAPI-KEY: synthetic-ccapi-out-secret',$o[CURLOPT_HTTPHEADER]??[],true))throw new \RuntimeException('Fixture wire rejected');
        parse_str($o[CURLOPT_POSTFIELDS]??'',$a);$method=$m[1];
        file_put_contents('/tmp/ccapi-payout-wire.jsonl',json_encode(['method'=>$method],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
        if($method==='tokens')$result=['DAI'=>'0x'.str_repeat('3',40)];
        elseif($method==='send'){
            if(($a['amount']??'')!=='9.000000' || ($a['from']??'')!=='0x'.str_repeat('1',40) || ($a['to']??'')!=='0x'.str_repeat('2',40) || !str_starts_with($a['statusURL']??'','http://fixture.invalid/balance/status?its=CCAPI_OUT&'))throw new \RuntimeException('Fixture intent rejected');
            file_put_contents('/tmp/ccapi-out-'.hash('sha256',$a['uniqID']).'.json',json_encode($a,JSON_THROW_ON_ERROR));$result=(string)(100000+(int)$a['label']);
        }else{
            $original=json_decode(file_get_contents('/tmp/ccapi-out-'.hash('sha256',$a['uniqID']).'.json'),true,32,JSON_THROW_ON_ERROR);
            $result=['id'=>(string)(100000+(int)$original['label']),'from'=>$original['from'],'to'=>$original['to'],'token'=>$original['token']??'','amount'=>$original['amount'],'fee'=>'0.000001','label'=>$original['label'],'state'=>'7','result'=>'','txid'=>str_repeat('a',64)];
        }
        $raw=json_encode(['result'=>$result],JSON_THROW_ON_ERROR);return($o[CURLOPT_WRITEFUNCTION])($h,$raw)===strlen($raw);
    }
    function curl_errno(object $h):int{return 0;}
    function curl_getinfo(object $h,int $option):int{return 200;}
    function curl_close(object $h):void{}
}
