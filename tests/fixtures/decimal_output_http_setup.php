<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('H_SCRIPT_FINANCIAL_TEST')!=='1' || getenv('DB_NAME')!=='fixture') exit(2);
require dirname(__DIR__,2).'/vendor/autoload.php';
$db=new HScript\Database\Connection();
if (!$db->open(getenv('DB_HOST'),'fixture',getenv('DB_USER'),getenv('DB_PASSWORD'))) exit(1);
if (($argv[1]??'')==='drift') {
    $db->update('Currs',['cNumDec'=>8,'cCASHINComis'=>99,'cCASHINMin'=>1000],'','cID=?d',[5]);
    $db->replace('Cfg',['Module'=>'UI','Prop'=>'NumDec','Val'=>8]);
    echo "Synthetic output policy drift applied.\n";exit;
}
$uid=$db->insert('Users',['uLogin'=>'decimal-output-user','uState'=>1,'uMail'=>'output@fixture.invalid']);
$db->insert('AddInfo',['auID'=>$uid]);
$db->insert('ApiTokens',['atuID'=>$uid,'atName'=>'Synthetic output token','atTokenHash'=>HScript\Http\ApiTokenRepository::hash('hs_'.str_repeat('3',64)),'atScopes'=>'*','atState'=>1,'atCreatedAt'=>time()]);
$db->insert('Currs',['cID'=>5,'cDisabled'=>0,'cHidden'=>0,'cCID'=>'BW','cName'=>'Synthetic output USD','cCurrID'=>'USD','cNumDec'=>2,'cCASHINMode'=>1,'cCASHOUTMode'=>1,
    'cCASHINMin'=>'0.000001','cCASHINMax'=>'9999999.999999','cCASHINComis'=>'5.55','cCASHINComisMin'=>'0.000001','cCASHINComisMax'=>'9999999.999999']);
// Synthetic historical read projections deliberately need no economic reconciliation.
$db->insert('Wallets',['wuID'=>$uid,'wcID'=>5,'wBal'=>'9999999.999999','wLock'=>'9999999.999998','wOut'=>'0.000001']);
$db->insert('Opers',['ouID'=>$uid,'ocID'=>5,'oOper'=>'BONUS','oState'=>3,'oSum'=>'0.000001','oComis'=>'0.000002','oMemo'=>'Synthetic old six-place row']);
$body=['currency_id'=>5,'amount'=>'25','memo'=>'Synthetic legacy numeric receipt','plan_id'=>0,'compound_percent'=>'0'];
(new HScript\Finance\IdempotencyStore($db))->execute('rest:deposit','output-legacy', $uid,HScript\Finance\IdempotencyRequest::fingerprint($body),
    fn()=>new HScript\Finance\IdempotencyResult(['operation'=>['id'=>987654,'amount'=>25.0,'fee'=>0.0,'net_amount'=>25.0,'state'=>'awaiting_confirmation']],201));
echo "Synthetic decimal output HTTP fixtures ready; no economic-state acceptance claim.\n";
