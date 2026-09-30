<?php

use HScript\Template\View;

$_auth = 1;
require_once('module/auth.php');

$_GET['add'] = '';

$table = 'Tickets';
$id_field = 'tID';
$out_link = moduleToLink('tickets');
$ticket_link = moduleToLink('tickets/ticket');

try 
{

	if (View::sendedForm('create'))
	{
		View::checkFormSecurity();
		
		View::setError($id = ticketCreate(_uid(), _IN('tCat'), _IN('tTopic'), _IN('tText')));
		View::showInfo('Completed', $ticket_link . "?id=$id");
	}

	if (View::sendedForm('answer'))
	{
		View::checkFormSecurity();

		$ticket_id = _INN('tID');
		View::setError(ticketAsk(_uid(), $ticket_id, _IN('mText')));
		View::showInfo('Completed', $ticket_link . "?id=$ticket_id");
	}

} 
catch (FormAbortException $e)
{
}

View::showPage('ticket');

?>
