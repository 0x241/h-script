<?php

declare(strict_types=1);

namespace HScript\Finance;

use PDO;
use RuntimeException;

/** Diagnostic checks only. Amount comparisons and aggregates stay in SQL DECIMAL. */
final class FinancialReconciler
{
    // Mirror PHP configuration key construction, including falsey Module='0'.
    public const SCALE_CONFIG_FILTER = "(CAST(Module AS BINARY)='UI' AND CAST(Prop AS BINARY)='NumDec') OR ((Module IS NULL OR CAST(Module AS BINARY) IN ('','0')) AND CAST(Prop AS BINARY)='UI_NumDec')";
    /** Each SELECT returns opaque record IDs; never accounts, batches or payloads. */
    public static function queries(): array
    {
        $outerParams = "CASE WHEN JSON_VALID(Opers.oParams) THEN Opers.oParams ELSE '{}' END";
        $parent = "CAST(JSON_UNQUOTE(JSON_EXTRACT($outerParams, '$.oid')) AS UNSIGNED)";
        $childParams = "CASE WHEN JSON_VALID(c.oParams) THEN c.oParams ELSE '{}' END";
        $sourceParams = "CASE WHEN JSON_VALID(s.oParams) THEN s.oParams ELSE '{}' END";
        $childParent = "CAST(JSON_UNQUOTE(JSON_EXTRACT($childParams, '$.oid')) AS UNSIGNED)";
        $scaleRows = 'SELECT COUNT(*) AS entries,MAX(Val) AS ui_scale FROM Cfg WHERE ' . self::SCALE_CONFIG_FILTER;
        $uiScale = "CASE WHEN entries=0 THEN '2' ELSE COALESCE(ui_scale,'2') END";
        return [
            'negative_wallet' => 'SELECT wuID AS entity_id, wcID AS currency_id FROM Wallets WHERE wBal<0 OR wLock<0 OR wOut<0',
            'null_wallet' => 'SELECT wuID AS entity_id, wcID AS currency_id FROM Wallets WHERE wBal IS NULL OR wLock IS NULL OR wOut IS NULL',
            'user_total' => 'SELECT uID AS entity_id FROM Users LEFT JOIN (SELECT wuID, SUM(wBal+wLock+wOut) AS total FROM Wallets GROUP BY wuID) w ON w.wuID=uID WHERE uBal IS NULL OR uBal<0 OR uBal<>COALESCE(total,0)',
            'wallet_owner_currency' => 'SELECT wuID AS entity_id, wcID AS currency_id FROM Wallets LEFT JOIN Users ON uID=wuID LEFT JOIN Currs ON cID=wcID WHERE uID IS NULL OR cID IS NULL',
            'operation_owner_currency' => 'SELECT oID AS entity_id FROM Opers LEFT JOIN Users ON uID=ouID LEFT JOIN Currs ON cID=ocID WHERE uID IS NULL OR cID IS NULL',
            'operation_amount' => 'SELECT oID AS entity_id FROM Opers WHERE oSum IS NULL OR oComis IS NULL OR oSum<0 OR oComis<0 OR oComis>oSum',
            'operation_state' => 'SELECT oID AS entity_id FROM Opers WHERE oState IS NULL OR oState NOT IN (0,1,2,3,4,5)',
            'operation_kind' => "SELECT oID AS entity_id FROM Opers WHERE oOper IS NULL OR oOper NOT IN ('CASHIN','CASHOUT','EX','EXIN','TR','TRIN','GIVE','TAKE','CALCIN','CALCOUT','REF','BONUS','PENALTY','BUY','BUY2','SELL','SELL2')",
            // Currency scale and SQL storage scale differ. Legacy nonpositive
            // metadata inherits UI precision; individual writes enforce SQL6.
            'currency_scale_storage' => "SELECT cID AS entity_id FROM Currs CROSS JOIN ($scaleRows) scale_cfg WHERE cNumDec>8 OR cNumDec< -99 OR (COALESCE(cNumDec,0)<=0 AND (entries>1 OR CONCAT($uiScale,'#') NOT REGEXP '^-?[0-9]{1,2}#$' OR CAST($uiScale AS SIGNED)<0 OR CAST($uiScale AS SIGNED)>8))",
            'unsupported_purchase' => "SELECT oID AS entity_id FROM Opers WHERE oOper='BUY2' AND oState IN (2,3)",
            'outgoing_total' => "SELECT wuID AS entity_id, wcID AS currency_id FROM Wallets LEFT JOIN (SELECT ouID,ocID,SUM(oSum) AS reserved FROM Opers WHERE oState=2 AND oOper IN ('CASHOUT','EX','TR','BUY','GIVE','TAKE') GROUP BY ouID,ocID) o ON ouID=wuID AND ocID=wcID WHERE wOut<>COALESCE(reserved,0) UNION ALL SELECT ouID,ocID FROM Opers LEFT JOIN Wallets ON wuID=ouID AND wcID=ocID WHERE oState=2 AND oOper IN ('CASHOUT','EX','TR','BUY','GIVE','TAKE') AND wuID IS NULL GROUP BY ouID,ocID",
            'locked_principal' => 'SELECT wuID AS entity_id,wcID AS currency_id FROM Wallets LEFT JOIN (SELECT duID,dcID,SUM(dZD) AS principal FROM Deps WHERE dState=1 GROUP BY duID,dcID) d ON duID=wuID AND dcID=wcID WHERE wLock<>COALESCE(principal,0) UNION ALL SELECT duID,dcID FROM Deps LEFT JOIN Wallets ON wuID=duID AND wcID=dcID WHERE dState=1 AND wuID IS NULL GROUP BY duID,dcID',
            'deposit_owner_currency_plan' => 'SELECT dID AS entity_id FROM Deps LEFT JOIN Users ON uID=duID LEFT JOIN Currs ON cID=dcID LEFT JOIN Plans ON pID=dpID WHERE uID IS NULL OR cID IS NULL OR pID IS NULL',
            'deposit_amount' => 'SELECT dID AS entity_id FROM Deps WHERE dZ0 IS NULL OR dZD IS NULL OR dZC IS NULL OR dZP IS NULL OR dZ0<0 OR dZD<0 OR dZC<0 OR dZP<0 OR (dState=1 AND dZC>dZD) OR dCompnd IS NULL OR dCompnd<0 OR dCompnd>100 OR dNPer IS NULL OR dNPer<0',
            'deposit_state' => 'SELECT dID AS entity_id FROM Deps WHERE dState IS NULL OR dState NOT IN (0,1,2,3) OR (dState=1 AND (dZD<=0 OR dNTS IS NULL OR dNTS IN (0,19700101000000))) OR (dState IN (2,3) AND (dNTS IS NULL OR dNTS NOT IN (0,19700101000000)))',
            'duplicate_gateway_batch' => "SELECT MIN(oID) AS entity_id,ocID AS currency_id,COUNT(*) AS occurrences FROM Opers WHERE oState=3 AND oOper IN ('CASHIN','CASHOUT') AND oBatch<>'' AND oBatch<>'-' AND LEFT(oBatch,1)<>'?' GROUP BY ocID,oBatch HAVING COUNT(*)>1",
            'paired_params_unreadable' => "SELECT oID AS entity_id FROM Opers WHERE oState=3 AND oOper IN ('EX','EXIN','TR','TRIN') AND (oParams IS NULL OR JSON_VALID(oParams)=0)",
            'paired_source' => "SELECT s.oID AS entity_id FROM Opers s LEFT JOIN Opers c ON $childParent=s.oID AND c.oOper=IF(s.oOper='TR','TRIN','EXIN') AND c.oState=3 WHERE s.oOper IN ('TR','EX') AND s.oState=3 AND JSON_VALID(s.oParams)=1 GROUP BY s.oID HAVING COUNT(c.oID)<>1",
            'paired_child' => "SELECT oID AS entity_id FROM Opers WHERE oOper IN ('EXIN','TRIN') AND oState=3 AND JSON_VALID(Opers.oParams)=1 AND NOT EXISTS (SELECT 1 FROM Opers s WHERE s.oID=$parent AND s.oState=3 AND s.oOper=IF(Opers.oOper='TRIN','TR','EX'))",
            'paired_identity' => "SELECT c.oID AS entity_id FROM Opers c JOIN Opers s ON $childParent=s.oID WHERE c.oState=3 AND s.oState=3 AND ((s.oOper='TR' AND c.oOper='TRIN' AND (c.ocID<>s.ocID OR c.ouID<>COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT($sourceParams,'$.uid2')) AS UNSIGNED),0) OR c.ouID=s.ouID)) OR (s.oOper='EX' AND c.oOper='EXIN' AND (c.ouID<>s.ouID OR c.ocID<>COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT($sourceParams,'$.cid2')) AS UNSIGNED),0) OR c.ocID=s.ocID)))",
            'paired_batch' => "SELECT c.oID AS entity_id FROM Opers c JOIN Opers s ON $childParent=s.oID WHERE c.oState=3 AND s.oState=3 AND s.oOper IN ('TR','EX') AND c.oOper=IF(s.oOper='TR','TRIN','EXIN') AND (c.oBatch IS NULL OR s.oBatch IS NULL OR c.oBatch='' OR c.oBatch<>s.oBatch)",
            'transfer_conservation' => "SELECT c.oID AS entity_id FROM Opers c JOIN Opers s ON $childParent=s.oID WHERE s.oOper='TR' AND c.oOper='TRIN' AND s.oState=3 AND c.oState=3 AND c.oSum<>s.oSum-s.oComis",
            'exchange_conservation' => "SELECT c.oID AS entity_id FROM Opers c JOIN Opers s ON $childParent=s.oID WHERE s.oOper='EX' AND c.oOper='EXIN' AND s.oState=3 AND c.oState=3 AND (JSON_EXTRACT($sourceParams,'$.sum2') IS NULL OR c.oSum<>CAST(JSON_UNQUOTE(JSON_EXTRACT($sourceParams,'$.sum2')) AS DECIMAL(20,6)))",
            'deposit_profit_history' => "SELECT Deps.dID AS entity_id FROM Deps LEFT JOIN (SELECT CAST(oTag AS UNSIGNED) AS source_deposit_id,SUM(oSum) AS profit FROM Opers WHERE oOper='CALCIN' AND oState=3 AND oTag REGEXP '^[0-9]+$' GROUP BY CAST(oTag AS UNSIGNED)) o ON source_deposit_id=Deps.dID WHERE dZP<>COALESCE(profit,0)",
            'balance_history_incomplete' => "SELECT hID AS entity_id FROM Hist WHERE hOper='BAL' AND (hcID=0 OR hoID=0 OR hSum=0)",
        ];
    }

