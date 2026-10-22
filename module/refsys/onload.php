<?php

use HScript\Util\StringHelper;
use HScript\Database\TransactionScope;
use HScript\Finance\CurrencyScale;
use HScript\Finance\Decimal;
use HScript\Finance\DecimalInput;
use HScript\Finance\FinancialOperationException;
use HScript\Finance\ReferralPolicy;
use HScript\Finance\IdempotencyStore;
use HScript\Finance\IdempotencyRequest;
use HScript\Finance\IdempotencyResult;
use HScript\Finance\RequestDecimal;
use HScript\Finance\ReferralSourceEvent;

/** Financial issuance uses current locked sponsor state, ancestry and overrides. */
function _refReadUser($uid)
{
    global $db;
    if (!$db->link->inTransaction()) return opReadUser($uid);
    if ($uid <= 0) return [];
    return $db->fetch1Row($db->query('SELECT * FROM Users LEFT JOIN AddInfo ON auID=uID WHERE uID=?d FOR UPDATE', [$uid]));
}

function _refPay($uid, $usr, $cid, $sum, $perc, $level = 0) // uID, uLogin
{
	useLib('balance');
	global $_cfg, $_currs;
	if (!isset($_currs[$cid])) return 'psys_wrong';
	// An inactive sponsor receives nothing; higher active ancestors still qualify.
	$sponsor = _refReadUser($uid);
	if (!$sponsor || !in_array((int)$sponsor['uState'], [1, 2], true)) return false;
	try {
		$source = $sum instanceof Decimal ? $sum : DecimalInput::read($sum);
		$percent = $perc instanceof Decimal ? $perc : ReferralPolicy::percentage($perc);
		// Nonpositive configured rates and rounded-zero rewards have no payout.
		if ($percent->compareTo(Decimal::of(0)) <= 0) return false;
		$reward = ReferralPolicy::payout($source, $percent, CurrencyScale::resolve($_currs[$cid], $_cfg));
		if ($reward->isZero()) return false;
	} catch (OverflowException) { return 'sum_overflow'; }
	catch (InvalidArgumentException $error) {
		return $error->getMessage() === 'amount_storage_precision' ? 'sum_precision'
			: ($error->getMessage() === 'ref_config_wrong' ? 'ref_config_wrong' : 'sum_wrong');
	}
	return opOperCreate($uid, 'REF', $cid, $reward,
		array('tag' => $usr['uID'], 'reflogin' => $usr['uLogin']),
		$usr['uLogin'] . StringHelper::valueIf($level > 1, " L$level"), true, true);
}

/** A source event cannot keep only the first successful levels of a payout chain. */
function _refPayByLevels($uid, $cid, $sum, $percs, $type, $userproc, ?array &$operationIds = null)
{
    global $db;
    $operationIds = [];
    if (!$percs) return false;
    try {
        return TransactionScope::run($db->link, function () use ($uid, $cid, $sum, $percs, $type, $userproc, &$operationIds) {
            global $db;
            $usr = _refReadUser($uid);
            if (!$usr || !($uid = $usr['uRef'])) return false;
            $visited = [(int)$usr['uID'] => true];
            if ($type > 0 && isset($visited[(int)$uid])) return 'ref_cycle';
            if ($type > 0) {
                switch ($type) {
                case 3: // Tier by the total principal of directly referred users.
                    $n = $db->fetch1($db->query('SELECT SUM(dZD) FROM Deps JOIN Users ON uID=duID WHERE uRef=?d LOCK IN SHARE MODE', [$uid]));
                    break;
                case 4: // Tier by the sponsor's own active deposit principal.
                    $n = $db->fetch1($db->query('SELECT SUM(dZD) FROM Deps WHERE duID=?d AND dState=1 LOCK IN SHARE MODE', [$uid]));
                    break;
                default:
                    $n = $db->fetch1($db->query('SELECT COUNT(*) FROM Users WHERE uRef=?d AND (SELECT COUNT(*) FROM Deps WHERE duID=uID LOCK IN SHARE MODE)>0 LOCK IN SHARE MODE', [$uid]));
                }
                if (!$rusr = _refReadUser($uid)) return false;
                if ($a = asArray($rusr[$userproc], HS2_NL)) $percs = $a;
                // Threshold entries use "minimum-percentage" notation. The last
                // threshold not exceeding the metric determines the single payout.
                if (!is_array($percs)) return 'ref_config_wrong';
                $tier = ReferralPolicy::tier(Decimal::of($n ?: 0), $percs);
                $id = FinancialOperationException::check(_refPay($uid, $usr, $cid, $sum, $tier['percent'], $tier['level']));
                if (is_int($id)) $operationIds[] = $id;
            } else {
                // Flat mode walks the uRef chain and may override every level's
                // percentage with that sponsor's per-user configuration.
                foreach (asArray($percs) as $i => $perc) {
                    if (isset($visited[(int)$uid])) return 'ref_cycle';
                    if (!$rusr = _refReadUser($uid)) break; // upref
                    $visited[(int)$uid] = true;
                    if ($a = asArray($rusr[$userproc], HS2_NL)) $perc = $a[$i] ?? 0;
                    $perc = ReferralPolicy::percentage($perc);
                    if ($perc->compareTo(Decimal::of(0)) > 0) {
                        $id = FinancialOperationException::check(_refPay($uid, $usr, $cid, $sum, $perc, $i + 1));
                        if (is_int($id)) $operationIds[] = $id;
                    }
                    $uid = $rusr['uRef'];
                }
            }
            return true;
        });
    } catch (FinancialOperationException $error) { return $error->getMessage(); }
    catch (OverflowException) { return 'sum_overflow'; }
    catch (InvalidArgumentException $error) { return $error->getMessage() === 'amount_storage_precision' ? 'sum_precision' : 'ref_config_wrong'; }
}

