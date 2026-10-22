<?php

declare(strict_types=1);

namespace HScript\Finance;

/** Administrator manual-charge work; called only inside the form receipt transaction. */
final class ManualCharge
{
    public static function run(array $ids, array $percentages, int $boundary, int $expectedBoundary): array|string
    {
        global $db;
        if (!$db->link->inTransaction()) throw new \LogicException('manual_charge_requires_transaction');
        if ($boundary !== $expectedBoundary) return 'date_wrong';
        $selected = $db->fetchRows($db->select('Plans', 'pID', 'pID ?i', [$ids], 'pID'), 1);
        if (!$selected) return ['info'=>'*NoSelected', 'done'=>0];
        // Completed form receipts replay before this callback, including old rounded requests.
        $storedPercentages = [];
        foreach ($selected as $pid) $storedPercentages[$pid] = StoredPercentage::exact(Decimal::of($percentages[$pid]));
        //--add to cron for manual---
        foreach ($selected as $pid) {
            $did = $db->fetch1($db->select('Deps', 'dID', 'dpID=?d and dState=1', [$pid], 'dID desc', 1));
            if ($did && !Decimal::of($storedPercentages[$pid])->isZero()) {
                if ($db->update('Plans', ['pmndID'=>$did, 'pmnPerc'=>$storedPercentages[$pid], 'pmndIDdone'=>0], '', 'pID=?d', [$pid]) === false)
                    throw new FinancialOperationException('plan_not_saved');
            }
        }
        //----------------------------
        $t = time(); $n = 0;
        foreach ($selected as $pid) {
            if (Decimal::of($storedPercentages[$pid])->isZero()) continue;
            $dl = $db->select('Deps LEFT JOIN Plans ON pID=dpID LEFT JOIN Users ON uID=duID', '*',
                'dID>pmndIDdone and dpID=?d and dState=1 and (dNPer<pNPer or pNPer=0) and dNTS<=? and dLTS<=?',
                [$pid, timeToStamp(), timeToStamp(time() - 10 * HS2_UNIX_MINUTE)], 'dID');
            while (abs(time() - $t) < 30 && ($d = $db->fetch($dl))) {
                FinancialOperationException::check(opDepoCharge($d, $storedPercentages[$pid], $boundary));
                if ($db->update('Plans', ['pmndIDdone'=>$d['dID']], '', 'pID=?d', [$d['pID']]) === false)
                    throw new FinancialOperationException('plan_not_saved');
                $n++;
            }
        }
        return ['info'=>'Completed', 'done'=>$n];
    }
}
