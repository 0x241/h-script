<?php

use HScript\Util\StringHelper;
use HScript\Template\View;
use HScript\Http\ApiResponse;

// Rewrite module

require_once __DIR__ . '/vendor/autoload.php';

hsConfigureErrorHandling();

if (!headers_sent()) {
	header('X-Frame-Options: DENY');
	header('X-Content-Type-Options: nosniff');
	header('Referrer-Policy: strict-origin-when-cross-origin');
	header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
	header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://unpkg.com https://cdnjs.cloudflare.com https://challenges.cloudflare.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data:; media-src 'self' blob:; frame-src 'self' https://www.youtube.com https://www.youtube-nocookie.com https://player.vimeo.com https://challenges.cloudflare.com;");
}

$_GS['https'] = hsIsHttpsRequest();
$_GS['root_url'] = getRootURL($_GS['https']);
$_GS['module_dir'] = 'module/';

if (is_file($_GS['module_dir'] . '_config.php'))
	include_once($_GS['module_dir'] . '_config.php');

function linkToModule($l)
{
	global $_rwlinks;
	$l = trim($l);
	$aliases = array(
		'wallets' => 'balance/wallets',
		'balance/wallets' => 'balance/wallets',
		'operation' => 'balance/oper',
		'operations' => 'balance',
		'message/show' => 'message/show'
	);
	if (isset($aliases[$l]))
		return $aliases[$l];
	if ('' === $l)
		$l = moduleToLink('index');
	foreach ($_rwlinks as $m => $r)
		if ($r[0] == $l)
			return $m;
	return '';
}

// https: 0-default / 1-on / 2-off
function moduleToLink($m = '', $chpu = false, $https = 0) // chpu - array(id, text[, text2...])
{
	global $_GS, $_rwlinks;
	if (!$m)
		$m = $_GS['module'];
	if (empty($_rwlinks[$m]))
		return '';
	$r = $_rwlinks[$m];
	if (is_array($chpu) and ($chpu[0] > 0) and ($chpu[1]))
		foreach ($chpu as $i => $m)
			if ($i == 0)
				$r[0] .= '/' . (0 + $chpu[0]);
			else
				$r[0] .= '/' . StringHelper::toTranslitURL($m);
	if ($https < 1)
		$https = isset($_GS['https_mode']) ? $_GS['https_mode'] : 0;
	if ($https >= 1)
		$r['https'] = ($https == 1);
	elseif (empty($r['https']))
		$r['https'] = $_GS['https'];
	return (($r['https'] xor $_GS['https']) ? fullURL($r[0], $r['https']) : $r[0]);
}

function useLib($m = '')
{
	global $_GS, $_rwlinks;
	if (!$m)
		$m = $_GS['module'];
	while (!file_exists($f = $_GS['module_dir'] . $m .'/lib.php'))
	{
		StringHelper::cutElemR($m, '/');
		if (!$m)
			return;
	}
	require_once($f);
}

// Load config

global $_cfg;
$_cfg = array();
if (is_file('_config.php'))
	include_once('_config.php');
if (is_file('_config.local.php'))
	include_once('_config.local.php');
if (empty($_cfg['cfg_link']))
	$_cfg['cfg_link'] = '_cfg';

// Process URI

$p = $_GS['uri'];
$f = StringHelper::cutElemL($p, '?'); // get only link
$is_api_v1 = ($f === 'api/v1') || str_starts_with($f, 'api/v1/');
$_GS['is_api'] = $is_api_v1;
if ($is_api_v1)
	ini_set('display_errors', '0');
if (!hsHasDatabaseConfiguration($_cfg) && ($f != $_cfg['cfg_link']))
{
	if ($is_api_v1)
		ApiResponse::error('api_unavailable', 'The API is not configured', 503);
	goToURL($_cfg['cfg_link']);
}
if (!$_cfg['cfg_link'] or ($f == $_cfg['cfg_link']))
	$m = '_config';
elseif ($l = moduleToLink('index'))
{
	if (preg_match('|(.+)\/(\d+)\/|', $f, $m)) // chpu
	{
		$f = $m[1];
		$_GET['id'] = $m[2];
	}
	$m = linkToModule($f);
	if (!$m and ($f != $l))
	{
		if ($is_api_v1)
			ApiResponse::error('route_not_found', 'API route not found', 404);
		xAddToLog($_GS['uri'], 'ul');
		header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found');
		header('Status: 404 Not Found');
		readfile('404.html');
/*		$sapi_name = php_sapi_name();
		if ($sapi_name == 'cgi' || $sapi_name == 'cgi-fcgi')
			header('Status: 404 Not Found');
		else
			header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found');*/
		exit;
//		goToURL($l . ($p ? '?' . $p : '')); // on unknown link - go home
	}
}
else
	$m = 'index';

if (!file_exists($f = $_GS['module_dir'] . $m . '/index.php'))
	if (!file_exists($f = $_GS['module_dir'] . $m . '.php'))
	{
		if ($is_api_v1)
			ApiResponse::error('route_not_found', 'API route not found', 404);
		xSysStop("Rewrite: Module '$m' not found");
	}

$_GS['module'] = $m; // account/login
$_GS['vmodule'] = (!empty($_rwlinks[$m]['admin']) ? 'admin' : $m);
$_GS['script'] = $f; // module/account/login/*.php

if ($m != '_config')
{
	// Twig init

	View::initialize();
	View::setPage('_selfLink', moduleToLink());

	$_GS['demo'] = file_exists('tpl_c/demo') || !empty($_cfg['demo_mode']);

	// onLoad init

	foreach ($_onload as $m => $s)
		if (file_exists($f = $_GS['module_dir'] . $m . '/onload.php'))
			include_once($f);

	useLib(); // use default module lib
	
}

if ($is_api_v1)
{
	try
	{
		require($_GS['script']);
	}
	catch (Throwable $e)
	{
		error_log('API request failed: ' . $e->getMessage());
		ApiResponse::error('internal_error', 'An internal API error occurred', 500);
	}
}
else
	require($_GS['script']);

?>
