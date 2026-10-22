<?php

declare(strict_types=1);

namespace {
    // Only the private read-only mount outside the document root can run this
    // fixture. Normal URLs and CLI test paths cannot activate its transport.
    $privateFixture = __FILE__ === '/fixture/ccapi_http_prepend.php';
    $explicitFixture = getenv('H_SCRIPT_CCAPI_HTTP_FIXTURE') === '1'
        && getenv('H_SCRIPT_FINANCIAL_TEST') === '1'
        && getenv('APP_ENV') === 'development' && getenv('APP_DOMAIN') === 'fixture.invalid'
        && getenv('DB_NAME') === 'fixture';
    if (PHP_SAPI === 'cli') {
        if (!$privateFixture || !$explicitFixture || !in_array($argv[1] ?? '', ['--setup','--policy','--verify'], true)) exit(64);
        require '/var/www/html/vendor/autoload.php';
        $db = new \HScript\Database\Connection();
        if (!$db->open(getenv('DB_HOST'),'fixture',getenv('DB_USER'),getenv('DB_PASSWORD'))) exit(1);
        $pdo = $db->link;
        $_cfg=[];
        foreach ($pdo->query('SELECT Module,Prop,Val FROM Cfg')->fetchAll() as $row)
            $_cfg[($row['Module']!==''?$row['Module'].'_':'').$row['Prop']]=$row['Val'];
        if ($argv[1] === '--setup') {
            $stamp = time();
            $db->insert('Currs',['cID'=>5,'cDisabled'=>0,'cHidden'=>0,'cCID'=>'CCAB','cName'=>'Synthetic CryptoCurrencyAPI Bitcoin',
                'cCurrID'=>'BTC','cNumDec'=>6,'cMTS'=>timeToStamp($stamp),'cCASHINMode'=>2,'cCASHINComis'=>10,
                'cCASHOUTMode'=>0,'cTRMode'=>0,'cEXMode'=>0,
                'cParamsSCI'=>encodeArrayToStr(['protocol'=>'3','v3_key'=>'synthetic-ccapi-v3-key'],'5CCAB'.$stamp,2)]);
            $uid=(int)$pdo->query("SELECT uID FROM Users WHERE uLogin='financial-user'")->fetchColumn();
            if ($uid !== 2) throw new \RuntimeException('Synthetic owner mismatch');
            $db->insert('Wallets',['wuID'=>$uid,'wcID'=>5,'wBal'=>'0','wLock'=>'0','wOut'=>'0']);
            foreach (['First','Second','Third'] as $index=>$question)
                $db->insert('FAQ',['fQuestion'=>$question.' synthetic FAQ','fAnswer'=>'Synthetic answer','fCat'=>$index===2?'Other':'General','fOrder'=>$index]);
            $db->replace('Cfg',['Module'=>'FAQ','Prop'=>'ShowCount','Val'=>100]);
            echo "Synthetic CryptoCurrencyAPI v3 HTTP currency ready.\n"; exit;
        }
        if ($argv[1] === '--policy') {
            $db->update('Currs',['cCASHINComis'=>20,'cCASHINComisMin'=>50,'cCASHINComisMax'=>60,
                'cCASHINMin'=>200,'cCASHINMax'=>50,'cCASHINMode'=>0],'','cID=?d',[5]);
            echo "Synthetic post-invoice policy changed.\n"; exit;
        }
        $stage=$argv[2] ?? '';
        if (!in_array($stage,['invoice','settled','restart'],true)) exit(64);
        $operation=$pdo->query('SELECT * FROM Opers WHERE ocID=5 ORDER BY oID')->fetchAll();
        $settled=$stage!=='invoice';
        if (count($operation)!==1 || $operation[0]['ouID']!==2 || $operation[0]['oOper']!=='CASHIN'
            || $operation[0]['oSum']!=='100.000000' || $operation[0]['oComis']!=='10.000000'
            || ($settled ? $operation[0]['oState']!==3 : $operation[0]['oState']!==0))
            throw new \RuntimeException('HTTP original invoice/commission mismatch');
        $o=$operation[0];
        $fullBatch=str_repeat('a',64).'#0';
        $storageBatch='c3'.substr(hash('sha256',$fullBatch),0,37);
        if ($settled && $o['oBatch']!==$storageBatch) throw new \RuntimeException('HTTP bounded batch marker mismatch');
        $wallet=$pdo->query('SELECT wBal,wLock,wOut FROM Wallets WHERE wuID=2 AND wcID=5')->fetch();
        if ($wallet!==['wBal'=>$settled?'90.000000':'0.000000','wLock'=>'0.000000','wOut'=>'0.000000'])
            throw new \RuntimeException('HTTP exact credit mismatch');
        $form=\HScript\Finance\DepositCheckout::storedForm($o);
        $row=$pdo->query("SELECT ikResponse FROM IdempotencyKeys WHERE ikNamespace='invoice:attempt'")->fetchColumn();
        $attempt=json_decode($row,true,32,JSON_THROW_ON_ERROR)['data']['contract'];
        if (!$form || $form['inbound_intent']!==$attempt || $attempt['gross']!=='100.000000'
            || $attempt['commission']!=='10' || $attempt['gateway']!=='CCAB' || $attempt['asset']!=='BTC')
            throw new \RuntimeException('HTTP protected inbound intent mismatch');
        foreach (['ccapi_','synthetic-ccapi-v3-key','synthetic-private-provider-key'] as $private)
            if (str_contains($o['oParams'],$private)) throw new \RuntimeException('HTTP stored invoice plaintext leak');
        if (str_contains(json_encode($form,JSON_THROW_ON_ERROR),'synthetic-private-provider-key')
            || str_contains(json_encode($form,JSON_THROW_ON_ERROR),'synthetic-ccapi-v3-key'))
            throw new \RuntimeException('HTTP provider secret retained in checkout');
        $receipt=strToArray($o['oParams2']);
        foreach (array_keys($receipt) as $field)
            if ($field==='sum' || str_starts_with((string)$field,'ccapi_') || $field==='inbound_intent')
                throw new \RuntimeException('HTTP immutable proof copied to receipt');
        $keys=$pdo->query("SELECT ikNamespace,ikState,ikResponse FROM IdempotencyKeys WHERE ikNamespace LIKE 'gateway:ccab:currency:5:%' ORDER BY ikNamespace")->fetchAll();
        if (count($keys)!==($settled?3:0)) throw new \RuntimeException('Rejected callback persisted identity or duplicate settlement');
        foreach ($keys as $key) {
            if ($key['ikState']!=='completed') throw new \RuntimeException('HTTP pending identity claim leaked');
            foreach (['synthetic-ccapi-v3-key','synthetic-private-provider-key',str_repeat('a',64)] as $private)
                if (str_contains($key['ikResponse'],$private)) throw new \RuntimeException('HTTP durable snapshot payload leak');
        }
        if ($settled) {
            $identity=$pdo->query("SELECT ikKeyHash FROM IdempotencyKeys WHERE ikNamespace='gateway:ccab:currency:5:identity'")->fetchColumn();
            if ($identity!==hash('sha256',"0\0".hash('sha256',$fullBatch)))
                throw new \RuntimeException('HTTP registry did not retain full provider transaction identity');
        }
        if ((int)$pdo->query("SELECT COUNT(*) FROM Hist WHERE huID=2 AND hOper='BAL'")->fetchColumn()!==($settled?1:0))
            throw new \RuntimeException('HTTP duplicate/missing BAL event');
        $wire=array_map(static fn($line)=>json_decode($line,true,8,JSON_THROW_ON_ERROR),file('/tmp/ccapi-http-wire.jsonl',FILE_IGNORE_NEW_LINES) ?: []);
        if (count($wire)!==1 || $wire[0]['method']!=='give' || $wire[0]['label']!==(string)$o['oID'])
            throw new \RuntimeException('GET or checkout retry created another provider invoice');
        foreach ((new \HScript\Finance\FinancialReconciler())->run($pdo)['checks'] as $name=>$check)
            if ($name!=='balance_history_incomplete' && $check['count']!==0) throw new \RuntimeException('HTTP reconciliation failed: '.$name);
        $projection=['Users'=>$pdo->query('SELECT uID,uBal FROM Users ORDER BY uID')->fetchAll()];
        foreach (['Opers','Wallets','Hist','IdempotencyKeys','Jobs'] as $table)
            $projection[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY 1,2')->fetchAll();
        $snapshot=hash('sha256',serialize($projection));
        if ($stage==='settled') file_put_contents('/tmp/ccapi-http-settled.sha256',$snapshot,LOCK_EX);
        if ($stage==='restart' && !hash_equals(trim((string)file_get_contents('/tmp/ccapi-http-settled.sha256')),$snapshot))
            throw new \RuntimeException('Restarted HTTP replay changed financial/registry/job state');
        echo "CryptoCurrencyAPI HTTP SQL effects, protected invoice and reconciliation verified ($stage).\n"; exit;
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
    // This private fixture intercepts only the client's native calls. It never
    // falls through to a real merchant transport or changes runtime endpoints.
    function curl_init(): object { return (object)['options'=>[],'errno'=>0,'status'=>200]; }
    function curl_setopt_array(object $handle,array $options): bool { $handle->options=$options; return true; }
    function curl_exec(object $handle): bool {
        $options=$handle->options;
        if (($options[CURLOPT_URL] ?? '')!=='https://new.cryptocurrencyapi.net/api/bitcoin/.give'
            || ($options[CURLOPT_POST] ?? false)!==true || ($options[CURLOPT_SSL_VERIFYPEER] ?? false)!==true
            || ($options[CURLOPT_SSL_VERIFYHOST] ?? 0)!==2 || ($options[CURLOPT_FOLLOWLOCATION] ?? true)!==false
            || !in_array('CCAPI-KEY: synthetic-ccapi-v3-key',$options[CURLOPT_HTTPHEADER] ?? [],true))
            throw new \RuntimeException('fixture_transport_rejected');
        parse_str($options[CURLOPT_POSTFIELDS] ?? '',$fields);
        if (!preg_match('/^[1-9][0-9]{0,8}$/D',$fields['label'] ?? '')
            || ($fields['uniqID'] ?? null)!=='hscript:'.hash('sha256','http://fixture.invalid/balance/status').':'.$fields['label']
            || count($fields)!==3 || ($fields['statusURL'] ?? '')!=='http://fixture.invalid/balance/status')
            throw new \RuntimeException('fixture_creation_intent_rejected');
        file_put_contents('/tmp/ccapi-http-wire.jsonl',json_encode(['method'=>'give','label'=>$fields['label']],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
        $body=json_encode(['result'=>['address'=>'bc1q'.str_repeat('q',38),'publicKey'=>'synthetic-public-provider-key',
            'privateKey'=>'synthetic-private-provider-key']],JSON_THROW_ON_ERROR);
        if (($options[CURLOPT_WRITEFUNCTION])($handle,$body)!==strlen($body)) { $handle->errno=23; return false; }
        return true;
    }
    function curl_errno(object $handle): int { return $handle->errno; }
    function curl_getinfo(object $handle,int $option): int { return $handle->status; }
    function curl_close(object $handle): void {}
}
