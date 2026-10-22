<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use HScript\Finance\FinancialAuditSnapshot;
use HScript\Finance\FinancialReconciler;

if (getenv('H_SCRIPT_FINANCIAL_TEST') !== '1' || getenv('DB_NAME') !== 'fixture') exit(2);
require dirname(__DIR__).'/vendor/autoload.php';
$pdo=new PDO('mysql:host=database;dbname=fixture;charset=utf8mb4',getenv('DB_USER'),getenv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
foreach (FinancialAuditSnapshot::COLUMNS as $table=>$columns) $pdo->exec('DELETE FROM '.$table);
$pdo->exec("INSERT INTO Users (uID,uLogin,uMail) VALUES (1,'snapshot-1','snapshot-1@fixture.invalid'),(2,'snapshot-2','snapshot-2@fixture.invalid')");
$pdo->exec("INSERT INTO Currs (cID,cCID,cCurrID,cNumDec) VALUES (1,'BW','USD',2)");
$cases=[
    'provisional_batch'=>"INSERT INTO Opers (oID,ouID,oOper,ocID,oSum,oState,oBatch,oParams) VALUES (10,1,'TR',1,1,3,'?PRIVATE_SOURCE','{\"uid2\":2}'),(11,2,'TRIN',1,1,3,'?PRIVATE_CHILD','{\"oid\":10}')",
    'json_scalar'=>"INSERT INTO Opers (oID,ouID,oOper,ocID,oSum,oState,oBatch,oParams) VALUES (10,1,'TR',1,1,3,'scalar','true'),(12,1,'TR',1,1,3,'scalar-null','null'),(13,1,'EX',1,1,3,'array','[]')",
    'numeric_prefix'=>"INSERT INTO Opers (oID,ouID,oOper,ocID,oSum,oState,oBatch,oParams) VALUES (10,1,'TR',1,1,3,'prefix','{\"uid2\":\"2PRIVATE\"}'),(11,2,'TRIN',1,1,3,'prefix','{\"oid\":\"10PRIVATE\"}')",
    'unrelated_legacy'=>"INSERT INTO Opers (oID,ouID,oOper,ocID,oSum,oState,oBatch,oParams) VALUES (10,1,'TR',1,1,3,'','{\"uid2\":2}'),(11,1,'TRIN',1,999,3,'','serialized_PRIVATE')",
    'case_accent_padding'=>"INSERT INTO Opers (oID,ouID,oOper,ocID,oSum,oState,oBatch,oParams) VALUES (10,1,'CASHIN',1,1,3,'PRIVATÉ','{}'),(11,2,'CASHIN',1,1,3,'private','{}'),(12,1,'CASHIN',1,1,3,'private ','{}'),(13,1,'CASHIN',1,1,3,'- ','{}'),(14,1,'CASHIN',1,1,3,' ','{}')",
    'decimal_quote'=>"INSERT INTO Opers (oID,ouID,oOper,ocID,oSum,oState,oBatch,oParams) VALUES (10,1,'EX',1,1,3,'quote','{\"cid2\":\"1PRIVATE\",\"sum2\":\"1.1234567PRIVATE\"}'),(11,1,'EXIN',1,1.123457,3,'quote','{\"oid\":10}')",
];
$assertions=0;
$collations=$pdo->query("SELECT COLLATION_NAME,CHARACTER_SET_NAME FROM information_schema.COLLATIONS WHERE COLLATION_NAME IN ('utf8mb4_unicode_ci','utf8mb4_0900_ai_ci','utf8mb4_unicode_nopad_ci','utf8mb4_bin','latin1_swedish_ci') ORDER BY COLLATION_NAME")->fetchAll(PDO::FETCH_KEY_PAIR);
foreach ($collations as $collation=>$characterSet) {
    $pdo->exec('ALTER TABLE Opers MODIFY oBatch varchar(40) CHARACTER SET '.$characterSet.' COLLATE '.$collation);
    foreach ($cases as $name=>$sql) {
        $pdo->exec('DELETE FROM Opers'); $pdo->exec($sql);
        $snapshot=(new FinancialAuditSnapshot())->capture($pdo);
        if (str_contains(json_encode($snapshot,JSON_THROW_ON_ERROR),'PRIVATE')) throw new RuntimeException('snapshot_privacy_'.$name);
        if ($name==='unrelated_legacy' && $snapshot['reconciliation']['checks']['paired_source']['count']!==1) throw new RuntimeException('legacy_masked_orphan');
        $pdo->beginTransaction();
        foreach (FinancialAuditSnapshot::COLUMNS as $table=>$columns) {
            $pdo->exec('DELETE FROM '.$table);
            foreach ($snapshot['tables'][$table] as $row) {
                if (array_keys($row)!==$columns) throw new RuntimeException('snapshot_column_order');
                if ($table==='Users') { $row['uLogin']='snapshot-'.$row['uID']; $row['uMail']='snapshot-'.$row['uID'].'@fixture.invalid'; }
                $pdo->prepare('INSERT INTO '.$table.' ('.implode(',',array_keys($row)).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));
            }
        }
        $pdo->commit();
        if ((new FinancialReconciler())->run($pdo)!==$snapshot['reconciliation']) throw new RuntimeException('snapshot_fidelity_'.$collation.'_'.$name);
        $assertions+=2;
    }
}
echo json_encode(['status'=>'snapshot_fidelity_verified','assertions'=>$assertions,'cases'=>count($cases),'collations'=>count($collations)],JSON_THROW_ON_ERROR)."\n";
