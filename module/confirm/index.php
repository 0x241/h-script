<?php

use HScript\Util\StringHelper;

use HScript\Template\View;

require_once('module/auth.php');

$uid = StringHelper::exValue(_SESSION('nuid'), _uid());

try 
{

	if ($uid and isset($_GET['resend']))
		opConfirmResend($uid);

	if (View::sendedForm())
	{
		View::checkFormSecurity();

		$code = _IN('Code');
		if (StringHelper::sEmpty($code) && is_array($parts = _IN('CodePart')))
			$code = implode('', $parts);
		if (StringHelper::sEmpty($code))
			View::setError('code_empty');
		$memo = opConfirmCodeMemo($code);
		$op = $db->fetch1Row($db->select('Hist', '*', 'hOper=? and hMemo=?', array('CONFIRM', $memo)));
		if (!$op && $memo !== $code)
			$op = $db->fetch1Row($db->select('Hist', '*', 'hOper=? and hMemo=?', array('CONFIRM', $code)));
		$uid = $op['huID'];
		if (!$uid)
			View::setError('code_not_found');
		if ($op['hTag'])
			View::setError('code_used');
		if ($_cfg['Confirm_Expire'] > 0)
			if (subStamps($op['hTS']) > $_cfg['Confirm_Expire'] * HS2_UNIX_MINUTE)
				View::setError('code_expired');
		if ($_cfg['Confirm_DifIP'])
			if ($_GS['client_ip'] != $op['hIP'])
				View::setError('dif_ip');
		$a = strToArray($op['hParams']);
		if (!$a['oper'])
			View::setError('oper_wrong');
		$db->update('Hist', array('hTag' => 1), '', 'hID=?d', array($op['hID'])); // mark as 'used'
		
		View::setError($e = opConfirmTry($uid, $a));
		if ($e)
		{
			unset($_SESSION['_confirm_code_mode']);
			View::showInfo('Completed', moduleToLink() . '?done');
		}
		View::showInfo('*Error');
	}

}
catch (FormAbortException $e)
{
}

View::showPage();

?>
