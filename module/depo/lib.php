<?php

use HScript\Util\StringHelper;
use HScript\Database\TransactionScope;
use HScript\Finance\IdempotencyStore;
use HScript\Finance\IdempotencyRequest;
use HScript\Finance\IdempotencyResult;
use HScript\Finance\FinancialOperationException;
use HScript\Finance\Decimal;
use HScript\Finance\DecimalInput;
use HScript\Finance\DepositPolicy;
use HScript\Finance\DepositForecast;
use HScript\Finance\ReadAmount;
use HScript\Finance\CurrencyScale;
use HScript\Finance\Rate;
use Brick\Math\RoundingMode;

use HScript\Cache\CatalogCache;
use HScript\Template\View;

function opDepoGetPlanList($uid, $by_admin = false, $only_ids = false, $cid = 0, $exclude_did = 0, $current = false)
{
    global $db, $_cfg;
    // Writers already hold the user's group lock. Each subquery needs its own
    // current-read clause; locking the outer plan query alone is insufficient.
    if ($current && !$db->link->inTransaction()) throw new LogicException('Current plan policy requires a transaction');
    $lock = $current ? ' LOCK IN SHARE MODE' : '';
    $params = [$uid, $uid];
    if ($cid) $params[] = $cid;
    if ($exclude_did) $params[] = $exclude_did;
    $plans = $db->fetchIDRows($db->query('SELECT P.*,
        (SELECT COUNT(dID) FROM Deps LEFT JOIN Plans P2 on P2.pID=dpID WHERE P2.pGroup=P.pGroupReq and duID=?d and dState>=2 LIMIT 1' . $lock . ') AS DCL,
        (SELECT COUNT(dID) FROM Deps WHERE duID=?d and dState=1 and dpID=P.pID'
        . StringHelper::valueIf($cid, ' and dcID=?d') . StringHelper::valueIf($exclude_did, ' and dID<>?d') . $lock . ') AS DCNT FROM Plans P'
        . StringHelper::valueIf(!$by_admin, ' WHERE pHidden=0') . ' ORDER BY pGroup, pID' . $lock, $params), false, 'pID');
    // Visibility controls candidate plans, never occupancy of an existing group.
    $groupParams = [$uid];
    $groupFilter = 'duID=?d AND dState=1';
    if ($cid) { $groupFilter .= ' AND dcID=?d'; $groupParams[] = $cid; }
    if ($exclude_did) { $groupFilter .= ' AND dID<>?d'; $groupParams[] = $exclude_did; }
    $bg = [];
    foreach ($db->fetchRows($db->query('SELECT pGroup,COUNT(dID) AS occupied FROM Deps JOIN Plans ON pID=dpID WHERE '
        . $groupFilter . ' GROUP BY pGroup' . $lock, $groupParams)) as $group)
        $bg[$group['pGroup']] = $group['occupied'];
    $pids = array();
    foreach ($plans as $pid => $p)
        if (
            (($p['pMaxCount'] > 0) and ($p['DCNT'] >= $p['pMaxCount'])) or
            (($p['pGroupReq'] > 0) and !$p['DCL']) or
            (!empty($_cfg['Depo_OneFromGroup']) and !empty($bg[$p['pGroup']]))
        )
            $plans[$pid]['Disabled'] = true;
        else
            $pids[] = $pid;
    if ($only_ids) return $pids;
    return $plans;
}

function opDepoMathError(InvalidArgumentException|OverflowException $error): string
{
    if ($error instanceof OverflowException) return 'sum_overflow';
    return match ($error->getMessage()) {
        'amount_storage_precision' => 'sum_precision',
        'rate_invalid' => 'ex_rate_not_set',
        'compnd_wrong' => 'compnd_wrong',
        default => 'sum_wrong',
    };
}

/** Error results roll back locally; an outer request retains ownership of its transaction. */
function opDepoWork(callable $work)
{
    global $db;
    try { return TransactionScope::run($db->link, $work); }
    catch (FinancialOperationException $error) { return $error->getMessage(); }
    catch (InvalidArgumentException|OverflowException $error) { return opDepoMathError($error); }
}

/** Serialize deposit writers and check shared backing before consuming any principal. */
function opDepoLockGroup($uid, $cid): void
{
    global $db;
    if (!$db->fetch1Row($db->query('SELECT uID FROM Users WHERE uID=?d FOR UPDATE', [$uid])))
        throw new FinancialOperationException('user_not_found');
    $wallet = $db->fetch1Row($db->query('SELECT wLock FROM Wallets WHERE wuID=?d AND wcID=?d FOR UPDATE', [$uid, $cid]));
    $rows = $db->fetchRows($db->query('SELECT dID,dZ0,dZD,dZC,dZP FROM Deps WHERE duID=?d AND dcID=?d AND dState=1 ORDER BY dID FOR UPDATE', [$uid, $cid]));
    $principal = Decimal::of(0);
    foreach ($rows as $row) {
        foreach (['dZ0','dZD','dZC','dZP'] as $field) DepositPolicy::stored(Decimal::of($row[$field]));
        if (Decimal::of($row['dZC'])->compareTo(Decimal::of($row['dZD'])) > 0)
            throw new FinancialOperationException('depo_principal_mismatch');
        $principal = $principal->plus(Decimal::of($row['dZD']));
    }
    if ($principal->compareTo(Decimal::of($wallet['wLock'] ?? '0')) !== 0)
        throw new FinancialOperationException('depo_principal_mismatch');
}

function opDepoUSD($sum, $curr): Decimal
{
    global $_cfg, $_currs;
    $amount = $sum instanceof Decimal ? $sum : DecimalInput::read($sum);
    $base = $_currs[1] ?? (is_array($_currs) ? reset($_currs) : null);
    if ($curr === ($base['cCurrID'] ?? 'USD')) return $amount;
    // Valuation is not a stored money field: retain wide decimal intermediates.
    try { $rate = Rate::of((string)($_cfg['Bal_Rate' . $curr] ?? 0)); }
    catch (InvalidArgumentException $error) { throw new InvalidArgumentException('rate_invalid', 0, $error); }
    return $rate->multiply($amount,
        CurrencyScale::resolve($base ?: [], $_cfg), RoundingMode::HalfUp);
}

function opDepoMinimumNative($minimum, $cid): Decimal
{
    global $_cfg, $_currs;
    $base = $_currs[1] ?? reset($_currs);
    $amount = Decimal::of($minimum);
    if ($_currs[$cid]['cCurrID'] === ($base['cCurrID'] ?? 'USD')) return $amount;
    // Ceiling never leaves the un-compounded principal below the plan minimum.
    return Rate::of((string)($_cfg['Bal_Rate' . $_currs[$cid]['cCurrID']] ?? 0))->divide($amount,
        min(6, CurrencyScale::resolve($_currs[$cid], $_cfg)), RoundingMode::Ceiling);
}

function opDepoPlanContains(array $plan, Decimal $valuation): bool
{
    return Decimal::of($plan['pMin'])->compareTo($valuation) <= 0
        && Decimal::of($plan['pMax'])->compareTo($valuation) > 0;
}

function opDepoSelectPlan($uid, $cid, Decimal $valuation, $pid = 0, $group = null, $by_admin = false, $exclude_did = 0): array|false
{
    global $db;
    $enabled = opDepoGetPlanList($uid, $by_admin, true, $cid, $exclude_did, true);
    $params = [$enabled];
    $filter = '(pID ?i)';
    if ($pid) { $filter .= ' AND pID=?d'; $params[] = $pid; }
    if ($group !== null) { $filter .= ' AND pGroup=?d'; $params[] = $group; }
    foreach ($db->fetchRows($db->query('SELECT * FROM Plans WHERE ' . $filter . ' ORDER BY pMax,pID LOCK IN SHARE MODE', $params)) as $plan)
        if (opDepoPlanContains($plan, $valuation)) return $plan;
    return false;
}

function opDepoValidatePlan(array $plan): void
{
    foreach (['pBonus','pPerc','pReturn'] as $field)
        if (Decimal::of($plan[$field])->isNegative()) throw new FinancialOperationException('plan_wrong');
    $minimum = DepositPolicy::compound(Decimal::of($plan['pCompndMin']));
    $maximum = DepositPolicy::compound(Decimal::of($plan['pCompndMax']));
    if ($minimum->compareTo($maximum) > 0) throw new FinancialOperationException('plan_wrong');
}

function opDepoCompoundForPlan(Decimal $compound, array $plan): Decimal
{
    $compound = DepositPolicy::compound($compound);
    if ($compound->compareTo(Decimal::of($plan['pCompndMin'])) < 0
        || $compound->compareTo(Decimal::of($plan['pCompndMax'])) > 0) {
        View::setPage('cmin', $plan['pCompndMin']);
        View::setPage('cmax', $plan['pCompndMax']);
        View::setPage('cplan', $plan['pName']);
        throw new FinancialOperationException('compnd_wrong');
    }
    return $compound;
}

function opDepoActive($uid, $cid, $did = 0): array|false
{
    global $db;
    $params = [$uid, $cid];
    if ($did) $params[] = $did;
    // A caller may have established an older snapshot before the group lock.
    return $db->fetch1Row($db->query('SELECT Deps.*,Plans.*,Currs.cCurrID FROM Deps JOIN Plans ON pID=dpID JOIN Currs ON cID=dcID WHERE duID=?d AND dcID=?d AND dState=1'
        . StringHelper::valueIf($did, ' AND dID=?d') . ' ORDER BY dID LIMIT 1 LOCK IN SHARE MODE', $params));
}

function opDepoNet($oid): Decimal
{
    global $db;
    $operation = $db->fetch1Row($db->select('Opers', 'oSum,oComis', 'oID=?d', [$oid]));
    if (!$operation) throw new FinancialOperationException('db_error');
    return Decimal::of($operation['oSum'])->minus(Decimal::of($operation['oComis']));
}

function opDepoCreate($uid, $cid, $sum, $compnd = 0, $pid = 0, $by_admin = false, $auto = false)
{
    return opDepoWork(function () use ($uid, $cid, $sum, $compnd, $pid, $by_admin, $auto) {
        global $db, $_cfg, $_currs;
    if(($_currs[$cid]['cCID']??'')==='CCT')return 'oper_disabled';
        if (!opReadUser($uid)) return 'user_not_found';
        if (!$cid) return 'psys_empty';
        if (!isset($_currs[$cid])) return 'psys_wrong';
        useLib('balance');
        if(!HScript\Payment\Provider\CurrencyArchive::writable($db,[(int)$cid]))return 'currency_archived';
        opDepoLockGroup($uid, $cid);
        $sum = opMoney($sum, $cid)->decimal();
        DepositPolicy::stored($sum);
        $valuation = opDepoUSD($sum, $_currs[$cid]['cCurrID']);
        if ($valuation->compareTo(Decimal::of('0.01')) < 0) return 'sum_wrong';
        $compound = DepositPolicy::compound(DecimalInput::read($compnd === '' ? 0 : $compnd));
        // Plan limits use the base-currency value so every accepted payment currency
        // is compared against the same pMin/pMax thresholds. Opening eligibility,
        // bonus and the source event retain their gross amount basis.
        $plan = opDepoSelectPlan($uid, $cid, $valuation, $pid, null, $by_admin);
        if (!$plan) return 'plan_wrong';
        opDepoValidatePlan($plan);
        if (!$pid && $compound->isZero()) $compound = Decimal::of($plan['pCompndMin']);
        $compound = opDepoCompoundForPlan($compound, $plan);
        $bonus = DepositPolicy::percentage($sum, Decimal::of($plan['pBonus']), CurrencyScale::resolve($_currs[$cid], $_cfg));
        $fee = opCalcComis($cid, 'GIVE', (string)$sum, true);
        FinancialOperationException::check($fee instanceof HScript\Finance\Money ? true : $fee);
        $net = $sum->minus($fee->decimal());
        if ($net->compareTo(Decimal::of(0)) <= 0) return 'sum_wrong';
        DepositPolicy::stored($net); DepositPolicy::stored($net->plus($bonus));
        if ($auto === 2) return 'passed';
        $oid = opOperCreate($uid, 'GIVE', $cid, $sum, ['bonus'=>(string)$bonus, 'auto'=>$auto], StringHelper::valueIf($auto, 'Auto '), false, true);
        FinancialOperationException::check($oid);
        FinancialOperationException::check(opOperConfirm($uid, $oid, [], true));
        $net = opDepoNet($oid);
        // The first accrual boundary depends on manual, interval, or immediate mode;
        // all subsequent boundaries advance by the selected plan period.
        if ($_cfg['Depo_ChargeMode'] == 2) $t = opDepoLastManualChargeDate();
        elseif ($_cfg['Depo_Interval'] > 0) {
            $t = stampToTime($_cfg['Depo_LastTime']) + HS2_UNIX_MINUTE * $_cfg['Depo_Interval'];
            if ($t < time()) $t = time();
        } else $t = time();
        $did = $db->insert('Deps', [
            'duID'=>$uid, 'dState'=>1, 'dpID'=>$plan['pID'], 'dCTS'=>timeToStamp($t),
            'dETS'=>timeToStamp($t + $plan['pNPer'] * $plan['pPer'] * HS2_UNIX_HOUR), 'dcID'=>$cid,
            'dZ0'=>DepositPolicy::stored($net), 'dZD'=>DepositPolicy::stored($net->plus($bonus)), 'dCompnd'=>(string)$compound,
            'dNTS'=>timeToStamp($t + $plan['pPer'] * HS2_UNIX_HOUR), 'dLTS'=>0,
        ]);
        if (!$did) return 'depo_not_saved';
        FinancialOperationException::check(opOperComplete($uid, $oid, ['tag'=>$did], true));
        if (!$db->replace('Cfg', ['Module'=>'Depo', 'Prop'=>'LastTime', 'Val'=>timeToStamp($t)], '', 'Module=? and Prop=?', ['Depo','LastTime']))
            return 'depo_not_saved';
        $event = ['did'=>$did, 'pid'=>$plan['pID'], 'cid'=>$cid, 'sum'=>(string)$sum, 'percs'=>asArray($plan['pDPerc'], HS2_NL)];
        HScript\Finance\ReferralSourceEvent::dispatch($db->link, 'DepoCreate', (int)$uid, $event,
            static fn() => opEvent('DepoCreate', $uid, $event));
        return $did;
    });
}

function opDepoTransition(array $deposit, Decimal $principal, Decimal $compounded): array
{
    $valuation = opDepoUSD($principal, $deposit['cCurrID']);
    $change = ['dZD'=>DepositPolicy::stored($principal), 'dZC'=>DepositPolicy::stored($compounded)];
    if (!opDepoPlanContains($deposit, $valuation)) { // change plan
        $plan = opDepoSelectPlan($deposit['duID'], $deposit['dcID'], $valuation, 0, $deposit['pGroup'], false, $deposit['dID']);
        if (!$plan) throw new FinancialOperationException('plan_not_found');
        opDepoValidatePlan($plan);
        opDepoCompoundForPlan(Decimal::of($deposit['dCompnd']), $plan);
        $change['dpID'] = $plan['pID'];
        $change['dZC'] = DepositPolicy::stored(DepositPolicy::compoundCap($principal, $compounded,
            opDepoMinimumNative($plan['pMin'], $deposit['dcID'])));
    }
    return $change;
}

function opDepoAdd($uid, $cid, $sum, $did = 0, $is_compnd = false)
{
    return opDepoWork(function () use ($uid, $cid, $sum, $did, $is_compnd) {
        global $db;
        useLib('balance');
        opDepoLockGroup($uid, $cid);
        $d = opDepoActive($uid, $cid, $did);
        if (!$d) return 'depo_wrong';
        $sum = opMoney($sum, $cid)->decimal();
        DepositPolicy::stored($sum);
        $valuation = opDepoUSD($sum, $d['cCurrID']);
        if ($valuation->compareTo(Decimal::of('0.01')) < 0) return 'sum_wrong';
        if (!$is_compnd) {
            if (!$d['pEnAdd']) return 'cant_add';
            if ($valuation->compareTo(Decimal::of($d['pMinAdd'])) < 0) return 'sum_wrong';
        }
        $oid = opOperCreate($uid, 'GIVE', $cid, $sum, ['tag'=>$d['dID']], '', false, $is_compnd);
        FinancialOperationException::check($oid);
        $net = opDepoNet($oid);
        if ($net->compareTo(Decimal::of(0)) <= 0) return 'sum_wrong';
        $change = opDepoTransition($d, Decimal::of($d['dZD'])->plus($net),
            Decimal::of($d['dZC'])->plus($is_compnd ? $net : Decimal::of(0)));
        FinancialOperationException::check(opOperConfirm($uid, $oid, [], true));
        // Compare the previous principal in the update to prevent concurrent top-ups
        // from overwriting each other. The transaction rolls back on loss.
        if (!$db->update('Deps', $change, '', 'dID=?d and dState=1 and dZD=?', [$d['dID'], $d['dZD']])) return 'depo_wrong';
        return opOperComplete($uid, $oid, [], true);
    });
}

function opDepoSub($uid, $cid, $sum, $did = 0)
{
    return opDepoWork(function () use ($uid, $cid, $sum, $did) {
        global $db;
        useLib('balance');
        opDepoLockGroup($uid, $cid);
        $d = opDepoActive($uid, $cid, $did);
        if (!$d) return 'depo_wrong';
        $requested = $sum instanceof Decimal ? $sum : DecimalInput::read($sum);
        $full = $requested->compareTo(Decimal::of($d['dZD'])) === 0;
        // An exact full withdrawal of the locked row returns its stored principal.
        $sum = $full ? $requested : opMoney($requested, $cid)->decimal();
        DepositPolicy::stored($sum);
        if (opDepoUSD($sum, $d['cCurrID'])->compareTo(Decimal::of('0.01')) < 0) return 'sum_wrong';
        if (Decimal::of($d['pClComis'])->compareTo(Decimal::of(100)) >= 0 || ($d['pMPer'] > 0 && $d['dNPer'] < $d['pMPer'])) return 'cant_sub';
        $principal = Decimal::of($d['dZD'])->minus($sum);
        if ($principal->isNegative()) return 'sum_wrong'; // more than depo
        if ($principal->isZero()) { // close; retain the historical principal
            $change = ['dState'=>3, 'dNTS'=>timeToStamp(0)];
        } else {
            $compounded = Decimal::of($d['dZC'])->minus($sum);
            if ($compounded->isNegative()) $compounded = Decimal::of(0);
            $change = opDepoTransition($d, $principal, $compounded);
        }
        $oid = opOperCreate($uid, 'TAKE', $cid, $sum, ['comis'=>StringHelper::valueIf($d['pClPer'] > 0 && $d['dNPer'] >= $d['pClPer'], 0, $d['pClComis']), 'tag'=>$d['dID']], '', false, false, $full);
        FinancialOperationException::check($oid);
        FinancialOperationException::check(opOperConfirm($uid, $oid, [], true));
        if (!$db->update('Deps', $change, '', 'dID=?d and dState=1 and dZD=?', [$d['dID'], $d['dZD']])) return 'depo_wrong';
        $result = opOperComplete($uid, $oid, [], true);
        if (!is_string($result) && ($change['dState'] ?? 1) >= 2)
            opEvent('DepoClose', $uid, ['did'=>$d['dID'], 'cid'=>$cid, 'state'=>$change['dState']]);
        return $result;
    });
}

function opDepoClose($uid, $cid, $did = 0)
{
    return opDepoWork(function () use ($uid, $cid, $did) {
        global $db;
        useLib('balance');
        opDepoLockGroup($uid, $cid);
        $d = opDepoActive($uid, $cid, $did);
        if (!$d) return 'depo_wrong';
        $principal = Decimal::of($d['dZD']);
        if (!$db->update('Deps', ['dState'=>3, 'dZD'=>'0.000000', 'dNTS'=>timeToStamp(0)], '', 'dID=?d and dState=1 and dZD=?', [$d['dID'], $d['dZD']])) return 'depo_wrong';
        return opOperCreate($uid, 'TAKE', $cid, $principal, ['tag'=>$d['dID']], '', true, true, true);
    });
}

function opDepoChangePlan($uid, $pid, $did, $by_admin = false)
{
    return opDepoWork(function () use ($uid, $pid, $did, $by_admin) {
        global $db;
        $cid = $db->fetch1($db->select('Deps', 'dcID', 'dID=?d AND duID=?d', [$did, $uid]));
        if (!$cid) return 'depo_wrong';
        opDepoLockGroup($uid, $cid);
        $d = opDepoActive($uid, $cid, $did);
        if (!$d || !$pid) return 'depo_wrong';
        $principal = Decimal::of($d['dZD']);
        $plan = opDepoSelectPlan($uid, $cid, opDepoUSD($principal, $d['cCurrID']), $pid, null, $by_admin, $did);
        if (!$plan) return 'plan_wrong';
        opDepoValidatePlan($plan);
        opDepoCompoundForPlan(Decimal::of($d['dCompnd']), $plan);
        if ((int)$d['dpID'] === (int)$pid) return 0;
        $compounded = DepositPolicy::compoundCap($principal, Decimal::of($d['dZC']), opDepoMinimumNative($plan['pMin'], $cid));
        return $db->update('Deps', ['dpID'=>$pid, 'dZC'=>DepositPolicy::stored($compounded)], '', 'dID=?d AND dState=1 AND dpID=?d', [$did, $d['dpID']]) ?: 'depo_not_saved';
    });
}

function opDepoChangeCompnd($uid, $did, $compnd)
{
    return opDepoWork(function () use ($uid, $did, $compnd) {
        global $db;
        $cid = $db->fetch1($db->select('Deps', 'dcID', 'dID=?d AND duID=?d', [$did, $uid]));
        if (!$cid) return 'depo_wrong';
        opDepoLockGroup($uid, $cid);
        $d = opDepoActive($uid, $cid, $did);
        if (!$d) return 'depo_wrong';
        $compound = opDepoCompoundForPlan(DecimalInput::read($compnd === '' ? 0 : $compnd), $d);
        if ($compound->compareTo(Decimal::of($d['dCompnd'])) === 0) return 0;
        return $db->update('Deps', ['dCompnd'=>(string)$compound], '', 'dID=?d AND dState=1', [$did]) ?: 'depo_not_saved';
    });
}
//=============Calculate percent from calendar========================================================================
function opDepoGetCalendarPerc()
{
    useLib('calendar');
    return (string)DecimalInput::read(getDayPerc(time()));
}
//=====================================================================================================================
function opDepoCharge($d, $perc = -1, $cdate = false) // !!! Deps JOIN Plans, Users
{
    global $db;
    if (empty($d['dID']) || empty($d['dNTS'])) return 'depo_wrong';
    $did = (int)$d['dID'];
    try {
        $percent = DecimalInput::read($perc);
        $manual = !$percent->isNegative();
        $boundary = $manual ? timeToStamp($cdate ?: 0) : (string)$d['dNTS'];
        $fingerprint = IdempotencyRequest::fingerprint(['deposit_id'=>$did, 'boundary'=>$boundary,
            'percent'=>$manual ? HScript\Finance\RequestDecimal::normalize($perc, true) : 'auto']);
        $method = $db->link->inTransaction() ? 'executeInTransaction' : 'execute';
        $result = (new IdempotencyStore($db))->$method($manual ? 'accrual:manual' : 'accrual:deposit', $did . ':' . $boundary, 0, $fingerprint,
            function () use ($db, $did, $boundary, $manual, $percent, $cdate) {
                $owner = $db->fetch1Row($db->select('Deps', 'duID,dcID', 'dID=?d', [$did]));
                if (!$owner) throw new FinancialOperationException('depo_wrong');
                useLib('balance');
                opDepoLockGroup($owner['duID'], $owner['dcID']);
                // The owner lookup may have established an older repeatable-read
                // snapshot. After locking the group, use its current principal
                // and state, including top-ups/withdrawals committed meanwhile.
                $d = $db->fetch1Row($db->query('SELECT * FROM Deps JOIN Plans ON pID=dpID JOIN Users ON uID=duID WHERE dID=?d FOR UPDATE', [$did]));
                if (!$d || (!$manual && (string)$d['dNTS'] !== $boundary)) throw new FinancialOperationException('depo_boundary_changed');
                if ($d['dState'] != 1) throw new FinancialOperationException('depo_wrong_state');
                if (!$manual) { // auto
                    $percent = Decimal::of($d['pPerc']);
                    //----------Change from calendar----------
                    if ($d['pPClndr']) {
                        $calendar = Decimal::of(opDepoGetCalendarPerc());
                        if ($calendar->compareTo(Decimal::of(0)) > 0) $percent = $calendar;
                    }
                    //--------------------------------------
                    $t = stampToTime($d['dNTS']);
                    $nts = $t + $d['pPer'] * HS2_UNIX_HOUR;
                    $nc = $d['pNoCalc'] || $d['uState'] != 1;
                    if (!$nc && $d['pWDays'] && $d['pPer'] <= 24) {
                        useLib('calendar');
                        $nc = getDayType($t) > 1;
                    }
                } else { // manual
                    if (!$cdate) throw new FinancialOperationException('date_wrong');
                    $percent = $percent->rounded(2, RoundingMode::HalfUp);
                    $nts = $cdate + $d['pPer'] * HS2_UNIX_HOUR;
                    $nc = $d['pNoCalc'] || $d['uState'] != 1 || $percent->isZero();
                }
                $change = ['dNTS'=>timeToStamp($nts), 'dLTS'=>timeToStamp()];
                if ($nc) { // NO Calc
                    if (!$db->update('Deps', $change, '', 'dID=?d AND dState=1 AND dNTS=?', [$did, $d['dNTS']]))
                        throw new FinancialOperationException('depo_not_saved');
                    return new IdempotencyResult(['charged'=>false]);
                }
                global $_currs, $_cfg;
                $terminal = $d['pNPer'] > 0 && $d['dNPer'] + 1 >= $d['pNPer'];
                $amounts = DepositPolicy::accrual(Decimal::of($d['dZD']), Decimal::of($d['dZC']), Decimal::of($d['dZP']),
                    $percent, Decimal::of($d['pReturn']), Decimal::of($d['dCompnd']), $terminal,
                    CurrencyScale::resolve($_currs[$d['dcID']], $_cfg));
                $change['dZP'] = DepositPolicy::stored($amounts['totalProfit']);
                $change['dNPer'] = $d['dNPer'] + 1;
                if ($terminal) {
                    // On the terminal period return eligible principal plus all compounded
                    // profit; worked principal leaves the deposit bucket exactly once.
                    $change['dState'] = 2;
                    //$change['dZD'] = 0;
                    $change['dNTS'] = timeToStamp(0);
                }
                // During a non-terminal period only the configured fraction of profit is
                // reinvested; the remainder stays in the available balance.
                if (!$db->update('Deps', $change, '', 'dID=?d AND dState=1 AND dNTS=?', [$did, $d['dNTS']]))
                    throw new FinancialOperationException('depo_not_saved');
                FinancialOperationException::check(opChangeStoredBalance($d['duID'], $d['dcID'],
                    $amounts['profit']->plus($amounts['returned']), $amounts['release']->negated(), 0, false, 0, 'CALCIN'));
                $event = ['tag'=>$did, 'did'=>$did, 'pid'=>$d['dpID'], 'cid'=>$d['dcID'],
                    'sum'=>DepositPolicy::stored($amounts['profit']), 'csum'=>DepositPolicy::stored($amounts['reinvest']),
                    'rsum'=>DepositPolicy::stored($amounts['returned']), 'nper'=>$change['dNPer']];
                if (!$amounts['profit']->isZero())
                    FinancialOperationException::check(opOperCreateInfo($d['duID'], 'CALCIN', $d['dcID'], $event['sum'], $event, '#' . $did));
                if (!$amounts['returned']->isZero())
                    FinancialOperationException::check(opOperCreateInfo($d['duID'], 'TAKE', $d['dcID'], $event['rsum'], $event));
                elseif (!$amounts['reinvest']->isZero())
                    FinancialOperationException::check(opDepoAdd($d['duID'], $d['dcID'], $amounts['reinvest'], $did, true));
                $event['percs'] = asArray($d['pPPerc'], HS2_NL);
                HScript\Finance\ReferralSourceEvent::dispatch($db->link, 'DepoCharge', (int)$d['duID'], $event,
                    static fn() => opEvent('DepoCharge', $d['duID'], $event));
                if ($terminal)
                    opEvent('DepoClose', $d['duID'], ['did'=>$did, 'cid'=>$d['dcID'], 'state'=>2, 'sum'=>DepositPolicy::stored($amounts['worked'])]);
                return new IdempotencyResult(['charged'=>true]);
            });
        return $result->data['charged'];
    } catch (FinancialOperationException $error) { return $error->getMessage(); }
    catch (HScript\Finance\IdempotencyConflict $error) { return 'accrual_input_conflict'; }
    catch (InvalidArgumentException|OverflowException $error) { return opDepoMathError($error); }
}

function opDepoLastManualChargeDate()
{
	global $_cfg;
	$y = gmdate('Y');
	$m = gmdate('n');
	$d = gmdate('j');
	$cdate = gmmktime(0, 0, 0, $m, $d, $y);
	$md = $_cfg['Depo_ManualDay'];
	switch ($_cfg['Depo_ManualPeriod'])
	{
	case 1:
		if (($md < 1) or ($md > 7))
			$nd = 7;
		else
			$nd = $md;
		$dd = gmdate('N');
		if ($dd != $nd)
			$cdate = gmmktime(0, 0, 0, $m, $d - $dd - 7 + $nd, $y);
		break;
	case 2:
		$ld = gmdate('j', gmmktime(0, 0, 0, $m + 1, 0, $y));
		if (($md < 1) or ($md > $ld))
		{
			$md = 0;
			$nd = $ld;
		}
		else
			$nd = $md;
		if ($d != $nd)
			if ($md == 0)
				$cdate = gmmktime(0, 0, 0, $m, 0, $y);
			else
				$cdate = gmmktime(0, 0, 0, $m - 1, $md, $y);
		break;
	}
	return $cdate;
}

function depoPrepareCalc()
{
	global $_GS, $db, $_cfg, $catalogCache;
	$compounds = array();
	$choices = $_cfg['Depo__Compnd'] ?? array();
	foreach (is_array($choices) ? $choices : array() as $c)
	{
		try {
			$decimal = DepositForecast::input($c);
			if (!$decimal->isNegative() && $decimal->compareTo(Decimal::of(100)) <= 0)
				$compounds[(string)$decimal] = (string)$decimal;
		} catch (InvalidArgumentException|\Brick\Math\Exception\MathException) { /* Invalid configured choice is unavailable. */ }
	}
	View::setPage('calc_compnd', $compounds, 0);
	$plans = $catalogCache->remember(
		CatalogCache::PLANS,
		'visible:pMin,pMax',
		static fn(): array => $db->fetchIDRows(
			$db->select('Plans', '*', 'pHidden=0', array(), 'pMin, pMax'),
			false,
			'pID'
		)
	);
	$select_plans = array();
	$plan_rows = array();
	foreach ($plans as $id => $pl)
	{
		try {
			$row = array('id' => (int)$id, 'name' => StringHelper::textLangFilter($pl['pName'], $_GS['lang']),
				'periods' => max(0, (int)$pl['pNPer']), 'days' => (int)$pl['pDays']);
			foreach (array('min'=>'pMin', 'max'=>'pMax', 'perc'=>'pPerc', 'bonus'=>'pBonus', 'return'=>'pReturn') as $field=>$column)
				$row[$field] = (string)DepositForecast::input($pl[$column] ?? 0);
			$plan_rows[] = $row;
			$select_plans[] = $row['name'];
		} catch (InvalidArgumentException|\Brick\Math\Exception\MathException) { /* Malformed historical plan cannot produce a forecast. */ }
	}

	$selected_plan = isset_RQ('plan') ? (int)_RQ('plan') : 0;
	if (!isset($plan_rows[$selected_plan])) $selected_plan = 0;
	$default_sum = isset($plan_rows[$selected_plan]) ? Decimal::of($plan_rows[$selected_plan]['min']) : Decimal::of(10);
	if ($default_sum->compareTo(Decimal::of(0)) <= 0) $default_sum = Decimal::of(10);
	$sum = $default_sum;
	$cmpd = Decimal::of(0);
	try { if (isset_RQ('sum')) $sum = DepositForecast::input(_RQ('sum')); }
	catch (InvalidArgumentException|\Brick\Math\Exception\MathException) { /* Retain invalid request fallback to the plan minimum. */ }
	if ($sum->compareTo(Decimal::of(0)) <= 0) $sum = $default_sum;
	try { if (isset_RQ('cmpd')) $cmpd = DepositForecast::input(_RQ('cmpd')); }
	catch (InvalidArgumentException|\Brick\Math\Exception\MathException) { /* Retain invalid request fallback to zero. */ }
	if ($cmpd->isNegative() || $cmpd->compareTo(Decimal::of(100)) > 0) $cmpd = Decimal::of(0);
	if ($compounds)
	{
		$choice = reset($compounds);
		foreach ($compounds as $c)
			if ($cmpd->compareTo(Decimal::of($c)) === 0) { $choice = $c; break; }
		$cmpd = Decimal::of($choice);
	}

	$result = array();
	$error = false;
	if ($plan_rows)
	{
		$plan = $plan_rows[$selected_plan];
		try {
			$result = array('plan' => $plan) + DepositForecast::calculate($sum, Decimal::of($plan['bonus']),
				Decimal::of($plan['perc']), Decimal::of($plan['return']), $cmpd, $plan['periods']);
		} catch (InvalidArgumentException|OverflowException) { $error = true; }
	}

	View::setPage('calc_pselect', $select_plans, 0);
	View::setPage('calc_plan_rows', $plan_rows, 0);
	View::setPage('calc_selected_plan', $selected_plan, 0);
	View::setPage('calc_sum_value', (string)$sum, 0);
	View::setPage('calc_cmpd_value', (string)$cmpd, 0);
	View::setPage('calc_result', $result, 0);
	View::setPage('calc_error', $error, 0);
}

function opDepoProgressWidthClass($value)
{
	$value = (int)round(max(0, min(100, (float)$value)));
	if ($value <= 0)
		return 'w-0';
	if ($value >= 100)
		return 'w-full';
	return 'w-[' . $value . '%]';
}

function opDepoListPrepare(&$list, ?int $now = null)
{
	global $_cfg, $_currs;
	useLib('balance');
	$now ??= time();
	foreach ($list as &$d)
	{
		$total_periods = max(0, (int)$d['pNPer']);
		$completed_periods = max(0, (int)$d['dNPer']);
		$d['PeriodTotal'] = $total_periods;

		if ($d['dState'] != 1)
		{
			$d['PeriodNumber'] = $total_periods > 0 ? min($completed_periods, $total_periods) : $completed_periods;
			$d['PeriodProgress'] = 100;
			$d['CurrentProgress'] = 100;
			$d['PeriodProgressClass'] = 'w-full';
			continue;
		}

		$t = stampToTime($d['dNTS']);
		$period_seconds = max(1, (int)$d['pPer'] * HS2_UNIX_HOUR);
		$period_start = stampToTime($d['dLTS']);
		if (($period_start <= 0) or ($period_start >= $t))
			$period_start = stampToTime($d['dCTS']);
		if (($period_start <= 0) or ($period_start >= $t))
			$period_start = $t - $period_seconds;
		$period_duration = max(1, $t - $period_start);
		$period_elapsed = max(0, min($now - $period_start, $period_duration));
		$current_period_progress = min(100, max(0, $period_elapsed * 100 / $period_duration));

		$d['PeriodNumber'] = $total_periods > 0
			? min($total_periods, $completed_periods + 1)
			: $completed_periods + 1;
		$d['PeriodProgress'] = $total_periods > 0
			? min(100, (($completed_periods + ($current_period_progress / 100)) * 100 / $total_periods))
			: $current_period_progress;
		$d['CurrentProgress'] = $d['PeriodProgress'];
		$d['PeriodProgressClass'] = opDepoProgressWidthClass($d['PeriodProgress']);

		$perc = $d['pPerc'] ?? '0'/* + $d['pReturn']*/;
		//----------Change from calendar----------
		if ($d['pPClndr'])
		{
			if (DecimalInput::read($pClndr = opDepoGetCalendarPerc())->compareTo(Decimal::of(0)) > 0)
			 $perc = $pClndr;
		}
		//--------------------------------------
		$profit = DecimalInput::read($d['dZD'] ?? '0')->multipliedBy(DecimalInput::read($perc))
			->dividedBy(Decimal::of(100), 6, RoundingMode::HalfUp);
		$d['NextCalcTime'] = $t;

		$dt = max(0, stampToTime($d['dNTS']) - $now);
		$d['Timer']['D'] = floor($dt / 86400);
		$d['Timer']['H'] = floor($dt / 3600) % 24;
		$d['Timer']['M'] = floor($dt / 60) % 60;
		$d['Timer']['S'] = $dt % 60;

		$tcalc = min($period_elapsed, $period_seconds);
		if ($tcalc <= 1)
			$tcalc = 0;
		$d['CurrentProfit'] = (string)$profit->multipliedBy(Decimal::of($tcalc))
			->dividedBy(Decimal::of($period_seconds), 6, RoundingMode::HalfUp);
		$hours = (int)$d['pPer'];
		$scale = CurrencyScale::resolve($_currs[$d['dcID']] ?? array(), $_cfg);
		$d['ProfitPerHour'] = (string)($hours > 0
			? $profit->dividedBy(Decimal::of($hours), $scale, RoundingMode::HalfUp)
			: Decimal::of(0)->rounded($scale, RoundingMode::HalfUp));
	}
}

function depoGetStat()
{
	global $db, $_cfg, $_vcurrs, $_currs;
	$stat = array();
	$startedAt = stampToTime((string)($_cfg['Depo_S0'] ?? ''));
	if ($startedAt <= 0)
	{
		$installedAt = (int)($_cfg['Telemetry_InstalledAt'] ?? 0);
		$startedAt = $installedAt >= 946684800 && $installedAt <= time()
			? $installedAt
			: time();
	}
	$stat['worked'] = max(0, (int)floor((time() - $startedAt) / HS2_UNIX_DAY));
	$stat['users'] = (int)$db->count('Users', 'uState=1') + (int)(isset($_cfg['Depo_S1']) ? $_cfg['Depo_S1'] : 0);
	$stat['usersonline'] = (int)$db->count('Users', 'uState=1 and uLTS> ?', array(timeToStamp(time() - 5 * HS2_UNIX_MINUTE)));
	$zin = ReadAmount::decimal($_cfg['Depo_S2'] ?? null);
	$zout = ReadAmount::decimal($_cfg['Depo_S3'] ?? null);
	$stat['zin2'] = array();
	$stat['zout2'] = array();
	foreach ($_vcurrs as $curr => $cids)
		if ($cids) {
			$in = ReadAmount::decimal($db->fetch1($db->select('Opers', 'SUM(oSum)', "oOper='CASHIN' AND oState=3 and ocID ?i", array($cids))))
				->plus(ReadAmount::decimal($_cfg['Depo_S2'.$curr] ?? null));
			// Keep SQL's historical NULL semantics for oSum-oComis and the net payout definition.
			$out = ReadAmount::decimal($db->fetch1($db->select('Opers', 'SUM(oSum-oComis)', "oOper='CASHOUT' AND oState=3 and ocID ?i", array($cids))))
				->plus(ReadAmount::decimal($_cfg['Depo_S3'.$curr] ?? null));
			$unpriced=false;foreach($cids as $cid)if(($_currs[$cid]['cCID']??'')==='CCT')$unpriced=true;
			$rate = $unpriced?Decimal::of(0):ReadAmount::decimal($_cfg['Bal_Rate'.$curr] ?? 1);
			$stat['zin2'][$curr] = (string)$in;
			$stat['zout2'][$curr] = (string)$out;
			$zin = $zin->plus($in->multipliedBy($rate));
			$zout = $zout->plus($out->multipliedBy($rate));
		}
	// Valued totals/offsets keep their exact precision; rounding belongs to display.
	$stat['zin'] = (string)$zin;
	$stat['zout'] = (string)$zout;
	$stat['zref'] = (string)ReadAmount::decimal($db->fetch1($db->select('Opers', 'SUM(oSum)', "oOper='REF' AND oState=3")))
		->plus(ReadAmount::decimal($_cfg['Depo_S4'] ?? null));
	$stat['zreinv'] = (string)ReadAmount::decimal($db->fetch1($db->select('Opers', 'SUM(oSum)', "oOper='GIVE' AND oState=3 AND NOT (oMemo ?%)", array('Auto'))))
		->plus(ReadAmount::decimal($_cfg['Depo_S5'] ?? null));
	$stat['deps'] = (int)$db->count('Deps', 'dState=1') + (int)(isset($_cfg['Depo_S6']) ? $_cfg['Depo_S6'] : 0);
	$stat['depsclosed'] = (int)$db->count('Deps', 'dState>=2') + (int)(isset($_cfg['Depo_S7']) ? $_cfg['Depo_S7'] : 0);
	$stat['lastuser'] = $db->fetch1Row($db->select('Users LEFT JOIN AddInfo ON auID=uID', 'uLogin, aCIP', 'uState=1', array(), 'uID desc', 1));
	if (($lastIn = ReadAmount::decimal($_cfg['Depo_S8'] ?? null))->compareTo(Decimal::of(0)) > 0)
		$stat['lastinop'] = array('oSum' => (string)$lastIn, 'uLogin' => $_cfg['Depo_S9'] ?? '');
	else
		$stat['lastinop'] = $db->fetch1Row($db->select('Opers LEFT JOIN Users ON uID=ouID', 'oSum, uLogin', "oOper='CASHIN' AND oState=3", array(), 'oCTS desc', 1));
	if (($lastOut = ReadAmount::decimal($_cfg['Depo_S11'] ?? null))->compareTo(Decimal::of(0)) > 0)
		$stat['lastoutop'] = array('oSum' => (string)$lastOut, 'uLogin' => $_cfg['Depo_S12'] ?? '');
	else
		$stat['lastoutop'] = $db->fetch1Row($db->select('Opers LEFT JOIN Users ON uID=ouID', 'oSum, uLogin', "oOper='CASHOUT' AND oState=3", array(), 'oCTS desc', 1));
	foreach (array('lastinop', 'lastoutop') as $key)
		if (is_array($stat[$key]) && array_key_exists('oSum', $stat[$key]))
			$stat[$key]['oSum'] = (string)ReadAmount::decimal($stat[$key]['oSum']);
	return $stat;
}

?>