    /** Owns one repeatable snapshot. Refuses nested transactions and non-InnoDB tables. */
    public function run(PDO $pdo, int $sampleLimit = 20): array
    {
        if ($sampleLimit < 0 || $sampleLimit > 100 || $pdo->inTransaction()) {
            throw new RuntimeException('financial_reconciliation_context_invalid');
        }
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        try {
            return $this->inspectSnapshot($pdo, $sampleLimit);
        } finally {
            $pdo->exec('ROLLBACK');
        }
    }

    /** Caller owns a read-only transaction, allowing export and checks at the same boundary. */
    public function inspectSnapshot(PDO $pdo, int $sampleLimit = 20): array
    {
        if (!$pdo->inTransaction() || $sampleLimit < 0 || $sampleLimit > 100) throw new RuntimeException('financial_snapshot_required');
        $this->assertTables($pdo);
        $checks = [];
        $total = 0;
        foreach (self::queries() as $id => $sql) {
            $count = (int)$pdo->query('SELECT COUNT(*) FROM (' . $sql . ') findings')->fetchColumn();
            $sample = $sampleLimit > 0 && $count > 0
                ? $pdo->query('SELECT * FROM (' . $sql . ') findings ORDER BY entity_id LIMIT ' . $sampleLimit)->fetchAll(PDO::FETCH_ASSOC) : [];
            $checks[$id] = ['count' => $count, 'sample' => $sample];
            $total += $count;
        }
        return ['status' => $total === 0 ? 'clear' : 'findings', 'finding_count' => $total, 'checks' => $checks,
            'limitations' => ['referral_receipts_not_in_audit_snapshot', 'history_is_not_a_ledger', 'pending_operations_need_review', 'exchange_uses_stored_quote']];
    }

    public function assertTables(PDO $pdo): void
    {
        $tables = $pdo->query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('Users','Wallets','Opers','Deps','Currs','Plans','Hist','Cfg')")->fetchAll(PDO::FETCH_KEY_PAIR);
        if (count($tables) !== 8 || count(array_filter($tables, static fn($engine) => $engine !== 'InnoDB')) > 0) {
            throw new RuntimeException('financial_tables_unavailable_or_nontransactional');
        }
    }

    /** Sanitized inherited precision only, read at the caller's snapshot boundary. */
    public function scaleContext(PDO $pdo): array
    {
        if (!$pdo->inTransaction()) throw new RuntimeException('financial_snapshot_required');
        $rows = $pdo->query('SELECT Val FROM Cfg WHERE ' . self::SCALE_CONFIG_FILTER)->fetchAll(PDO::FETCH_COLUMN);
        if (count($rows) > 1) return ['ui_num_dec'=>'invalid'];
        try {
            return ['ui_num_dec'=>CurrencyScale::resolve(['cNumDec'=>0], ['UI_NumDec'=>$rows[0] ?? 2])];
        } catch (\InvalidArgumentException) { return ['ui_num_dec'=>'invalid']; }
    }
}
