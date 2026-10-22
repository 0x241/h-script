<?php

use HScript\Util\StringHelper;

use HScript\Template\View;

$_auth = 90;
require_once('module/auth.php');

$table = 'Users';
$id_field = 'uID';
$fform = 'users_filter';
	
try 
{

	if (View::sendedForm('', $fform))
	{
		View::checkFormSecurity($fform);
		
		foreach (array('uGroup', 'uLogin', 'aName', 'uMail', 'uState', 'RefLogin') as $f)
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
		View::checkFormSecurity();
		$requested_ids = array_values(array_unique(array_map('intval', $ids)));
		$ids = $db->fetchRows($db->select($table, $id_field, '?# ?i', array($id_field, $requested_ids)), 1);
		if (count($ids) > 0)
		{
			if ((int)$_user['uLevel'] < 99 && $db->count($table, '?# ?i and uLevel>=?d', array($id_field, $ids, (int)$_user['uLevel'])) > 0)
			{
				http_response_code(403);
				View::setError('access_denied');
			}

			if (View::sendedForm('setgroup'))
			{
				if ($_GS['demo'] && (int)$_user['uLevel'] < 99)
				{
					http_response_code(403);
					View::setError('access_denied');
				}
				$filter = '?# ?i';
				$params = array($id_field, $ids);
				if ((int)$_user['uLevel'] < 99)
				{
					$filter .= ' and uLevel<?d';
					$params[] = (int)$_user['uLevel'];
				}
				$db->update('Users', array('uGroup' => _IN('Group')), '', $filter, $params);
			}
			
			if (View::sendedForm('del'))
			{
//				$db->delete('AddInfo', '?# ?i', array('auID', $ids));
//				$db->delete($table, '?# ?i', array($id_field, $ids));
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

$flt = '1';
$fp = array();
if (isset($_SESSION[$fform]))
	foreach (array('uGroup' => 'Users.uGroup', 'uLogin' => 'Users.uLogin', 'aName' => 'aName', 'uMail' => 'Users.uMail', 'uState' => 'Users.uState', 'RefLogin' => 'U.uLogin') as $f => $b)
		if (($v = $_SESSION[$fform][$f]) != StringHelper::valueIf($f == 'uState', '9'))
		{
			if ($f == 'uLogin')
			{
				$flt .= ' and ((Users.uLogin=?) or (Users.uMail=?))';
				$fp[] = $v;
			}
			else
				$flt .= ' and (' . $b . StringHelper::valueIf(in_array($f, array('uLogin', 'aName', 'uMail')), ' ?%)' ,'=?)');
			$fp[] = $v;
		}

$list = opPageGet(_GETN('page'), 20, "$table LEFT JOIN AddInfo ON auID=uID LEFT JOIN Users U ON U.uID=Users.uRef", 
	'Users.*, AddInfo.aName, U.uLogin as RefLogin', 
	$flt, $fp, 
	array(
		$id_field => array(),
		'uGroup' => array(),
		'uLogin' => array(),
		'aName' => array(),
		'uMail' => array(),
		'uState' => array(),
		'uLevel' => array(),
		'RefLogin' => array(),
		'BalUSD' => array(),
		'BalEUR' => array(),
		'BalRUB' => array(),
		'BalBTC' => array(),
		'BalETH' => array(),
		'BalXRP' => array()
	), 
	_GET('sort'), $id_field
);
View::stampTableToStr($list, 'nBTS, nLTS');

$wlist = $db->fetchRows($db->select('Wallets', 'wuID, wcID, wBal', 'wuID ?i and wBal>0', array(array_keys($list))));
foreach ($wlist as $w) {
	$currKey = 'Bal' . HScript\Finance\WalletPortfolio::code($_currs[$w['wcID']], $w['wcID']);
	if (!isset($list[$w['wuID']][$currKey])) {
		$list[$w['wuID']][$currKey] = 0;
	}
	$list[$w['wuID']][$currKey] += $w['wBal'];
}

View::setPage('list', $list);

$vcurrs = array(
	'USD' => array(),
	'EUR' => array(),
	'RUB' => array(),
	'BTC' => array(),
	'ETH' => array(),
	'XRP' => array()
);
foreach ($_currs as $cid => $c)
	$vcurrs[HScript\Finance\WalletPortfolio::code($c, $cid)][] = $cid;
View::setPage('vcurrs', $vcurrs);

View::showPage();

?>
