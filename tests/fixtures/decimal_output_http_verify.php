<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('H_SCRIPT_FINANCIAL_TEST')!=='1' || getenv('DB_NAME')!=='fixture') exit(2);
require dirname(__DIR__,2).'/vendor/autoload.php';
$db=new HScript\Database\Connection();
if (!$db->open(getenv('DB_HOST'),'fixture',getenv('DB_USER'),getenv('DB_PASSWORD'))) exit(1);
$pdo=$db->link;$uid=$pdo->query("SELECT uID FROM Users WHERE uLogin='decimal-output-user'")->fetchColumn();
if ((int)$pdo->query("SELECT COUNT(*) FROM Opers WHERE ouID=$uid")->fetchColumn()!==2) throw new RuntimeException('Output write duplicated');
$responses=$pdo->query("SELECT ikResponse FROM IdempotencyKeys WHERE ikOwnerID=$uid AND ikNamespace='rest:deposit' ORDER BY ikID")->fetchAll(PDO::FETCH_COLUMN);
if (count($responses)!==2) throw new RuntimeException('Output receipts changed');
$old=json_decode($responses[0],true,512,JSON_THROW_ON_ERROR)['data']['operation'];
$new=json_decode($responses[1],true,512,JSON_THROW_ON_ERROR)['data']['operation'];
if (!is_float($old['amount']) || $old['amount']!==25.0 || $new['amount']!=='1.010000' || $new['fee']!=='0.060000' || $new['net_amount']!=='0.950000') throw new RuntimeException('Stored original receipt type/precision lost');
$all=[];
foreach (['Users','AddInfo','Wallets','Opers','Hist','Deps','IdempotencyKeys','Jobs'] as $table) {
    $rows=array_map('serialize',$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC));sort($rows);$all[$table]=$rows;
}
$hash=hash('sha256',serialize($all));$path='/tmp/decimal-output-financial-snapshot';
if (($argv[1]??'')==='replay') {
    if (file_get_contents($path)!==$hash) throw new RuntimeException('Replay rewrote financial projections or original snapshot bytes');
} else { file_put_contents($path,$hash);chmod($path,0600); }
echo "Output SQL projections, receipt types and snapshot bytes verified.\n";
