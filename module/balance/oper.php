<?php

use HScript\Payment\PaymentManager;

use HScript\Util\StringHelper;

use HScript\Template\View;
use HScript\Security\SessionCsrf;
use HScript\Finance\FinancialForm;
use HScript\Finance\RequestDecimal;

$payment_status_requested = isset($_GET['payment_status']);
if ($payment_status_requested) {
	// Preserve idle timeout: automatic background reads are not user activity.
	startSessionSafely();
	$payment_activity = $_SESSION['_lts'] ?? null;
}
$_auth = 1;
require_once('module/auth.php');
if ($payment_status_requested) {
	if ($payment_activity === null) unset($_SESSION['_lts']);
	else $_SESSION['_lts'] = $payment_activity;
	header('Content-Type: application/json; charset=UTF-8');
	header('Cache-Control: no-store, private');
	header('X-Content-Type-Options: nosniff');
	if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
		header('Allow: GET'); http_response_code(405); exit('{}');
	}
	$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
	if (!$id) { http_response_code(404); exit('{}'); }
	$now = time();
	if ($now - (int)($_SESSION['_payment_status_at'] ?? 0) < 5) {
		header('Retry-After: 5'); http_response_code(429); exit('{}');
	}
	$_SESSION['_payment_status_at'] = $now;
	$result = HScript\Payment\CheckoutProgress::read($db, _uid(), $id);
	if (!$result) { http_response_code(404); exit('{}'); }
	echo json_encode($result, JSON_THROW_ON_ERROR);
	exit;
}
$paymentManager = new PaymentManager($db);

$table = 'Opers';
$id_field = 'oID';
$uid_field = 'ouID';
$out_link = moduleToLink('balance');
$pay_requested = false;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['read_receipt']))
{
	if (!SessionCsrf::valid($_POST['session_csrf'] ?? null))
	{
		http_response_code(403);
		exit;
	}
	$read_id = filter_var($_POST['read_receipt'], FILTER_VALIDATE_INT);
	if (!$read_id || !$db->count('Opers', 'oID=?d and ouID=?d', array($read_id, _uid())))
	{
		http_response_code(404);
		exit;
	}
	$db->update('Opers', array('oNTS' => ''), '', 'oID=?d and ouID=?d', array($read_id, _uid()));
	http_response_code(204);
	exit;
}

