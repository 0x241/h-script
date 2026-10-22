<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('H_SCRIPT_FINANCIAL_TEST') !== '1' || getenv('DB_NAME') !== 'fixture') exit(2);
require dirname(__DIR__,2).'/vendor/autoload.php';
$db=new HScript\Database\Connection();if (!$db->open(getenv('DB_HOST'),'fixture',getenv('DB_USER'),getenv('DB_PASSWORD'))) exit(1);
$row=$db->fetch1Row($db->select('Currs','*','cID=1'));
foreach (['cCASHINMin'=>'-0.000001','cCASHOUTComis'=>'9999.99','cEXIn'=>'-9999.999999','cEXOut'=>'0.000001','cCASHOUTLimit'=>'9999999.999999'] as $field=>$value) if ($row[$field]!==$value) throw new RuntimeException($field);
$calendar=$db->link->query('SELECT cType,cPerc FROM Calend')->fetchAll(PDO::FETCH_ASSOC);
if (count($calendar)!==1 || (int)$calendar[0]['cType']!==1 || $calendar[0]['cPerc']!=='99999.99') throw new RuntimeException('Calendar exact SQL');
$all=[];foreach (['Currs','Calend','Plans','Wallets','Deps','Opers','Cfg','Jobs','IdempotencyKeys'] as $table) {$rows=array_map('serialize',$db->link->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC));sort($rows);$all[$table]=$rows;}
$hash=hash('sha256',serialize($all));$path='/tmp/decimal-configuration-fingerprint';
if (($argv[1]??'')==='replay') {if (file_get_contents($path)!==$hash) throw new RuntimeException('Read/restart changed financial/config/queue rows');}
else {file_put_contents($path,$hash);chmod($path,0600);}
echo "Exact currency/calendar native HTTP storage and restart fingerprint passed.\n";
