<?php

use HScript\Template\View;

$_auth = 90;
require_once('module/auth.php');

$table = 'Review';
$id_field = 'oID';
$id = _GETN('id');
$out_link = moduleToLink('review/admin');

if (!$id)
	goToURL($out_link);

try
{
	if (View::sendedForm('del'))
	{
		View::checkFormSecurity();
		$db->delete($table, "$id_field=?d", array($id));
		View::showInfo('Deleted', $out_link);
	}

	if (View::sendedForm())
	{
		View::checkFormSecurity();

		$a = $_IN;
		$a['oText'] = trim((string)_IN('oText'));
		if (!$a['oText'])
			View::setError('text_empty');
		$a['oRating'] = _INN('oRating');
		if (($a['oRating'] < 1) or ($a['oRating'] > 5))
			View::setError('rating_wrong');
		$a['oState'] = isset_IN('oState') ? 1 : 0;
		$a['oOrder'] = intval($a['oOrder']);

		$db->update($table, $a, 'oText, oRating, oState, oOrder', "$id_field=?d", array($id));
		View::showInfo('Saved', moduleToLink() . "?id=$id");
	}
}
catch (FormAbortException $e)
{
}

$el = $db->fetch1Row($db->select("$table LEFT JOIN Users ON uID=ouID", 'Review.*, uLogin', "$id_field=?d", array($id)));
if (!$el)
	goToURL($out_link);

View::stampArrayToStr($el, 'oTS');
View::setPage('el', $el, 2);
View::showPage();

?>
