<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('H_SCRIPT_FINANCIAL_TEST') !== '1' || getenv('DB_NAME') !== 'fixture') exit(2);
require dirname(__DIR__,2).'/vendor/autoload.php';
$db=new HScript\Database\Connection();
if (!$db->open(getenv('DB_HOST'),'fixture',getenv('DB_USER'),getenv('DB_PASSWORD'))) exit(1);
$salt=time();
$uid=(int)$db->fetch1($db->query("SELECT uID FROM Users WHERE uLogin='financial-user'"));
if ($uid<1) throw new RuntimeException('Synthetic financial owner missing');
$gateways=['EA'=>'ETH','EAT'=>'USDT','EAT1'=>'DAI','BSC'=>'BNB','BSCT'=>'USDT','XRPA'=>'XRP','TRA'=>'TRX','TRAT'=>'USDT','ACR'=>'RUB','PKAU'=>'USD','PKAR'=>'RUB'];
foreach ($gateways as $offset=> $asset) {
    $cid=10+array_search($offset,array_keys($gateways),true);
    $db->insert('Currs',['cID'=>$cid,'cDisabled'=>0,'cHidden'=>0,'cCID'=>$offset,'cName'=>'Synthetic '.$offset,
        'cCurrID'=>$asset,'cNumDec'=>2,'cMTS'=>timeToStamp($salt),'cCASHINMode'=>2,'cCASHOUTMode'=>2,
        'cParamsSCI'=>encodeArrayToStr(['key'=>'synthetic-policy-key','apipass'=>'synthetic-policy-key','id'=>'122'],$cid.$offset.$salt,2)]);
    // Deliberately stale automation flags and funded wallet must not authorize
    // unsupported provider dispatch or a callback financial stage.
    $db->insert('Wallets',['wuID'=>$uid,'wcID'=>$cid,'wBal'=>'1000','wMTS'=>timeToStamp($salt),
        'wParams'=>encodeArrayToStr(['acc'=>'synthetic-policy-destination'],$cid.$uid.$salt)]);
}
$db->insert('Opers',['oID'=>42,'ouID'=>$uid,'ocID'=>2,'oOper'=>'CASHIN','oState'=>0,'oSum'=>'100',
    'oCTS'=>timeToStamp(time()),'oComis'=>'0','oParams2'=>arrayToStr(['memo'=>'Synthetic policy original order'])]);
// Remove the explicit UTC in the common positive fixture for this negative
// policy case. No callback may infer a zone from server/user settings.
$wm=$db->fetch1Row($db->query('SELECT * FROM Currs WHERE cID=4'));
$wmEpoch=stampToTime($wm['cMTS']);
$db->update('Currs',['cParamsSCI'=>encodeArrayToStr(['key'=>'synthetic-webmoney-secret'],'4WM'.$wmEpoch,2)],'','cID=?d',[4]);
$projection=[];
foreach (['Currs','Wallets','Opers','Hist','Deps','Jobs'] as $table)
    $projection[$table]=$db->fetchRows($db->query('SELECT * FROM '.$table));
$projection['Users']=$db->fetchRows($db->query('SELECT uID,uBal FROM Users ORDER BY uID'));
$projection['gateway_keys']=$db->fetchRows($db->query("SELECT * FROM IdempotencyKeys WHERE ikNamespace LIKE 'gateway:%' OR ikNamespace='invoice:attempt'"));
file_put_contents('/tmp/gateway-policy-baseline.json',json_encode($projection,JSON_THROW_ON_ERROR));
chmod('/tmp/gateway-policy-baseline.json',0600);
echo "Synthetic callback policy HTTP fixtures ready.\n";
