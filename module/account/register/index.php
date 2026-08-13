<?php

use HScript\Template\View;

require_once('module/auth.php');

try 
{

	if (View::sendedForm('', 'register_frm'))
	{
		View::checkFormSecurity('register_frm');

		View::setError($nuid = opRegisterPrepare($_IN), 'register_frm');
		if ($nuid > 1)
			if ($_cfg['Account_RegConfirm'])
			{
				useLib('confirm');
				if ($_cfg['SMS_REG'])
				{
					$tel = _IN('aTel');
					if (opConfirmPrepareSMS($nuid, 'REG', array('tel' => $tel), '', $tel))
						View::showInfo('Saved', moduleToLink('account/register') . '?need_confirm_sms');
				}
				$mail = _IN('uMail');
				if (opConfirmPrepare($nuid, 'REG', array('mail' => $mail), '', $mail))
					View::showInfo('Saved', moduleToLink('account/register') . '?need_confirm');
			} 
			elseif (opRegisterComplete($nuid))
				View::showInfo('Completed', moduleToLink('account/register') . '?done');
		View::showInfo('*Error');
	}

}
catch (FormAbortException $e)
{
}

View::setPage('valid_ref', _SESSION('_ref'));

// External authentication providers may resume registration from this URL.
$_SESSION['_go_after_login'] = '';

View::showPage();

?>
