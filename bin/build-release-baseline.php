<?php

declare(strict_types=1);

use HScript\Update\ReleaseInventory;
use HScript\Update\SchemaVersion;

$root = isset($argv[1]) ? rtrim((string)$argv[1], '/') : '';
$target = isset($argv[2]) ? (string)$argv[2] : '';
if ($root === '' || $target === '' || !is_dir($root) || !str_starts_with($target, $root . '/'))
{
	fwrite(STDERR, "Usage: php bin/build-release-baseline.php <release-root> <target-json>\n");
	exit(2);
}

require $root . '/vendor/autoload.php';
$version = SchemaVersion::requireValid(trim((string)file_get_contents($root . '/VERSION')), 'release version');
if (file_exists($target) && !unlink($target))
	throw new RuntimeException('Existing release baseline could not be replaced');

$files = array();
foreach (ReleaseInventory::integrityBaseline($root) as $file)
	$files[$file['path']] = array(
		'sha256' => $file['sha256'],
		'size' => $file['size'],
		'class' => $file['class'],
	);
ksort($files, SORT_STRING);
$payload = json_encode(array(
	'format' => 2,
	'version' => $version,
	'files' => $files,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
if (file_put_contents($target, $payload, LOCK_EX) !== strlen($payload))
	throw new RuntimeException('Release baseline could not be written');
chmod($target, 0644);
