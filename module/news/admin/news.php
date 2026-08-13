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
	View::setPage('el', $el, 2);
}
else
	View::setPage('today', View::timeToStr(time(), 0));

View::showPage();

?>
