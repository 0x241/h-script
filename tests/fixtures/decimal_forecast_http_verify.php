<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('H_SCRIPT_FINANCIAL_TEST') !== '1' || getenv('DB_NAME') !== 'fixture') exit(2);
require_once dirname(__DIR__,2).'/vendor/autoload.php';
if (!isset($db)) {
    $db=new HScript\Database\Connection();
    if (!$db->open(getenv('DB_HOST'),'fixture',getenv('DB_USER'),getenv('DB_PASSWORD'))) exit(1);
}
$all=['user_money'=>$db->link->query('SELECT uID,uBal FROM Users ORDER BY uID')->fetchAll(PDO::FETCH_ASSOC)];
foreach (['Wallets','Opers','Deps','Hist','IdempotencyKeys','Jobs','Plans','Currs'] as $table) {
    $rows=array_map('serialize',$db->link->query('SELECT * FROM '.$table.($table==='Hist' ? " WHERE hOper NOT IN ('LOGIN','LOGOUT')" : ''))->fetchAll(PDO::FETCH_ASSOC));sort($rows);$all[$table]=$rows;
}
$hash=hash('sha256',serialize($all));$path='/tmp/decimal-forecast-financial-snapshot';
if (basename($_SERVER['SCRIPT_FILENAME'])==='decimal_forecast_http_setup.php') {
    file_put_contents($path,$hash);chmod($path,0600);
} elseif (file_get_contents($path)!==$hash) throw new RuntimeException('Read forecast rewrote monetary rows/plans/currencies/history/receipts/queue');
echo "Forecast monetary projections unchanged; authentication metadata and LOGIN/LOGOUT audit events excluded.\n";
