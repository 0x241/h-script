<?php

use HScript\Template\View;
use HScript\Payment\PaymentManager;

$_auth = 90;
require_once('module/auth.php');
$paymentManager = new PaymentManager($db);

useLib('balance');

$table = 'Currs';
$id_field = 'cID';
$out_link = moduleToLink('balance/admin/currs');

try 
{

	$cids = $paymentManager->definitions();

	if (View::sendedForm('', 'add'))
	{
		View::checkFormSecurity('add');
		
		if (!($c = $cids[_IN('PSys')]))
			View::setError('psys_not_selected', 'add');
		if ($id = $db->insert('Currs', array('cCID' => _IN('PSys'), 'cCurrID' => $c[1], 'cName' => $c[0], 'cCurr' => $c[1])))
			View::showInfo('Added', moduleToLink() . "?id=$id");
		View::showInfo('*Error');
	}

	if (View::sendedForm())
	{
		View::checkFormSecurity();

		if (($cid = _INN('cID')) and (_IN('cCID')))
		{
			$a = $_IN;
			opDecodeCurrParams($db->fetch1Row($db->select($table, '*', 'cID=?d', array($cid))), $p, $p_sci, $p_api);
			$t = time();
			$key = $cid . $a['cCID'] . $t;
			View::setError($p = opEditToCurrParams($paymentManager->fields($a['cCID']), $p, (array)_IN('P'), 'P'));
			View::setError($p_sci = opEditToCurrParams($paymentManager->fields($a['cCID'], 'sci'), $p_sci, (array)_IN('PSCI'), 'PSCI'));
			View::setError($p_api = opEditToCurrParams($paymentManager->fields($a['cCID'], 'api'), $p_api, (array)_IN('PAPI'), 'PAPI'));
			$a['cParams'] = encodeArrayToStr($p, $key);
			$a['cParamsSCI'] = encodeArrayToStr($p_sci, $key, 2);
			$a['cParamsAPI'] = encodeArrayToStr($p_api, $key, 3);
			$a['cMTS'] = timeToStamp($t);
			if ($id = $db->save($table, $a,
				'cDisabled, cHidden, cName, cCurr, cNumDec, ' .
				'cCASHINMode, cCASHINMin, cCASHINMax, cCASHINInt, cCASHINComis, cCASHINComisMin, cCASHINComisMax, ' .
				'cCASHOUTMode, cCASHOUTMin, cCASHOUTMax, cCASHOUTInt, cCASHOUTComis, cCASHOUTComisMin, cCASHOUTComisMax, cCASHOUTLimitPer, cCASHOUTLimit, ' .
				'cEXMode, cEXOut, cEXIn, cEXMin, cEXMax, cEXInt, cEXComis, cEXComisMin, cEXComisMax, ' .
				'cTRMode, cTRMin, cTRMax, cTRInt, cTRComis, cTRComisMin, cTRComisMax, ' .
				'cBUYMode, ' .
				'cSELLMode, ' .
				'cBUY2Mode, ' .
				'cSELL2Mode, ' .
				'cGIVEMode, ' .
				'cTAKEMode, ' .
				'cParams, cParamsSCI, cParamsAPI, cMTS', $id_field))
				View::showInfo('Saved', $out_link . "?id=$id");
		}
		View::showInfo('*Error');
	}

} 
catch (FormAbortException $e)
{
}

if (!isset($_GET['add']))
{
	if ($id = _GETN('id'))
		$el = $db->fetch1Row($db->select($table, '*', "$id_field=?d", array($id)));
	if (!$el)
		goToURL(moduleToLink() . '?add');
	opDecodeCurrParams($el, $el['P'], $el['PSCI'], $el['PAPI']);
	View::stampArrayToStr($el, 'cMTS');
	View::setPage('el', $el, 2);
	View::setPage('cName', isset($cids[$el['cCID']]) ? $cids[$el['cCID']][0] : $el['cName']);
	View::setPage('pfields', opCurrParamsToEdit($paymentManager->fields($el['cCID']), 'P'), 1);
	View::setPage('sfields', opCurrParamsToEdit($paymentManager->fields($el['cCID'], 'sci'), 'PSCI'), 1);
	View::setPage('afields', opCurrParamsToEdit($paymentManager->fields($el['cCID'], 'api'), 'PAPI'), 1);
	if (isset($_GET['testapi']))
	{
		$res = $paymentManager->getBalance($el['cCID'], $el['PAPI']);
		View::setPage('res', $res);
	}
}
else
{
	$list = $db->fetchRows($db->select('Currs', 'cCID'), 1);
	foreach ($list as $id)
		unset($cids[$id]);
	$list = array();
	foreach ($cids as $id => $r)
		$list[$id] = $r[0] . ', ' . $r[1];
	View::setPage('cids', $list); // available CIDs
}

View::showPage();

?>
