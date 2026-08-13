<?php

use HScript\Template\View;

$_auth = 90;
require_once('module/auth.php');

try
{
	if (isset_IN('ids') and (count($ids = (array)_IN('ids')) > 0))
	{
		View::checkFormSecurity();
		$changed = false;
		if (View::sendedForm('del'))
		{
			foreach ($ids as $message_id)
				$changed = messageDeleteConversationForAdmin(intval($message_id)) or $changed;
		}
		View::showInfo($changed ? 'Completed' : '*NoSelected');
	}

} 
catch (FormAbortException $e)
{
}

$list = messageAdminConversationSummaries(_uid(), _GETN('page'), 20);
View::stampTableToStr($list, 'mTS');

View::setPage('list', $list);

View::showPage();

?>
