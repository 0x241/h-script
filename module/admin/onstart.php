<?php

use HScript\Template\View;
use HScript\Telemetry\CollectorMode;

global $self_category, $admin_modules;
$self_category = '';
$self_admin_title = '';
$admin_modules = array();
foreach ($_rwlinks as $m => $r)
{
	if (empty($r['admin']))
		continue;
	if ((int)($r['admin_level'] ?? 90) > (int)($_user['uLevel'] ?? 0))
		continue;
	if (
		!empty($r['collector_only'])
		&& !CollectorMode::enabled($_cfg, (string)($_GS['domain'] ?? ''))
	)
		continue;
	$admin_title = is_string($r['admin']) ? $r['admin'] : $m;
	$category = isset($r['category']) ? $r['category'] : '';
	if (!$category)
	{
		$s = explode('/', $admin_title, 2);
		if (isset($s[1]) && $s[1])
		{
			$category = $s[0];
			$admin_title = $s[1];
		}
	}
	View::prepVal($category, 3);
	View::prepVal($admin_title, 3);
	if ($m == $_GS['module'])
	{
		$self_category = $category;
		$self_admin_title = $admin_title;
	}
	if ($admin_title != '-')
		$admin_modules[$category][$admin_title] = $m;
}

$admin_module_lookup = array();
foreach ($admin_modules as $category => $modules)
	foreach ($modules as $title => $module)
		$admin_module_lookup[$module] = array(
			'module' => $module,
			'title' => $title,
			'category' => $category
		);

$admin_pinned_ids = isset($_SESSION['_admin_pins']) && is_array($_SESSION['_admin_pins'])
	? $_SESSION['_admin_pins']
	: array();
$pin_module = (string)_GET('admin_pin');
$unpin_module = (string)_GET('admin_unpin');

if ($pin_module && isset($admin_module_lookup[$pin_module]))
{
	$admin_pinned_ids = array_values(array_diff($admin_pinned_ids, array($pin_module)));
	array_unshift($admin_pinned_ids, $pin_module);
}
elseif ($unpin_module)
	$admin_pinned_ids = array_values(array_diff($admin_pinned_ids, array($unpin_module)));

$admin_pinned_modules = array();
foreach ($admin_pinned_ids as $module)
	if (isset($admin_module_lookup[$module]))
		$admin_pinned_modules[] = $admin_module_lookup[$module];

$admin_pinned_ids = array_column($admin_pinned_modules, 'module');
$_SESSION['_admin_pins'] = $admin_pinned_ids;

$admin_unpinned_modules = array();
foreach ($admin_modules as $category => $modules)
	foreach ($modules as $title => $module)
		if (!in_array($module, $admin_pinned_ids, true))
			$admin_unpinned_modules[$category][$title] = $module;

View::setPage('up_category', $self_category);
View::setPage('up_title', $self_admin_title);
View::setPage('up_modules', isset($admin_modules[$self_category]) ? $admin_modules[$self_category] : array());
View::setPage('admin_modules', $admin_modules);
View::setPage('admin_unpinned_modules', $admin_unpinned_modules);
View::setPage('admin_pinned_ids', $admin_pinned_ids);
View::setPage('admin_pinned_modules', $admin_pinned_modules);

clearstatcache();
View::setPage('needupdatedb', ($_cfg['Const_DBVer'] != (is_file('_dbstru.php') ? filemtime('_dbstru.php') : 0)));

?>
