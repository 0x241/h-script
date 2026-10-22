<?php

declare(strict_types=1);

namespace {
    // Fixture activation requires a private RO mount and a disposable environment.
    $privateFixture=__FILE__==='/fixture/volet_http_prepend.php';
    $explicitFixture=getenv('H_SCRIPT_VOLET_HTTP_FIXTURE')==='1' && getenv('H_SCRIPT_FINANCIAL_TEST')==='1'
        && getenv('APP_ENV')==='development' && getenv('APP_DOMAIN')==='fixture.invalid' && getenv('DB_NAME')==='fixture';
    if (PHP_SAPI==='cli') {
        if (!$privateFixture || !$explicitFixture || !in_array($argv[1] ?? '',['--setup','--verify'],true)) exit(64);
        require '/var/www/html/vendor/autoload.php';
        $db=new \HScript\Database\Connection();
        if (!$db->open(getenv('DB_HOST'),'fixture',getenv('DB_USER'),getenv('DB_PASSWORD'))) exit(1);
        $pdo=$db->link; $_cfg=[];
        foreach ($pdo->query('SELECT Module,Prop,Val FROM Cfg')->fetchAll() as $row)
            $_cfg[($row['Module']!==''?$row['Module'].'_':'').$row['Prop']]=$row['Val'];
        $fixture=json_decode(file_get_contents('/var/www/html/tests/fixtures/volet_sci.json'),true,32,JSON_THROW_ON_ERROR);
        if ($argv[1]==='--setup') {
            $now=time();
            $db->insert('Currs',['cID'=>5,'cDisabled'=>0,'cHidden'=>0,'cCID'=>'AC','cName'=>'Synthetic Volet USD',
                'cCurrID'=>'USD','cNumDec'=>2,'cMTS'=>timeToStamp($now),'cCASHINMode'=>2,'cCASHOUTMode'=>0,'cTRMode'=>0,'cEXMode'=>0,
                'cParamsSCI'=>encodeArrayToStr($fixture['sci'],'5AC'.$now,2),'cParamsAPI'=>encodeArrayToStr($fixture['api'],'5AC'.$now,3)]);
            $uid=(int)$pdo->query("SELECT uID FROM Users WHERE uLogin='financial-user'")->fetchColumn();
            if ($uid!==2) throw new \RuntimeException('Synthetic owner mismatch');
            $db->insert('Wallets',['wuID'=>$uid,'wcID'=>5,'wBal'=>'0','wLock'=>'0','wOut'=>'0']);
            echo "Synthetic Volet HTTP currency ready.\n"; exit;
        }
        $stage=$argv[2] ?? '';
        if (!in_array($stage,['pending','settled','restart'],true)) exit(64);
        $operations=$pdo->query('SELECT * FROM Opers WHERE ocID=5 ORDER BY oID')->fetchAll();
        if (count($operations)!==1 || $operations[0]['ouID']!==2 || $operations[0]['oOper']!=='CASHIN' || $operations[0]['oSum']!=='1.230000')
            throw new \RuntimeException('HTTP original Volet invoice count/amount mismatch');
        $o=$operations[0]; $settled=$stage!=='pending';
        if ($settled ? $o['oState']!==3 : !in_array($o['oState'],[0,1,2],true)) throw new \RuntimeException('HTTP Volet state mismatch');
        $wallet=$pdo->query('SELECT wBal,wLock,wOut FROM Wallets WHERE wuID=2 AND wcID=5')->fetch();
        if ($wallet!==['wBal'=>$settled?'1.230000':'0.000000','wLock'=>'0.000000','wOut'=>'0.000000'])
            throw new \RuntimeException('HTTP exact Volet credit mismatch');
        $form=\HScript\Finance\DepositCheckout::storedForm($o);
        if (!$form || $form['volet_profile']!==\HScript\Finance\VoletContract::PROFILE || $form['volet_api_secret']!==$fixture['api']['apipass']
            || $form['volet_api_name']!==$fixture['api']['api'] || $form['volet_api_email']!==$fixture['api']['name']
            || $form['volet_wallet']!==$fixture['api']['acc'] || $form['volet_amount']!=='1.23' || $form['volet_scale']!=='2'
            || $form['volet_local_fee_percent']!=='0' || $form['volet_local_fee_min']!=='0' || $form['volet_local_fee_max']!=='0' || $form['volet_local_fee_commission']!=='0'
            || $form['ac_order_id']!==(string)$o['oID'] || $form['ac_sci_name']!==$fixture['sci']['sci'])
            throw new \RuntimeException('HTTP encrypted Volet intent mismatch');
        foreach ([$fixture['api']['apipass'],$fixture['api']['name'],$fixture['api']['acc'],$fixture['sci']['key'],'volet_profile'] as $private)
            if (str_contains($o['oParams'],$private)) throw new \RuntimeException('HTTP saved intent plaintext leak');
        $receipt=strToArray($o['oParams2']);
        foreach (['volet_profile','volet_proof_hash','volet_fee_policy_hash','volet_api_secret','ac_transaction_status','ac_buyer_email','ac_fee','ac_merchant_amount'] as $field)
            if (isset($receipt[$field])) throw new \RuntimeException('HTTP private/unsigned receipt field leak');
        $keys=$pdo->query("SELECT ikNamespace,ikState,ikResponse FROM IdempotencyKeys WHERE ikNamespace LIKE 'gateway:ac:currency:5:%' ORDER BY ikNamespace")->fetchAll();
        if (count($keys)!==($settled?2:0)) throw new \RuntimeException('HTTP callback persisted an invalid or duplicate identity');
        foreach ($keys as $key) {
            if ($key['ikState']!=='completed') throw new \RuntimeException('HTTP unfinished callback key');
            $decoded=json_decode($key['ikResponse'],true,32,JSON_THROW_ON_ERROR);
            foreach ([$fixture['sci']['key'],$fixture['api']['apipass'],$fixture['api']['name'],$fixture['api']['acc'],$fixture['callback']['ac_src_wallet']] as $private)
                if (str_contains(serialize($decoded),$private)) throw new \RuntimeException('HTTP durable Volet snapshot plaintext leak');
        }
        if ((int)$pdo->query("SELECT COUNT(*) FROM Hist WHERE huID=2 AND hOper='BAL'")->fetchColumn()!==($settled?1:0))
            throw new \RuntimeException('HTTP duplicate/missing Volet BAL event');
        $wire=array_map(static fn($line)=>json_decode($line,true,8,JSON_THROW_ON_ERROR),file('/tmp/volet-http-wire.jsonl',FILE_IGNORE_NEW_LINES) ?: []);
        foreach (['findPaymentByOrderId','findTransaction'] as $method)
            if ($settled && count(array_filter($wire,static fn($row)=>$row['case']==='good' && $row['method']===$method))<13)
                throw new \RuntimeException('Concurrent two-lookup proof was not exercised');
        $report=(new \HScript\Finance\FinancialReconciler())->run($pdo);
        foreach ($report['checks'] as $name=>$check)
            if ($name!=='balance_history_incomplete' && $check['count']!==0) throw new \RuntimeException('HTTP reconciliation failed: '.$name);
        $projection=['Users'=>$pdo->query('SELECT uID,uBal FROM Users ORDER BY uID')->fetchAll()];
        foreach (['Opers','Wallets','Hist','IdempotencyKeys','Jobs'] as $table) $projection[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY 1,2')->fetchAll();
        $snapshot=hash('sha256',serialize($projection));
        if ($stage==='settled') file_put_contents('/tmp/volet-http-settled.sha256',$snapshot,LOCK_EX);
        if ($stage==='restart' && !hash_equals(trim((string)file_get_contents('/tmp/volet-http-settled.sha256')),$snapshot))
            throw new \RuntimeException('HTTP restarted replay changed financial/registry/job state');
        echo "Volet HTTP SQL effects, protected intent and reconciliation verified ($stage).\n"; exit;
    }
    $remote=$_SERVER['REMOTE_ADDR'] ?? '';
    $privateAddress=filter_var($remote,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)
        && (str_starts_with($remote,'127.') || str_starts_with($remote,'10.') || str_starts_with($remote,'192.168.')
            || preg_match('/^172\.(?:1[6-9]|2[0-9]|3[01])\./D',$remote));
    if (!$privateFixture || !$explicitFixture || PHP_SAPI!=='fpm-fcgi' || ($_SERVER['HTTP_HOST'] ?? '')!=='fixture.invalid'
        || !$privateAddress || !in_array($_SERVER['SCRIPT_FILENAME'] ?? '',['/var/www/html/rw.php','/var/www/html/index.php'],true)) {
        http_response_code(404); exit;
    }
    require '/fixture/gateway_http_audit_prepend.php';
}

namespace HScript\Payment {
    // Test-only offline transport. No native fallback exists in this fixture.
    function curl_init(): object { return (object)['options'=>[],'errno'=>0,'status'=>200]; }
    function curl_setopt_array(object $handle,array $options): bool { $handle->options=$options; return true; }
    function curl_exec(object $handle): bool {
        global $db;
        $options=$handle->options;
        if (($options[CURLOPT_URL] ?? '')!=='https://account.volet.com/wsm/apiWebService' || ($options[CURLOPT_POST] ?? false)!==true
            || ($options[CURLOPT_SSL_VERIFYPEER] ?? false)!==true || ($options[CURLOPT_SSL_VERIFYHOST] ?? 0)!==2
            || ($options[CURLOPT_FOLLOWLOCATION] ?? true)!==false) throw new \RuntimeException('fixture_transport_rejected');
        $document=new \DOMDocument();
        if (!$document->loadXML($options[CURLOPT_POSTFIELDS])) throw new \RuntimeException('fixture_request_rejected');
        $xpath=new \DOMXPath($document); $xpath->registerNamespace('s','http://schemas.xmlsoap.org/soap/envelope/');
        $operation=$xpath->query('/s:Envelope/s:Body/*')->item(0); $method=$operation?->localName;
        if (!in_array($method,['findPaymentByOrderId','findTransaction'],true)) throw new \RuntimeException('fixture_method_rejected');
        $read=static fn(string $path): string=>$xpath->evaluate('string('.$path.')',$operation);
        $fixture=json_decode(file_get_contents('/var/www/html/tests/fixtures/volet_sci.json'),true,32,JSON_THROW_ON_ERROR);
        if ($read('arg0/accountEmail')!==$fixture['api']['name'] || $read('arg0/apiName')!==$fixture['api']['api']
            || $read('arg0/authenticationToken')!==hash('sha256',$fixture['api']['apipass'].':'.gmdate('Ymd:H')))
            throw new \RuntimeException('fixture_authentication_rejected');
        $id=$method==='findPaymentByOrderId' ? $read('arg1/orderId') : (string)(int)substr($read('arg1'),-12);
        if (!preg_match('/^[1-9][0-9]{0,14}$/D',$id)) throw new \RuntimeException('fixture_order_rejected');
        $o=$db->fetch1Row($db->select('Opers','*','oID=?d',[(int)$id]));
        if (!$o || (int)$o['ocID']!==5 || $method==='findPaymentByOrderId' && $read('arg1/sciName')!==$fixture['sci']['sci'])
            throw new \RuntimeException('fixture_saved_order_rejected');
        $transaction='235f9d0b-b48f-462c-9949-'.str_pad($id,12,'0',STR_PAD_LEFT);
        if ($method==='findTransaction' && $read('arg1')!==$transaction) throw new \RuntimeException('fixture_transaction_rejected');
        $created=gmdate('Y-m-d\TH:i:s\Z',stampToTime($o['oCTS']));
        $order=array_replace($fixture['order'],['orderId'=>$id,'transactionId'=>$transaction,'paymentCreated'=>$created,'transactionCreated'=>$created,'transactionCompleted'=>$created]);
        $record=array_replace($fixture['transaction'],['id'=>$transaction,'orderId'=>$id,'startTime'=>$created,'updatedTime'=>$created]);
        $case=$_POST['fixture_case'] ?? 'good';
        if (!is_string($case)) throw new \RuntimeException('fixture_case_rejected');
        $mutations=[
            'merchant'=>['order','sciName','Other Store'],'receiver'=>['transaction','walletDestId','USD999999999999'],
            'source'=>['transaction','walletSrcId','USD999999999999'],'amount'=>['order','transactionAmount','1.24'],
            'fee'=>['transaction','fullCommission','0.01'],'income'=>['transaction','amountIncome','1.22'],
            'asset'=>['order','transactionCurrency','EUR'],'direction'=>['transaction','direction','OUTGOING'],
            'type'=>['transaction','transactionType','DIRECT_PAYMENT'],'sci'=>['transaction','sci','false'],
            'api'=>['transaction','api','true'],'order'=>['order','orderId',(string)((int)$id+1)],
            'transaction'=>['order','transactionId','235f9d0b-b48f-462c-9949-000000000099'],
            'pending'=>['order','paymentStatus','PENDING_THIRD_PARTY_CONFIRMATION'],'refunded'=>['order','paymentStatus','REFUNDED'],
            'transaction_refunded'=>['transaction','status','REFUNDED'],'created'=>['transaction','startTime','2026-01-01T00:00:00Z'],
        ];
        if (isset($mutations[$case])) { [$target,$field,$value]=$mutations[$case]; if ($target==='order') $order[$field]=$value; else $record[$field]=$value; }
        elseif (!in_array($case,['good','tls','timeout','http500','malformed','oversized','xxe','namespace'],true)) throw new \RuntimeException('fixture_case_rejected');
        $data=$method==='findPaymentByOrderId'?$order:$record;
        $xml='<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><v:'.$method.'Response xmlns:v="http://api.volet.com/"><return>';
        foreach ($data as $field=>$value) $xml.='<'.$field.'>'.htmlspecialchars($value,ENT_XML1|ENT_QUOTES,'UTF-8').'</'.$field.'>';
        $xml.='</return></v:'.$method.'Response></s:Body></s:Envelope>';
        if ($case==='tls' || $case==='timeout') { $handle->errno=$case==='tls'?60:28; return false; }
        if ($case==='http500') $handle->status=500;
        if ($case==='malformed') $xml='synthetic-private-provider-message';
        if ($case==='oversized') $xml=str_repeat('x',65537);
        if ($case==='xxe') $xml='<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]>'.$xml;
        if ($case==='namespace') $xml=str_replace('http://api.volet.com/','http://attacker.invalid/',$xml);
        file_put_contents('/tmp/volet-http-wire.jsonl',json_encode(['method'=>$method,'case'=>$case,'order'=>$id],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
        chmod('/tmp/volet-http-wire.jsonl',0600);
        foreach (str_split($xml,16384) as $chunk)
            if (($options[CURLOPT_WRITEFUNCTION])($handle,$chunk)!==strlen($chunk)) { $handle->errno=23; return false; }
        return true;
    }
    function curl_errno(object $handle): int { return $handle->errno; }
    function curl_getinfo(object $handle,int $option): int { return $handle->status; }
    function curl_close(object $handle): void {}
}
