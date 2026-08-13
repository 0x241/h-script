<?php

use HScript\Template\View;

$module = 'SMS';
$setup_preserve_empty = array('EP_PrivateKey');
require_once('module/admin/setup.php');

View::showPage();

?>
