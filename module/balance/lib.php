<?php

use HScript\Util\StringHelper;
use HScript\Database\TransactionScope;
use HScript\Finance\WithdrawalDispatcher;
use HScript\Payment\PaymentManager;
use HScript\Finance\CommissionPolicy;
use HScript\Finance\CurrencyScale;
use HScript\Finance\Decimal;
use HScript\Finance\DecimalInput;
use HScript\Finance\ExchangePolicy;
use HScript\Finance\Rate;
use HScript\Finance\Money;
use HScript\Finance\Percentage;
use HScript\Finance\StoredAmount;
use Brick\Math\RoundingMode;

use HScript\Template\View;

/*
BONUS - by system						0	0	0		0	0	0		+$	0	0
PENALTY									0	0	0		0	0	0		-$	0	0

CASHIN - add funds from p.sys			0	0	0		0	0	0		+$	0	0
	[needs 'oParams2' filled date, batch or set via opConfirm 'params']
CASHOUT - withdraw to p.sys				$$	0	0		-$	0	+$		0	0	-$

EX - exchange							$$	0	0		-$	+$	0		0	-$	0
EXIN									0	0	0		0	0	0		+$	0	0 (cid2)

TR - send								$$	0	0		-$	0	+$		0	0	-$
TRIN - receive							0	0	0		0	0	0		+$	0	0 (uid2)

BUY - buy item							$$	0	0		-$	+$	0		0	-$	0
SELL - sell item 						0	0	0		0	0	0		+$	0	0
BUY2 - use service 						$$	0	0		-$	+$	0		0	-$	0
SELL2 - provide service					0	0	0		0	0	0		+$	0	0

REF - referral comission N				0	0	0		0	0	0		+$	0	0

GIVE - make deposit						$$	0	0		-$	+$	0		0	0	0
TAKE - withdraw to balance				0	0	0		0	0	0		+$	-$	0

CALCIN - periodically by system			0	0	0		0	0	0		+$	0	0
CALCOUT									$$	0	0		0	0	0		-$	0	0
----------------------------------
0 = Ожидание (подтверждения)
1 = Ожидает пополнения
2 = Ожидает обработки (выполнения)
3 = Выполнена
4 = Отклонена
5 = Отменена

Двойные операции:
Пополнение+обмен (cid2, sum2)
Пополнение+покупка (vid, amount)
пополнение+вклад (pid)
Обмен (cid2, sum2)+вывод
*/

function opUser($uid)
{
	global $db;
	return $db->count('Users', 'uID=?d and (uState=1 or uState=2)', array($uid));
}

function opCalcComis($cid, $oper, $sum, $by_admin)
{
	global $db, $_cfg, $_currs;
	$c = isset($_currs[$cid]) ? $_currs[$cid] : null;
	if (!$c)
		return 'psys_wrong';
	$prfx = 'c' . $oper;
	if (!$by_admin and empty($c[$prfx . 'Mode']) and ($oper != 'EXIN') and ($oper != 'TRIN'))
		return 'oper_disabled';
	try {
		$amount = DecimalInput::read($sum);
		if (!empty($c[$prfx . 'Int']) && !$amount->isInteger()) return 'sum_not_int';
		$minimum = DecimalInput::read($c[$prfx . 'Min'] ?? 0);
		$maximum = DecimalInput::read($c[$prfx . 'Max'] ?? 0);
		if ($amount->compareTo($minimum) < 0) return 'sum_min';
		if ($maximum->compareTo(Decimal::of(0)) > 0 && $amount->compareTo($maximum) > 0) return 'sum_max';
		// Calculate with extra precision first, then round to the currency scale and
		// clamp the result to the configured absolute commission bounds.
		$fee = CommissionPolicy::calculate($amount,
			Percentage::of((string)DecimalInput::read($c[$prfx . 'Comis'] ?? 0)),
			DecimalInput::read($c[$prfx . 'ComisMin'] ?? 0), DecimalInput::read($c[$prfx . 'ComisMax'] ?? 0),
			(string)$cid, CurrencyScale::resolve($c, $_cfg));
		StoredAmount::exact($fee->decimal());
		return $fee;
	} catch (OverflowException) { return 'sum_overflow'; }
	catch (InvalidArgumentException $e) {
		return $e->getMessage() === 'amount_storage_precision' ? 'sum_precision' : 'sum_wrong';
	}
}

/** New money ingress; remaining deposit/rate callers use an explicit boundary. */
function opMoney($value, $cid): Money
{
	global $_cfg, $_currs;
	return Money::fromDecimal($value instanceof Decimal ? $value : DecimalInput::read($value), (string)$cid,
		CurrencyScale::resolve($_currs[$cid] ?? [], $_cfg), RoundingMode::HalfUp);
}

/** Bare base-denominated CASHIN/bonus conversion; no exchange adjustments. */
function opAmountFromBase($value, $cid): Money|string
{
	global $_cfg, $_currs;
	if (!isset($_currs[$cid])) return 'psys_wrong';
    if(($_currs[$cid]['cCID']??'')==='CCT')return 'ex_rate_not_set';
	try {
		$amount = $value instanceof Decimal ? $value : DecimalInput::read($value);
		if ($amount->compareTo(Decimal::of(0)) <= 0) return 'sum_wrong';
	} catch (InvalidArgumentException) { return 'sum_wrong'; }
	try {
		$rate = Rate::of((string)DecimalInput::read($_cfg['Bal_Rate' . $_currs[$cid]['cCurrID']] ?? 0));
	} catch (InvalidArgumentException) { return 'ex_rate_not_set'; }
	try {
		$money = opMoney($rate->divide($amount, CurrencyScale::resolve($_currs[$cid], $_cfg), RoundingMode::HalfUp), $cid);
		if ($money->decimal()->compareTo(Decimal::of(0)) <= 0) return 'sum_wrong';
		StoredAmount::exact($money->decimal());
		return $money;
	} catch (OverflowException) { return 'sum_overflow'; }
	catch (InvalidArgumentException $e) { return $e->getMessage() === 'amount_storage_precision' ? 'sum_precision' : 'sum_wrong'; }
}

function opAdditionalComis($sum, $percentage, $cid): Money
{
	global $_cfg, $_currs;
	$percent = DecimalInput::read($percentage);
	$fee = $percent->compareTo(Decimal::of(0)) > 0
		? Percentage::of((string)$percent)->apply(DecimalInput::read($sum), 6, RoundingMode::HalfUp)
		: Decimal::of(0);
	return Money::fromDecimal($fee, (string)$cid, CurrencyScale::resolve($_currs[$cid], $_cfg), RoundingMode::HalfUp);
}

