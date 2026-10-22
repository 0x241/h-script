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
foreach (['Wallets','Opers','Deps','Hist','IdempotencyKeys','Jobs','Plans','Currs','Cfg','PaymentProviders','PaymentProviderCredentials'] as $table) {
    $rows=array_map('serialize',$db->link->query('SELECT * FROM '.$table.($table==='Hist' ? " WHERE hOper NOT IN ('LOGIN','LOGOUT')" : ''))->fetchAll(PDO::FETCH_ASSOC));sort($rows);$all[$table]=$rows;
}
$hash=array_map(static fn($rows)=>hash('sha256',serialize($rows)),$all);
$hash['cfg_properties']=[];
foreach ($db->link->query('SELECT Module,Prop,Val FROM Cfg')->fetchAll(PDO::FETCH_ASSOC) as $row) $hash['cfg_properties'][$row['Module'].'_'.$row['Prop']]=hash('sha256',serialize($row));
ksort($hash['cfg_properties']);$path='/tmp/decimal-aggregate-financial-snapshot';
if (basename($_SERVER['SCRIPT_FILENAME'])==='decimal_aggregate_http_setup.php') {
    file_put_contents($path,json_encode($hash,JSON_THROW_ON_ERROR));chmod($path,0600);
} else {
    $before=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    $changed=[];
    foreach ($hash as $name=>$value) if (($before[$name] ?? null)!==$value) $changed[]=$name;
    if ($changed) {
        $cfg=[];foreach (array_unique(array_merge(array_keys($before['cfg_properties'] ?? []),array_keys($hash['cfg_properties']))) as $key)
            if (($before['cfg_properties'][$key] ?? null)!==($hash['cfg_properties'][$key] ?? null)) $cfg[]=$key;
        throw new RuntimeException('Read aggregate projection differs: '.json_encode(['tables'=>$changed,'cfg_properties'=>$cfg],JSON_THROW_ON_ERROR));
    }
}
echo "Aggregate monetary projections unchanged; authentication metadata and LOGIN/LOGOUT audit events excluded.\n";
