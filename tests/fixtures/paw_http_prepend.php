<?php

declare(strict_types=1);

namespace {
    // Only the read-only fixture mount outside the document root may activate.
    $privateFixture = __FILE__ === '/fixture/paw_http_prepend.php';
    $explicitFixture = getenv('H_SCRIPT_PAW_HTTP_FIXTURE') === '1'
        && getenv('H_SCRIPT_FINANCIAL_TEST') === '1'
        && getenv('APP_ENV') === 'development' && getenv('APP_DOMAIN') === 'fixture.invalid'
        && getenv('DB_NAME') === 'fixture';
    if (PHP_SAPI === 'cli') {
        if (!$privateFixture || !$explicitFixture || !in_array($argv[1] ?? '', ['--setup','--verify','--history','--history-close','--schema-down','--native-verify','--wallet-setup','--wallet-verify','--wallet-fault','--wallet-fault-verify'], true)) exit(64);
        require '/var/www/html/vendor/autoload.php';
        $db = new \HScript\Database\Connection();
        if (!$db->open(getenv('DB_HOST'),'fixture',getenv('DB_USER'),getenv('DB_PASSWORD'))) exit(1);
        $pdo = $db->link;
        $check = static function(bool $ok,string $message): void { if (!$ok) throw new \RuntimeException($message); };
        if ($argv[1] === '--setup') {
            foreach ([89,90] as $level) {
                $id=$db->insert('Users',['uLogin'=>'paw-review-'.$level,'uPass'=>hashPassword('synthetic-financial-password'),
                    'uMail'=>'paw-review-'.$level.'@fixture.invalid','uState'=>1,'uLevel'=>$level,'uLang'=>'ru','uTheme'=>'light']);
                $db->insert('AddInfo',['auID'=>$id,'aNeedReConfig'=>0]);
            }
            echo "Synthetic PAW HTTP administrator roles ready; no provider profile seeded.\n"; exit;
        }
        if(str_starts_with($argv[1],'--wallet-')) {
            $_SERVER+=['SERVER_NAME'=>'fixture.invalid','SCRIPT_NAME'=>'/rw.php','SERVER_PORT'=>80,'REMOTE_ADDR'=>'127.0.0.1'];
            global $_cfg;$_cfg=[];require '/var/www/html/_config.php';require '/var/www/html/module/dbinit.php';require '/var/www/html/module/balance/lib.php';
            $uid=(int)$pdo->query("SELECT uID FROM Users WHERE uLogin='financial-user'")->fetchColumn();
            $peer=(int)$pdo->query("SELECT uID FROM Users WHERE uLogin='financial-peer'")->fetchColumn();
            $currencies=$pdo->query("SELECT cID,cCurrID FROM Currs WHERE cCID='PAW' AND cProviderSelected=1")->fetchAll(\PDO::FETCH_KEY_PAIR);
            $byCode=array_flip($currencies);$check(isset($byCode['USD'],$byCode['EUR']),'active fiat fixtures');
            $money=static function()use($pdo):string{$data=['users'=>$pdo->query('SELECT uID,uBal FROM Users ORDER BY uID')->fetchAll(),'wallets'=>$pdo->query('SELECT wuID,wcID,wBal,wLock,wOut FROM Wallets ORDER BY wuID,wcID')->fetchAll()];foreach(['Opers','Deps']as$table)$data[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll();return hash('sha256',serialize($data));};
            $wallets=static fn()=>hash('sha256',serialize($pdo->query('SELECT * FROM Wallets ORDER BY wuID,wcID')->fetchAll()));
            if($argv[1]==='--wallet-setup'){
                foreach(['Sec'=>['MinPIN'=>'6'],'Bal'=>['LockWallets'=>'1','LastUpdate'=>'20261024000000','FutureMetadata'=>'preserve']]as$module=>$props)foreach($props as$prop=>$value)$db->replace('Cfg',['Module'=>$module,'Prop'=>$prop,'Val'=>$value]);
                $db->update('Users',['uPIN'=>hashPassword('123456')],'','uID=?d',[$uid]);
                foreach($byCode as$code=>$cid){$now=time();$db->update('Wallets',['wParams'=>encodeArrayToStr($code==='USD'?['acc'=>'PAW']:[],$cid.$uid.$now),'wMTS'=>timeToStamp($now)],'','wuID=?d AND wcID=?d',[$uid,$cid]);}
                file_put_contents('/tmp/paw-wallet-money.sha256',$money());
                file_put_contents('/tmp/paw-wallet-peer.sha256',hash('sha256',serialize($pdo->query('SELECT * FROM Wallets WHERE wuID='.$peer.' ORDER BY wcID')->fetchAll())));
                echo "Wallet/PIN/legacy receipt and rate metadata fixtures ready.\n";exit;
            }
            if($argv[1]==='--wallet-verify'){
                foreach($byCode as$code=>$cid){$row=$db->fetch1Row($db->select('Wallets','*','wuID=?d AND wcID=?d',[$uid,$cid]));$params=opDecodeUserCurrParams($row);$check($code==='USD'?($params['usdt_tron']??'')==='TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t':($params['usdc_eth']??'')==='0x'.str_repeat('b',40),'distinct owner currency recipient saved');}
                $check(hash_equals(file_get_contents('/tmp/paw-wallet-money.sha256'),$money()),'wallet edits leave financial rows/buckets exact');
                $check(hash_equals(file_get_contents('/tmp/paw-wallet-peer.sha256'),hash('sha256',serialize($pdo->query('SELECT * FROM Wallets WHERE wuID='.$peer.' ORDER BY wcID')->fetchAll()))),'cross-owner wallets untouched');
                foreach(['RateBNB'=>'500.125000000000000001','LastUpdate'=>'20261024000000','FutureMetadata'=>'preserve']as$prop=>$expected){$q=$pdo->prepare("SELECT Val FROM Cfg WHERE Module='Bal' AND Prop=?");$q->execute([$prop]);$check($q->fetchColumn()===$expected,'exact rate and preserved metadata '.$prop);}
                echo "Wallet owner/PIN/lock/omitted-group and rate HTTP SQL checks passed.\n";exit;
            }
            if($argv[1]==='--wallet-fault'){
                $db->replace('Cfg',['Module'=>'Bal','Prop'=>'LockWallets','Val'=>'0']);
                file_put_contents('/tmp/paw-wallet-fault.sha256',$wallets());
                // Nontransactional synthetic evidence proves both writes were attempted,
                // while the financial InnoDB rows must roll back atomically.
                $pdo->exec('CREATE TABLE PawHttpWalletFaultSteps (cid int NOT NULL) ENGINE=MyISAM');
                $late=max(array_values($byCode));$ids=implode(',',array_map('intval',array_values($byCode)));
                $pdo->exec("CREATE TRIGGER paw_http_wallet_fail BEFORE UPDATE ON Wallets FOR EACH ROW BEGIN IF NEW.wuID=".$uid." AND NEW.wcID IN (".$ids.") THEN INSERT INTO PawHttpWalletFaultSteps VALUES(NEW.wcID); IF NEW.wcID=".$late." THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic wallet late failure'; END IF; END IF; END");
                echo "Disposable late wallet-write fault enabled.\n";exit;
            }
            $expected=array_map('intval',array_values($byCode));sort($expected);$check(array_map('intval',$pdo->query('SELECT cid FROM PawHttpWalletFaultSteps')->fetchAll(\PDO::FETCH_COLUMN))===$expected,'both currency updates reached SQL before the late failure');
            $check(hash_equals(file_get_contents('/tmp/paw-wallet-fault.sha256'),$wallets()),'late second-currency failure rolls back every wallet write');$pdo->exec('DROP TRIGGER paw_http_wallet_fail');$pdo->exec('DROP TABLE PawHttpWalletFaultSteps');
            echo "Wallet HTTP late-write atomic rollback passed.\n";exit;
        }
        $projection=static function() use ($pdo): string {
            $data=['users'=>$pdo->query('SELECT uID,uBal FROM Users ORDER BY uID')->fetchAll()];
            foreach (['Wallets'=>'wcID','Opers'=>'ocID','Hist'=>'hcID'] as $table=>$column)
                $data[$table]=$pdo->query("SELECT * FROM $table WHERE $column IN (SELECT cID FROM Currs WHERE cCID='PAW') ORDER BY 1,2")->fetchAll();
            $data['keys']=$pdo->query("SELECT * FROM IdempotencyKeys WHERE ikNamespace LIKE 'gateway:paw:%' ORDER BY ikID")->fetchAll();
            $data['jobs']=$pdo->query("SELECT * FROM Jobs WHERE jType='synthetic.paw.pending' ORDER BY jID")->fetchAll();
            return hash('sha256',serialize($data));
        };
        if ($argv[1] === '--history-close') {
            $check(hash_equals(trim((string)file_get_contents('/tmp/paw-http-history.sha256')),$projection()),'blocked archival preserves the complete pending history projection');
            $oid=(int)$pdo->query("SELECT oID FROM Opers JOIN Currs ON cID=ocID WHERE cCID='PAW' AND ouID=2 AND oState=0 AND oMemo='Synthetic historical pending PAW'")->fetchColumn();
            $check($oid>0,'exact pending fixture exists before ordinary cancellation');
            $q=$pdo->prepare('SELECT * FROM Opers WHERE oID=?');$q->execute([$oid]);$before=$q->fetch(\PDO::FETCH_ASSOC);
            require '/var/www/html/module/balance/lib.php';
            $check(opOperCancel(2,$oid)===true,'ordinary owner cancellation closes the synthetic pending operation');
            $q->execute([$oid]);$after=$q->fetch(\PDO::FETCH_ASSOC);$check((int)$after['oState']===5,'pending fixture is cancelled, never settled');
            foreach($before as$field=>$value)if(!in_array($field,['oState','oTS'],true))$check($after[$field]===$value,'cancellation preserves original operation '.$field);
            file_put_contents('/tmp/paw-http-history.sha256',$projection(),LOCK_EX);
            echo "Pending archival rejection verified; ordinary owner cancellation permits the next retirement phase.\n";exit;
        }
        if ($argv[1] === '--history') {
            $cid=(int)$pdo->query("SELECT cID FROM Currs WHERE cCID='PAW' AND cCurrID='USD'")->fetchColumn();
            $check($cid>0,'PAW USD exists after real form submission');
            $db->insert('Wallets',['wuID'=>2,'wcID'=>$cid,'wBal'=>'0','wLock'=>'0','wOut'=>'0']);
            $oid=$db->insert('Opers',['ouID'=>2,'ocID'=>$cid,'oOper'=>'CASHIN','oState'=>0,'oSum'=>'2.500000','oMemo'=>'Synthetic historical pending PAW']);
            $db->insert('Hist',['huID'=>2,'hcID'=>$cid,'hoID'=>$oid,'hOper'=>'SYNTHETIC','hSum'=>'0','hMemo'=>'Synthetic retained PAW history']);
            for ($i=0;$i<2;$i++) $db->insert('Jobs',['jType'=>'synthetic.paw.pending','jPayload'=>'{"fixture":true}','jState'=>0]);
            file_put_contents('/tmp/paw-http-history.sha256',$projection(),LOCK_EX);
            echo "Synthetic PAW wallet, pending operation, history and jobs preserved for management checks.\n"; exit;
        }
        if ($argv[1] === '--schema-down') {
            // Native balances/proofs were independently verified immediately before this step.
            file_put_contents('/tmp/paw-http-history.sha256',$projection(),LOCK_EX);
            // This destructive fixture is confined to the disposable database.
            $pdo->exec('ALTER TABLE Currs DROP FOREIGN KEY fk_currency_provider_key');
            $pdo->exec('ALTER TABLE Currs DROP FOREIGN KEY fk_currency_provider');
            $drop=stripos((string)$pdo->getAttribute(\PDO::ATTR_SERVER_VERSION),'MariaDB')!==false ? 'DROP CONSTRAINT' : 'DROP CHECK';
            $pdo->exec("ALTER TABLE Currs $drop chk_currency_provider, $drop chk_paw_native, $drop chk_coinnew_configuration_only, $drop chk_currency_archived");
            $pdo->exec('ALTER TABLE Currs DROP INDEX uq_currency_provider_fiat');
            $pdo->exec('ALTER TABLE Currs DROP COLUMN cProviderID, DROP COLUMN cProviderSelected, DROP COLUMN cProviderKeyVersion');
            // Remove newer provider children first, keeping FK checks enabled.
            $pdo->exec('DROP TABLE ProviderTokenCatalogs');
            $pdo->exec('DROP TABLE ProviderNetworkWallets');
            $pdo->exec('DROP TABLE PaymentProviderCredentials');
            $pdo->exec('DROP TABLE PaymentProviders');
            (new \HScript\Update\SchemaStateRepository($db))->setCurrentVersion('1.0.4');
            echo "Disposable pre-migration database has no provider tables or child columns.\n"; exit;
        }
        if($argv[1]==='--native-verify') {
            $uid=(int)$pdo->query("SELECT uID FROM Users WHERE uLogin='financial-user'")->fetchColumn();
            $profile=$pdo->query("SELECT * FROM PaymentProviders WHERE ppSlot='PAW'")->fetch(\PDO::FETCH_ASSOC);
            $check($profile && $profile['ppEnvironment']==='sandbox','active new sandbox profile');
            foreach(['USD'=>'9.840000','EUR'=>'31.250000'] as $fiat=>$expected){
                $q=$pdo->prepare("SELECT wBal FROM Wallets JOIN Currs ON cID=wcID WHERE wuID=? AND cProviderID=? AND cCurrID=?");$q->execute([$uid,$profile['ppID'],$fiat]);$check($q->fetchColumn()===$expected,'exact native wallet '.$fiat);
            }
            $check((int)$pdo->query("SELECT COUNT(*) FROM Opers JOIN Currs ON cID=ocID WHERE cCID='PAW' AND oState=3")->fetchColumn()===2,'only two native invoices settle');
            $check((int)$pdo->query("SELECT COUNT(*) FROM IdempotencyKeys WHERE ikNamespace LIKE 'gateway:paw:%:settled' AND ikState='completed'")->fetchColumn()===2,'exactly two permanent committed PAW proofs');
            $check((int)$pdo->query("SELECT COUNT(*) FROM Currs WHERE cCID='PAW' AND cCASHOUTMode<>0")->fetchColumn()===0,'no PAW payout activated');
            $check((int)$pdo->query("SELECT COUNT(*) FROM IdempotencyKeys WHERE ikNamespace='invoice:attempt' AND ikOperationID IN (SELECT oID FROM Opers WHERE oMemo='Synthetic PAW timeout')")->fetchColumn()===1,'timeout has one durable attempt');
            $check(count(file('/tmp/paw-native-http-wire.jsonl',FILE_IGNORE_NEW_LINES))===3,'no repeated provider invoice on retry or restart');
            echo "Native PAW exact SQL, proof, timeout and payout checks passed.\n";exit;
        }
        $stage=$argv[2] ?? '';
        if (!in_array($stage,['created','managed','restart','schema'],true)) exit(64);
        $wire=array_map(static fn($line)=>json_decode($line,true,8,JSON_THROW_ON_ERROR),file('/tmp/paw-http-wire.jsonl',FILE_IGNORE_NEW_LINES) ?: []);
        $check(count($wire)===($stage==='created'?3:($stage==='schema'?9:6)),'only two explicit save POSTs validate PAW; GET/cooldown/CSRF/replay perform no provider requests');
        if ($stage==='schema') {
            $check((new \HScript\Update\SchemaStateRepository($db))->currentVersion()==='1.0.4','schema version unchanged');
            $check((int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('PaymentProviders','PaymentProviderCredentials','ProviderTokenCatalogs','ProviderNetworkWallets')")->fetchColumn()===0,'guard did not create or query missing provider tables');
            $check((int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Currs' AND COLUMN_NAME IN ('cProviderID','cProviderSelected')")->fetchColumn()===0,'guard did not add schema columns');
        } else {
            $profile=$pdo->query('SELECT * FROM PaymentProviders')->fetchAll();
            $check(count($profile)===1,'one shared profile');
            $p=$profile[0]; $created=$stage==='created';
            $check($p['ppState']===($created?'enabled':'retired') && (int)$p['ppRevision']===($created?1:5),'real create/settings/rotate/disable/retire once each');
            $check((int)$p['ppCurrentKeyVersion']===($created?1:2),'rotation advanced shared key once');
            $check((int)$pdo->query('SELECT COUNT(*) FROM PaymentProviderCredentials')->fetchColumn()===($created?1:2),'old encrypted key retained');
            $repo=new \HScript\Payment\Provider\PawProviderRepository($db);
            $check($repo->key((int)$p['ppID'],1)==='sk_sandbox_HTTP_KEY_CANARY_first','initial key recoverable');
            if (!$created) $check($repo->key((int)$p['ppID'],2)==='sk_sandbox_HTTP_KEY_CANARY_rotated','rotated key recoverable');
            $check((int)$pdo->query("SELECT COUNT(*) FROM Currs WHERE cCID='PAW' AND cProviderID IS NOT NULL")->fetchColumn()===2,'exactly two fiat children retained');
            $check((int)$pdo->query("SELECT COUNT(*) FROM Currs WHERE cCID='PAW' AND (cDisabled<>".($created?0:1)." OR cCASHINMode<>0 OR cCASHOUTMode<>0 OR cProviderSelected<>".($created?1:0).')')->fetchColumn()===0,'native-off gate and fiat selection preserved');
            $check((int)$pdo->query("SELECT COUNT(*) FROM Currs WHERE cCID='PAW' AND (COALESCE(cParams,'')<>'' OR COALESCE(cParamsSCI,'')<>'' OR COALESCE(cParamsAPI,'')<>'')")->fetchColumn()===0,'no copied key in legacy currency fields');
        }
        if ($stage!=='created') $check(hash_equals(trim((string)file_get_contents('/tmp/paw-http-history.sha256')),$projection()),'management, blocked routes and restart preserve financial history, wallets, balances, identities and pending jobs');
        echo "PAW common setup HTTP SQL checks passed ($stage).\n"; exit;
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

namespace HScript\Payment\Provider {
    // No native fallback, URL injection or runtime configuration hook exists.
    function curl_init(): object { return (object)['options'=>[]]; }
    function curl_setopt_array(object $handle,array $options): bool { $handle->options=$options; return true; }
    function curl_exec(object $handle): bool {
        $o=$handle->options; $url=$o[CURLOPT_URL] ?? '';
        if (($o[CURLOPT_HTTPGET] ?? false)!==true || ($o[CURLOPT_SSL_VERIFYPEER] ?? false)!==true
            || ($o[CURLOPT_SSL_VERIFYHOST] ?? 0)!==2 || ($o[CURLOPT_FOLLOWLOCATION] ?? true)!==false
            || ($o[CURLOPT_MAXREDIRS] ?? -1)!==0 || ($o[CURLOPT_PROTOCOLS] ?? 0)!==CURLPROTO_HTTPS
            || ($o[CURLOPT_TIMEOUT] ?? 99)>5 || ($o[CURLOPT_CONNECTTIMEOUT] ?? 99)>2)
            throw new \RuntimeException('fixture_transport_rejected');
        $headers=$o[CURLOPT_HTTPHEADER] ?? [];
        if (!in_array('x-api-key: sk_sandbox_HTTP_KEY_CANARY_first',$headers,true)
            && !in_array('x-api-key: sk_sandbox_HTTP_KEY_CANARY_rotated',$headers,true))
            throw new \RuntimeException('fixture_credentials_rejected');
        if ($url==='https://api.pawpayments.com/api/v2/invoices?page=1&per_page=1') $result=[];
        elseif (preg_match('~^https://api\.pawpayments\.com/api/v2/rates\?base=(USD|EUR)$~D',$url,$match))
            $result=['base'=>$match[1],'rates'=>['btc'=>'60000.01']];
        else throw new \RuntimeException('fixture_endpoint_rejected');
        file_put_contents('/tmp/paw-http-wire.jsonl',json_encode(['path'=>substr($url,strlen('https://api.pawpayments.com'))],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
        $body=json_encode(['ok'=>true,'result'=>$result]+(str_contains($url,'/invoices?') ? ['pagination'=>['page'=>1,'per_page'=>1,'total'=>0,'pages'=>0]] : []),JSON_THROW_ON_ERROR);
        return ($o[CURLOPT_WRITEFUNCTION])($handle,$body)===strlen($body);
    }
    function curl_getinfo(object $handle,int $option): int|string { return $option===CURLINFO_HTTP_CODE ? 200 : 'application/json'; }
    function curl_close(object $handle): void {}
}

namespace HScript\Payment {
    // This namespace is reachable only after the private fixture/environment guards.
    function curl_init():object{return(object)['options'=>[]];}
    function curl_setopt_array(object $handle,array $options):bool{$handle->options=$options;return true;}
    function curl_exec(object $handle):bool {
        $o=$handle->options;
        if(($o[CURLOPT_URL]??null)!=='https://api.pawpayments.com/api/v2/invoices' || ($o[CURLOPT_POST]??null)!==true
            || ($o[CURLOPT_FOLLOWLOCATION]??true)!==false || ($o[CURLOPT_SSL_VERIFYPEER]??null)!==true || ($o[CURLOPT_SSL_VERIFYHOST]??null)!==2
            || ($o[CURLOPT_TIMEOUT_MS]??99999)>7000 || ($o[CURLOPT_CONNECTTIMEOUT_MS]??99999)>2000 || ($o[CURLOPT_PROTOCOLS]??0)!==CURLPROTO_HTTPS)
            throw new \RuntimeException('fixture_native_transport_rejected');
        if(!in_array('x-api-key: sk_sandbox_HTTP_KEY_CANARY_first',$o[CURLOPT_HTTPHEADER]??[],true))throw new \RuntimeException('fixture_native_credentials_rejected');
        $f=json_decode($o[CURLOPT_POSTFIELDS]??'',true,8,JSON_THROW_ON_ERROR);
        if(($f['billing_type']??null)!=='VARY' || ($f['fee_bearer']??null)!=='client' || ($f['webhook_format']??null)!=='native_v2' || isset($f['asset'])
            || !in_array($f['fiat_currency']??null,['USD','EUR'],true) || !is_string($f['amount']??null))throw new \RuntimeException('fixture_native_request_rejected');
        file_put_contents('/tmp/paw-native-http-wire.jsonl',json_encode(['operation'=>$f['extra'],'currency'=>$f['fiat_currency']],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
        if(($_SERVER['HTTP_X_FIXTURE_PAW_FAIL']??'')==='1')return false;
        $invoice=['order_id'=>substr(hash('sha256','paw-http:'.$f['extra']),0,24),'status'=>'created','sandbox'=>true,
            'fiat_currency'=>$f['fiat_currency'],'fiat_amount'=>$f['amount'],'billing_type'=>'VARY','fee_bearer'=>'client','extra'=>$f['extra'],'metadata'=>$f['metadata'],
            'created_at'=>(string)time(),'expires_at'=>(string)(time()+3600)];
        header('X-Fixture-Paw-Invoice: '.base64_encode(json_encode(['invoice'=>$invoice,'callback'=>$f['notify_url']??('http://fixture.invalid/balance/status?its=PAW&provider='.$f['metadata']['provider'].'&version='.$f['metadata']['version'])],JSON_THROW_ON_ERROR)));
        $body=json_encode(['ok'=>true,'result'=>$invoice],JSON_THROW_ON_ERROR);return ($o[CURLOPT_WRITEFUNCTION])($handle,$body)===strlen($body);
    }
    function curl_getinfo(object $handle,int $option):int|string{return $option===CURLINFO_RESPONSE_CODE?201:'application/json';}
    function curl_close(object $handle):void{}
}
