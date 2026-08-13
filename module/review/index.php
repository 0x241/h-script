<?php

use HScript\Util\StringHelper;

use HScript\Template\View;

require_once('module/auth.php');

$table = 'Review';
$id_field = 'oID';
	
try 
{

	if (View::sendedForm())
	{
		View::checkFormSecurity();
		
		if (_uid())
		{
			if (!_IN('Text'))
				View::setError('text_empty');
			$rating = _INN('Rating');
			if (($rating < 1) or ($rating > 5))
				View::setError('rating_wrong');
			if ($id = $db->insert($table, array(
				'oTS' => timeToStamp(),
				'ouID' => _uid(),
				'oText' => _IN('Text'),
				'oRating' => $rating,
				'oState'=> StringHelper::valueIf(!$_cfg['Review_Mode'], 1)
			)))
				View::showInfo('Added', moduleToLink() . StringHelper::valueIf($_cfg['Review_Mode'], '?awating'));
		}
		View::showInfo('*Error');
	}

} 
catch (FormAbortException $e)
{
}

$n = $_cfg['Review_ShowCount'];
if (!$n)
	$n = 10;
$list = opPageGet(_GETN('page'), $n, "$table LEFT JOIN Users ON uID=ouID LEFT JOIN AddInfo ON auID=ouID", 
	'*', 'oState=1', array(),
	array(
		'nTS' => array('oOrder desc, oTS desc, oID desc')
	),
	_GET('sort'), $id_field
);
View::stampTableToStr($list, 'oTS', 0);

$total = $db->count('Review','oState = 1 ', '');

View::setPage('list', $list);
View::setPage('total', $total);

View::showPage();

?>