function opCalcExSum($is_out, $cid, $sum)
{
	global $_cfg, $_currs;
	if (!isset($_currs[$cid])) return 'psys_wrong';
	$c = $_currs[$cid];
    if(($c['cCID']??'')==='CCT')return 'ex_rate_not_set';
	try {
		$amount = $sum instanceof Decimal ? $sum : DecimalInput::read($sum);
		if ($amount->compareTo(Decimal::of(0)) <= 0) return 'ex_sum_wrong';
	} catch (InvalidArgumentException) { return 'ex_sum_wrong'; }
	try {
		$rate = Rate::of((string)DecimalInput::read($_cfg['Bal_Rate' . $c['cCurrID']] ?? 0));
	} catch (InvalidArgumentException) { return 'ex_rate_not_set'; }
	try {
		// Rates are stored as base-currency value per source unit. Converting out
		// multiplies by the rate; converting in divides and applies the inverse fee.
		return ExchangePolicy::convert($amount, $rate,
			Percentage::of((string)DecimalInput::read($c[$is_out ? 'cEXOut' : 'cEXIn'] ?? 0)),
			(bool)$is_out, CurrencyScale::resolve($c, $_cfg));
	} catch (InvalidArgumentException) { return 'ex_rate_not_set'; }
	catch (\Brick\Math\Exception\MathException | OverflowException) { return 'ex_overflow'; }
}

function opCalcEx($cid, $cid2, $sum)
{
	global $_currs;
	if (!isset($_currs[$cid])) return 'psys_wrong';
	if (!isset($_currs[$cid2])) return 'psys2_wrong';
	if ($cid != 1) {
		$sum = opCalcExSum(true, $cid, $sum);
		if (is_string($sum)) return $sum;
	}
	if ($cid2 != 1) {
		$sum = opCalcExSum(false, $cid2, $sum);
		if (is_string($sum)) return $sum;
	}
	try {
		// The source intermediate may exceed storage range. Only the final quote
		// is stored, at the same target scale used by the paired EXIN operation.
		$money = opMoney($sum, $cid2);
		if ($money->decimal()->compareTo(Decimal::of(0)) <= 0) return 'ex_sum_wrong';
		StoredAmount::exact($money->decimal());
		return $money;
	} catch (OverflowException) { return 'sum_overflow'; }
	catch (InvalidArgumentException $e) { return $e->getMessage() === 'amount_storage_precision' ? 'sum_precision' : 'ex_sum_wrong'; }
}

/** Decimal-only receipt presentation; existing stored quotes are never rewritten. */
function opExchangeQuoteDisplay(array $operation): array
{
	global $_currs, $_GS;
	$params = $operation['oParams'] ?? [];
	$cid = $params['cid2'] ?? $operation['ocID'];
	try {
		if (!isset($_currs[$cid])) return [];
		$amount = (string)opMoney($params['sum2'] ?? 0, $cid);
		$currency = StringHelper::textLangFilter($_currs[$cid]['cCurr'] ?: $_currs[$cid]['cCurrID'], $_GS['lang'] ?? '');
		return ['amount' => $amount, 'currency' => $currency];
	} catch (InvalidArgumentException | OverflowException) { return []; }
}

// Operations

function opOperChkAWD($o)
{
	global $db, $_cfg;
	$oper = $o['oOper'];
	$uid = $o['ouID'];
	$cid = $o['ocID'];
	$sum = (string)DecimalInput::read($o['oSum'])->minus(DecimalInput::read($o['oComis']));
	if (empty($_cfg["Bal_AWD$oper"]) or (($oper == 'EXIN') and ($cid == 1)))
		return false;
	if ($cid == 1)
	{
		$params = array(
			'cid2' => $db->fetch1($db->select('AddInfo', 'aDefCurr', 'auID=?d', array($uid)))
		);
		return opOperCreate($uid, 'EX', 1, $sum, $params, 'Auto', true, true);
	}
	else
		return opOperCreate($uid, 'CASHOUT', $cid, $sum, array(), 'Auto', true, true);
}

function opOperCreateInfo($uid, $oper, $cid, $sum, $params = array(), $memo = '')
{
	global $db;
	return TransactionScope::run($db->link, static function()use($uid,$oper,$cid,$sum,$params,$memo){
	global $db, $_currs;
	if(!HScript\Payment\Provider\CurrencyArchive::writable($db,[(int)$cid]))return 'currency_archived';
	$params = asArray($params);
	try {
		// Informational receipts retain their exact stored six-place value, even
		// when the currency display scale is lower. They do not move funds.
		$sum = StoredAmount::exact(DecimalInput::read($sum));
		$fee = opAdditionalComis($sum, $params['comis'] ?? 0, $cid);
		StoredAmount::exact($fee->decimal());
	} catch (OverflowException) { return 'sum_overflow'; }
	catch (InvalidArgumentException $e) { return $e->getMessage() === 'amount_storage_precision' ? 'sum_precision' : 'sum_wrong'; }
	$a = array(
		'oCTS' => timeToStamp(),
		'oATS' => timeToStamp(time() + HS2_UNIX_HOUR),
		'ouID' => $uid,
		'oOper' => $oper,
		'ocID' => $cid,
		'oSum' => $sum,
		'oComis' => (string)$fee,
		'oParams' => arrayToStr($params),
		'oBatch' => isset($params['batch']) ? $params['batch'] : '',
		'oTag' => isset($params['tag']) ? $params['tag'] : '',
		'oTS' => timeToStamp(),
		'oState' => 3,
		'oMemo' => $memo,
		'oNTS' => timeToStamp()
	);
	$oid = $db->insert('Opers', $a);
	if (!$oid) return 'db_error';
	$n = array(
		'oid' => $oid,
		'oper' => $oper,
		'cid' => $cid,
		'sum' => $sum,
		'psys' => isset($_currs[$cid]['cName']) ? $_currs[$cid]['cName'] : '',
		'curr' => trim((string)($_currs[$cid]['cCurr'] ?? '')) ?: trim((string)($_currs[$cid]['cCurrID'] ?? ''))
	);
	$params = array_merge($params, $n);
	$params['url'] = fullURL(moduleToLink('balance/oper'));
	if ($usr = opReadUser($uid))
	{
		View::SendMailToUser($usr['uMail'], "Oper$oper",
			opUserConsts($usr, $params),
			$usr['uLang']
		);
		View::sendMailToAdmin("Oper$oper", opUserConsts($usr, $params));
	}
	opEvent('Oper', $uid, $params);
	opOperChkAWD($a);
	return $oid;
	});
}

