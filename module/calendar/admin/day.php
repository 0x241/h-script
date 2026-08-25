<?php

use HScript\Template\View;

$_auth = 90;
require_once('module/auth.php');

$table = 'Calend';
$id_field = 'cID';
$out_link = moduleToLink('calendar/admin/days');

try 
{

	if (View::sendedForm())
	{
		View::checkFormSecurity();

		$a = $_IN;
		$dateInput = trim((string)_IN('cTS'));
		$isNumericDate = preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/D', $dateInput, $dateParts);
		if (!preg_match('/^[\p{L}\p{N}.,\s-]{6,40}$/uD', $dateInput)
			|| ($isNumericDate && !checkdate((int)$dateParts[2], (int)$dateParts[1], (int)$dateParts[3])))
			View::setError('date_empty');
		$a['cTS'] = $dateInput;
		View::strArrayToStamp($a, 'cTS', 1);
		if (!$a['cTS'])
			View::setError('date_empty');
		if ($db->count($table, 'cID<>?d and cTS=?', array($a['cID'], $a['cTS'])) > 0)
			View::setError('date_exist');
		if (!$a['cType'])
			View::setError('type_empty');
        if ($a['cPerc'] < 0)
            View::setError('perc_wrong');
		if ($id = $db->save($table, $a, 
			'cTS, cType, cPerc', $id_field))
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
	View::stampArrayToStr($el, 'cTS', 1);
	View::setPage('el', $el, 2);
}
else
	View::setPage('today', View::timeToStr(time(), 1));

View::setPage('d_types', array(
	1 => 'рабочий',
	2 => 'выходной',
	3 => 'праздничный',
), 0);
View::showPage();

?>
