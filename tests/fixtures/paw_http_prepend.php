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
        if (!$privateFixture || !$explicitFixture || !in_array($argv[1] ?? '', ['--setup','--verify','--history','--schema-down'], true)) exit(64);
        require '/var/www/html/vendor/autoload.php';
        $db = new \HScript\Database\Connection();
        if (!$db->open(getenv('DB_HOST'),'fixture',getenv('DB_USER'),getenv('DB_PASSWORD'))) exit(1);
        $pdo = $db->link;
        $check = static function(bool $ok,string $message): void { if (!$ok) throw new \RuntimeException($message); };
        if ($argv[1] === '--setup') {
            foreach ([89,90] as $level) {
                $id=$db->insert('Users',['uLogin'=>'paw-review-'.$level,'uPass'=>hashPassword('synthetic-financial-password'),
                    'uMail'=>'paw-review-'.$level.'@fixture.invalid','uState'=>1,'uLevel'=>$level,'uLang'=>'en','uTheme'=>'light']);
                $db->insert('AddInfo',['auID'=>$id,'aNeedReConfig'=>0]);
            }
            echo "Synthetic PAW HTTP administrator roles ready; no provider profile seeded.\n"; exit;
        }
        $projection=static function() use ($pdo): string {
            $data=['users'=>$pdo->query('SELECT uID,uBal FROM Users ORDER BY uID')->fetchAll()];
            foreach (['Wallets'=>'wcID','Opers'=>'ocID','Hist'=>'hcID'] as $table=>$column)
                $data[$table]=$pdo->query("SELECT * FROM $table WHERE $column IN (SELECT cID FROM Currs WHERE cCID='PAW') ORDER BY 1,2")->fetchAll();
            $data['keys']=$pdo->query("SELECT * FROM IdempotencyKeys WHERE ikNamespace LIKE 'gateway:paw:%' ORDER BY ikID")->fetchAll();
            $data['jobs']=$pdo->query("SELECT * FROM Jobs WHERE jType='synthetic.paw.pending' ORDER BY jID")->fetchAll();
            return hash('sha256',serialize($data));
        };
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
            // This destructive fixture is confined to the disposable database.
            $pdo->exec('ALTER TABLE Currs DROP FOREIGN KEY fk_currency_provider_key');
            $pdo->exec('ALTER TABLE Currs DROP FOREIGN KEY fk_currency_provider');
            $drop=stripos((string)$pdo->getAttribute(\PDO::ATTR_SERVER_VERSION),'MariaDB')!==false ? 'DROP CONSTRAINT' : 'DROP CHECK';
            $pdo->exec("ALTER TABLE Currs $drop chk_currency_provider, $drop chk_paw_configuration_only");
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
        $stage=$argv[2] ?? '';
        if (!in_array($stage,['created','managed','restart','schema'],true)) exit(64);
        $wire=array_map(static fn($line)=>json_decode($line,true,8,JSON_THROW_ON_ERROR),file('/tmp/paw-http-wire.jsonl',FILE_IGNORE_NEW_LINES) ?: []);
        $check(count($wire)===($stage==='created'?3:6),'only two explicit save POSTs validate PAW; GET/cooldown/CSRF/replay perform no provider requests');
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
            $check((int)$pdo->query("SELECT COUNT(*) FROM Currs WHERE cCID='PAW' AND (cDisabled<>1 OR cCASHINMode<>0 OR cCASHOUTMode<>0 OR cProviderSelected<>".($created?1:0).')')->fetchColumn()===0,'native-off gate and fiat selection preserved');
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
