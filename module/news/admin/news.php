<?php

use HScript\Template\View;
use HScript\Content\HtmlSanitizer;

$_auth = 90;
require_once('module/auth.php');

$table = 'News';
$id_field = 'nID';
$out_link = moduleToLink('news/admin/newses');

try 
{

	if (View::sendedForm())
	{
		View::checkFormSecurity();
		
		$a = $_IN;
		if (!isset($a['nAttn']) || $a['nAttn'] === '')
			$a['nAttn'] = 0;
		$dateInput = trim((string)_IN('nTS'));
		$isNumericDate = preg_match('/^(\d{2})\.(\d{2})\.(\d{4})\s(\d{2}):(\d{2})$/D', $dateInput, $dateParts);
		if (!preg_match('/^[\p{L}\p{N}.,:\s-]{6,60}$/uD', $dateInput)
			|| ($isNumericDate && (!checkdate((int)$dateParts[2], (int)$dateParts[1], (int)$dateParts[3])
				|| (int)$dateParts[4] > 23
				|| (int)$dateParts[5] > 59)))
			View::setError('date_empty');
		$a['nTS'] = $dateInput;
		View::strArrayToStamp($a, 'nTS', 0);
		View::strArrayToStamp($a, 'nDBegin', 1);
		View::strArrayToStamp($a, 'nDEnd', 2);
		if (!$a['nTS'])
			View::setError('date_empty');
		if (!$a['nTopic'])
			View::setError('topic_empty');
		if (!$a['nAnnounce'])
			View::setError('ann_empty');
		if (!$a['nText'])
			View::setError('text_empty');
		$a['nText'] = HtmlSanitizer::sanitize((string)$a['nText']);
		if ($id = $db->save($table, $a, 
			'nDBegin, nDEnd, nTS, nTopic, nAttn, nAnnounce, nText', $id_field))
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
	View::stampArrayToStr($el, 'nTS', 0);
	View::stampArrayToStr($el, 'nDBegin, nDEnd', 1);
	$el['nText'] = HtmlSanitizer::sanitize((string)($el['nText'] ?? ''));
	// Twig escapes form values for their output context. Pre-escaping here would
	// turn stored rich text into literal tags when CKEditor reads the textarea.
	View::setPage('el', $el, 0);
}
else
	View::setPage('today', View::timeToStr(time(), 0));

View::showPage();

?>
