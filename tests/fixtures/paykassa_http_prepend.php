<?php

declare(strict_types=1);

namespace {
    // This file is inert at every normal test URL/CLI entry. Only a private,
    // read-only mount outside the document root can activate the fixture.
    $privateFixture = __FILE__ === '/fixture/paykassa_http_prepend.php';
    $explicitFixture = getenv('H_SCRIPT_PAYKASSA_HTTP_FIXTURE') === '1'
        && getenv('H_SCRIPT_FINANCIAL_TEST') === '1'
        && getenv('APP_ENV') === 'development' && getenv('APP_DOMAIN') === 'fixture.invalid'
        && getenv('DB_NAME') === 'fixture';
    if (PHP_SAPI === 'cli') {
        if (!$privateFixture || !$explicitFixture || !in_array($argv[1] ?? '', ['--setup','--verify'], true)) exit(64);
        require '/var/www/html/vendor/autoload.php';
        $db = new \HScript\Database\Connection();
        if (!$db->open(getenv('DB_HOST'),'fixture',getenv('DB_USER'),getenv('DB_PASSWORD'))) exit(1);
        $pdo = $db->link;
        $_cfg=[];
        foreach ($pdo->query('SELECT Module,Prop,Val FROM Cfg')->fetchAll() as $row)
            $_cfg[($row['Module']!==''?$row['Module'].'_':'').$row['Prop']]=$row['Val'];
        if ($argv[1] === '--setup') {
            $stamp = time();
            $db->insert('Currs',['cID'=>5,'cDisabled'=>0,'cHidden'=>0,'cCID'=>'PKB','cName'=>'Synthetic Paykassa Bitcoin',
                'cCurrID'=>'BTC','cNumDec'=>6,'cMTS'=>timeToStamp($stamp),'cCASHINMode'=>2,
                'cCASHOUTMode'=>0,'cTRMode'=>0,'cEXMode'=>0,
                'cParamsSCI'=>encodeArrayToStr(['id'=>'122','key'=>'synthetic-paykassa-sci-secret'],'5PKB'.$stamp,2)]);
            $uid=(int)$pdo->query("SELECT uID FROM Users WHERE uLogin='financial-user'")->fetchColumn();
            if ($uid !== 2) throw new \RuntimeException('Synthetic owner mismatch');
            $db->insert('Wallets',['wuID'=>$uid,'wcID'=>5,'wBal'=>'0','wLock'=>'0','wOut'=>'0']);
            // Every remote mutation gets its own original encrypted invoice.
            // These CLI fixtures are never exposed by an HTTP setup endpoint.
            require '/var/www/html/tests/fixtures/financial_runtime.php';
            $_currs=$db->fetchIDRows($db->select('Currs'),false,'cID');
            $_onload=[]; $_GS['client_ip']='127.0.0.1'; $_GS['root_url']='http://fixture.invalid/';
            $manager=new \HScript\Payment\PaymentManager($db);
            $cases=[];
            foreach(['merchant','receiver','hash','tag','asset','amount','float','partial','order','invoice',
                'remoteerror','tls','timeout','http500','malformed','oversized','unknown'] as $kind) {
                $id=opOperCreate($uid,'CASHIN',5,'1.23');
                if (!is_int($id) || $id<1) throw new \RuntimeException('Synthetic mutation invoice failed');
                \HScript\Finance\DepositCheckout::prepare($db,$uid,$id,static function(array $operation) use($manager):array {
                    opDecodeCurrParams($operation,$pay,$sci,$api);
                    return $manager->processDeposit('PKB',['sci'=>$sci,'sum'=>$operation['oSum'],'tag'=>$operation['oID']]);
                });
                $cases[$kind]=$id;
            }
            // CLI prepared invoices created the trace as root. The later FPM
            // provider double must append under its own private file owner.
            if (!chown('/tmp/paykassa-http-wire.jsonl','www-data')
                || !chmod('/tmp/paykassa-http-wire.jsonl',0600))
                throw new \RuntimeException('Synthetic provider trace permission setup failed');
            file_put_contents('/tmp/paykassa-http-cases.json',json_encode($cases,JSON_THROW_ON_ERROR),LOCK_EX);
            chmod('/tmp/paykassa-http-cases.json',0600);
            echo "Synthetic Paykassa HTTP currency and isolated original mutation invoices ready.\n"; exit;
        }
        $stage=$argv[2] ?? '';
        if (!in_array($stage,['pending','settled','restart'],true)) exit(64);
        $operation=$pdo->query("SELECT * FROM Opers WHERE ocID=5 ORDER BY oID")->fetchAll();
        $cases=json_decode(file_get_contents('/tmp/paykassa-http-cases.json'),true,32,JSON_THROW_ON_ERROR);
        $original=array_values(array_filter($operation,static fn($row)=>!in_array($row['oID'],array_values($cases),true)));
        if (count($operation)!==count($cases)+1 || count($original)!==1) throw new \RuntimeException('HTTP original invoice count mismatch');
        foreach($operation as $row) {
            if ($row['ouID']!==2 || $row['oOper']!=='CASHIN' || $row['oSum']!=='1.230000')
                throw new \RuntimeException('HTTP original invoice amount/owner mismatch');
            if (in_array($row['oID'],array_values($cases),true) && $row['oState']!==0)
                throw new \RuntimeException('Rejected mutated invoice changed financial state');
        }
        $o=$original[0]; $settled=$stage!=='pending';
        if ($settled ? $o['oState']!==3 : !in_array($o['oState'],[0,1,2],true)) throw new \RuntimeException('HTTP invoice state mismatch');
        $wallet=$pdo->query('SELECT wBal,wLock,wOut FROM Wallets WHERE wuID=2 AND wcID=5')->fetch();
        if ($wallet!==['wBal'=>$settled?'1.230000':'0.000000','wLock'=>'0.000000','wOut'=>'0.000000'])
            throw new \RuntimeException('HTTP exact credit mismatch');
        $form=\HScript\Finance\DepositCheckout::storedForm($o);
        $expectedHash='fixture_paykassa_'.$o['oID'];
        if (!$form || $form['paykassa_merchant_id']!=='122' || $form['paykassa_amount']!=='1.23'
            || $form['paykassa_hash']!==hash('sha256',$expectedHash)
            || $form['paykassa_invoice']!==(string)(579000+$o['oID']) || $form['paykassa_tag']!==''
            || $form['paykassa_address']!=='bc1qsyntheticinvoice'.str_pad((string)$o['oID'],8,'0',STR_PAD_LEFT).'receiverabcdef1234'
            || array_intersect_key($form,array_flip(['url','get','hash']))!==['url'=>'https://crypto.paykassa.pro/sci/index.php?hash='.$expectedHash,'get'=>true,'hash'=>$expectedHash])
            throw new \RuntimeException('HTTP saved invoice proof mismatch');
        foreach (['paykassa_','synthetic-paykassa-sci-secret',$form['paykassa_address'],$expectedHash] as $private)
            if (str_contains($o['oParams'],$private)) throw new \RuntimeException('HTTP stored invoice plaintext leak');
        $receipt=strToArray($o['oParams2']);
        if (isset($receipt['merchant_id']) || isset($receipt['provider_invoice_hash']) || isset($receipt['profile_key_hash']) || isset($receipt['profile_timezone'])) throw new \RuntimeException('HTTP public receipt proof leak');
        $keys=$pdo->query("SELECT ikNamespace,ikState,ikResponse FROM IdempotencyKeys WHERE ikNamespace LIKE 'gateway:pkb:currency:5:%' ORDER BY ikNamespace")->fetchAll();
        if (count($keys)!==($settled?2:0)) throw new \RuntimeException('Rejected HTTP callback persisted identity or duplicate settlement');
        foreach ($keys as $key) {
            if ($key['ikState']!=='completed') throw new \RuntimeException('HTTP pending identity claim leaked');
            foreach (['private_hash','synthetic-paykassa-sci-secret',$expectedHash,$form['paykassa_address']] as $private)
                if (str_contains($key['ikResponse'],$private)) throw new \RuntimeException('HTTP durable snapshot payload leak');
        }
        if ((int)$pdo->query("SELECT COUNT(*) FROM Hist WHERE huID=2 AND hOper='BAL'")->fetchColumn()!==($settled?1:0))
            throw new \RuntimeException('HTTP duplicate/missing BAL event');
        $wire=array_map(static fn($line)=>json_decode($line,true,8,JSON_THROW_ON_ERROR),file('/tmp/paykassa-http-wire.jsonl',FILE_IGNORE_NEW_LINES) ?: []);
        $creates=array_filter($wire,static fn($row)=>$row['func']==='sci_create_order_get_data');
        if (count($creates)!==count($cases)+1 || count(array_filter($creates,static fn($row)=>(int)$row['order']===$o['oID']))!==1)
            throw new \RuntimeException('GET or checkout retry created another provider invoice');
        foreach(array_keys($cases) as $kind) {
            if ($kind==='unknown') continue;
            if (count(array_filter($wire,static fn($row)=>$row['case']===$kind && (int)$row['order']===$cases[$kind]))!==1)
                throw new \RuntimeException('Mutation did not reach its own original invoice: '.$kind);
        }
        if (count(array_filter($wire,static fn($row)=>$row['case']==='good'))!==($settled?1:0))
            throw new \RuntimeException('Exact token parallel/restart cache performed duplicate remote work');
        $quota=$pdo->query("SELECT ikNamespace,ikResponse FROM IdempotencyKeys WHERE ikNamespace LIKE 'verification:paykassa:%'")->fetchAll();
        if (count($quota)!==count($cases)+1+($settled?1:0)) throw new \RuntimeException('Quota/cache row count mismatch');
        foreach($quota as $row) foreach(['private_hash','synthetic-paykassa-sci-secret','bc1qsynthetic','fixture-'] as $private)
            if (str_contains($row['ikResponse'],$private)) throw new \RuntimeException('Quota/cache private payload leak');
        $report=(new \HScript\Finance\FinancialReconciler())->run($pdo);
        foreach ($report['checks'] as $name=>$check)
            if ($name!=='balance_history_incomplete' && $check['count']!==0) throw new \RuntimeException('HTTP reconciliation failed: '.$name);
        // Compare the complete financial rows and registry, including encrypted
        // checkout, against the post-settlement snapshot after application restart.
        $projection=['Users'=>$pdo->query('SELECT uID,uBal FROM Users ORDER BY uID')->fetchAll()];
        foreach (['Opers','Wallets','Hist','IdempotencyKeys','Jobs'] as $table)
            $projection[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY 1,2')->fetchAll();
        $snapshot=hash('sha256',serialize($projection));
        if ($stage==='settled') file_put_contents('/tmp/paykassa-http-settled.sha256',$snapshot,LOCK_EX);
        if ($stage==='restart' && !hash_equals(trim((string)file_get_contents('/tmp/paykassa-http-settled.sha256')),$snapshot))
            throw new \RuntimeException('Restarted HTTP replay changed financial/registry/job state');
        echo "Paykassa HTTP SQL effects, protected invoice and reconciliation verified ($stage).\n"; exit;
    }
    $remote=$_SERVER['REMOTE_ADDR'] ?? '';
    $privateAddress=filter_var($remote,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)
        && (str_starts_with($remote,'127.') || str_starts_with($remote,'10.')
            || str_starts_with($remote,'192.168.') || preg_match('/^172\.(?:1[6-9]|2[0-9]|3[01])\./D',$remote));
    if (!$privateFixture || !$explicitFixture || PHP_SAPI!=='fpm-fcgi'
        || ($_SERVER['HTTP_HOST'] ?? '')!=='fixture.invalid' || !$privateAddress
        || !in_array($_SERVER['SCRIPT_FILENAME'] ?? '',['/var/www/html/rw.php','/var/www/html/index.php'],true)) {
        http_response_code(404); exit;
    }
    require '/fixture/gateway_http_audit_prepend.php';
}

namespace HScript\Payment {
    // Namespaced functions intercept this client only. There is no native
    // transport fallback or runtime URL/configuration hook.
    function curl_init(): object { return (object)['options'=>[],'errno'=>0,'status'=>200]; }
    function curl_setopt_array(object $handle,array $options): bool { $handle->options=$options; return true; }
    function curl_exec(object $handle): bool {
        $options=$handle->options;
        if (($options[CURLOPT_URL] ?? '')!=='https://paykassa.app/sci/0.4/index.php'
            || ($options[CURLOPT_POST] ?? false)!==true || ($options[CURLOPT_SSL_VERIFYPEER] ?? false)!==true
            || ($options[CURLOPT_SSL_VERIFYHOST] ?? 0)!==2 || ($options[CURLOPT_FOLLOWLOCATION] ?? true)!==false)
            throw new \RuntimeException('fixture_transport_rejected');
        parse_str($options[CURLOPT_POSTFIELDS] ?? '',$fields);
        if (($fields['sci_id'] ?? '')!=='122' || ($fields['sci_key'] ?? '')!=='synthetic-paykassa-sci-secret'
            || ($fields['test'] ?? '')!=='false') throw new \RuntimeException('fixture_credentials_rejected');
        $func=$fields['func'] ?? ''; $case='create';
        if ($func==='sci_create_order_get_data') {
            $id=$fields['order_id'] ?? '';
            if (($fields['amount'] ?? '')!=='1.23' || ($fields['currency'] ?? '')!=='BTC'
                || ($fields['system'] ?? '')!=='11') throw new \RuntimeException('fixture_creation_intent_rejected');
        } elseif ($func==='sci_confirm_order') {
            if (!preg_match('/^fixture-([1-9][0-9]{0,8})-(good|merchant|receiver|hash|tag|asset|amount|float|partial|order|invoice|remoteerror|tls|timeout|http500|malformed|oversized)$/D',$fields['private_hash'] ?? '',$match))
                throw new \RuntimeException('fixture_token_rejected');
            $id=$match[1]; $case=$match[2];
        } else throw new \RuntimeException('fixture_method_rejected');
        if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,8}$/D',$id)) throw new \RuntimeException('fixture_order_rejected');
        file_put_contents('/tmp/paykassa-http-wire.jsonl',json_encode(['func'=>$func,'order'=>$id,'case'=>$case],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
        $address='bc1qsyntheticinvoice'.str_pad($id,8,'0',STR_PAD_LEFT).'receiverabcdef1234';
        $hash='fixture_paykassa_'.$id;
        if ($func==='sci_create_order_get_data') {
            $data=['invoice'=>(string)(579000+(int)$id),'order_id'=>$id,'wallet'=>$address,'amount'=>'1.23000000',
                'system'=>'BitCoin','currency'=>'BTC','url'=>'https://crypto.paykassa.pro/sci/index.php?hash='.$hash,'tag'=>false];
        } else {
            $data=['shop_id'=>'122','order_id'=>$id,'transaction'=>(string)(579000+(int)$id),'amount'=>'1.23000000',
                'currency'=>'BTC','system'=>'BitCoin','address'=>$address,'tag'=>false,'hash'=>$hash,'partial'=>'no'];
            match($case) {
                'merchant'=>$data['shop_id']='123','receiver'=>$data['address']='bc1qredirectedinvoice123456receiverabcdef',
                'hash'=>$data['hash']='fixture_redirected_payment','tag'=>$data['tag']='17','asset'=>$data['currency']='ETH',
                'amount'=>$data['amount']='1.24','float'=>$data['amount']=1.23,'partial'=>$data['partial']='yes',
                'order'=>$data['order_id']=(string)((int)$id+1),'invoice'=>$data['transaction']=(string)(579001+(int)$id),default=>null,
            };
        }
        if ($case==='tls' || $case==='timeout') { $handle->errno=$case==='tls'?60:28; return false; }
        if ($case==='http500') $handle->status=500;
        $body=$case==='malformed'?'{broken':($case==='oversized'?str_repeat('x',65537):json_encode([
            'error'=>$case==='remoteerror','message'=>'synthetic-private-provider-message synthetic-paykassa-sci-secret','data'=>$data],JSON_THROW_ON_ERROR));
        if (($options[CURLOPT_WRITEFUNCTION])($handle,$body)!==strlen($body)) { $handle->errno=23; return false; }
        return true;
    }
    function curl_errno(object $handle): int { return $handle->errno; }
    function curl_getinfo(object $handle,int $option): int { return $handle->status; }
    function curl_close(object $handle): void {}
}