function opOperCreate($uid, $oper, $cid, $sum, $params = array(), $memo = '', $and_complete = false, $by_admin = false, $preserve_stored = false)
{
	global $db;
	return TransactionScope::run($db->link, function () use ($uid, $oper, $cid, $sum, $params, $memo, $and_complete, $by_admin, $preserve_stored) {
	global $db, $_cfg, $_currs;
	if ($uid < 0)
		$uid = _uid();
	$params = asArray($params);
	if (!$oper)
		return 'oper_empty';
	if (!$uid)
		return 'user_empty';
	// REF issuer eligibility must agree with the current locked sponsor policy.
	$user_exists = $oper === 'REF'
		? $db->fetch1($db->query('SELECT uID FROM Users WHERE uID=?d AND uState IN (1,2) LOCK IN SHARE MODE', [$uid]))
		: opUser($uid);
	if (!$user_exists)
		return 'user_wrong';
	if (!$cid)
		return 'psys_empty';
	if (!isset($_currs[$cid]))
		return 'psys_wrong';
	$archiveCurrencies=[(int)$cid];if($oper==='EX'&&!empty($params['cid2']))$archiveCurrencies[]=(int)$params['cid2'];
	if(!HScript\Payment\Provider\CurrencyArchive::writable($db,$archiveCurrencies))return 'currency_archived';
	try {
		// Locked deposit principal and paired stored quotes retain their original scale.
		if ($preserve_stored && (!$sum instanceof Decimal
            || !(($oper === 'TAKE' && !empty($params['tag']))
                || (in_array($oper, ['TRIN','EXIN'], true) && !empty($params['oid'])))))
			return 'sum_wrong';
		$money = $preserve_stored ? Money::fromDecimal($sum, (string)$cid, 6, RoundingMode::Unnecessary) : opMoney($sum, $cid);
		StoredAmount::exact($money->decimal());
		$sum = (string)$money;
	} catch (OverflowException) { return 'sum_overflow'; }
	catch (InvalidArgumentException $e) { return $e->getMessage() === 'amount_storage_precision' ? 'sum_precision' : 'sum_wrong'; }
	// Persisted provider capability wins over stale request-global mode flags.
	if (in_array($oper, array('CASHIN','CASHOUT'), true)) {
		$gateway = $db->fetch1($db->select('Currs', 'cCID', 'cID=?d', array($cid)));
		if (\HScript\Payment\CallbackCapabilities::disabledAutomation((string)$gateway))
			return 'automatic_payment_unsupported';
	}
    $pawDestination=null;
    $payoutCurrency=$oper==='CASHOUT'?$cid:($oper==='EX' && !empty($_cfg['Const_IntCurr']) && $cid==1?(int)($params['cid2']??0):0);
    if($payoutCurrency>0) {
        $payoutRow=$db->fetch1Row($db->select('Currs','*','cID=?d',[$payoutCurrency]));
        if(($payoutRow['cCID']??null)==='PAW') {
            try {
                if(!\HScript\Payment\Provider\PawPayoutSchema::ready($db->link,true) || (int)$payoutRow['cDisabled'] || !(int)$payoutRow['cCASHOUTMode'])return 'oper_disabled';
                $configuration=(new \HScript\Payment\Provider\PawProviderRepository($db))->configuration($payoutRow);
                if(!$configuration['_provider_enabled'])return 'oper_disabled';
                if($configuration['_provider_environment']!=='live')return 'paw_payout_sandbox_unsupported';
                $wallet=$db->fetch1Row($db->select('Wallets','*','wuID=?d AND wcID=?d',[$uid,$payoutCurrency]));
                if($oper==='CASHOUT' && isset($params['paw_destination'])) {
                    $frozen=$params['paw_destination'];
                    if(!is_array($frozen) || ($frozen['payout_asset']??null)!==($params['payout_asset']??null)
                        || !\HScript\Finance\PawPayoutTarget::valid($params['payout_asset'],$frozen['acc']??null))return 'paw_payout_destination_required';
                    $pawDestination=['payout_asset'=>$frozen['payout_asset'],'acc'=>$frozen['acc']];
                } else $pawDestination=\HScript\Finance\PawPayoutTarget::select($params['payout_asset']??null,opDecodeUserCurrParams($wallet?:[]));
                // An internal exchange carries the exact selected destination to its child.
                $params['paw_destination']=$pawDestination;
            } catch(\HScript\Finance\FinancialOperationException $error) { return $error->getMessage(); }
        }
    }
	if (!$by_admin and empty($_currs[$cid]['c' . $oper . 'Mode']) and ($oper != 'EXIN') and ($oper != 'TRIN'))
		return 'oper_disabled';
	if ($sum <= 0)
		return 'sum_wrong';
	if (is_string($csum = opCalcComis($cid, $oper, $sum, $by_admin)))
		return $csum; // 'sum_wrong'
	try {
		if (isset($params['comis']) && DecimalInput::read($params['comis'])->compareTo(Decimal::of(0)) > 0)
			$csum = $csum->plus(opAdditionalComis($sum, $params['comis'], $cid));
		StoredAmount::exact($csum->decimal());
		if ($money->decimal()->compareTo($csum->decimal()) < 0) return 'sum_wrong';
		$net = (string)$money->decimal()->minus($csum->decimal());
		$csum = (string)$csum;
	} catch (OverflowException) { return 'sum_overflow'; }
	catch (InvalidArgumentException $e) { return $e->getMessage() === 'amount_storage_precision' ? 'sum_precision' : 'sum_wrong'; }
	if (in_array($oper, array('CASHOUT', 'EX', 'TR', 'BUY', 'BUY2', 'GIVE')))
		if (DecimalInput::read($db->fetch1($db->select('Wallets', 'wBal', 'wuID=? and wcID=?', array($uid, $cid))) ?: 0)->compareTo($money->decimal()) < 0)
			return 'low_bal1';
	if ($oper == 'EX')
	{
		if (isset($params['cid2']) && $params['cid2'] == $cid)
			return 'psys2_equal_psys';
		if (empty($params['cid2']))
			return 'psys2_empty';
		$params['psys'] = isset($_currs[$params['cid2']]['cName']) ? $_currs[$params['cid2']]['cName'] : '';
		if (!$params['psys'])
			return 'psys2_wrong';
		$params['sum2'] = opCalcEx($cid, $params['cid2'], $net);
		if (is_string($params['sum2']))
			return $params['sum2']; // 'sum2_wrong'
		$params['sum2'] = (string)$params['sum2'];
	}
	if ($oper == 'TR')
	{
		if (isset($params['uid2']) && $params['uid2'] == $uid)
			return 'user2_equal_user';
		if (empty($params['uid2']))
			return 'user2_empty';
		$params['user'] = $db->fetch1($db->select('Users', 'uLogin', 'uID=? and (uState=1 or uState=2)', array($params['uid2'])));
		if (!$params['user'])
			return 'user2_not_found';
	}
	if (($oper == 'CASHOUT') or (($oper == 'EX') and ($cid == 1)))
		if (($usr = opReadUser($uid)) and !empty($usr['uWDDisable']))
			return 'wd_disable';
	$oid = $db->insert('Opers', 
		array(
			'oCTS' => timeToStamp(),
			'oATS' => timeToStamp(time() + HS2_UNIX_HOUR),
			'ouID' => $uid,
			'oOper' => $oper,
			'ocID' => $cid,
			'oSum' => $sum,
			'oComis' => $csum,
			'oParams' => arrayToStr($params),
			'oBatch' => isset($params['batch']) ? $params['batch'] : '',
			'oTag' => isset($params['tag']) ? $params['tag'] : '',
			'oTS' => 0, // complete date
			'oState' => 0,
			'oMemo' => $memo,
			'oNTS' => 0 // date of modify (by admin / system)
		)
	);
	if (!$oid)
		return 'db_error';
	if (in_array($oper, array('CASHIN', 'CASHOUT')))
	{
		$w = $db->fetch1Row($db->select('Users LEFT JOIN Wallets ON (wcID=?d AND wuID=uID)', 
			'*', 'uID=?d', array($cid, $uid)));
		$params2 = $oper==='CASHOUT' && $pawDestination!==null ? $pawDestination : opDecodeUserCurrParams($w);
		$params2['date'] = timeToStamp();
		$params2['memo'] = StringHelper::textVarReplace(
				StringHelper::exValue('Invoice ##id#, #user#', StringHelper::textLangFilter(StringHelper::exValue(isset($_cfg['Bal_' . $oper . 'Text']) ? $_cfg['Bal_' . $oper . 'Text'] : '', $memo), isset($w['uLang']) ? $w['uLang'] : '')),
				array('id' => $oid, 'user' => isset($w['uLogin']) ? $w['uLogin'] : '')
			);
		$db->update('Opers', array('oParams2' => arrayToStr($params2)), '', 'oID=?d', array($oid));
	}
	if ($and_complete)
	{
		if (is_string($err = opOperConfirm($uid, $oid, array(), $by_admin)))
			return $err;
		if (is_string($err = opOperComplete($uid, $oid, array(), $by_admin)))
			return $err;
	}
	return $oid;
	});
}

