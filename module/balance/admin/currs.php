<?php

use HScript\Template\View;

$_auth = 90;
require_once('module/auth.php');

$table = 'Currs';
$id_field = 'cID';

try 
{

	if (isset_IN('ids') and (count($ids = (array)_IN('ids')) > 0))
	{
		View::showInfo('*CantComplete');
/*		$ids = $db->fetchRows($db->select($table, $id_field, '?# ?i', array($id_field, $ids)), 1);
		if (count($ids) > 0)
		{
			View::checkFormSecurity();
			
			if (View::sendedForm('del'))
			{
				// ??? chk Internal
//				$db->delete($table, '?# ?i', array($id_field, $ids));
			}
			
			View::showInfo();
		}
		else
			View::showInfo('*NoSelected');*/
	}

} 
catch (FormAbortException $e)
{
}

useLib('balance');

$list = opPageGet(_GETN('page'), 20, $table, '*', '', null, 
	array(
		$id_field => array($id_field)
	),
	_GET('sort'), $id_field
);
foreach ($list as $id => $r)
	opDecodeCurrParams($r, $p, $p, $list[$id]['PAPI']);
View::setPage('list', $list);

View::showPage();

?>