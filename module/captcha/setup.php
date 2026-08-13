<?php

use HScript\Template\View;

$module = 'Captcha';
$setup_preserve_empty = array('Turnstile_SiteKey', 'Turnstile_SecretKey');
require_once('module/admin/setup.php');

View::showPage();

?>