/** Persist a transition inside its owning transaction and verify every intended field. */
function opOperSaveState(array $previous, array $changes): bool
{
	global $db;
	$db->update('Opers', $changes, '', 'oID=?d and oState=?d', array($previous['oID'], $previous['oState']));
	$saved = $db->fetch1Row($db->select('Opers', '*', 'oID=?d', array($previous['oID'])));
	if (!$saved) return false;
	// A repeated CASHIN confirmation may legitimately affect no row. A matched
	// row or unchanged state alone does not prove that its receipt was saved.
	foreach ($changes as $field => $value) {
		if (!array_key_exists($field, $saved)) return false;
		if ($value === null || $saved[$field] === null) {
			if ($value !== $saved[$field]) return false;
		} elseif ($field === 'oSum' || $field === 'oComis') {
			if (Decimal::of($saved[$field])->compareTo(Decimal::of($value)) !== 0) return false;
		} elseif ((string)$saved[$field] !== (string)$value) return false;
	}
	return true;
}

/** Serialize completed batch identity even when no matching operation exists yet. */
function opOperBatchError($cid, $batch): ?string
{
	global $db;
	if (!$db->link->inTransaction()) throw new LogicException('Batch validation requires a transaction');
	// An existing currency row supplies the mutex under RR and READ COMMITTED.
	// Keep SQL batch equality (including the configured collation) unchanged.
	if (!$db->fetch1($db->query('SELECT cID FROM Currs WHERE cID=?d FOR UPDATE', [$cid]))) return 'psys_wrong';
	if ($db->fetch1($db->query('SELECT oID FROM Opers WHERE ocID=?d AND oBatch=? AND oState=3 LIMIT 1 LOCK IN SHARE MODE', [$cid, $batch]))) return 'batch_exists';
	return null;
}

function opOperConfirm($uid, $oid, $params = array(), $by_admin = false)
{
	global $db, $_cfg, $_currs;
	if (is_array($oid))
	{
		if ($o = $db->fetch1Row($db->select('Opers', '*', 'oID=?d and ouID=?d', array($id = $oid['oid'], $uid))))
			if ($o['oState'] <= 1)
			{
				$err = opOperConfirm($uid, $id, array());
				if (($o['ocID'] == 1) or (isset($_currs[$o['ocID']]['c' . $o['oOper'] . 'Mode']) && $_currs[$o['ocID']]['c' . $o['oOper'] . 'Mode'] == 2))
				{
					if ($err === 'limit_exceeded')
						$err = opOperConfirm($uid, $id, array(), true);
					elseif (!is_string($err) and ($o['oOper'] != 'CASHIN'))
							opOperComplete($uid, $id, array());
					if (is_string($err))
						View::sendMailToAdmin('OperRequired',
							opUserConsts(opReadUser($uid), array('oid' => $id, 'url' => fullURL(moduleToLink('balance/admin/oper')))));
				}
				$oid = StringHelper::exValue($id, $o['oTag']);
				View::showInfo('Completed', moduleToLink('balance/oper') . "?id=$oid");
			}
		View::showInfo('*Error', moduleToLink('balance'));
	}
	return TransactionScope::run($db->link, function () use ($uid, $oid, $params, $by_admin) {
	global $db, $_cfg, $_currs;
	$db->fetch1Row($db->query('SELECT oID FROM Opers WHERE oID=?d FOR UPDATE', array($oid)));
	$params = asArray($params);
	if (!($o = $db->fetch1Row($db->select('Opers LEFT JOIN Currs ON cID=ocID', '*', 'oID=?d' . StringHelper::valueIf($uid > 0, ' and ouID=?d'), array($oid, $uid)))))
		return 'oper_not_found';
	if ($o['oState'] == 2 && $o['oOper'] !== 'CASHIN') return true;
	if (!(($o['oState'] < 2) or (($o['oState'] == 2) and ($o['oOper'] == 'CASHIN'))))
		return 'oper_state_wrong';
//	if (!$by_admin and (stampToTime($o['oATS']) < time()))
//		return 'oper_expired';
	$uid = $o['ouID'];
	$oparams = strToArray($o['oParams']);
	$oparams2 = strToArray($o['oParams2']);
	$a = array(
		'oTS' => timeToStamp(),
		'oState' => 2
	);
	$cid = $o['ocID'];
	$sum = $o['oSum'];
	$res = 'oper_unknown';
	switch ($o['oOper'])
	{
	case 'BONUS':
	case 'PENALTY':
	case 'EXIN':
	case 'TRIN':
	case 'SELL':
	case 'SELL2':
	case 'REF':
	case 'CALCIN':
	case 'CALCOUT':
		$res = true;
		break;
	case 'CASHIN':
		$res = true;
		if ($params) // from status
		{
			if (isset($params['sum']) && $params['sum'] === '?')
				$params['sum'] = $sum;
			if (isset($params['date'])) $params['date'] = timeToStamp($params['date']);
			$a['oParams2'] = arrayToStr($params);
			$oparams2 = $params;
		}
		if (empty($oparams2['date']))
			$res = 'data_date_wrong';
		elseif (empty($oparams2['batch']))
			$res = 'data_batch_wrong';
		// A completed gateway batch may credit a currency only once.
		elseif ($batchError = opOperBatchError($cid, $oparams2['batch']))
			$res = $batchError;
		$a['oBatch'] = '?' . (isset($oparams2['batch']) ? $oparams2['batch'] : '');
		break;
	case 'CASHOUT':
		if (!$by_admin and !empty($o['cCASHOUTLimitPer']) and ($o['cCASHOUTLimitPer'] > 0))
		{
			$outsum = $db->fetch1($db->select('Opers', 'SUM(oSum)',
				"(ouID=?d) and (oOper=?) and (ocID=?d) and (oState=3) and (oTS>?)", 
				array($uid, 'CASHOUT', $cid, timeToStamp(time() - ($o['cCASHOUTLimitPer'] * HS2_UNIX_HOUR)))));
			if (DecimalInput::read($sum)->plus(DecimalInput::read($outsum ?: 0))->compareTo(DecimalInput::read($o['cCASHOUTLimit'] ?? 0)) > 0)
			{
				$res = 'limit_exceeded';
				break;
			}
		}
		$res = opChangeStoredBalance($uid, $cid, (string)DecimalInput::read($sum)->negated(), 0, $sum, true, $oid, '');
		break;
	case 'EX':
		if ($params)
		{
			$a['oParams'] = arrayToStr($params);
			$oparams = $params;
		}
		$res = opChangeStoredBalance($uid, $cid, (string)DecimalInput::read($sum)->negated(), 0, $sum, true, $oid, '');
		break;
	case 'TR':
		if ($params)
		{
			$a['oParams'] = arrayToStr($params);
			$oparams = $params;
		}
		if (isset($oparams['uid2']) && opUser($oparams['uid2']))
			$res = opChangeStoredBalance($uid, $cid, (string)DecimalInput::read($sum)->negated(), 0, $sum, true, $oid, '');
		else
			$res = 'user2_wrong';
		break;
	case 'BUY':
	case 'GIVE':
		$res = opChangeStoredBalance($uid, $cid, (string)DecimalInput::read($sum)->negated(), 0, $sum, empty($oparams['nocheck']), $oid, '');
		break;
	case 'TAKE':
		$res = opChangeStoredBalance($uid, $cid, 0, (string)DecimalInput::read($sum)->negated(), $sum, empty($oparams['nocheck']), $oid, '');
		break;
	}
	if (is_string($res))
		return $res;
	if (!opOperSaveState($o, $a)) return 'db_error';
	return true;
	});
}

