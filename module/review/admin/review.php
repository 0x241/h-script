<?php

use HScript\Template\View;

$_auth = 90;
require_once('module/auth.php');

$table = 'Review';
$id_field = 'oID';
$id = _GETN('id');
$is_new = isset($_GET['add']);
$out_link = moduleToLink('review/admin');

if (!$id && !$is_new)
	goToURL($out_link);

try
{
	if ($id && View::sendedForm('del'))
	{
		View::checkFormSecurity();
		$db->delete($table, "$id_field=?d", array($id));
		View::showInfo('Deleted', $out_link);
	}

	if (View::sendedForm())
	{
		View::checkFormSecurity();

		$a = $_IN;
		$authorLogin = trim((string)_IN('uLogin'));
		if ($authorLogin === '')
			View::setError('author_empty');
		if (!preg_match('/^[\p{L}\p{N}_.-]{1,40}$/uD', $authorLogin))
			View::setError('author_wrong');
		$authorId = (int)$db->fetch1($db->select('Users', 'uID', 'uLogin=?', array($authorLogin)));
		$a['ouID'] = $authorId;
		$a['oAuthor'] = $authorLogin;
		$dateInput = trim((string)_IN('oTS'));
		$isNumericDate = preg_match('/^(\d{2})\.(\d{2})\.(\d{4})\s(\d{2}):(\d{2})$/D', $dateInput, $dateParts);
		if (!preg_match('/^[\p{L}\p{N}.,:\s-]{6,60}$/uD', $dateInput)
			|| ($isNumericDate && (!checkdate((int)$dateParts[2], (int)$dateParts[1], (int)$dateParts[3])
				|| (int)$dateParts[4] > 23
				|| (int)$dateParts[5] > 59)))
			View::setError('date_empty');
		$a['oTS'] = $dateInput;
		View::strArrayToStamp($a, 'oTS', 0);
		if (empty($a['oTS']))
			View::setError('date_empty');
		$a['oText'] = trim((string)_IN('oText'));
		if (!$a['oText'])
			View::setError('text_empty');
		$a['oRating'] = _INN('oRating');
		if (($a['oRating'] < 1) or ($a['oRating'] > 5))
			View::setError('rating_wrong');
		$a['oState'] = isset_IN('oState') ? 1 : 0;
		$a['oOrder'] = intval($a['oOrder']);

		if ($savedId = $db->save(
			$table,
			$a,
			'ouID, oAuthor, oTS, oText, oRating, oState, oOrder',
			$id_field
		))
			View::showInfo('Saved', moduleToLink() . "?id=$savedId");
		View::showInfo('*Error');
	}
}
catch (FormAbortException $e)
{
}

if (!$is_new)
{
	$el = $db->fetch1Row($db->select(
		"$table LEFT JOIN Users ON uID=ouID",
		"Review.*, COALESCE(NULLIF(Review.oAuthor, ''), Users.uLogin, '') AS uLogin",
		"$id_field=?d",
		array($id)
	));
	if (!$el)
		goToURL($out_link);

	View::stampArrayToStr($el, 'oTS', 0);
	View::setPage('el', $el, 2);
}
else
	View::setPage('today', View::timeToStr(time(), 0));

View::showPage();

?>
