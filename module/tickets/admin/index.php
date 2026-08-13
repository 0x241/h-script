<?php

use HScript\Template\View;

$_auth = 50;
require_once('module/auth.php');

$table = 'Tickets';
$id_field = 'tID';
	
try 
{

	if (isset_IN('ids') and (count($ids = (array)_IN('ids')) > 0))
	{
		$ids = $db->fetchRows($db->select($table, $id_field, '?# ?i', array($id_field, $ids)), 1);
		if (count($ids) > 0)
		{
			View::checkFormSecurity();
			
			if (View::sendedForm('del'))
			{
				$db->delete($table, '?# ?i', array($id_field, $ids));
				$db->delete('TMsg', '?# ?i', array('mtID', $ids));
			}
			
			View::showInfo();
		}
		else
			View::showInfo('*NoSelected');
	}

} 
catch (FormAbortException $e)
{
}

$list = opPageGet(_GETN('page'), 20, 
	"$table LEFT JOIN Users ON uID=tuID", 
	"$table.*, uLogin, (SELECT COUNT(*) FROM TMsg WHERE mtID=tID and muID<>?d) AS cnt, (tState<=2) AS _Marked", '', array(_uid()), 
	array(
		$id_field => array(),
		'tLTS' => array('tLTS desc', 'tLTS'),
		'uLogin' => array('uLogin', 'uLogin desc')
	), 
	_GET('sort'), $id_field
);
View::stampTableToStr($list, 'tTS, tLTS');

View::setPage('list', $list);

View::showPage();

?>