try
{
	if (View::sendedForm('pay', 'pay'))
	{
		FinancialForm::check('balance:pay', 'pay');
		$pay_requested = true;
	}

	if (View::sendedForm('', 'add'))
	{
		FinancialForm::check('balance:add', 'add');

		if (!_IN('Oper'))
			goToURL($out_link);
		$a = $_IN;
		$a += array(
			'Oper' => '',
			'PSys' => 0,
			'PSys2' => 0,
			'Sum' => 0,
			'PIN' => '',
			'Login2' => '',
			'Plan' => 0,
			'Compnd' => 0,
			'Memo' => ''
		);
		cn($a['Sum']);
		if (($a['Oper'] == 'CASHOUT') and $_cfg['Bal_NeedPIN'] and !verifyPasswordWithLegacyDigest($a['PIN'], $_user['uPIN'], $_cfg['Const_Salt'], false))
			View::setError('pin_wrong', 'add');
		$id = FinancialForm::execute('balance:add', [
			'operation'=>(string)$a['Oper'], 'currency_id'=>(int)$a['PSys'],
			'amount'=>RequestDecimal::normalize($a['Sum']), 'peer_login'=>(string)$a['Login2'],
			'target_currency_id'=>(int)$a['PSys2'], 'plan_id'=>(int)$a['Plan'],
			'compound'=>RequestDecimal::normalize($a['Compnd'] ?: 0, true), 'memo'=>$a['Memo'],
		], static function () use ($a) {
		global $db, $_cfg, $_currs;
		if ($_cfg['Const_IntCurr'] and ($a['Oper'] == 'CASHIN') and $_cfg['Bal_SumInInternal'])
		{
			$converted = opAmountFromBase($a['Sum'], $a['PSys']);
			if (is_string($converted)) View::setError($converted, 'add');
			$a['Sum'] = (string)$converted;
		}
		if ($a['Oper'] == 'CASHOUT')
		{
			$udata = opDecodeUserCurrParams($_currs[$a['PSys']]);
			if (!$udata['acc'])
				View::setError('wallet_not_defined', 'add');
		}
		if ($_cfg['Depo_AutoDepo'] and ($a['Oper'] == 'CASHIN'))
		{
			useLib('depo');
			$err = opDepoCreate(_uid(), $a['PSys'], $a['Sum'], $a['Compnd'], $a['Plan'], false, 2);
			if ($err != 'passed')
				View::setError($err, 'add');
		}
		if (!isset($a['PSys']))
			$a['PSys'] = 1;
		$params = array(
			'uid2' => $db->fetch1($db->select('Users', 'uID', 'uLogin=?', array($a['Login2']))),
			'cid2' => $a['PSys2'],
			'pid' => $a['Plan'],
			'compnd' => $a['Compnd']
		);
		$cid = $a['PSys'];
		if ($_cfg['Const_IntCurr'] and ($a['Oper'] == 'CASHOUT'))
		{
			$params['cid2'] = $a['PSys'];
			$a['Oper'] = 'EX';
			$a['PSys'] = 1;
		}
		return opOperCreate(_uid(), $a['Oper'], $a['PSys'], $a['Sum'], $params, $a['Memo']);
		});
		View::setError($id, 'add');
		View::showInfo('Saved', moduleToLink() . "?id=$id");
	}

	if (View::sendedForm('', 'data'))
	{
		View::checkFormSecurity('data');

		if ($o = $db->fetch1Row($db->select('Opers LEFT JOIN Currs on cID=ocID', '*', 
			"$id_field=?d and $uid_field=?d and oOper=? and oState<=1", array(_INN('oID'), _uid(), 'CASHIN'))))
		{
			$a = array(
				'date' => timeToStamp(View::textToTime(_IN('date'))),
				'batch' => strip_tags(_IN('batch')),
				'memo' => _IN('memo')
			);
			if (!$a['date'] or (stampToTime($a['date']) >= time()))
				View::setError('data_date_wrong', 'data');
			if (!$a['batch'])
				View::setError('data_batch_wrong', 'data');
			View::setError($a = opEditToCurrParams($paymentManager->fields($o['cCID']), $a, $_IN), 'data');
			$db->update('Opers', array('oParams2' => arrayToStr($a)), '', 'oID=?d', ($o['oID']));
			View::showInfo('Saved');
		}
		View::showInfo('*Error');
	}
	
	if ($id = _INN($id_field))
	{
		$actions=[];foreach(['cancel'=>'cancel','del'=>'delete',''=>'confirm'] as $suffix=>$candidate)if(View::sendedForm($suffix))$actions[]=$candidate;
		if(count($actions)!==1){http_response_code(400);exit('Invalid operation action');}
		$action=$actions[0];
		FinancialForm::check('balance:' . $action);
		$response = FinancialForm::execute('balance:' . $action, ['operation_id' => $id, 'afm_account' => _IN('AFMAccount'), 'afm_batch' => _IN('AFMBatch')], function () use ($id, $id_field, $uid_field, $out_link, $action) {
		global $db, $_cfg, $_currs, $_user, $paymentManager;
		
		if ($o = $db->fetch1Row($db->select('Opers', '*', "$id_field=?d and $uid_field=?d", array($id, _uid()))))
		{
			if ($action === 'delete' and ($o['oState'] >= 5))
			{
				$db->delete('Opers', 'oID=?d', array($id));
				return ['info' => 'Deleted', 'url' => $out_link];
			}
			elseif ($action === 'cancel' and ($o['oState'] <= 2))
			{
				if (opOperCancel(_uid(), $id, array()) === true)
					return ['info' => 'Canceled', 'url' => moduleToLink() . '?id=' . $id];
			}
			elseif ($action === 'confirm' and ($o['oState'] <= 1))
			{
				if (($o['oOper'] == 'CASHIN') and ($_cfg['Bal_AFMMode'] > 0))
				{
					$a = array(
						'date' => timeToStamp(),
						'batch' => 'IN' . $o['oID'],
						'memo' => $o['oMemo'],
						'acc' => '???'
					);
					if ($_cfg['Bal_AFMMode'] & 1)
						if (!$a['acc'] = _IN('AFMAccount'))
							View::setError('afmacc_wrong');
						elseif ($c = $paymentManager->fields($_currs[$o['ocID']]['cCID']))
							if ($c['acc'][1] and !preg_match('/^' . $c['acc'][1] . '$/', $a['acc']))
								View::setError('afmacc_wrong');
					if ($_cfg['Bal_AFMMode'] & 2)
						if (!$a['batch'] = _IN('AFMBatch'))
							View::setError('afmbatch_wrong');
					$db->update('Opers', array('oParams2' => arrayToStr($a)), '', 'oID=?d', ($o['oID']));
				}
				if ($_cfg['SMS_CASHOUT'] and (($o['oOper'] == 'CASHOUT') or (($o['oOper'] == 'EX') and ($o['ocID'] == 1))))
				{
					useLib('confirm');
					$tel = $_user['aTel'];
					opConfirmPrepareSMS(_uid(), 'OPER', array('oid' => $id, 'tel' => $tel), '', $tel);
					return ['info' => 'Saved', 'url' => moduleToLink('confirm') . '?need_confirm_sms'];
				}
				$err = opOperConfirm(_uid(), $id, array());
				if (($err === 'limit_exceeded') and ($_currs[$o['ocID']]['c' . $o['oOper'] . 'Mode'] == 2))
				{
					View::setError(opOperConfirm(_uid(), $id, array(), true));
					View::sendMailToAdmin('OperRequired',
						opUserConsts($_user, array('oid' => $id, 'url' => fullURL(moduleToLink('balance/admin/oper')))));
				}
				else
				{
					View::setError($err);
					if ($o['oOper'] != 'CASHIN')
					{
						if ($_currs[$o['ocID']]['c' . $o['oOper'] . 'Mode'] == 2)
						{
							View::setError(opOperComplete(_uid(), $id, array('initid' => $id)));
							if ($p = strToArray($db->fetch1($db->select('Opers', 'oParams2', "$id_field=?d", array($id)))))
								if ($id = $p['newid'])
									return ['info' => 'Completed', 'url' => moduleToLink() . '?id=' . $id];
						}
						else
							View::sendMailToAdmin('OperRequired',
								opUserConsts($_user, array('oid' => $id, 'url' => fullURL(moduleToLink('balance/admin/oper')))));
					}
				}
				return ['info' => 'Completed', 'url' => moduleToLink() . '?id=' . $id];
			}
		}
		return 'oper_state_wrong';
		});
		View::setError($response);
		View::showInfo($response['info'], $response['url']);
	}

}
catch (InvalidArgumentException $e)
{
	http_response_code(422);
	xSysStop('Invalid financial amount', true);
} 
catch (FormAbortException $e)
{
}

