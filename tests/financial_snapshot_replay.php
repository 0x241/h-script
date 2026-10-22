<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use HScript\Database\Connection;
use HScript\Finance\FinancialAuditSnapshot;
use HScript\Finance\FinancialReconciler;

// Financial projections are imported only in the uniquely isolated fixture DB.
if (getenv('H_SCRIPT_FINANCIAL_TEST') !== '1' || getenv('DB_NAME') !== 'fixture') exit(2);
require dirname(__DIR__) . '/vendor/autoload.php';
$db = new Connection();
if (!$db->open(getenv('DB_HOST'),'fixture',getenv('DB_USER'),getenv('DB_PASSWORD'))) throw new RuntimeException('Fixture unavailable');
$pdo = $db->link;
$path = getenv('H_SCRIPT_FINANCIAL_SNAPSHOT_FILE');
$snapshot = $path ? json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR) : (new FinancialAuditSnapshot())->capture($pdo);
if (($snapshot['format'] ?? '') !== FinancialAuditSnapshot::FORMAT || array_keys($snapshot['tables']) !== array_keys(FinancialAuditSnapshot::COLUMNS)) throw new RuntimeException('Unsupported financial snapshot; recapture with the current CLI');
$context=$snapshot['scale_context'] ?? [];
if (array_keys($context)!==['ui_num_dec'] || !($context['ui_num_dec']==='invalid' || (is_int($context['ui_num_dec']) && $context['ui_num_dec']>=0 && $context['ui_num_dec']<=8))) throw new RuntimeException('Invalid financial scale context');
$pdo->beginTransaction();
try {
    $pdo->exec('DELETE FROM Cfg WHERE ' . FinancialReconciler::SCALE_CONFIG_FILTER);
    $pdo->prepare("INSERT INTO Cfg (Module,Prop,Val) VALUES ('UI','NumDec',?)")->execute([(string)$context['ui_num_dec']]);
    foreach (FinancialAuditSnapshot::COLUMNS as $table=>$columns) {
        $pdo->exec('DELETE FROM '.$table);
        foreach ($snapshot['tables'][$table] as $row) {
            if (array_keys($row) !== $columns) throw new RuntimeException('Unexpected snapshot columns');
            if ($table === 'Users') {
                $row['uLogin']='financial-'.$row['uID'];
                $row['uMail']='financial-'.$row['uID'].'@fixture.invalid';
            }
            $sql='INSERT INTO '.$table.' ('.implode(',',array_keys($row)).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')';
            $pdo->prepare($sql)->execute(array_values($row));
        }
    }
    $pdo->commit();
} catch (Throwable $error) { $pdo->rollBack(); throw $error; }
$checker = new FinancialReconciler();
$report = $checker->run($pdo);
if ($report !== $snapshot['reconciliation'] || $checker->run($pdo) !== $report) throw new RuntimeException('Snapshot replay changed financial findings');
echo json_encode(['status'=>'snapshot_replayed','source'=>$path?'provided_financial_projection':'synthetic_fixture','rows'=>array_map('count',$snapshot['tables']),'reconciliation'=>$report],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