function opOperComplete($uid, $oid, $params = array(), $by_admin = false, $aparams = array())
{
	global $db;
	return TransactionScope::run($db->link, function () use ($uid, $oid, $params, $by_admin, $aparams) {
	global $db, $_cfg;
	$db->fetch1Row($db->query('SELECT oID FROM Opers WHERE oID=?d FOR UPDATE', array($oid)));
	$o = $db->fetch1Row($db->select('Opers LEFT JOIN Currs ON cID=ocID LEFT JOIN Users ON uID=ouID', 
		'Opers.*, Currs.*, Users.uLogin', 'oID=?d' . StringHelper::valueIf($uid > 0, ' and ouID=?d'), array($oid, $uid)));
	if (!$o) return 'oper_not_found';
	if ($o['oOper']==='CASHOUT' && \HScript\Payment\Gateways\CryptoCurrencyApi::supports($o['cCID']) && WithdrawalDispatcher::wasAttempted($db,(int)$oid)) {
        try { \HScript\Finance\CcapiPayout::assertCompletion($db,(int)$oid); }
        catch (\Throwable) { return 'ccapi_payout_reconciliation_required'; }
    }
    if($o['oOper']==='CASHOUT' && $o['cCID']==='PAW' && WithdrawalDispatcher::wasAttempted($db,(int)$oid)) {
        try { \HScript\Finance\PawPayout::assertCompletion($db,(int)$oid); }
        catch(\Throwable) { return 'paw_payout_reconciliation_required'; }
    }
	if ($o['oState'] == 3) return true;
	if ($o['oState'] != 2)
		return 'oper_state_wrong';
	$uid = $o['ouID'];
	$params = asArray($params);
	$oparams = strToArray($o['oParams']);
	$oparams2 = strToArray($o['oParams2']);
	$a = array(
		'oTS' => timeToStamp(),
		'oState' => 3,
		'oPTS' => 0
	);
	if (!empty($params['tag']))
	{
		$o['oTag'] = $params['tag'];
		$a['oTag'] = $params['tag'];
	}
	if ($by_admin)
		$a['oNTS'] = timeToStamp();
	$cid = $o['ocID'];
	$sum = (string)DecimalInput::read($o['oSum'])->minus(DecimalInput::read($o['oComis']));
	if ($sum < 0)
		return 'oper_sum_wrong';
	$n = array(
		'oid' => $oid,
		'oper' => $o['oOper'],
		'tag' => isset($o['oTag']) ? $o['oTag'] : '',
		'cid' => $cid,
		'sum' => $sum,
		'psys' => isset($o['cName']) ? $o['cName'] : '',
		'curr' => trim((string)($o['cCurr'] ?? '')) ?: trim((string)($o['cCurrID'] ?? ''))
	);
	$res = 'oper_unknown';
	switch ($o['oOper'])
	{
	case 'CALCIN':
		if (!empty($o['oTag']) and $o['oTag'] > 0)
			$a['oMemo'] = (isset($o['oMemo']) ? $o['oMemo'] : '') . '#' . $o['oTag'];
	case 'EXIN':
	case 'TRIN':
	case 'BONUS':
	case 'SELL':
	case 'SELL2':
	case 'REF':
		$res = opChangeStoredBalance($uid, $cid, $sum, 0, 0, false, $oid, isset($o['oMemo']) ? $o['oMemo'] : '');
		if (($o['oOper'] == 'EXIN') and !empty($_cfg['Const_IntCurr']) and isset($oparams['cid2']) and ($oparams['cid2'] == 1))
		{
			if (is_string($res)) return $res;
			$woid = opOperCreate($uid, 'CASHOUT', $cid, $sum, array_intersect_key($oparams,array_flip(['payout_asset','paw_destination'])), 'Auto', false, $by_admin);
			if (is_string($woid)) return $woid;
			if (is_string($err = opOperConfirm($uid, $woid, array(), $by_admin))) return $err;
			if (isset($o['cCASHOUTMode']) && $o['cCASHOUTMode'] == 2)
				if (is_string($err = opOperComplete($uid, $woid, array(), $by_admin))) return $err;
			$p = strToArray($db->fetch1($db->select('Opers', 'oParams2', 'oID=?d', array(isset($oparams['initid']) ? $oparams['initid'] : 0))));
			$p['newid'] = $woid;
			$db->update('Opers', array('oParams2' => arrayToStr($p)), '', 'oID=?d', array(isset($oparams['initid']) ? $oparams['initid'] : 0));
		}

		break;
	case 'PENALTY':
	case 'CALCOUT':
		$res = opChangeStoredBalance($uid, $cid, (string)DecimalInput::read($sum)->negated(), 0, 0, !$by_admin, $oid, isset($o['oMemo']) ? $o['oMemo'] : '');
		break;
	case 'CASHOUT':
		if (!empty($params['date'])) // fake send ??? direct params
		{
			$params['date'] = timeToStamp($params['date']);
			$a['oParams2'] = arrayToStr($params);
			$oparams2 = $params;
		}
		elseif (empty($oparams2['batch']) or ($oparams2['batch'] == '-')) // real send
		{
			// The reservation and outbox commit together. The worker does network I/O.
			WithdrawalDispatcher::enqueue($db, (int)$oid, $by_admin ? $aparams : []);
			return true;
		}
		$n['acc'] = isset($oparams2['acc']) ? $oparams2['acc'] : ''; // payee acc
		$n['batch'] = isset($oparams2['batch']) ? $oparams2['batch'] : '';
	case 'CASHIN':
		if (!empty($oparams2['cid']) and ($oparams2['cid'] != $cid))
			return 'psys_wrong';
		if (isset($oparams2['sum'])) // SUM from SCI
		{
			try { $sum = (string)opMoney($oparams2['sum'], $cid); StoredAmount::exact(Decimal::of($sum)); }
			catch (OverflowException) { return 'sum_overflow'; }
			catch (InvalidArgumentException $e) { return $e->getMessage() === 'amount_storage_precision' ? 'sum_precision' : 'sum_wrong'; }
			$a['oSum'] = $sum;
			if (is_string($res = opCalcComis($cid, $o['oOper'], $sum, $by_admin)))
				break;
			$a['oComis'] = (string)$res;
			$sum = (string)Decimal::of($sum)->minus($res->decimal());
			if (Decimal::of($sum)->isNegative())
			{
				$res = 'data_sum_wrong';
				break;
			}
			$n['sum'] = $sum;
		}
		if (empty($oparams2['date']))
			$res = 'data_date_wrong';
		elseif (empty($oparams2['batch']))
			$res = 'data_batch_wrong';
		elseif ($batchError = opOperBatchError($cid, $oparams2['batch']))
			$res = $batchError;
		else
		{
			$a['oCTS'] = $oparams2['date'];
			$a['oBatch'] = $oparams2['batch'];
			$a['oMemo'] = isset($oparams2['memo']) ? $oparams2['memo'] : '';
			if ($o['oOper'] == 'CASHIN')
			{
				$res = opChangeStoredBalance($uid, $cid, $sum, 0, 0, false, $oid, $a['oMemo']);
				if (!empty($oparams2['acc'])) // try fill currency property 'acc'
				{
					$w = $db->fetch1Row($db->select('AddInfo LEFT JOIN Wallets ON (wcID=?d AND wuID=auID)', 
						'AddInfo.aDefCurr, Wallets.*', 'auID=?d', array($cid, $uid)));
					$p = opDecodeUserCurrParams($w);
					if (!in_array($o['cCID'],['PAW','BW'],true) && empty($p['acc'])) // Receipts never replace manually saved PAW or bank payout details.
					{
						$t = time();
						$key = $cid . $uid . $t;
						$p = array(
							'wParams' => encodeArrayToStr(array('acc' => $oparams2['acc']), $key),
							'wMTS' => timetostamp($t)
						);
						$db->update('Wallets', $p, '', 'wcID=?d and wuID=?d', array($cid, $uid));
					}
					if (empty($w['aDefCurr']))
						$db->update('AddInfo', array('aDefCurr' => $cid), '', 'auID=?d', array($uid));
				}
				if (!is_string($res) and !empty($_cfg['Const_IntCurr']) and ($cid > 1) and (($o['cCID']??$db->fetch1($db->select('Currs','cCID','cID=?d',[$cid])))!=='CCT'))
				{
					$err = opOperCreate($uid, 'EX', $cid, $sum, array('cid2' => 1), 'Auto', true, true);
					if (is_string($err)) return $err;
					if (!is_string($err))
					{
						$o2 = $db->fetch1Row($db->select('Opers', 'ocID, oSum, oComis, oParams', 'oID=?d', array($err)));
						$o2params = strToArray($o2['oParams']);
						$n['cid2'] = isset($o2params['cid2']) ? $o2params['cid2'] : 0;
						$n['sum2'] = isset($o2params['sum2']) ? $o2params['sum2'] : 0;
						global $_currs;
						$n['psys2'] = isset($_currs[$n['cid2']]['cName']) ? $_currs[$n['cid2']]['cName'] : '';
						$n['curr2'] = isset($_currs[$n['cid2']]['cCurr']) ? $_currs[$n['cid2']]['cCurr'] : '';
					}
				}
			}
			else
				$res = opChangeStoredBalance($uid, $cid, 0, 0, (string)DecimalInput::read($o['oSum'])->negated(), false, $oid, isset($o['oMemo']) ? $o['oMemo'] : '');
		}
		break;
	case 'EX':
		$res = opChangeStoredBalance($uid, $cid, 0, 0, (string)DecimalInput::read($o['oSum'])->negated(), false, $oid, isset($o['oMemo']) ? $o['oMemo'] : '');
		if ($res === true)
		{
			$cid2 = isset($oparams['cid2']) ? $oparams['cid2'] : 0;
			$sum2 = isset($oparams['sum2']) ? $oparams['sum2'] : 0;
			$batch = 'EX' . str_pad($oid, 6, '0', STR_PAD_LEFT);
			$a['oBatch'] = $batch;
			$n['batch'] = $batch;
			$n['course'] = DecimalInput::read($sum)->compareTo(Decimal::of(0)) > 0
				? (string)DecimalInput::read($sum2)->dividedBy(DecimalInput::read($sum), 4, RoundingMode::HalfUp)
				: '0.0000';
			global $_currs;
			$n['psys2'] = isset($_currs[$cid2]['cName']) ? $_currs[$cid2]['cName'] : '';
			$n['curr2'] = isset($_currs[$cid2]['cCurr']) ? $_currs[$cid2]['cCurr'] : '';
			$child = opOperCreate($uid, 'EXIN', $cid2, DecimalInput::read($sum2),
				array('oid' => $oid, 'sum2' => $sum, 'cid2' => $cid, 'batch' => $batch, 'initid' => isset($params['initid']) ? $params['initid'] : 0)+array_intersect_key($oparams,array_flip(['payout_asset','paw_destination'])), isset($o['oMemo']) ? $o['oMemo'] : '', true, true, true);
			if (is_string($child)) return $child;
		}
		break;
	case 'TR':
		if (!empty($oparams['uid2']) and $usr2 = opReadUser($oparams['uid2']))
		{
			$res = opChangeStoredBalance($uid, $cid, 0, 0, (string)DecimalInput::read($o['oSum'])->negated(), false, $oid, isset($o['oMemo']) ? $o['oMemo'] : '');
			if ($res === true)
			{
				$batch = 'TR' . str_pad($oid, 6, '0', STR_PAD_LEFT);
				$a['oBatch'] = $batch;
				$n['to'] = isset($usr2['uLogin']) ? $usr2['uLogin'] : '';
				$n['batch'] = $batch;
				$child = opOperCreate($oparams['uid2'], 'TRIN', $cid, DecimalInput::read($sum),
					array('oid' => $oid, 'uid2' => $uid, 'user' => isset($o['uLogin']) ? $o['uLogin'] : '', 'batch' => $batch), isset($o['oMemo']) ? $o['oMemo'] : '', true, $by_admin, true);
				if (is_string($child)) return $child;
			}
		}
		else
			$res = 'user2_wrong';
		break;
	case 'BUY':
		$res = opChangeStoredBalance($uid, $cid, 0, 0, (string)DecimalInput::read($o['oSum'])->negated(), false, $oid, isset($o['oMemo']) ? $o['oMemo'] : '');
		break;
	case 'GIVE':
		if (!empty($oparams['bonus']) and $oparams['bonus'] > 0)
			if (is_string($error = opOperCreateInfo($uid, 'BONUS', $cid, $oparams['bonus']))) return $error;
		$res = opChangeStoredBalance($uid, $cid, 0, (string)DecimalInput::read($sum)->plus(DecimalInput::read($oparams['bonus'] ?? 0)), (string)DecimalInput::read($o['oSum'])->negated(), false, $oid, isset($o['oMemo']) ? $o['oMemo'] : '');
		if (!empty($o['oTag']) and $o['oTag'] > 0)
			$a['oMemo'] = (isset($o['oMemo']) ? $o['oMemo'] : '') . '#' . $o['oTag'];
		break;
	case 'TAKE':
		$res = opChangeStoredBalance($uid, $cid, $sum, 0, (string)DecimalInput::read($o['oSum'])->negated(), false, $oid, isset($o['oMemo']) ? $o['oMemo'] : '');
		if (!empty($o['oTag']) and $o['oTag'] > 0)
			$a['oMemo'] = (isset($o['oMemo']) ? $o['oMemo'] : '') . '#' . $o['oTag'];
		break;
	}
	if (is_string($res))
	{
		$db->update('Opers', array('oPTS' => 0), '', 'oID=?d', array($oid));
//		if (!$by_admin)
			View::sendMailToAdmin('OperNotComplete',
				opUserConsts(opReadUser($uid), array('oid' => $oid, 'url' => fullURL(moduleToLink('balance/admin/oper')))));
		return $res;
	}
	if (!opOperSaveState($o, $a)) return 'db_error';
	$oparams = array_merge($oparams, $n);
	$oparams['url'] = fullURL(moduleToLink('balance/oper'));
	if ($usr = opReadUser($uid))
	{
		useLib('sms');
		if (function_exists('smsToUser'))
			smsToUser($uid, isset($usr['aTel']) ? $usr['aTel'] : '', 'Oper' . $o['oOper'], opUserConsts($usr, $oparams), isset($usr['uLang']) ? $usr['uLang'] : '');
		View::sendMailToUser(isset($usr['uMail']) ? $usr['uMail'] : '', 'Oper' . $o['oOper'],
			opUserConsts($usr, $oparams),
			isset($usr['uLang']) ? $usr['uLang'] : ''
		);
		View::sendMailToAdmin('Oper' . $o['oOper'],
			opUserConsts($usr, $oparams));
	}
	if ($o['oOper'] === 'CASHIN')
		HScript\Finance\ReferralSourceEvent::dispatch($db->link, 'Oper', (int)$uid, $oparams,
			static fn() => opEvent('Oper', $uid, $oparams));
	else
		opEvent('Oper', $uid, $oparams);
	opOperChkAWD($o);
	return true;
	});
}

