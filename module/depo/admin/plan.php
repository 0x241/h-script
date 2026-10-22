<?php

use HScript\Util\StringHelper;
use HScript\Finance\PlanInput;

use HScript\Template\View;

$_auth = 90;
require_once('module/auth.php');

$table = 'Plans';
$id_field = 'pID';
$out_link = moduleToLink('depo/admin/plans');

try
{

	if (View::sendedForm())
	{
		View::checkFormSecurity();

		$a = $_IN;
		foreach (array('pHidden', 'pNoCalc', 'pWDays', 'pPClndr', 'pEnAdd') as $field)
			if (!isset($a[$field]) || $a[$field] === '')
				$a[$field] = 0;
		if (StringHelper::sEmpty($a['pName']))
			View::setError('name_empty');
		try { $a = PlanInput::normalize($a); }
		catch (InvalidArgumentException $e) {
			if ($e->getMessage() === 'percent_required') {
				View::setError('perc_wrong', '', false);
				View::setError('perc2_wrong');
			}
			View::setError($e->getMessage());
		}
		if ($a['pPer'] < 1)
			View::setError('period_wrong');
		$a['pDays'] = round(_INN('pPer') * _INN('pNPer') / 24);
		if ($id = $db->save($table, $a,
			'pHidden, pNoCalc, pGroup, pGroupReq, pMaxCount, pName, pDescr, pMin, pMax, pDays, pWDays, pPClndr, pPerc, pPer, pNPer, pMPer, pClPer, pClComis, pReturn, pBonus, pEnAdd, pMinAdd, pCompndMin, pCompndMax, pDPerc, pPPerc', $id_field))
			View::showInfo('Saved', $out_link . "?id=$id");
		View::showInfo('*Error');
	}

}
catch (FormAbortException $e)
{
}

if (!isset($_GET['add']))
{
	if (_GETN('id'))
		$el = $db->fetch1Row($db->select($table, '*', "$id_field=?d", array(_GETN('id'))));
	if (!$el)
		goToURL(moduleToLink() . '?add');
	View::setPage('el', $el, 2);
}

View::showPage();

?>