if (!isset($_GET['add']))
{
	if ($id = _GETN('id'))
		$el = $db->fetch1Row($db->select("$table LEFT JOIN Users on uID=ouID LEFT JOIN Currs on cID=ocID", '*', "$id_field=?d and $uid_field=?d", array($id, _uid())));
	if (!$el)
		goToURL($out_link);
	if (isset($_GET['check']) && $el['oOper'] === 'CASHIN' && (int)$el['oState'] === 3)
		goToURL(moduleToLink('cabinet'));
	View::stampArrayToStr($el, 'oCTS, oTS, oNTS');
	$el['oParams'] = strToArray($el['oParams']);
	$el['oParams2'] = strToArray($el['oParams2']);
	View::stampArrayToStr($el['oParams2'], 'date', 1);
	View::setPage('el', $el);
	View::setPage('exchange_quote', opExchangeQuoteDisplay($el), 0);
	View::setPage('read_receipt_id', $id, 0);
	if (($el['oOper'] == 'CASHIN') and ($el['oState'] <= 2))
	{
        View::setPage('payment_route_required',isset(HScript\Payment\Routing\PaymentRoutes::GATEWAYS[$el['cCID']]));
        View::setPage('payment_route_choices',HScript\Payment\Routing\PaymentRoutes::enabled($el));
		opDecodeCurrParams($el, $p, $p_sci, $p_api);
		if ($pay_requested and in_array($el['cCASHINMode'], array(2, 3)))
		{
            try {
			$pf = HScript\Finance\DepositCheckout::prepare($db, (int)_uid(), (int)$id, static function (array $operation) use ($paymentManager, $id): array {
				global $_cfg, $db;
				opDecodeCurrParams($operation, $p, $p_sci, $p_api);
				$wallet = $db->fetch1Row($db->select('Wallets', '*', 'wcID=?d AND wuID=?d', [(int)$operation['ocID'], (int)$operation['ouID']]));
				$up = opDecodeUserCurrParams($wallet ?: []);
				$up['psysorder'] = strToArray($operation['oParams'])['psysorder'] ?? '';
				return $paymentManager->processDeposit($operation['cCID'], array(
				'pay' => $p,
				'sci' => $p_sci,
				'api' => $p_api,
				'payment_route'=>$operation['_payment_route']??null,
                'ccapi_wallets'=>$operation['_ccapi_wallets']??null,
                'callback_scale' => HScript\Finance\CallbackContract::scale($operation),
				'callback_fee_policy' => $operation,
				'sum' => $operation['oSum'],
				'memo' => strToArray($operation['oParams2'])['memo'],
				'tag' => $id,
				'url_ok' => fullURL(moduleToLink('balance/oper')) . "?id=$id&check",
				'url_fail' => fullURL(moduleToLink('balance')) . '?fail',
				'url_callback' => StringHelper::valueIf(empty($p_sci['hideurl']), fullURL(moduleToLink('balance/status'))),
				'user' => $up,
				'force_payer' => $_cfg['Bal_ForcePayer'],
			));
			}, $_POST['payment_route']??null);
            } catch (HScript\Finance\IdempotencyConflict) {
                http_response_code(409);header('Content-Type: text/plain; charset=UTF-8');exit('Payment network cannot change after invoice creation.');
            } catch (HScript\Finance\CallbackValidationException $e) {
                if(!str_starts_with($e->reason,'payment_route_'))throw $e;
                http_response_code(400);header('Content-Type: text/plain; charset=UTF-8');exit('Select an enabled payment network and retry.');
            }
			View::setPage('pform', $pf, 0);
			if (($pf['formkind'] ?? '') === 'native_address')
				View::setPage('native_details', HScript\Payment\NativePaymentDetails::present($el['cCID'], $pf), 0);
			if (!empty($pf['url']) && ($pf['formkind'] ?? '') !== 'native_address')
				View::setPage('pay_auto_submit', true, 0);
		}
		elseif (!$pay_requested && HScript\Payment\Gateways\CryptoCurrencyApi::supports($el['cCID']))
		{
			$stored = HScript\Finance\DepositCheckout::nativeStored($db, (int)_uid(), (int)$id);
			if ($stored !== null) {
				View::setPage('pform', $stored, 0);
				View::setPage('native_details', HScript\Payment\NativePaymentDetails::present($el['cCID'], $stored), 0);
			}
		}
		if (in_array($el['cCASHINMode'], array(1, 3)))
		{
			View::setPage('pfields', $pf = $paymentManager->fields($el['cCID']));
			View::setPage('pvalues', $p);
			if ($a = opCurrParamsToEdit($pf, '', $el['oState'] == 2))
				View::setPage('dfields', array(1 => '') + $a, 1);
			$c = $paymentManager->definitions($el['cCID']);
			if (!$c[2])
				View::setPage('defaultbatch', 'IN' . str_pad($el['oID'], 6, '0', STR_PAD_LEFT));
		}
	}
}
else
{
	$oper = _GET('add');
	if (!in_array($oper, array('CASHIN', 'CASHOUT', 'EX', 'TR')))
		goToURL($out_link);
	if (!$_cfg['Const_IntCurr'] or in_array($oper, array('CASHIN', 'CASHOUT', 'EX')))
	{
		$list = array();
		$list2 = array();
		foreach ($_currs as $id => $r)
			if (!$r['cHidden'])
			{
				switch ($oper)
				{
				case 'CASHIN':
					if (($r['cCASHINMode'] > 0) and ($id != 1))
						$list[$id] = $r['cName'];
					break;
				case 'CASHOUT':
					if (($r['cCASHOUTMode'] > 0) and ($id != 1))
						$list[$id] = $r['cName'];
					break;
				case 'EX':
					if ($r['cEXMode'] > 0)
						$list[$id] = $r['cName'] . StringHelper::valueIf($r['wBal'] > 0, '{!!} [' . _z($r['wBal'], $r['wcID'], -1) . ']');
					if ($r['cEXMode'] > 0)
						$list2[$id] = $r['cName'] . StringHelper::valueIf($r['wBal'] > 0, '{!!} [' . _z($r['wBal'], $r['wcID'], -1) . ']');
					break;
				case 'TR':
					if ($r['cTRMode'] > 0)
						$list[$id] = $r['cName'] . StringHelper::valueIf($r['wBal'] > 0, '{!!} [' . _z($r['wBal'], $r['wcID'], -1) . ']');
					break;
				}
			}
		if (!$list)
			View::showInfo('*CantComplete', $out_link);
		View::setPage('clist', $list);
		if ($oper == 'EX')
			View::setPage('clist2', $list2);
	}
	if (($oper=='CASHIN') and $_cfg['Depo_AutoDepo'])
	{
		useLib('depo');
		$plans = opDepoGetPlanList(_uid());
		$pl = array();
		$cmax = 0;
		foreach ($plans as $pid => $p)
			if (!$p['Disabled'])
			{
				$pl[$pid] = $p['pName'];
				if ($p['pCompndMax'] > $cmax)
					$cmax = $p['pCompndMax'];
			}
		if (!$pl)
			View::showInfo('*CantComplete', $out_link);
		View::setPage('plans', $pl);
		View::setPage('pcmax', $cmax);
		View::setPage('icurr', $_currs[1]['cCurr']);
	}
}

View::setPage('currs', $_currs);

$_GS['vmodule'] = 'balance';
View::showPage();

?>
