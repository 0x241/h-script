<?php

use HScript\Cache\CatalogCache;
use HScript\Cache\RedisCache;
use HScript\Database\Connection;
use HScript\Queue\JobQueue;

$db = new Connection();

function hsDatabaseEnvironmentValue(string $name): string
{
	$file = getenv($name . '_FILE');
	if ($file !== false && $file !== '' && is_readable($file))
		return trim((string)file_get_contents($file));
	$value = getenv($name);
	return $value === false ? '' : (string)$value;
}

if (!empty($_cfg['db_credentials_env']))
{
	$dbLogin = hsDatabaseEnvironmentValue('DB_USER');
	$dbPassword = hsDatabaseEnvironmentValue('DB_PASSWORD');
}
else
{
	// Backward-compatible reader for existing non-Docker installations. New
	// Docker configurations never persist database credentials in _config.php.
	$k = md5($_GS['domain'] . $_cfg['sys_id']);
	$dbLogin = decode1($_cfg['db_login'], $k, false, 1);
	$dbPassword = decode1($_cfg['db_pass'], $k, false, 2);
}
$db->open($_cfg['db_host'], $_cfg['db_name'], $dbLogin, $dbPassword);
if (!$db->link)
	xSysStop("DBInit: Can't open MySQL database");

$cache = RedisCache::fromEnvironment();
$catalogCache = new CatalogCache($cache);
$db->onMutation(static function (string $table) use ($catalogCache): void
{
	$catalogCache->invalidateTable($table);
});
$jobQueue = new JobQueue($db);
