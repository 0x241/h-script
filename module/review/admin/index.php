<?php

use HScript\Template\View;

$_auth = 90;
require_once('module/auth.php');

$table = 'Review';
$id_field = 'oID';
	
try 
{

	if (isset_IN('ids') and (count($ids = (array)_IN('ids')) > 0))
	{
		$ids = $db->fetchRows($db->select($table, $id_field, '?# ?i', array($id_field, $ids)), 1);
		if (count($ids) > 0)
		{
			View::checkFormSecurity();
			
			if (View::sendedForm('accept'))
			{
				$db->update($table, array('oState' => 1), '', '?# ?i', array($id_field, $ids));
			}
			elseif (View::sendedForm('del'))
			{
				$db->delete($table, '?# ?i', array($id_field, $ids));
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

$list = opPageGet(_GETN('page'), 20, "$table LEFT JOIN Users ON uID=ouID", '*', '', null, 
	array(
		$id_field => array(),
		'oTS' => array('oTS desc', 'oTS'),
		'uLogin' => array('uLogin', 'uLogin desc')
	), 
	_GET('sort'), $id_field
);
View::stampTableToStr($list, 'oTS');

View::setPage('list', $list);

View::showPage();

?>