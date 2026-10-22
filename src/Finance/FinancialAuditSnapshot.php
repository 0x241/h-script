<?php

declare(strict_types=1);

namespace HScript\Finance;

use PDO;
use RuntimeException;

/** A financial projection, not a restorable application backup. Contains no identity or gateway credentials. */
final class FinancialAuditSnapshot
{
    public const FORMAT = 'hscript-financial-audit-v2';
    public const COLUMNS = [
        'Users' => ['uID','uState','uRef','uBal'],
        'Wallets' => ['wcID','wuID','wBal','wLock','wOut'],
        'Currs' => ['cID','cCID','cCurrID','cNumDec','cEXIn','cEXOut'],
        'Plans' => ['pID','pGroup','pMin','pMax','pPerc','pPer','pNPer','pReturn','pBonus','pCompndMin','pCompndMax','pClComis'],
        'Deps' => ['dID','duID','dState','dpID','dCTS','dETS','dcID','dZ0','dZD','dCompnd','dZC','dNTS','dLTS','dZP','dNPer'],
        'Opers' => ['oID','ouID','oOper','ocID','oSum','oComis','oState','oCTS','oTS','oPTS','oTag','oBatch','oParams'],
        'Hist' => ['hID','hOper','huID','hcID','hSum','hoID'],
    ];

    public function capture(PDO $pdo): array
    {
        if ($pdo->inTransaction()) throw new RuntimeException('financial_snapshot_context_invalid');
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        try {
            (new FinancialReconciler())->assertTables($pdo);
            // Fingerprints must preserve the source column's SQL equality,
            // including case folding and PAD SPACE vs NO PAD collations.
            $metadata = $pdo->query("SELECT CHARACTER_SET_NAME,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Opers' AND COLUMN_NAME='oBatch'")->fetch(PDO::FETCH_ASSOC);
            if (!$metadata || !preg_match('/^[a-zA-Z0-9_]+$/D', $metadata['CHARACTER_SET_NAME'])
                || !preg_match('/^[a-zA-Z0-9_]+$/D', $metadata['COLLATION_NAME'])) throw new RuntimeException('financial_batch_collation_unavailable');
            $characterSet = $metadata['CHARACTER_SET_NAME'];
            $collation = $metadata['COLLATION_NAME'];
            $padded = (bool)$pdo->query("SELECT CONVERT('a' USING $characterSet) COLLATE $collation = CONVERT('a ' USING $characterSet) COLLATE $collation")->fetchColumn();
            $batch = $padded ? 'RTRIM(oBatch)' : 'oBatch';
            $batchProjection = "CASE WHEN oBatch IS NULL THEN NULL WHEN oBatch='' THEN '' WHEN oBatch='-' THEN '-' ELSE CONCAT(IF(LEFT(oBatch,1)='?','?:','b:'),LEFT(SHA2(WEIGHT_STRING($batch),256),38)) END";
            $params = "CASE WHEN JSON_VALID(oParams) THEN oParams ELSE '{}' END";
            $tables = [];
            foreach (self::COLUMNS as $table => $columns) {
                $projection = $columns;
                if ($table === 'Opers') {
                    $projection = array_map(static fn($column) => match ($column) {
                        'oBatch' => $batchProjection . ' AS oBatch',
                        'oTag' => "CASE WHEN oTag REGEXP '^[0-9]+$' THEN CAST(oTag AS UNSIGNED) ELSE '' END AS oTag",
                        'oParams' => 'JSON_VALID(oParams) AS params_valid',
                        default => $column,
                    }, $columns);
                    foreach (['oid','uid2','cid2','sum2'] as $key) {
                        $type = $key === 'sum2' ? 'DECIMAL(20,6)' : 'UNSIGNED';
                        $projection[] = "CAST(JSON_UNQUOTE(JSON_EXTRACT($params,'$.$key')) AS $type) AS safe_$key";
                    }
                }
                $rows = $pdo->query('SELECT ' . implode(',', $projection) . ' FROM ' . $table . ' ORDER BY 1,2 LIMIT 250001')->fetchAll(PDO::FETCH_ASSOC);
                if (count($rows) > 250000) throw new RuntimeException('financial_snapshot_row_limit');
                if ($table === 'Opers') {
                    foreach ($rows as &$row) {
                        $safe = [];
                        foreach (['oid','uid2','cid2','sum2'] as $key) {
                            if ($row['safe_'.$key] !== null) $safe[$key] = (string)$row['safe_'.$key];
                            unset($row['safe_'.$key]);
                        }
                        // SQL casts preserve legacy numeric prefixes without
                        // exporting the raw strings. Valid scalars remain valid
                        // JSON; malformed/serialized parameters remain unreadable.
                        $row['oParams'] = $row['params_valid'] ? json_encode((object)$safe, JSON_THROW_ON_ERROR) : null;
                        unset($row['params_valid']);
                    }
                    unset($row);
                }
                $tables[$table] = $rows;
            }
            return ['format' => self::FORMAT, 'captured_at' => gmdate('c'), 'tables' => $tables,
                'scale_context' => (new FinancialReconciler())->scaleContext($pdo),
                'reconciliation' => (new FinancialReconciler())->inspectSnapshot($pdo)];
        } finally { $pdo->exec('ROLLBACK'); }
    }
}
