<?php

use HScript\Util\StringHelper;
use HScript\Payment\PaymentManager;

use HScript\Template\View;
use HScript\Finance\FinancialForm;
use HScript\Finance\RequestDecimal;

$_auth = 71;
require_once('module/auth.php');
$paymentManager = new PaymentManager($db);
// Reject mixed action markers before receipt writes or provider status I/O.
if (($_SERVER['REQUEST_METHOD']??'')==='POST') {
    $submitted=0;
    foreach (['add','data','ccapi_payout_check','paw_payout_check'] as $form) if(isset($_POST[View::getFormName($form).'_btn']))++$submitted;
    foreach (['cancel','del',''] as $suffix) if(isset($_POST[View::getFormName().'_btn'.$suffix]))++$submitted;
    if($submitted>1){http_response_code(400);exit('Invalid operation action');}
}

useLib('balance');

$table = 'Opers';
$id_field = 'oID';
$out_link = moduleToLink('balance/admin/opers');

try 
{

	if (View::sendedForm('', 'add'))
	{
		FinancialForm::check('admin:balance:add', 'add');

		if (!_IN('Oper'))
			View::setError('oper_empty', 'add');
		$uid = $db->fetch1($db->select('Users', 'uID', 'uLogin=? and (uState=1 or uState=2)', array(_IN('Login'))));
		if (!$uid)
			View::setError('user_empty', 'add');
		if (!isset_IN('PSys'))
			goToURL(moduleToLink() . '?add=' . _IN('Oper') . '&user=' . _IN('Login'));
		if (isset($_IN['Sum'])) cn($_IN['Sum']);
		$a = array(
			'uid2' => $db->fetch1($db->select('Users', 'uID', 'uLogin=?', array(_IN('Login2')))),
			'cid2' => _IN('PSys2'),
			'pid' => _IN('Plan')
		);
		if ((_IN('Oper') == 'CALCIN') and (_INN('PSys') < 0))
		{
			$d = $db->fetch1Row($db->select('Deps LEFT JOIN Plans ON pID=dpID LEFT JOIN Users ON uID=duID', '*', 
				'duID=?d and dState=1 and (dNPer<pNPer or pNPer=0)', array($uid)));
			useLib('depo');
			View::setError($did = FinancialForm::execute('admin:balance:charge', ['user_id'=>(int)$uid,'currency_id'=>_INN('PSys')], static function () use ($d) { HScript\Finance\FinancialOperationException::check(opDepoCharge($d)); return (int)$d['dID']; }));
			View::showInfo('Completed', moduleToLink('depo/admin/depo') . '?id=' . $did);
		}
		else
			View::setError($id = FinancialForm::execute('admin:balance:add', ['user_id'=>(int)$uid,'operation'=>_IN('Oper'),'currency_id'=>_INN('PSys'),'amount'=>RequestDecimal::normalize(_IN('Sum')),'params'=>$a,'memo'=>_IN('Memo')], static fn()=>opOperCreate($uid,_IN('Oper'),_INN('PSys'),_IN('Sum'),$a,_IN('Memo'),false,true)), 'add');
		View::showInfo('Added', moduleToLink() . "?id=$id");
	}

    if(View::sendedForm('', 'paw_payout_check')) {
        FinancialForm::check('admin:balance:paw-payout-check','paw_payout_check');
        $payoutId=_INN('payout_id');
        try { HScript\Finance\PawPayout::reconcile($db,$payoutId); }
        catch(\Throwable){http_response_code(409);exit('Payout is pending or requires reconciliation. No resend was performed.');}
        View::showInfo('Saved',moduleToLink().'?id='.$payoutId);
    }

    if(View::sendedForm('', 'ccapi_payout_check')) {
        FinancialForm::check('admin:balance:ccapi-payout-check','ccapi_payout_check');
        $payoutId=_INN('payout_id');
        try { HScript\Finance\CcapiPayout::reconcile($db,$payoutId); }
        catch(\Throwable){http_response_code(409);exit('Payout is pending or requires reconciliation. No resend was performed.');}
        View::showInfo('Saved',moduleToLink().'?id='.$payoutId);
    }

	if (View::sendedForm('', 'data'))
	{
		View::checkFormSecurity('data');

		if ($o = $db->fetch1Row($db->select('Opers LEFT JOIN Currs on cID=ocID', '*',
			"$id_field=?d and oState<=2", array(_INN('oID')))))
		{
            if($o['oOper']==='CASHOUT' && ($o['cCID']==='PAW' || HScript\Payment\Gateways\CryptoCurrencyApi::supports($o['cCID'])) && HScript\Finance\WithdrawalDispatcher::wasAttempted($db,(int)$o['oID'])){http_response_code(409);exit('Payout requires provider reconciliation');}
			$a = array(
				'date' => timeToStamp(View::textToTime(_IN('date'))),
				'batch' => strip_tags(_IN('batch')),
				'memo' => _IN('memo')
			);
			if (!$a['date'] or (stampToTime($a['date']) >= time()))
				View::setError('data_date_wrong', 'data');
			if (!$a['batch'])
				View::setError('data_batch_wrong', 'data');
            if($o['oOper']==='CASHOUT' && $o['cCID']==='PAW') {
                $destination=strToArray($o['oParams2']);
                $a+=array_intersect_key($destination,array_flip(['acc','payout_asset']));
            } else {
			View::setError($a = opEditToCurrParams($paymentManager->fields($o['cCID']), $a, $_IN), 'data');
            }
            if($o['oOper']==='CASHOUT' && HScript\Payment\Gateways\CryptoCurrencyApi::supports($o['cCID'])) {
                try { HScript\Finance\CcapiPayout::saveReceipt($db,(int)$o['oID'],$a); }
                catch(\Throwable){http_response_code(409);exit('Payout requires provider reconciliation');}
            } elseif($o['oOper']==='CASHOUT' && $o['cCID']==='PAW') {
                try { HScript\Finance\PawPayout::saveReceipt($db,(int)$o['oID'],$a); }
                catch(\Throwable){http_response_code(409);exit('Payout requires provider reconciliation');}
            } else $db->update('Opers', array('oBatch' => StringHelper::valueIf($o['oState'] == 2, '?' . $a['batch']), 'oParams2' => arrayToStr($a)), '', 'oID=?d', array($o['oID']));
			View::showInfo('Saved');
		}
		View::showInfo('*Error');
	}
	
	if ($id = _INN($id_field))
	{
		$actions=[];foreach(['cancel'=>'cancel','del'=>'delete',''=>'complete'] as $suffix=>$candidate)if(View::sendedForm($suffix))$actions[]=$candidate;
		if(count($actions)!==1){http_response_code(400);exit('Invalid operation action');}
		$action=$actions[0];
		FinancialForm::check('admin:balance:' . $action);
		$response=FinancialForm::execute('admin:balance:' . $action, ['operation_id'=>$id,'api_fingerprint'=>HScript\Finance\IdempotencyRequest::fingerprint((array)_IN('API'))], function () use ($id,$id_field,$out_link,$action) {
		global $db;
		
		if ($o = $db->fetch1Row($db->select('Opers', '*', "$id_field=?d", array($id))))
		{
			if ($action === 'delete' and ($o['oState'] >= 4))
			{
				$db->delete('Opers', 'oID=?d', array($id));
				return ['info'=>'Deleted','url'=>$out_link];
			}
			elseif ($action === 'cancel' and ($o['oState'] <= 2))
			{
				if (opOperCancel($o['ouID'], $id, array(), true) === true)
					return ['info'=>'Canceled','url'=>moduleToLink() . '?id=' . $id];
			}
			elseif ($action === 'complete' and ($o['oState'] <= 2))
			{
				if ($o['oState'] < 2)
					View::setError(opOperConfirm($o['ouID'], $id, array(), true));
				View::setError(opOperComplete($o['ouID'], $id, array(), true, (array)_IN('API')));
				return ['info'=>'Completed','url'=>moduleToLink() . '?id=' . $id];
			}
		}
		return 'oper_state_wrong';
		});
		View::setError($response);
		View::showInfo($response['info'],$response['url']);
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
		$el = $db->fetch1Row($db->select("$table LEFT JOIN Users on uID=ouID LEFT JOIN Currs on cID=ocID", '*', "$id_field=?d", array($id)));
	if (!$el)
		goToURL($out_link);
    $el=HScript\Payment\Provider\CurrencyPresentation::annotate($db,[$el])[0];
    $ccapiAttempted=false;$frozenPayout=null;
    if($el['oOper']==='CASHOUT' && HScript\Payment\Gateways\CryptoCurrencyApi::supports($el['cCID'])) {
        $ccapiAttempted=HScript\Finance\WithdrawalDispatcher::wasAttempted($db,(int)$el['oID']) || array_key_exists('ccapi_payout',strToArray($el['oParams']));
        View::setPage('ccapi_payout_attempted',$ccapiAttempted);
        if($ccapiAttempted)try {
            $frozenPayout=HScript\Finance\CcapiPayout::original($db,(int)$el['oID']);
            if((int)$el['oState']===3)HScript\Finance\CcapiPayout::assertCompletion($db,(int)$el['oID']);
        }catch(\Throwable){View::setPage('ccapi_payout_reconciliation',true);}
    }
    if($el['oOper']==='CASHOUT' && $el['cCID']==='PAW') {
        $attempted=HScript\Finance\WithdrawalDispatcher::wasAttempted($db,(int)$el['oID']);
        View::setPage('paw_payout_attempted',$attempted);
        if($attempted)try {
            HScript\Finance\PawPayout::original($db,(int)$el['oID']);
            if((int)$el['oState']===3)HScript\Finance\PawPayout::assertCompletion($db,(int)$el['oID']);
        }catch(\Throwable){View::setPage('paw_payout_reconciliation',true);}
    }
	View::stampArrayToStr($el, 'oCTS, oTS, oNTS');
	$el['oParams'] = strToArray($el['oParams']);
	$el['oParams2'] = strToArray($el['oParams2']);
	View::stampArrayToStr($el['oParams2'], 'date', 1);
	View::setPage('el', $el);
	View::setPage('exchange_quote', opExchangeQuoteDisplay($el), 0);
	$is_out = $el['oOper'] === 'CASHOUT';
    if($is_out && $el['oState']<=2 && HScript\Payment\Gateways\CryptoCurrencyApi::supports($el['cCID']) && !empty($el['cProviderID']) && array_key_exists('cAssetKey',$el)){
        $sources=[];$routes=$ccapiAttempted?[]:HScript\Payment\Routing\PaymentRoutes::catalog($el['cCID'],$el);
        if(!$ccapiAttempted && !$routes && isset(HScript\Payment\Gateways\CryptoCurrencyApi::NATIVE[$el['cCID']])){[$chain,$asset]=HScript\Payment\Gateways\CryptoCurrencyApi::NATIVE[$el['cCID']];$routes=[['network'=>$chain,'label'=>$asset]];}
        foreach($routes as $route){$wallet=HScript\Payment\Routing\CcapiWallets::read($db,(int)$el['cProviderID'],$route['network']);$sources[]=['network'=>$route['label'],'address'=>$wallet['pnPayoutFrom']?:'Основной адрес CCAPI'];}
        if($frozenPayout)$sources=[['network'=>$frozenPayout['contract']['chain'],'address'=>$frozenPayout['arguments']['from']]];
        View::setPage('ccapi_payout_sources',$sources);
    }
	if (($el['oOper'] === 'CASHIN' || $is_out) && $el['oState'] <= 2)
	{
		$c = $paymentManager->definitions($el['cCID']);
		if ($el['cCID']!=='PAW' && ($a = opCurrParamsToEdit($paymentManager->fields($el['cCID']))))
			View::setPage('dfields', array(1 => '') + $a, 1);
		if (empty($c[2 + $is_out]))
			View::setPage('defaultbatch', StringHelper::valueIf($is_out, 'OUT', 'IN') . str_pad($el['oID'], 6, '0', STR_PAD_LEFT));
		opDecodeCurrParams($el, $p, $p, $p);
		if ($is_out and !empty($c[3]) and !HScript\Payment\Gateways\CryptoCurrencyApi::supports($el['cCID']) and empty($p['apipass']))
			if ($a = opCurrParamsToEdit($paymentManager->fields($el['cCID'], 'api'), 'API'))
				View::setPage('afields', array(1 => '') + $a, 1);
	}
}
else
{
	if ($oper = _GET('add'))
	{
		$uid = $db->fetch1($db->select('Users', 'uID', 'uLogin=? and (uState=1 or uState=2)', array(_GET('user'))));
		if (!$uid)
			goToURL(moduleToLink() . '?add');
		View::setPage('currs', $db->fetchIDRows($db->select('Currs LEFT JOIN Wallets ON wcID=cID and wuID=?d',
		'*', 'cDisabled=0', array($uid), 'cID'), false, 'cID'));
	}
	$list = array();
	foreach ($_currs as $id => $r)
		if (($id > 1) or !in_array((string)_GET('add'), array('CASHIN', 'CASHOUT')))
			$list[$id] = $r['cName'];
	if ($oper == 'CALCIN')
	{
		if ($dlist = $db->fetchRows($db->select('Deps LEFT JOIN Plans ON pID=dpID', '*', 'duID=?d and dState=1', array($uid), 'dID')))
		{
			foreach ($dlist as $d)
				$list[-$d['dID']] = '#' . $d['dID'] . ' [' . StringHelper::textLangFilter($d['pName'], $_GS['lang']) . '] - ' . _z($d['dZD'], $d['dcID']) . ' ' . $_currs[$d['dcID']]['cCurr'] . ' ' . $_currs[$d['dcID']]['cName'];
		}
	}
	View::setPage('clist', $list);
}

View::showPage();

?>
