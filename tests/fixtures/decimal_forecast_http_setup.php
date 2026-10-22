<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('H_SCRIPT_FINANCIAL_TEST') !== '1' || getenv('DB_NAME') !== 'fixture') exit(2);
require dirname(__DIR__,2).'/vendor/autoload.php';
$db=new HScript\Database\Connection();
if (!$db->open(getenv('DB_HOST'),'fixture',getenv('DB_USER'),getenv('DB_PASSWORD'))) exit(1);
$db->update('Plans',['pHidden'=>1],'','1=1');
$db->insert('Plans',['pID'=>10,'pName'=>'{!en!}<img src=x onerror=alert(1)> Exact forecast{!ru!}Точный прогноз',
    'pMin'=>'1.005001','pMax'=>'9999999.999999','pBonus'=>'1.23','pPerc'=>'1.23','pNPer'=>7,'pReturn'=>50,'pDays'=>7,'pPer'=>24]);
$db->insert('Plans',['pID'=>11,'pName'=>'Limited forecast','pMin'=>2,'pMax'=>10000,'pPerc'=>1,'pNPer'=>10001,'pReturn'=>100,'pPer'=>24]);
$db->replace('Cfg',['Module'=>'Depo','Prop'=>'_Compnd','Val'=>"0\r\n33.330000000000000001\r\n33.33\r\n100"]);
$db->update('Currs',['cNumDec'=>6],'','cID=2');
$uid=$db->link->query("SELECT uID FROM Users WHERE uLogin='financial-user'")->fetchColumn();
$db->insert('Deps',['dID'=>10,'duID'=>$uid,'dcID'=>2,'dpID'=>10,'dState'=>1,'dZD'=>'1234.000001',
    'dCTS'=>timeToStamp(time()-3600),'dLTS'=>timeToStamp(time()-3600),'dNTS'=>timeToStamp(time()+82800)]);
require __DIR__.'/decimal_forecast_http_verify.php';
