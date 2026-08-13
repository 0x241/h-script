<?php

use HScript\Util\StringHelper;

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
		foreach (array('pCompndMin' => 'compndmin_wrong', 'pCompndMax' => 'compndmax_wrong') as $field => $error)
		{
			if (!isset($a[$field]) || StringHelper::sEmpty($a[$field]))
				$a[$field] = 0;
			else
				cn($a[$field]);
			if (!is_numeric($a[$field]))
				View::setError($error);
			$a[$field] = (float)$a[$field];
		}
		if (StringHelper::sEmpty($a['pName']))
			View::setError('name_empty');
		if ($a['pMin'] < 0.01)
			View::setError('min_wrong');
		if ($a['pMax'] <= $a['pMin'])
			View::setError('max_wrong');
		if (($a['pPerc'] <= 0) and ($a['pReturn'] <= 0))
		{
			View::setError('perc_wrong', '', false);
			View::setError('perc2_wrong');
		}
		if ($a['pPer'] < 1)
			View::setError('period_wrong');
		if (($a['pCompndMin'] < 0) or ($a['pCompndMin'] > 100))
			View::setError('compndmin_wrong');
		if (($a['pCompndMax'] < 0) or ($a['pCompndMax'] > 100) or ($a['pCompndMax'] < $a['pCompndMin']))
			View::setError('compndmax_wrong');
		if (($a['pClComis'] < 0) or ($a['pClComis'] > 100))
			View::setError('clcomis_wrong');
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
