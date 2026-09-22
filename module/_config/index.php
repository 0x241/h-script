<?php

use HScript\Update\ConfiguratorRouteRegistry;
use HScript\Security\ConfiguratorSecurity;
use HScript\Observability\CorrelationContext;

error_reporting(7);
startSessionSafely();
require_once __DIR__ . '/translations.php';
CorrelationContext::setActorClass(!empty($_SESSION['cfg_logged']) ? 'administrator' : 'public');

if (isset($_GET['lang'])) {
	$_SESSION['cfg_lang'] = cfg_language($_GET['lang']);
	$cfgQuery = $_GET;
	unset($cfgQuery['lang']);
	header('Location: ?' . ($cfgQuery ? http_build_query($cfgQuery, '', '&', PHP_QUERY_RFC3986) : 'modules'));
	exit;
}
if (isset($_GET['theme'])) {
	$_SESSION['cfg_theme'] = ($_GET['theme'] === 'light') ? 'light' : 'dark';
	$redir = '?' . $_SERVER['QUERY_STRING'];
	$redir = preg_replace('/&?theme=(light|dark)/i', '', $redir);
	$redir = str_replace('?&', '?', $redir);
	header("Location: " . ($redir === '?' ? '?modules' : $redir));
	exit;
}
$_SESSION['cfg_lang'] = cfg_language();

$cfgSecurity = new ConfiguratorSecurity(dirname(__DIR__, 2));
$cfgClientIp = $cfgSecurity->clientIp();
try
{
	$cfgSecurity->assertAllowed($cfgClientIp);
}
catch (Throwable $exception)
{
	$cfgSecurity->audit('access', 'blocked', $cfgClientIp, array('reason' => 'cidr'));
	http_response_code(403);
	header('Content-Type: text/plain; charset=UTF-8');
	echo cfg_t('configurator.index.configurator_access_is_denied_for_this_address');
	exit;
}

function getMsg()
{
	return '' . (isset($_SESSION['cfg_info_message']) ? $_SESSION['cfg_info_message'] : '');
}

function addMsg($s, bool $error = false)
{
	if (!isset($_SESSION['cfg_info_message']))
		$_SESSION['cfg_info_message'] = '';
	$_SESSION['cfg_info_message'] .= "$s<br>";
	$_SESSION['cfg_info_error'] = !empty($_SESSION['cfg_info_error']) || $error;
}

function showMsg()
{
	if ($s = getMsg())
	{
		unset($_SESSION['cfg_info_message']);
		echo '<div class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-bold text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-100">' . $s . '</div>';
	}
}

if (empty($_SESSION['cfg_logged']))
	if (!isset($_GET['login']))
		goToURL($_cfg['cfg_link'] . '?login');

$pass = is_readable('module/_config/pass') ? trim(file_get_contents('module/_config/pass')) : '';
if (!$pass)
	$_GET['pass'] = 1;
elseif (!$_cfg['cfg_link'])
	$_GET['setup'] = 1;
foreach (ConfiguratorRouteRegistry::keys() as $m)
	if (isset($_GET[$m]))
	{
		$route = ConfiguratorRouteRegistry::get($m);
		$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
		if (!in_array($method, $route['methods'], true))
		{
			header('Allow: ' . implode(', ', $route['methods']));
			http_response_code(405);
			exit;
		}
		if ($method === 'POST' && $route['mutates_server_state'])
		{
			try
			{
				$rate = $cfgSecurity->consumeRateLimit('route-' . $m, $cfgClientIp, $m === 'login' ? 10 : 60, $m === 'login' ? 300 : 60);
				if (!$rate['allowed'])
				{
					$cfgSecurity->audit('rate_limit', 'blocked', $cfgClientIp, array('route' => $m));
					header('Retry-After: ' . (int)$rate['retry_after']);
					http_response_code(429);
					addMsg(cfg_t('configurator.index.too_many_requests_try_again_later'), true);
					goToURL($_cfg['cfg_link'] . '?' . $m);
				}
			}
			catch (Throwable $exception)
			{
				$cfgSecurity->audit('rate_limit', 'error', $cfgClientIp, array('route' => $m));
				http_response_code(503);
				header('Content-Type: text/plain; charset=UTF-8');
				echo cfg_t('configurator.index.configurator_protection_is_temporarily_unavailable');
				exit;
			}
		}
		include("module/_config/$m.php");
		exit;
	}
	
include("module/_config/modules.php");

?>
