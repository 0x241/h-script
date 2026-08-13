<?php

use HScript\Template\View;

$_auth = 90;
require_once('module/auth.php');

$today_stamp = timeToStamp(strtotime('today'));

$admin_stats = array(
	'users_total' => (int)$db->fetch1($db->select('Users', 'COUNT(*)')),
	'users_today' => (int)$db->fetch1($db->select('Users LEFT JOIN AddInfo ON auID=uID', 'COUNT(*)', 'aCTS>=?', array($today_stamp))),
	'active_deposits' => (int)$db->fetch1($db->select('Deps', 'COUNT(*)', 'dState=1')),
	'active_deposits_today' => (int)$db->fetch1($db->select('Deps', 'COUNT(*)', 'dState=1 and dCTS>=?', array($today_stamp))),
	'active_deposits_sum' => (float)$db->fetch1($db->select('Deps', 'COALESCE(SUM(dZD),0)', 'dState=1')),
	'cashin_count' => (int)$db->fetch1($db->select('Opers', 'COUNT(*)', "oOper='CASHIN'")),
	'cashout_count' => (int)$db->fetch1($db->select('Opers', 'COUNT(*)', "oOper='CASHOUT'")),
	'inout_today' => (int)$db->fetch1($db->select('Opers', 'COUNT(*)', "(oOper='CASHIN' or oOper='CASHOUT') and oCTS>=?", array($today_stamp))),
	'tickets_total' => (int)$db->fetch1($db->select('Tickets', 'COUNT(*)')),
	'tickets_open' => (int)$db->fetch1($db->select('Tickets', 'COUNT(*)', 'tState<8'))
);
View::setPage('admin_stats', $admin_stats);

$admin_recent_opers = $db->fetchRows($db->select(
	'Opers LEFT JOIN Users ON uID=ouID LEFT JOIN Currs ON cID=ocID',
	'Opers.*, Users.uLogin, Currs.cName, Currs.cCurr, Currs.cCurrID',
	'',
	array(),
	'oCTS desc',
	6
));
View::stampTableToStr($admin_recent_opers, 'oCTS, oTS, oNTS');
View::setPage('admin_recent_opers', $admin_recent_opers);

View::showPage();

?>
