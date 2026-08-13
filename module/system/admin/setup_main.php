<?php

use HScript\Template\View;
use HScript\Application;

$module = 'Sys';
require_once('module/admin/setup.php');




$ip = '';
$ipDisplay = '***.***.***.***';
if (empty($_GS['demo']))
{
	try
	{
		$curlHandle = curl_init();
		curl_setopt($curlHandle, CURLOPT_URL, 'https://api.ipify.org');
		curl_setopt($curlHandle, CURLOPT_CONNECTTIMEOUT, 2);
		curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($curlHandle, CURLOPT_USERAGENT, !empty($_cfg['HTTP_UserAgent']) ? $_cfg['HTTP_UserAgent'] : Application::userAgent());
		$ip = (string)curl_exec($curlHandle);
		curl_close($curlHandle);
	}
	catch (Throwable $e)
	{
		xAddToLog($e->getMessage(), 'system');
	}
	$ipDisplay = $ip;
}

View::setPage('ip', $ip);
View::setPage('ip_display', $ipDisplay);
$languages = View::translationLanguages();
View::setPage('available_languages', array_combine(
	$languages,
	array_map('strtoupper', $languages)
), 0);
View::showPage();

?>
