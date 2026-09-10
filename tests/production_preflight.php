<?php

declare(strict_types=1);

use HScript\Security\ProductionPreflight;

require dirname(__DIR__) . '/vendor/autoload.php';

function preflightAssert(bool $condition, string $message): void
{
	if (!$condition) throw new RuntimeException($message);
}

function preflightRemove(string $path): void
{
	if (is_link($path) || is_file($path)) { unlink($path); return; }
	if (!is_dir($path)) return;
	foreach ((array)scandir($path) as $item)
		if ($item !== '.' && $item !== '..') preflightRemove($path . '/' . $item);
	rmdir($path);
}

$root = sys_get_temp_dir() . '/hscript-preflight-' . bin2hex(random_bytes(8));
mkdir($root . '/module/_config', 0700, true);
foreach (array('.cfg', 'upload', 'backup') as $directory) mkdir($root . '/' . $directory, 0700, true);
file_put_contents($root . '/_config.php', "<?php\n");
file_put_contents($root . '/module/_config/pass', 'configured');
chmod($root . '/_config.php', 0600);
$environment = array('APP_ENV', 'APP_AUTO_INSTALL', 'APP_DEBUG', 'BACKUP_STORAGE_PATH', 'UPDATE_WORK_PATH', 'CONFIGURATOR_ALLOWED_CIDRS');
$previous = array();
foreach ($environment as $name) $previous[$name] = getenv($name);

try
{
	putenv('APP_ENV=development');
	putenv('APP_AUTO_INSTALL=0');
	putenv('APP_DEBUG=0');
	putenv('BACKUP_STORAGE_PATH=' . $root . '/backup');
	putenv('UPDATE_WORK_PATH=' . $root . '/.cfg/update');
	mkdir($root . '/.cfg/update', 0700, true);
	$development = (new ProductionPreflight($root, false, false))->inspect();
	preflightAssert(!$development['blockers'], 'Ready development environment was blocked');

	putenv('APP_ENV=production');
	putenv('APP_AUTO_INSTALL=1');
	putenv('APP_DEBUG=1');
	$production = (new ProductionPreflight($root, false, false))->inspect();
	$blockerIds = array_column($production['blockers'], 'id');
	preflightAssert(in_array('auto_install', $blockerIds, true), 'APP_AUTO_INSTALL production blocker is missing');
	preflightAssert(in_array('https', $blockerIds, true), 'HTTPS production blocker is missing');
	preflightAssert(in_array('debug', array_column($production['warnings'], 'id'), true), 'APP_DEBUG warning is missing');
	preflightAssert(count((new ProductionPreflight($root, true, false))->forOperation('backup')) === 1, 'Operation-specific production blockers are incorrect');

	echo "Production preflight tests passed.\n";
}
finally
{
	foreach ($previous as $name => $value)
		if ($value === false) putenv($name); else putenv($name . '=' . $value);
	preflightRemove($root);
}
