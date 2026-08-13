<?php

function captchaGetHTML($form)
{
	$url = moduleToLink('captcha') . "?f=$form";
	return "<img src=\"$url\" border=\"0\" class=\"captcha\" alt=\"Captcha\">";
}

function captchaCheck($form)
{
	$rk = isset($_SESSION['_capt'][$form]) ? $_SESSION['_capt'][$form] : ''; // real (true) code
	unset($_SESSION['_capt'][$form]);
	return ($rk and (_RQ('__Capt') === $rk));
}

?>
