<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('H_SCRIPT_FINANCIAL_TEST')!=='1' || getenv('DB_NAME')!=='fixture') exit(2);
require dirname(__DIR__,2).'/vendor/autoload.php';
$db=new HScript\Database\Connection();if (!$db->open(getenv('DB_HOST'),'fixture',getenv('DB_USER'),getenv('DB_PASSWORD'))) exit(1);
$all=[];
foreach (['Currs','Plans','Wallets','Deps','Opers','Cfg','Jobs','IdempotencyKeys','Installations','InstallationReports','InstallationIpHistory','InstallationDomainEvents'] as $table) {
    $rows=array_map('serialize',$db->link->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC));sort($rows);$all[$table]=$rows;
}
$hash=hash('sha256',serialize($all));$path='/tmp/decimal-closure-fingerprint';
if (($argv[1]??'')==='replay') { if (file_get_contents($path)!==$hash) throw new RuntimeException('Read/restart changed financial/public/config/receipt/queue rows'); }
else { file_put_contents($path,$hash);chmod($path,0600); }
echo "Read-only financial/public native HTTP fingerprint passed.\n";
