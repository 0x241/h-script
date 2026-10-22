<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('H_SCRIPT_FINANCIAL_TEST')!=='1' || getenv('DB_NAME')!=='fixture') exit(2);

use HScript\Finance\FinancialReconciler;

function propertyFingerprint(): string
{
    global $pdo;
    $all=[];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $rows=array_map('serialize',$pdo->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC));
        sort($rows,SORT_STRING);$all[$table]=$rows;
    }
    ksort($all);
    return hash('sha256',serialize($all));
}

/** Exact classified F01 row IDs; every other economic/configuration check must be clear. */
function propertyReconcile(int $auditCount): void
{
    global $pdo;
    $ids=$pdo->query("SELECT hID FROM Hist WHERE hOper='BAL' ORDER BY hID")->fetchAll(PDO::FETCH_COLUMN);
    financialAssert(count($ids)===$auditCount,'model predicts exact BAL event count');
    financialAssert((int)financialValue("SELECT COUNT(*) FROM Hist WHERE hOper='BAL' AND hcID=0 AND hoID=0 AND hSum=0")===$auditCount,'exact legacy F01 metadata classification');
    $before=propertyFingerprint();$report=(new FinancialReconciler())->run($pdo,100);
    financialAssert(propertyFingerprint()===$before && !$pdo->inTransaction(),'reconciler is read-only and releases its snapshot');
    foreach ($report['checks'] as $name=>$check) {
        financialAssert($check['count']===($name==='balance_history_incomplete'?$auditCount:0),'no unexplained finding: '.$name);
        if ($name==='balance_history_incomplete') {
            financialAssert(array_map('strval',array_column($check['sample'],'entity_id'))===array_map('strval',array_slice($ids,0,100)),'exact expected F01 finding IDs');
        } else financialAssert($check['sample']===[],'clean finding sample: '.$name);
    }
}

function propertyScale(int $scale): void
{
    global $pdo,$db,$_cfg,$_currs;
    $_cfg['UI_NumDec']=$scale;
    $pdo->prepare("INSERT INTO Cfg (Module,Prop,Val) VALUES ('UI','NumDec',?) ON DUPLICATE KEY UPDATE Val=VALUES(Val)")->execute([(string)$scale]);
    $pdo->exec('UPDATE Currs SET cNumDec='.$scale);
    $_currs=$db->fetchIDRows($db->select('Currs'),false,'cID');
}
