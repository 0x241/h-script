<?php

use HScript\Template\View;

$_auth = 90;
require_once('module/auth.php');

$table = 'Deps';
$id_field = 'dID';
$fform = 'deps_filter';
	
try 
{

	if (View::sendedForm('', $fform))
	{
		View::checkFormSecurity($fform);
		
		foreach (array('uLogin', 'dcID', 'dpID', 'dState') as $f)
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

}
catch (FormAbortException $e)
{
}

if ($user = _GET('user'))
{
	$flt = 'uLogin=?';
	$fp = array($user);
}
else
{
	$flt = '1';
	$fp = array();
	if (isset($_SESSION[$fform]))
		foreach (array('uLogin' => '', 'dcID' => '0', 'dpID' => '0', 'dState' => '9') as $f => $v0)
			if (($v = $_SESSION[$fform][$f]) != $v0)
			{
				if ($f == 'uLogin')
				{
					$flt .= ' and ((Users.uLogin=?) or (Users.uMail=?))';
					$fp[] = $v;
				}
				else
					$flt .= " and ($f=?)";
				$fp[] = $v;
			}
}

$list = opPageGet(_GETN('page'), 20, 
	"$table LEFT JOIN Users ON uID=duID LEFT JOIN Currs ON cID=dcID LEFT JOIN Plans ON pID=dpID", '*', 
	$flt, $fp,
	array(
		$id_field => array(),
		'uLogin' => array('uLogin', 'uLogin desc'),
		'pName' => array('pName', 'pName desc'),
		'dLTS' => array('dLTS desc', 'dLTS'),
		'dNTS' => array('dNTS desc', 'dNTS')
	), 
	_GET('sort'), $id_field
);
View::stampTableToStr($list, 'dCTS, dLTS, dNTS');

View::setPage('list', $list);

$currs = array();
foreach ($_currs as $id => $c)
	$currs[$id] = $c['cName'];
View::setPage('currs', $currs);

View::setPage('plans', $db->fetchIDRows($db->select('Plans', 'pID, pName'), 2, 'dpID'));

View::showPage();

?>
