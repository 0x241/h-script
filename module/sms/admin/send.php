<?php

use HScript\Template\View;

$_auth = 90;
require_once('module/auth.php');

try 
{

	if (View::sendedForm('send'))
	{
		View::checkFormSecurity();
		
		View::setError($id = smsPush(0, _IN('To'), _IN('Text'), _IN('From'), _IN('Translit'), 2));
		View::showInfo('Saved', moduletoLink());
	}

} 
catch (FormAbortException $e)
{
}

View::showPage();

?>