<?php

use HScript\Util\StringHelper;

use HScript\Template\View;

$_auth = 90;
require_once('module/auth.php');

$table = 'Users';
$id_field = 'uID';
$out_link = moduleToLink('account/admin/users');

try 
{

	if (View::sendedForm())
	{
		View::checkFormSecurity();
		
		useLib('account/register');
		$a = $_IN;
		if (!($id = $a[$id_field]))
		{
			View::setError($id = opRegisterPrepare($a, true));
			View::showInfo('Added', moduleToLink() . "?id=$id");
		}
		
		if ($_GS['demo'] and ($_user['uLevel'] < 99))
		{
			if ($id <= 3)
				View::showInfo('*Denied');
			$a['uLevel'] = 1;
		}

		View::setError(opRegisterUserCheck($a, $id, true));
		$a['uMode'] = View::normalizeTemplateMode($a['uMode'] ?? '');
		if (!empty($_IN['uMode']) && !View::templateModeExists($a['uMode']))
			View::setError('mode_wrong');
		View::strArrayToStamp($a, 'uBTS');
		if ($id = $db->save($table, $a, 
			'uGroup, uLogin, uMail, uState, uBTS, uLevel, uLang, uMode, uTheme, uRef, uBFC, uWDDisable' .
			StringHelper::valueIf($a['uPass'], ', uPass, uPTS') . StringHelper::valueIf($a['uPIN'], ', uPIN'), $id_field)
		)
			View::showInfo('Saved', $out_link . "?id=$id");
		View::showInfo('*Error');
	}

} 
catch (FormAbortException $e)
{
}

if (!isset($_GET['add']))
{
	if (_GETN('id'))
		$el = $db->fetch1Row($db->select("$table LEFT JOIN AddInfo ON auID=uID LEFT JOIN Users U ON U.uID=Users.uRef", 
		"$table.*, AddInfo.*, U.uLogin as uRef", "$table.$id_field=?d", array(_GETN('id'))));
	if (!$el)
		goToURL(moduleToLink() . '?add');
	if (isset($_GET['login']) and (!$_GS['demo'] or ($_user['uLevel'] == 99)))
	{
		useLib('account/login');
		opLogin($el['uID'], '', false, true);
	}
	View::stampArrayToStr($el, 'uBTS, uLTS');
	View::setPage('el', $el, 2);
	$langs = array();
	$lang_str = is_array($_cfg['UI__Langs']) ? implode("\n", $_cfg['UI__Langs']) : $_cfg['UI__Langs'];
	$clean_langs = preg_split('/[\r\n]+/', $lang_str, -1, PREG_SPLIT_NO_EMPTY);
	foreach ($clean_langs as $l) {
		$l = trim($l);
		if ($l !== '') {
			$langs[$l] = strtoupper($l);
		}
	}
	View::setPage('langs', $langs);
	View::setPage('currs', $db->fetchIDRows($db->select('Currs LEFT JOIN Wallets ON wcID=cID and wuID=?d',
		'*', '', array($el['uID']), 'cID'), false, 'cID'));
//	$ips = $db->fetchRows($db->select('Hist', 'hIP, hTS', "hOper='LOGIN' and huID=?d", array($el['uID']), 'hTS desc', '10'));
//	View::stampTableToStr($ips, 'hTS');
//	View::setPage('ips', $ips);
}

View::showPage();

?>
