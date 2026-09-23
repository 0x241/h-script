<?php

use HScript\Util\StringHelper;
use HScript\Template\View;
use HScript\Http\ApiResponse;
use HScript\Http\LocaleRouter;
use HScript\Http\SystemPageRenderer;
use HScript\Observability\HttpRequestObserver;
use HScript\Observability\StructuredLogger;
use HScript\Update\SchemaUpdateGate;

// Rewrite module

require_once __DIR__ . '/vendor/autoload.php';

hsConfigureErrorHandling();
HttpRequestObserver::begin($_SERVER);

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

$_localeRouter = new LocaleRouter($_rwlinks);

function linkToModule($l)
{
	global $_localeRouter;
	return $_localeRouter->moduleForAlias(trim($l));
}

// https: 0-default / 1-on / 2-off
function moduleToLink($m = '', $chpu = false, $https = 0) // chpu - array(id, text[, text2...])
{
	global $_GS, $_rwlinks, $_localeRouter;
	if (!$m)
		$m = $_GS['module'];
	if (empty($_rwlinks[$m]))
		return '';
	$module = $m;
	$r = $_rwlinks[$m];
    if ($module === 'news/show' && is_array($chpu) && ($chpu[0] ?? 0) > 0) {
        $r[0] = $_GS['public_news_paths'][(int)$chpu[0]][$_GS['url_locale'] ?? '']
            ?? HScript\Http\PublicSeo::newsPath($r[0], (int)$chpu[0], (string)($chpu[1] ?? ''));
    } elseif (is_array($chpu) && ($chpu[0] > 0) && $chpu[1]) {
        foreach ($chpu as $i => $part) {
            $r[0] .= '/' . ($i === 0 ? (int)$chpu[0] : StringHelper::toTranslitURL($part));
        }
    }
	if ($_localeRouter->primaryLocale() !== '')
		$r[0] = $_localeRouter->url($module, $r[0], $_GS['url_locale'] ?? null);
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

function hsRenderSystemPage(string $page, string $configuratorUrl = ''): void
{
	$requestedLanguage = $_GET['system_lang'] ?? null;
	$language = SystemPageRenderer::selectLanguage(
		$requestedLanguage,
		$_COOKIE['lang'] ?? null,
		(string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')
	);
	if (SystemPageRenderer::normalizeLanguage($requestedLanguage) !== null)
	{
		setcookie('lang', $language, array(
			'expires' => time() + 30 * HS2_UNIX_DAY,
			'path' => '/',
			'secure' => hsUseSecureCookies(),
			'httponly' => false,
			'samesite' => 'Lax',
		));
	}
	echo SystemPageRenderer::render(
		$page,
		$language,
		(string)($_SERVER['REQUEST_URI'] ?? '/'),
		$configuratorUrl
	);
}

function hsRouteNotFound(): never
{
	global $_GS;
	xAddToLog($_GS['uri'], 'ul');
	http_response_code(404);
	header('Content-Type: text/html; charset=UTF-8');
	header('X-Robots-Tag: noindex, nofollow');
	hsRenderSystemPage('not_found');
	exit;
}

// Process URI

$p = $_GS['uri'];
$f = StringHelper::cutElemL($p, '?'); // get only link
$is_api_v1 = ($f === 'api/v1') || str_starts_with($f, 'api/v1/');
$_GS['is_api'] = $is_api_v1;
if ($is_api_v1)
	ini_set('display_errors', '0');
$updateTrafficBlocked = SchemaUpdateGate::requiresTrafficGate(__DIR__);
$maintenanceActive = is_file(__DIR__ . '/.cfg/maintenance.json');
if ($f !== $_cfg['cfg_link'] && ($maintenanceActive || $updateTrafficBlocked))
{
	header('Retry-After: 60');
	header('Cache-Control: no-store, max-age=0');
	header('X-Robots-Tag: noindex, nofollow');
	if ($is_api_v1)
		ApiResponse::error('maintenance', 'H-Script update is in progress', 503);
	http_response_code(503);
	header('Content-Type: text/html; charset=UTF-8');
	hsRenderSystemPage(
		$maintenanceActive ? 'maintenance' : 'update_required',
		'/' . ltrim((string)$_cfg['cfg_link'], '/') . '?update'
	);
	exit;
}
if (!hsHasDatabaseConfiguration($_cfg) && ($f != $_cfg['cfg_link']))
{
	if ($is_api_v1)
		ApiResponse::error('api_unavailable', 'The API is not configured', 503);
	goToURL($_cfg['cfg_link']);
}
if (!$_cfg['cfg_link'] or ($f == $_cfg['cfg_link']))
	$m = '_config';
else
{
	$_GS['locale_route'] = $_localeRouter->parse($f);
	if ($_GS['locale_route'] === null)
	{
		if ($is_api_v1)
			ApiResponse::error('route_not_found', 'API route not found', 404);
		hsRouteNotFound();
	}
	$m = $_GS['locale_route']['module'];
	if ($_GS['locale_route']['id'] !== null)
		$_GET['id'] = $_GS['locale_route']['id'];
}

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
		StructuredLogger::event('error', 'api', 'api_request_failed', 'failure', 0, '', array('error_class' => $e::class));
		ApiResponse::error('internal_error', 'An internal API error occurred', 500);
	}
}
else
	require($_GS['script']);

?>
