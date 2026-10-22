<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('H_SCRIPT_FINANCIAL_TEST') !== '1' || getenv('DB_NAME') !== 'fixture') exit(2);
require dirname(__DIR__,2).'/vendor/autoload.php';
$db=new HScript\Database\Connection();
if (!$db->open(getenv('DB_HOST'),'fixture',getenv('DB_USER'),getenv('DB_PASSWORD'))) exit(1);
$row=$db->fetch1Row($db->select('Plans','*',"pName='Decimal native form'"));
foreach (['pMin'=>'0.010001','pMax'=>'1000.000000','pPerc'=>'1.23','pReturn'=>'-1.00','pBonus'=>'-0.01','pMinAdd'=>'-0.000001','pCompndMin'=>'33.33','pCompndMax'=>'33.33','pClComis'=>'100.00'] as $field=>$expected)
    if (($row[$field]??null)!==$expected) throw new RuntimeException('Native plan exact SQL '.$field);
if ((int)$db->fetch1($db->select('Plans','COUNT(*)',"pName='Decimal native form'"))!==1) throw new RuntimeException('Invalid native requests created a plan');
if ($db->fetch1($db->select('Plans','pmnPerc','pID=2'))!=='2.50') throw new RuntimeException('Manual stored percentage');
if ($db->fetch1($db->select('Wallets','wBal','wuID=5 AND wcID=2'))!=='102.500000') throw new RuntimeException('Exactly one manual accrual');
if ((int)$db->fetch1($db->select('IdempotencyKeys','COUNT(*)',"ikNamespace IN ('web:admin:depo:charge','accrual:manual')"))!==2) throw new RuntimeException('Manual source and child receipts');
$all=[];
foreach (['Plans','Wallets','Deps','Opers','Cfg','Jobs','IdempotencyKeys'] as $table) {
    $rows=array_map('serialize',$db->link->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC));sort($rows);$all[$table]=$rows;
}
$fingerprint=hash('sha256',serialize($all));$path='/tmp/decimal-plan-http-fingerprint';
if (($argv[1]??'')==='replay') { if (file_get_contents($path)!==$fingerprint) throw new RuntimeException('Restart replay modified financial/configuration/queue/receipt rows'); }
else { file_put_contents($path,$fingerprint);chmod($path,0600); }
echo "Exact native plan/manual receipt and restart fingerprint passed.\n";
