<?php

use HScript\Template\View;

$_auth = 90;
require_once('module/auth.php');

$table = 'Deps';
$id_field = 'dID';
$out_link = moduleToLink('depo/admin/depos');
try 
{

	if (View::sendedForm('', 'new'))
	{
		View::checkFormSecurity('new');
		
		$uid = $db->fetch1($db->select('Users', 'uID', 'uLogin=? and (uState=1 or uState=2)', array(_IN('Login'))));
		if (!$uid)
			View::setError('user_empty', 'new');
		if (!isset_IN('PSys'))
			goToURL(moduleToLink() . '?add&user=' . _IN('Login'));
		cn($_IN['Sum']);		
		View::setError($id = opDepoCreate($uid, _INN('PSys'), _IN('Sum'), _IN('Compnd'), _INN('Plan'), true), 'new');
		View::showInfo('Completed', moduleToLink() . "?id=$id");
	}

	if (View::sendedForm('chg'))
	{
		View::checkFormSecurity();
		
		View::setError(opDepoChangeCompnd(_INN('duID'), $id = _INN($id_field), _IN('Compnd')));
		View::showInfo('Completed', moduleToLink() . "?id=$id");
	}
	
	if (View::sendedForm('add'))
	{
		View::checkFormSecurity();
		
		cn($_IN['Sum']);		
		View::setError(opDepoAdd(_INN('duID'), _INN('dcID'), _IN('Sum'), $id = _INN($id_field)));
		View::showInfo('Completed', moduleToLink() . "?id=$id");
	}
	if (View::sendedForm('sub'))
	{
			View::checkFormSecurity();
		
		cn($_IN['Sum']);		
		View::setError(opDepoSub(_INN('duID'), _INN('dcID'), _IN('Sum'), $id = _INN($id_field)));
		View::showInfo('Completed', moduleToLink() . "?id=$id");
	}
	if (View::sendedForm('chp'))
	{
		View::checkFormSecurity();
		
		View::setError(opDepoChangePlan(_INN('duID'), _IN('dpID'), $id = _INN($id_field)));
		View::showInfo('Completed', moduleToLink() . "?id=$id");
	}
	if (View::sendedForm('cls'))
	{
		View::checkFormSecurity();
		
		View::setError(opDepoClose(_INN('duID'), _INN('dcID'), $id = _INN($id_field)));
		View::showInfo('Completed', moduleToLink() . "?id=$id");
	}

} 
catch (FormAbortException $e)
{
}

if (!isset($_GET['add']))
{
	if (_GETN('id'))
		$el = $db->fetch1Row($db->select("$table LEFT JOIN Users ON uID=duID LEFT JOIN Currs ON cID=dcID LEFT JOIN Plans ON pID=dpID", 
			'*', "$id_field=?d", array(_GETN('id'))));
	if (!$el)
		goToURL(moduleToLink() . '?add');
	View::stampArrayToStr($el, 'dCTS, dLTS, dNTS', 0);
	View::setPage('el', $el, 2);
	$currs = $db->fetchIDRows($db->select('Currs LEFT JOIN Wallets ON wcID=cID and wuID=?d', 
		'*', 'cDisabled=0', array($el['duID']), 'cID'), false, 'cID');
	View::setPage('currs', $currs);
	$plans = opDepoGetPlanList($uid, true);
	$pl = array();
	foreach ($plans as $pid => $p)
		if (!$p['Disabled'])
			$pl[$pid] = $p['pName'];
	View::setPage('plans', $pl);
}
elseif (_GET('user'))
{
	$uid = $db->fetch1($db->select('Users', 'uID', 'uLogin=? and (uState=1 or uState=2)', array(_GET('user'))));
	if (!$uid)
		goToURL(moduleToLink() . '?add');
	$currs = $db->fetchIDRows($db->select('Currs LEFT JOIN Wallets ON wcID=cID and wuID=?d', 
		'*', 'cDisabled=0', array($uid), 'cID'), false, 'cID');
	View::setPage('currs', $currs);
	$list = array();
	foreach ($currs as $id => $r)
		if ($r['wBal'] > 0)
			$list[$id] = $r['cName'];
	if (!$list)
		View::showInfo('*CantComplete', moduleToLink() . '?add');
	View::setPage('clist', $list);
	$plans = opDepoGetPlanList($uid, true);
	$pl = array();
	$cmax = 0;
	foreach ($plans as $pid => $p)
		if (!$p['Disabled'])
		{
			$pl[$pid] = $p['pName'];
			if ($p['pCompndMax'] > $cmax)
				$cmax = $p['pCompndMax'];
		}
	View::setPage('plans', $pl);
	View::setPage('cmax', $cmax);
}

View::showPage();

?>