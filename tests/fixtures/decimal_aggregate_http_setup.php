<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('H_SCRIPT_FINANCIAL_TEST') !== '1' || getenv('DB_NAME') !== 'fixture') exit(2);
require dirname(__DIR__,2).'/vendor/autoload.php';
$db=new HScript\Database\Connection();
if (!$db->open(getenv('DB_HOST'),'fixture',getenv('DB_USER'),getenv('DB_PASSWORD'))) exit(1);
$fixture=json_decode(file_get_contents(__DIR__.'/decimal_aggregate_cases.json'),true,32,JSON_THROW_ON_ERROR);
foreach (['Wallets','Opers','Deps','Hist','IdempotencyKeys','Jobs'] as $table) $db->link->exec('DELETE FROM '.$table);
$db->update('Currs',['cNumDec'=>6],'','1=1');
$db->replace('Cfg',['Module'=>'UI','Prop'=>'NumDec','Val'=>6]);
// Login normally queues an AdminIn notice; disable its recipient only in this synthetic read fixture.
$db->replace('Cfg',['Module'=>'Sys','Prop'=>'AdminMail','Val'=>'']);
$db->update('Currs',['cCurrID'=>'EUR'],'','cID=2');
$db->update('Currs',['cCurrID'=>'BTC'],'','cID=3');
$db->update('Currs',['cDisabled'=>1,'cName'=>'Disabled historical USD'],'','cID=4');
$uid=$db->link->query("SELECT uID FROM Users WHERE uLogin='financial-user'")->fetchColumn();
$insert=$db->link->prepare('INSERT INTO Opers (ouID,ocID,oOper,oState,oSum,oComis,oMemo,oCTS) VALUES (?,?,?,?,?,?,?,?)');
foreach ($fixture['operations'] as $row) $insert->execute([$uid,...array_values($row)]);
$insert=$db->link->prepare('INSERT INTO Deps (duID,dcID,dpID,dState,dZD,dCTS,dLTS,dNTS) VALUES (?,4,1,1,?,20250101000000,20250101000000,20990101000000)');
for ($i=0;$i<1001;$i++) $insert->execute([$uid,'9999999.999999']);
$db->insert('Deps',['duID'=>$uid,'dcID'=>4,'dpID'=>1,'dState'=>2,'dZD'=>'1.000001','dCTS'=>'20250101000000']);
$db->insert('Wallets',['wuID'=>$uid,'wcID'=>2,'wBal'=>'1.005001','wLock'=>'0.000001','wOut'=>'0.000002']);
$db->insert('Wallets',['wuID'=>$uid,'wcID'=>4,'wBal'=>'2.005001','wLock'=>'0.000002','wOut'=>'0.000003']);
require __DIR__.'/decimal_aggregate_http_verify.php';
