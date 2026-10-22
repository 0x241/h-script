<?php

use HScript\Finance\Decimal;
use HScript\Finance\DecimalInput;
use HScript\Finance\FinancialOperationException;
use HScript\Finance\IdempotencyRequest;
use HScript\Finance\IdempotencyResult;
use HScript\Finance\IdempotencyStore;
use HScript\Finance\StoredAmount;

function onBalanceEvent($oper, $uid, $params)
{
    global $db, $_cfg, $_currs;
    if ($oper !== 'RegComplete') return;
    $uid = (int)$uid;
    try { $bonus = DecimalInput::read($_cfg['Bal_RegBonus'] ?? 0); }
    catch (InvalidArgumentException) { return; }
    if ($bonus->compareTo(Decimal::of(0)) <= 0) return;
    useLib('balance');
    $amounts = [];
    if ($_cfg['Const_IntCurr']) {
        if (!isset($_currs[1])) return true;
        try {
            $amount = opMoney($bonus, 1);
            StoredAmount::exact($amount->decimal());
            if ($amount->decimal()->compareTo(Decimal::of(0)) > 0) $amounts[1] = (string)$amount;
        } catch (InvalidArgumentException|OverflowException) { return; }
    } else foreach ($_currs as $cid => $currency) {
        $amount = opAmountFromBase($bonus, $cid);
        if (!is_string($amount)) $amounts[$cid] = (string)$amount;
    }
    // Preserve the existing independent skips for unsupported bonus currencies.
    // Once issued, the same registration cannot acquire another bonus after a
    // changed amount, rate, enabled currency, or internal-currency setting.
    if (!$amounts) return;
    $method = $db->link->inTransaction() ? 'executeInTransaction' : 'execute';
    (new IdempotencyStore($db))->$method('registration:bonus', (string)$uid, (int)$uid,
        IdempotencyRequest::fingerprint(['event' => 'RegComplete', 'user_id' => (int)$uid]),
        static function () use ($uid, $amounts): IdempotencyResult {
            global $db;
            // Legacy rows have no registration event identity. Keep their money
            // unchanged and refuse another automatic issuance for ambiguous
            // already-paid Bonus history rather than guessing its provenance.
            $legacy = $db->fetch1($db->query("SELECT oID FROM Opers WHERE ouID=?d AND oOper='BONUS' AND oMemo='Bonus' AND oState=3 ORDER BY oID LIMIT 1 LOCK IN SHARE MODE", [$uid]));
            if ($legacy) return new IdempotencyResult(['issued' => false, 'reason' => 'legacy_bonus_unbound', 'operation_ids' => []], 200, (int)$legacy);
            $ids = [];
            foreach ($amounts as $cid => $amount)
                $ids[] = FinancialOperationException::check(opOperCreate($uid, 'BONUS', $cid, $amount, [], 'Bonus', true, true));
            return new IdempotencyResult(['issued' => true, 'operation_ids' => $ids], 200, $ids[0]);
        });
    return true;
}

?>