/** Only a source-owning transaction may first issue this event's rewards. */
function _refEventOnce($event, $uid, array $params, callable $pay): void
{
    global $db;
    $uid = (int)$uid;
    $id = (int)($params[$event === 'Oper' ? 'oid' : 'did'] ?? 0);
    $period = $event === 'DepoCharge' ? (int)($params['nper'] ?? 0) : 0;
    $cid = (int)($params['cid'] ?? 0);
    if ($uid <= 0 || $id <= 0 || $cid <= 0 || ($event === 'DepoCharge' && $period <= 0))
        throw new FinancialOperationException('ref_source_wrong');
    $namespace = match ($event) {
        'Oper' => 'referral:cashin',
        'DepoCreate' => 'referral:deposit',
        'DepoCharge' => 'referral:accrual',
        default => throw new FinancialOperationException('ref_source_wrong'),
    };
    $key = (string)$id . ($period ? ':' . $period : '');
    $basis = RequestDecimal::normalize($params['sum'], true);
    $fingerprint = IdempotencyRequest::fingerprint(['source_id' => $id, 'period' => $period,
        'user_id' => $uid, 'currency_id' => $cid, 'sum' => $basis]);
    $participating = $db->link->inTransaction();
    $sourceOwned = ReferralSourceEvent::owns($db->link, $event, $uid, $params);
    $method = $participating ? 'executeInTransaction' : 'execute';
    (new IdempotencyStore($db))->$method($namespace, $key, $uid, $fingerprint,
        static function () use ($db, $event, $uid, $id, $period, $cid, $basis, $params, $pay, $sourceOwned): IdempotencyResult {
            // A completed legacy source has no immutable payout binding. A bare
            // replay cannot infer it from sponsor tags or matching reward sums.
            // Already-bound events replay their original receipt before this work.
            if (!$sourceOwned) throw new FinancialOperationException('ref_source_unbound');
            if ($event === 'Oper')
                $source = $db->fetch1($db->query("SELECT oID FROM Opers WHERE oID=?d AND ouID=?d AND oOper='CASHIN' AND oState=3 LOCK IN SHARE MODE", [$id, $uid]));
            else
                $source = $db->fetch1($db->query('SELECT dID FROM Deps WHERE dID=?d AND duID=?d AND dcID=?d' . ($period ? ' AND dNPer=?d' : '') . ' LOCK IN SHARE MODE', array_merge([$id, $uid, $params['cid']], $period ? [$period] : [])));
            if (!$source) throw new FinancialOperationException('ref_source_wrong');
            $ids = [];
            $pay($ids);
            return new IdempotencyResult(['event' => $event, 'source_id' => $id, 'period' => $period,
                'currency_id' => $cid, 'basis' => $basis, 'operation_ids' => $ids], 200, $event === 'Oper' ? $id : null);
        });
}

function onRefSysEvent($oper, $uid, $params)
{
	global $db, $_cfg;
	switch ($oper)
	{
	case 'Oper':
		if ($params['oper'] != 'CASHIN')
			break;
		if (!empty($params['cid2']) && isset($params['sum2']))
		{
			$params['cid'] = $params['cid2'];
			$params['sum'] = $params['sum2'];
		}
		_refEventOnce($oper, $uid, $params, static function (&$ids) use ($uid, $params) {
			global $_cfg;
			FinancialOperationException::check(_refPayByLevels($uid, $params['cid'], $params['sum'],
				$_cfg['Ref__Perc'] ?? [], $_cfg['Ref_Type'] ?? 0, 'aPerc', $ids));
		});
		break;
	case 'DepoCreate':
		_refEventOnce($oper, $uid, $params, static function (&$ids) use ($uid, $params) {
			global $_cfg;
			FinancialOperationException::check(_refPayByLevels($uid, $params['cid'], $params['sum'],
				StringHelper::exValue($_cfg['Ref__DPerc'] ?? [], $params['percs'] ?? []),
				$_cfg['Ref_DType'] ?? 0, 'aDPerc', $ids));
		});
		break;
	case 'DepoCharge':
		_refEventOnce($oper, $uid, $params, static function (&$ids) use ($uid, $params) {
			global $_cfg;
			FinancialOperationException::check(_refPayByLevels($uid, $params['cid'], $params['sum'],
				StringHelper::exValue($_cfg['Ref__PPerc'] ?? [], $params['percs'] ?? []),
				$_cfg['Ref_PType'] ?? 0, 'aPPerc', $ids));
		});
		break;
	default:
		return;
	}
}

?>