function opOperCancel($uid, $oid, $params = array(), $by_admin = false)
{
	global $db;
	return TransactionScope::run($db->link, function () use ($uid, $oid, $params, $by_admin) {
	global $db, $_cfg;
	$db->fetch1Row($db->query('SELECT oID FROM Opers WHERE oID=?d FOR UPDATE', array($oid)));
	$params = asArray($params);
	if (!($o = $db->fetch1Row($db->select('Opers', '*', 'oID=?d' . StringHelper::valueIf($uid > 0, ' and ouID=?d'), array($oid, $uid)))))
		return 'oper_not_found';
	if ($o['oOper'] === 'CASHOUT' && WithdrawalDispatcher::wasAttempted($db, (int)$oid)) return 'payout_reconciliation_required';
	if ($o['oState'] >= 3)
		return 'oper_state_wrong';
	$uid = $o['ouID'];
	$oparams = strToArray($o['oParams']);
	$oparams2 = strToArray($o['oParams2']);
	$a = array(
		'oTS' => timeToStamp(),
		'oState' => ($by_admin ? 4 : 5)
	);
	if ($by_admin)
		$a['oNTS'] = timeToStamp();
	$cid = $o['ocID'];
	$sum = $o['oSum'];
	if ($o['oState'] == 2)
	{
		$res = 'oper_unknown';
		switch ($o['oOper'])
		{
		case 'BONUS':
		case 'PENALTY':
		case 'CASHIN':
		case 'EXIN':
		case 'TRIN':
		case 'SELL':
		case 'SELL2':
		case 'REF':
		case 'CALCIN':
		case 'CALCOUT':
			$res = true;
			break;
		case 'CASHOUT':
		case 'EX':
		case 'TR':
		case 'BUY':
		case 'GIVE':
			$res = opChangeStoredBalance($uid, $cid, $sum, 0, (string)DecimalInput::read($sum)->negated(), false, $oid, '');
			if (!is_string($res) and ($o['oOper'] == 'CASHOUT') and !empty($_cfg['Const_IntCurr']) and ($cid > 1) and (($o['cCID']??$db->fetch1($db->select('Currs','cCID','cID=?d',[$cid])))!=='CCT'))
			{
				$exchange = opOperCreate($uid, 'EX', $cid, $sum, array('cid2' => 1), 'Auto', true, true);
				if (is_string($exchange)) return $exchange;
			}
			break;
		case 'TAKE':
			$res = opChangeStoredBalance($uid, $cid, 0, $sum, (string)DecimalInput::read($sum)->negated(), false, $oid, '');
			break;
		}
		if (is_string($res))
			return $res;
	}
	if (!opOperSaveState($o, $a)) return 'db_error';
	return true;
	});
}

