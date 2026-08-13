<?php

use HScript\Template\View;$_auth = 1;require_once('module/auth.php');View::setPage('list', $db->fetchIDRows($db->select('Opers LEFT JOIN Users ON uID=ouID',	'oID, uLogin, ocID, oSum, oBatch, uMail', 'oOper=? and oState=3', array('CASHOUT'), 'oID desc', 10), false, 'oID'));View::showPage();?>