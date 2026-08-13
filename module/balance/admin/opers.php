<?php

use HScript\Util\StringHelper;

use HScript\Template\View;

$_auth = 70;
require_once('module/auth.php');

$table = 'Opers';
$id_field = 'oID';
$fform = 'opers_filter';
	
try 
{

	if (View::sendedForm('', $fform))
	{
		View::checkFormSecurity($fform);
		
		foreach (array('uLogin', 'oOper', 'ocID', 'oBatch', 'oState', 'oMemo') as $f)
			$_SESSION[$fform][$f] = _IN($f);
		opPageReset();
		goToURL();
	}

	if (View::sendedForm('clear', $fform))
	{
		View::checkFormSecurity($fform);
		
		unset($_SESSION[$fform]);
		opPageReset();
		goToURL();
	}

	if (isset_IN('ids') and (count($ids = (array)_IN('ids')) > 0))
	{
		$ids = $db->fetchRows($db->select($table, $id_field, '?# ?i', array($id_field, $ids)), 1);
		if (count($ids) > 0)
		{
			View::checkFormSecurity();
			
			if (View::sendedForm('complete'))
			{
				foreach ($ids as $id)
					opOperComplete(0, $id, array(), true);
			}
			
			if (View::sendedForm('confirm'))
			{
				foreach ($ids as $id)
				if ($o = $db->fetch1Row($db->select('Opers', '*', '(oID=?d) and (oState<3) and (oOper=? or oOper=?)', array($id, 'CASHIN', 'CASHOUT'))))
				{
					$p = strToArray($o['oParams2']);
					if (!$p['date'])
						$p['date'] = timeToStamp();
					if (!$p['batch'])
						$p['batch'] = 'M' . str_pad($id, 6, '0', STR_PAD_LEFT);
					$db->update('Opers', array('oParams2' => arrayToStr($p)), '', 'oID=?d', array($id));
					  if ($o['oState'] < 2)
						opOperConfirm($o['ouID'], $id, array(), true);
						opOperComplete($o['ouID'], $id, array(), true);
				}
			}
			
			if (View::sendedForm('cancel'))
			{
				foreach ($ids as $id)
					opOperCancel(0, $id, array(), true);
			}
			
			if (View::sendedForm('del'))
			{
				$db->delete($table, '(oState >= 4) and (?# ?i)', array($id_field, $ids));
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

if ($user = _GET('user'))
{
	$flt = 'uLogin=?';
	$fp = array($user);
	View::setPage('linkparams', "&user=$user");
}
else
{
	$flt = '1';
	$fp = array();
	if (isset($_SESSION[$fform]))
		foreach (array('uLogin' => '', 'oOper' => '0', 'ocID' => '0', 'oBatch' => '', 'oState' => '9', 'oMemo' => '') as $f => $v0)
			if (($v = $_SESSION[$fform][$f]) != $v0)
			{
				if ($f == 'uLogin')
				{
					$flt .= ' and ((Users.uLogin=?) or (Users.uMail=?))';
					$fp[] = $v;
				}
				else
					$flt .= ' and (' . $f . StringHelper::valueIf($f == 'oMemo', ' ?%)' ,'=?)');
				$fp[] = $v;
			}
}

$list = opPageGet(_GETN('page'), 20,
	"$table LEFT JOIN Users on uID=ouID LEFT JOIN Currs on cID=ocID",
	"$table.*, uLogin, cName, cCurr, (oState=2) AS _Marked", 
	$flt, $fp,
	array(
		$id_field => array(),
		'uLogin' => array('uLogin', 'uLogin desc'),
		'oTS' => array('oCTS desc', 'oCTS', 'oTS desc', 'oTS')
	), 
	_GET('sort'), $id_field
);
View::stampTableToStr($list, 'oCTS, oTS');
foreach ($list as $id => $r)
{
	$list[$id]['oParams'] = strToArray($r['oParams']);
	$list[$id]['oParams2'] = strToArray($r['oParams2']);
}

View::setPage('list', $list);

$currs = array();
foreach ($_currs as $id => $c)
	$currs[$id] = $c['cName'];
View::setPage('currs', $currs);

global $_page;
if (empty($_page['up_category'])) {
	$_page['up_category'] = 'Баланс';
	$_page['up_modules'] = array(
		'balance/admin/opers' => 'Операции',
		'balance/admin/currs' => 'Платежные системы',
		'balance/admin/setup' => 'Настройки'
	);
}

View::showPage();

?>
