<?php

$_GS['stateless'] = true;
$_smode = 2;
require_once('module/auth.php');

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$rootURL = getRootURL(true);
$rootPath = '/' . trim((string)parse_url($rootURL, PHP_URL_PATH), '/');
$rootPath = $rootPath === '/' ? '/' : $rootPath . '/';
$disallow = array();
foreach ($_rwlinks as $route)
{
	if (!empty($route['indexable']) || !isset($route[0]))
		continue;
	$path = trim((string)$route[0], '/');
	if ($path === '' || in_array($path, array('robots.txt', 'sitemap.xml'), true))
		continue;
	$path = preg_replace('#[^a-zA-Z0-9/_-]#', '', $path);
	if ($path !== '')
		$disallow[$rootPath . $path] = true;
}

echo "User-agent: *\n";
echo 'Allow: ' . $rootPath . "\n";
foreach (array_keys($disallow) as $path)
	echo 'Disallow: ' . $path . "\n";
echo "\nSitemap: " . $rootURL . ltrim(moduleToLink('sitemap'), '/') . "\n";

?>
