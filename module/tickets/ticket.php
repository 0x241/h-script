<?php

use HScript\Template\View;

$_auth = 1;
require_once('module/auth.php');

$table = 'Tickets';
$id_field = 'tID';
$out_link = moduleToLink('tickets');
$ticket_link = moduleToLink('tickets/ticket');
$is_hx = isset($_SERVER['HTTP_HX_REQUEST']) && ($_SERVER['HTTP_HX_REQUEST'] == 'true');

try 
{

	if (View::sendedForm('create'))
	{
		View::checkFormSecurity();

		View::setError($id = ticketCreate(_uid(), _IN('tCat'), _IN('tTopic'), _IN('tText')));
		if ($is_hx)
			$_GET['id'] = $id;
		else
			View::showInfo('Completed', $ticket_link . "?id=$id");
	}

	if (View::sendedForm('answer'))
	{
		View::checkFormSecurity();

		$ticket_id = _INN('tID');
		View::setError(ticketAsk(_uid(), $ticket_id, _IN('mText')));
		if ($is_hx)
		{
			unset($_IN['mText']);
			$_GET['id'] = $ticket_id;
		}
		else
			View::showInfo('Completed', $ticket_link . "?id=$ticket_id");
	}

	if (View::sendedForm('close', 'ticket'))
	{
		View::checkFormSecurity('ticket');

		View::setError(ticketClose(_uid(), $id = _INN('tID')), 'ticket');
		if ($is_hx)
			$_GET['id'] = $id;
		else
			View::showInfo('Completed', $ticket_link . "?id=$id");
	}

} 
catch (FormAbortException $e)
{
}

if (!isset($_GET['add']))
{
	if ($id = _GETN('id'))
		$el = $db->fetch1Row($db->select("$table LEFT JOIN Users ON uID=tuID LEFT JOIN AddInfo ON auID=uID",
			"$table.*, Users.uID, Users.uLogin, Users.uMail, AddInfo.aName, AddInfo.aAvatar", "$id_field=?d and tuID=?d", array($id, _uid())));
	if (!$el)
		goToURL(moduleToLink() . '?add');
	View::stampArrayToStr($el, 'tTS, tLTS', 0);
	View::setPage('el', $el, 2);
	
	$list = $db->fetchRows($db->select('TMsg LEFT JOIN Users ON uID=muID LEFT JOIN AddInfo ON auID=uID',
		'TMsg.*, Users.uID, Users.uLogin, Users.uMail, AddInfo.aName, AddInfo.aAvatar', 'mtID=?d', array($id)));
	View::stampTableToStr($list, 'mTS, mRTS');
	View::setPage('list', $list, 2);
	
	$db->update('TMsg', array('mRTS' => timeToStamp()), '', 'mtID=?d and (mRTS IS NULL)', array($id));
	updateUserCounters();
}
	
View::showPage();

?>
