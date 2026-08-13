<?php

use HScript\Template\View;

$module = 'Sec';
View::setPage('via_https', $_GS['https']);
View::setPage('curr_ip', $_GS['client_ip']);
require_once('module/admin/setup.php');

View::showPage();

?>