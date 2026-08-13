<?php

use HScript\Http\Client;

function captchaGetHTML($form)
{
	global $_cfg;
	$sitekey = !empty($_cfg['Turnstile_SiteKey']) ? $_cfg['Turnstile_SiteKey'] : '';
	if ($sitekey === '')
		return '';
	
	$html = '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
	$html .= '<div class="cf-turnstile" data-sitekey="' . htmlspecialchars($sitekey) . '" data-theme="auto" data-size="flexible"></div>';
	return $html;
}

function captchaCheck($form)
{
	global $_cfg, $_GS;
	$secretkey = !empty($_cfg['Turnstile_SecretKey']) ? $_cfg['Turnstile_SecretKey'] : '';
	
	if (empty($_POST['cf-turnstile-response'])) {
		return false;
	}
	
	$token = $_POST['cf-turnstile-response'];
	$isTestSecret = ($secretkey === '1x00000000000000000000000000000000000000AA');
	$allowTestSecret = in_array(strtolower((string)getenv('APP_ENV')), array('dev', 'development', 'local'), true)
		|| in_array(strtolower((string)getenv('ALLOW_TURNSTILE_TEST_KEYS')), array('1', 'true', 'yes', 'on'), true);
	if ($secretkey === '' || ($isTestSecret && !$allowTestSecret)) {
		return false;
	}
	if ($isTestSecret) {
		return true;
	}
	
	$url = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
	$data = array(
		'secret' => $secretkey,
		'response' => $token,
		'remoteip' => $_GS['client_ip']
	);
	
	$result = Client::request($url, http_build_query($data));
	if ($result === false) {
		return false;
	}
	
	$response = json_decode($result, true);
	return !empty($response['success']);
}

?>