// Change balance

/** Internal persisted/computed deltas never pass through currency rounding again. */
function opChangeStoredBalance($uid, $cid, $z1, $z2, $z3, $chk_before = true, $oid = 0, $memo = '')
{
    try {
        $deltas = array_map(static fn($value) => $value instanceof Decimal ? $value : DecimalInput::read($value), [$z1, $z2, $z3]);
        return opChangeBalance($uid, $cid, ...array_merge($deltas, [$chk_before, $oid, $memo]));
    } catch (OverflowException) { return 'bal_overflow'; }
    catch (InvalidArgumentException) { return 'bal_wrong'; }
}

function opChangeBalance($uid, $cid, $z1, $z2, $z3, $chk_before = true, $oid = 0, $memo = '')
{
	if (($uid <= 0) or ($cid <= 0))
		return 'bal_wrong';
	try {
		$z1 = StoredAmount::exact($z1 instanceof Decimal ? $z1 : opMoney($z1, $cid)->decimal());
		$z2 = StoredAmount::exact($z2 instanceof Decimal ? $z2 : opMoney($z2, $cid)->decimal());
		$z3 = StoredAmount::exact($z3 instanceof Decimal ? $z3 : opMoney($z3, $cid)->decimal());
	} catch (OverflowException) { return 'bal_overflow'; }
	catch (InvalidArgumentException $e) { return $e->getMessage() === 'amount_storage_precision' ? 'bal_precision' : 'bal_wrong'; }
	if (($z1 == 0) and ($z2 == 0) and ($z3 == 0))
		return true;
	// z1, z2, and z3 change the available, locked, and outgoing buckets. uBal
	// mirrors their combined value for fast account-level reads.
	$z = (string)Decimal::of($z1)->plus(Decimal::of($z2))->plus(Decimal::of($z3));
	global $db;
	return TransactionScope::run($db->link, function () use ($uid, $cid, $z1, $z2, $z3, $z, $oid, $memo) {
	global $db;
	$user = $db->fetch1Row($db->query('SELECT uID,uBal FROM Users WHERE uID=?d FOR UPDATE', array($uid)));
	if (!$user) return 'bal_wrong';
	$w = $db->fetch1Row(
		$db->query('SELECT wBal, wLock, wOut FROM Wallets WHERE wcID=?d and wuID=?d FOR UPDATE', array($cid, $uid))
	);
	$walletExists = (bool)$w;
	$w = $w ?: array('wBal' => 0, 'wLock' => 0, 'wOut' => 0);
	try {
		foreach (array('wBal'=>$z1, 'wLock'=>$z2, 'wOut'=>$z3) as $field=>$delta) {
			$next = DecimalInput::read($w[$field])->plus(Decimal::of($delta));
			if ($next->isNegative()) return array('wBal'=>'low_bal1', 'wLock'=>'low_bal2', 'wOut'=>'low_bal3')[$field];
			StoredAmount::exact($next);
		}
		if (!Decimal::of($z)->isZero()) {
			$total = DecimalInput::read($user['uBal'])->plus(Decimal::of($z));
			if ($total->isNegative()) return 'low_bal';
			StoredAmount::exact($total);
		}
	} catch (OverflowException) { return 'bal_overflow'; }
	catch (InvalidArgumentException) { return 'bal_wrong'; }
	if (!$walletExists)
		$db->query('INSERT INTO Wallets (wcID, wuID) VALUES (?d, ?d) ON DUPLICATE KEY UPDATE wMTS=wMTS', array($cid, $uid));
	try
	{
		// The non-negative predicates provide an atomic overdraft guard even when
		// concurrent requests passed the earlier informational balance check.
		if (!$db->update('Wallets', 
			array(
				'wBal=' => "wBal+$z1", 
				'wLock=' => "wLock+$z2", 
				'wOut=' => "wOut+$z3"
			), 
			'', "wcID=?d and wuID=?d and (wBal+$z1>=0) and (wLock+$z2>=0) and (wOut+$z3>=0)", array($cid, $uid)
		))
			xAbort();
		if ($z != 0)
			if (!$db->update('Users', array('uBal=' => "uBal+$z"), '', "uID=?d and (uBal+$z>=0)", array($uid)))
				xAbort();
	}
	catch (FormAbortException $e)
	{
		return 'low_bal';
	}
	catch (Throwable $e)
	{
		throw $e;
	}
	opAddHist('BAL', $uid, '', $memo, 0, $cid, $z, $oid);
	return true;
	});
}

