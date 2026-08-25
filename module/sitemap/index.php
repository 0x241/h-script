<?php

require_once('module/auth.php');

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$escape = static fn(string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$rootURL = rtrim((string)($_GS['root_url'] ?? ''), '/') . '/';
$absoluteURL = static fn(string $path): string => $rootURL . ltrim($path, '/');
$urls = array();
foreach ($_rwlinks as $moduleName => $route)
{
	if (empty($route['sitemap']) || !is_array($route['sitemap']))
		continue;
	$urls[] = array(
		'loc' => $absoluteURL(moduleToLink($moduleName)),
		'changefreq' => (string)($route['sitemap']['changefreq'] ?? 'monthly'),
		'priority' => (string)($route['sitemap']['priority'] ?? '0.5'),
	);
}

$now = timeToStamp();
$news = isset($_rwlinks['news/show']) ? $db->fetchRows($db->select(
	'News',
	'nID, nTopic, nTS',
	'(nDBegin=0 or nDBegin<=?) and (nDEnd=0 or nDEnd>=?)',
	array($now, $now),
	'nTS desc'
)) : array();
foreach ($news as $item)
{
	$timestamp = stampToTime((string)($item['nTS'] ?? ''));
	$urls[] = array(
		'loc' => $absoluteURL(moduleToLink('news/show', array((int)$item['nID'], (string)$item['nTopic']))),
		'lastmod' => $timestamp ? gmdate('c', $timestamp) : '',
		'changefreq' => 'monthly',
		'priority' => '0.6',
	);
}

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
foreach ($urls as $url)
{
	echo "  <url>\n";
	echo '    <loc>' . $escape((string)$url['loc']) . "</loc>\n";
	if (!empty($url['lastmod']))
		echo '    <lastmod>' . $escape((string)$url['lastmod']) . "</lastmod>\n";
	echo '    <changefreq>' . $escape((string)$url['changefreq']) . "</changefreq>\n";
	echo '    <priority>' . $escape((string)$url['priority']) . "</priority>\n";
	echo "  </url>\n";
}
echo "</urlset>\n";

?>
