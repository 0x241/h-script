<?php

use HScript\Template\View;
use HScript\Content\HtmlSanitizer;

$_auth = 90;
require_once('module/auth.php');

$table = 'FAQ';
$id_field = 'fID';
$out_link = moduleToLink('faq/admin/faqs');

try 
{

	if (View::sendedForm())
	{
		View::checkFormSecurity();
		
		$a = $_IN;
		$a['fCTS'] = timeToStamp();
		if (!$a['fQuestion'])
			View::setError('question_empty');
		if (!$a['fAnswer'])
			View::setError('answer_empty');
		$a['fAnswer'] = HtmlSanitizer::sanitize((string)$a['fAnswer']);
		if ($id = $db->save($table, $a, 
			'fHidden, fCTS, fCat, fOrder, fQuestion, fAnswer', $id_field))
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
	View::stampArrayToStr($el, 'fCTS', 0);
	View::setPage('el', $el, 2);
}

$cats = array();
foreach ((array)$_cfg['FAQ__Cats'] as $c)
	$cats[$c] = $c;
View::setPage('cats', $cats);

View::showPage();

?>