// Currs support

function opDecodeCurrParams($crec, &$p, &$p_sci, &$p_api)
{
	$key = (isset($crec['cID']) ? $crec['cID'] : '') . (isset($crec['cCID']) ? $crec['cCID'] : '') . stampToTime(isset($crec['cMTS']) ? $crec['cMTS'] : '');
	$p = decodeArrayFromStr(isset($crec['cParams']) ? $crec['cParams'] : '', $key);
	$p_sci = decodeArrayFromStr(isset($crec['cParamsSCI']) ? $crec['cParamsSCI'] : '', $key, 2);
	$p_api = decodeArrayFromStr(isset($crec['cParamsAPI']) ? $crec['cParamsAPI'] : '', $key, 3);
    if (!empty($crec['cProviderID']) && ($crec['cCID']??'')==='PAW') {
        global $db;
        $p_sci=(new \HScript\Payment\Provider\PawProviderRepository($db))->configuration($crec);
    }
    if (!empty($crec['cProviderID']) && ($crec['cCID'] ?? '') !== 'PAW') {
        global $db;
        $resolved=(new \HScript\Payment\Provider\ProviderRepository($db))->configuration($crec,$p_api);
        $p_sci=$resolved['sci'];$p_api=$resolved['api'];
        if ($crec['cCID']==='ST') $p_sci += [
            '_provider_id'=>$resolved['profile_id'],'_provider_public'=>$resolved['public_id'],
            '_provider_version'=>$resolved['key_version'],'_provider_environment'=>$resolved['environment']];
    }

}

function opDecodeUserCurrParams($wrec)
{
	$key = (isset($wrec['wcID']) ? $wrec['wcID'] : '') . (isset($wrec['wuID']) ? $wrec['wuID'] : '') . stampToTime(isset($wrec['wMTS']) ? $wrec['wMTS'] : '');
	return decodeArrayFromStr(isset($wrec['wParams']) ? $wrec['wParams'] : '', $key);
}

function opEditToCurrParams($fields, $old, $new, $fn = '')
{
	if (!is_array($old))
		$old = array();
	foreach ($fields as $f => $v)
		if (!is_int($f))
		{
			$is_pass = ((substr($f, -3) == 'key') or (substr($f, -4) == 'pass'));
			if (!empty($new[$f]) or !$is_pass)
			{
				if (!empty($new[$f]) and !empty($v[1]) and !preg_match('/^' . $v[1] . '$/', $new[$f]))
					return $fn . $f . '_wrong';
				if (!$is_pass)
					$new[$f] = strip_tags(isset($new[$f]) ? $new[$f] : '');
				elseif (isset($new[$f]) && $new[$f] == '-')
					$new[$f] = '';
				$old[$f] = isset($new[$f]) ? $new[$f] : '';
			}
		}
	foreach ($old as $f => $v)
		if (StringHelper::sEmpty($v))
			unset($old[$f]);
	return $old;
}

function opCurrParamsToEdit($fields, $fn = '', $readonly = false)
{
	$res = array();
	foreach ($fields as $f => $v)
	{
		if (is_int($f))
			$res[$f] = $v;
		else
		{
			$fi = ($fn ? $fn . '[' . $f . ']' : $f);
			if ($f == '_url')
			{
				$url = fullURL(moduleToLink('balance/status'));
				$res[$fi] = 
					array('X', isset($v[0]) ? $v[0] : '', 0, "<a href=\"$url\" target=\"_blank\">$url</a>");
//				$res[$fn . '[hideurl]'] = 
//					array('C', 'Hide URL');
			}
			else
			{
				$is_pass = ((substr($f, -3) == 'key') or (substr($f, -4) == 'pass'));
				$res[$fi] =
					array(
						StringHelper::valueIf($is_pass, '*', StringHelper::valueIf((isset($v[3]) ? $v[3] : ""), 'S', 'T')),
						isset($v[0]) ? $v[0] : '', 
						array($fn . $f . '_wrong' => '{!en!}wrong format{!ru!}неверный формат'),
						(isset($v[3]) ? $v[3] : ""),
						'comment' => (isset($v[2]) ? $v[2] : ""),
						'readonly' => $readonly
					);
			}
		}
	}
	return $res;
}

function prepareStat()
{
	global $db;
	View::setPage('stats', $db->fetchIDRows($db->select('Currs',
		"*, 
		(SELECT SUM(oSum) FROM Opers WHERE ouID=?d AND ocID=cID AND oOper='REF' AND oState=3) AS ZREF,
		(SELECT SUM(oSum) FROM Opers WHERE ouID=?d AND ocID=cID AND oOper='CALCIN' AND oState=3) AS ZCALCIN,
		(SELECT SUM(oSum) FROM Opers WHERE ouID=?d AND ocID=cID AND ((cID=1 AND oOper='EXIN') OR (cID>1 AND oOper='CASHIN')) AND oState=3) AS ZIN,
		(SELECT SUM(oSum) FROM Opers WHERE ouID=?d AND ocID=cID AND ((cID=1 AND oOper='EX') OR (cID>1 AND oOper='CASHOUT')) AND oState=3) AS ZOUT,
		(SELECT SUM(dZD) FROM Deps WHERE duID=?d AND dcID=cID AND dState=1) AS ZINDEPO,
		(SELECT SUM(oSum) FROM Opers WHERE ouID=?d AND ocID=cID AND oOper='BONUS' AND oState=3) AS ZBONUS
		", 
		'cDisabled=0', array(_uid(), _uid(), _uid(), _uid(), _uid(), _uid()), 'cID'), false, 'cID'));
}

?>
