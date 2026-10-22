<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('H_SCRIPT_FINANCIAL_TEST') !== '1' || getenv('DB_NAME') !== 'fixture') exit(2);
require dirname(__DIR__,2) . '/vendor/autoload.php';
$db=new HScript\Database\Connection();
if (!$db->open(getenv('DB_HOST'),'fixture',getenv('DB_USER'),getenv('DB_PASSWORD'))) exit(1);
$pdo=$db->link;
if (($argv[1] ?? '')==='reconfigure') {
    $db->update('Currs',['cDisabled'=>0,'cHidden'=>0],'','cID=?d',[1]);
    foreach (['Const'=>['IntCurr'=>1],'Bal'=>['SumInInternal'=>1,'RateBTC'=>2]] as $module=>$properties)
        foreach ($properties as $property=>$value) $db->replace('Cfg',['Module'=>$module,'Prop'=>$property,'Val'=>$value]);
    echo "Synthetic configuration changed for persisted form replay.\n"; exit;
}
$expected=[
    "SELECT COUNT(*) FROM Opers"=>17,
    "SELECT COUNT(*) FROM Opers WHERE oOper='CASHOUT'"=>1,
    "SELECT COUNT(*) FROM Jobs WHERE jType='financial.withdraw'"=>1,
    "SELECT COUNT(*) FROM IdempotencyKeys WHERE ikState='pending'"=>0,
    "SELECT COUNT(*) FROM Opers WHERE oState=3"=>14,
    "SELECT dNPer FROM Deps WHERE duID=5"=>1,
];
foreach ($expected as $sql=>$value) if ((int)$pdo->query($sql)->fetchColumn()!==$value) throw new RuntimeException('Financial HTTP effect count mismatch: '.$sql);
$wallets=$pdo->query('SELECT wuID,wBal,wOut FROM Wallets WHERE wcID=2 ORDER BY wuID')->fetchAll();
if ($wallets!==[['wuID'=>2,'wBal'=>'947.000000','wOut'=>'25.000000'],['wuID'=>3,'wBal'=>'1013.000000','wOut'=>'0.000000'],['wuID'=>5,'wBal'=>'102.000000','wOut'=>'0.000000']]) throw new RuntimeException('Financial HTTP wallet mismatch');
if ($pdo->query('SELECT wBal FROM Wallets WHERE wuID=2 AND wcID=3')->fetchColumn()!=='50.000000') throw new RuntimeException('YooMoney HTTP gross amount mismatch');
if ($pdo->query('SELECT wBal FROM Wallets WHERE wuID=2 AND wcID=4')->fetchColumn()!=='25.000000') throw new RuntimeException('WebMoney HTTP original amount mismatch');
$report=(new HScript\Finance\FinancialReconciler())->run($pdo);
foreach ($report['checks'] as $name=>$check) if ($name!=='balance_history_incomplete' && $check['count']!==0) throw new RuntimeException('Financial HTTP reconciliation failed: '.$name);
echo "Financial HTTP effects and reconciliation verified.\n";
