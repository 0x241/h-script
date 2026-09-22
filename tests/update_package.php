<?php

declare(strict_types=1);

use HScript\Update\OfficialReleaseProvider;
use HScript\Update\ReleaseBaselineRepository;
use HScript\Update\SchemaStateRepository;
use HScript\Update\UpdatePackageService;
use HScript\Update\UpdateSettings;

$root = dirname(__DIR__);
$domain = (string)(getenv('APP_DOMAIN') ?: 'localhost');
$_SERVER += array(
	'SERVER_NAME' => $domain, 'HTTP_HOST' => $domain, 'SCRIPT_NAME' => '/tests/update_package.php',
	'SERVER_PORT' => 80, 'REQUEST_URI' => '/', 'SERVER_ADDR' => '127.0.0.1', 'REMOTE_ADDR' => '127.0.0.1',
);
chdir($root);
require $root . '/vendor/autoload.php';
require_once __DIR__ . '/fixtures/update_contract.php';
global $_cfg;
$_cfg = array();
if (is_file($root . '/_config.php')) require $root . '/_config.php';
if (is_file($root . '/_config.local.php')) require $root . '/_config.local.php';
require $root . '/module/dbinit.php';

function updatePackageAssert(bool $condition, string $message): void
{
	if (!$condition) throw new RuntimeException($message);
}

function updatePackageRejects(callable $callback, string $message): void
{
	try { $callback(); }
	catch (Throwable) { return; }
	throw new RuntimeException($message);
}

function updatePackageRemove(string $path): void
{
	if (is_link($path) || is_file($path)) { unlink($path); return; }
	if (!is_dir($path)) return;
	foreach ((array)scandir($path) as $item)
		if ($item !== '.' && $item !== '..') updatePackageRemove($path . '/' . $item);
	rmdir($path);
}

final class TestOfficialReleaseProvider implements OfficialReleaseProvider
{
	public function __construct(private string $archive, private string $version, private ?string $checksum = null) {}

	public function latest(): array { return $this->metadata(); }
	public function byVersion(string $version): array
	{
		if ($version !== $this->version) throw new RuntimeException('Unexpected test release version');
		return $this->metadata();
	}
	public function downloadArchive(array $release, string $target, int $maximumBytes): void
	{
		if (!copy($this->archive, $target)) throw new RuntimeException('Test release could not be copied');
	}
	private function metadata(): array
	{
		return array(
			'version' => $this->version,
			'tag' => 'v' . $this->version,
			'released_at' => '2026-09-08T00:00:00Z',
			'summary' => 'Официальный тестовый GitHub Release.',
			'changes' => array('Проверка штатного shared-hosting архива.'),
			'archive_name' => 'h-script-' . $this->version . '-shared-hosting.tar.gz',
			'archive_url' => 'https://github.com/0x241/h-script/releases/download/v' . $this->version . '/h-script-' . $this->version . '-shared-hosting.tar.gz',
			'archive_sha256' => $this->checksum ?? (string)hash_file('sha256', $this->archive),
			'sigstore_url' => 'https://github.com/0x241/h-script/releases/download/v' . $this->version . '/h-script-' . $this->version . '-shared-hosting.tar.gz.sigstore.json',
		);
	}
}

function updatePackageCompatibility(string $sourceVersion, string $targetVersion, string $schema): string
{
	return json_encode(updateTestCompatibility($sourceVersion, $targetVersion, $schema, $schema), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}

function updatePackageArchive(string $directory, string $sourceVersion, string $version, string $schema, string $twigPath, string $twig, array $extraFiles = array()): string
{
	$tar = $directory . '/h-script-' . $version . '-shared-hosting.tar';
	$phar = new PharData($tar);
	$files = array(
		'VERSION' => $version . "\n",
		'SCHEMA_VERSION' => $schema . "\n",
		'resources/update-compatibility.json' => updatePackageCompatibility($sourceVersion, $version, $schema),
		$twigPath => $twig,
		'src/ReleaseProbe.php' => "<?php\n// release probe\n",
	) + $extraFiles;
	$baseline = array();
	foreach ($files as $path => $contents)
	{
		$customizable = str_starts_with($path, 'tpl/themes/') || (str_starts_with($path, 'tpl/') && str_ends_with($path, '.twig'));
		$baseline[$path] = array('sha256' => hash('sha256', $contents), 'size' => strlen($contents), 'class' => $customizable ? 'customizable' : 'core_strict');
	}
	ksort($baseline, SORT_STRING);
	$files['resources/release-baseline.json'] = json_encode(array('format' => 2, 'version' => $version, 'files' => $baseline), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
	foreach ($files as $path => $contents) $phar->addFromString('h-script/' . $path, $contents);
	$phar->compress(Phar::GZ);
	unset($phar);
	unlink($tar);
	return $tar . '.gz';
}

function updatePackageSeedBaseline(UpdateSettings $settings, string $root, string $twigPath, string $twigSource, array $extra = array()): void
{
	$managed = array(
		array('path' => 'VERSION', 'sha256' => hash_file('sha256', $root . '/VERSION'), 'size' => filesize($root . '/VERSION'), 'class' => 'core_strict'),
		array('path' => 'SCHEMA_VERSION', 'sha256' => hash_file('sha256', $root . '/SCHEMA_VERSION'), 'size' => filesize($root . '/SCHEMA_VERSION'), 'class' => 'core_strict'),
		array('path' => 'resources/update-compatibility.json', 'sha256' => hash_file('sha256', $root . '/resources/update-compatibility.json'), 'size' => filesize($root . '/resources/update-compatibility.json'), 'class' => 'core_strict'),
		array('path' => $twigPath, 'sha256' => hash('sha256', $twigSource), 'size' => strlen($twigSource), 'class' => 'customizable'),
	);
	foreach ($extra as $path => $contents) $managed[] = array('path' => $path, 'sha256' => hash('sha256', $contents), 'size' => strlen($contents), 'class' => str_ends_with($path, '.twig') ? 'customizable' : 'core_strict');
	(new ReleaseBaselineRepository($settings))->publish(trim((string)file_get_contents($root . '/VERSION')), $managed);
}

function updatePackageTarEntry(string $name, string $contents, string $type = '0', int $mode = 0644, string $link = ''): string
{
	$header = str_repeat("\0", 512);
	$write = static function (string &$value, int $offset, int $length, string $contents): void {
		$value = substr_replace($value, str_pad(substr($contents, 0, $length), $length, "\0"), $offset, $length);
	};
	$write($header, 0, 100, $name);
	$write($header, 100, 8, sprintf("%07o\0", $mode));
	$write($header, 108, 8, "0000000\0");
	$write($header, 116, 8, "0000000\0");
	$write($header, 124, 12, sprintf("%011o\0", strlen($contents)));
	$write($header, 136, 12, sprintf("%011o\0", time()));
	$write($header, 148, 8, '        ');
	$write($header, 156, 1, $type);
	$write($header, 157, 100, $link);
	$write($header, 257, 6, "ustar\0");
	$write($header, 263, 2, '00');
	$checksum = array_sum(unpack('C*', $header));
	$write($header, 148, 8, sprintf("%06o\0 ", $checksum));
	return $header . $contents . str_repeat("\0", (512 - strlen($contents) % 512) % 512);
}

$temporaryRoot = sys_get_temp_dir() . '/hscript-update-package-' . bin2hex(random_bytes(8));
if (!mkdir($temporaryRoot, 0700, true)) throw new RuntimeException('Test directory could not be created');

try
{
	$schemaVersion = (new SchemaStateRepository($db))->currentVersion();
	if ($schemaVersion === null) throw new RuntimeException('Schema metadata is not initialized');
	$sourceVersion = trim((string)file_get_contents($root . '/VERSION'));
	$version = preg_replace_callback('/^(\d+)\.(\d+)\.(\d+).*$/',
		static fn(array $parts): string => $parts[1] . '.' . $parts[2] . '.' . ((int)$parts[3] + 1), $sourceVersion);
	$twigPath = 'tpl/account/login.twig';
	if (!is_file($root . '/' . $twigPath))
	{
		$candidates = glob($root . '/tpl/*/*.twig');
		$twigPath = substr((string)$candidates[0], strlen($root) + 1);
	}
	$twigRelease = (string)file_get_contents($root . '/' . $twigPath) . "\n{# official release test #}\n";
	$coreCustomPath = 'src/PackageCustomProbe.php';
	$coreRemovedPath = 'src/PackageRemovedProbe.php';
	$coreSource = "<?php\n// official source\n";
	$coreLocal = "<?php\n// local customization\n";
	$coreRelease = "<?php\n// release change\n";
	file_put_contents($root . '/' . $coreCustomPath, $coreLocal);
	file_put_contents($root . '/' . $coreRemovedPath, $coreSource);
	$archive = updatePackageArchive($temporaryRoot, $sourceVersion, $version, $schemaVersion, $twigPath, $twigRelease, array($coreCustomPath => $coreRelease));
	$provider = new TestOfficialReleaseProvider($archive, $version);
	$settings = new UpdateSettings($root, $temporaryRoot . '/work', 10485760, 20971520, 100, 10485760, 2);
	updatePackageSeedBaseline($settings, $root, $twigPath, "{# official prior template #}\n", array($coreCustomPath => $coreSource, $coreRemovedPath => $coreSource));
	$service = new UpdatePackageService($db, $settings, $provider);

	$prepared = $service->prepareManual($archive);
	updatePackageAssert($prepared['source'] === 'manual', 'Manual release source is invalid');
	updatePackageAssert($prepared['archive_sha256'] === hash_file('sha256', $archive), 'GitHub checksum was not stored');
	$conflictPaths = array_column($prepared['conflicts'], 'path');
	updatePackageAssert(in_array($twigPath, $conflictPaths, true), 'Modified Twig template was not exposed as a conflict');
	updatePackageAssert(in_array($coreCustomPath, $conflictPaths, true), 'Modified core file was not exposed as a conflict');
	updatePackageAssert(is_file($temporaryRoot . '/work/conflicts/' . $prepared['id'] . '/local/' . $twigPath), 'Local Twig conflict copy is missing');
	updatePackageAssert(is_file($temporaryRoot . '/work/conflicts/' . $prepared['id'] . '/release/' . $twigPath), 'Release Twig conflict copy is missing');
	$removedPlans = array_values(array_filter($prepared['activation_plan'], static fn(array $entry): bool => $entry['path'] === $coreRemovedPath));
	updatePackageAssert(count($removedPlans) === 1 && $removedPlans[0]['action'] === 'delete', 'File removed from the release was not scheduled for deletion');
	updatePackageAssert(is_file($settings->baselinePath()), 'Installed full release baseline was not stored');
	$service->revalidate($prepared);

	$latest = $service->prepareLatest();
	updatePackageAssert($latest['source'] === 'github', 'Latest GitHub release source is invalid');

	$wrongProvider = new TestOfficialReleaseProvider($archive, $version, str_repeat('0', 64));
	$wrongSettings = new UpdateSettings($root, $temporaryRoot . '/wrong-work', 10485760, 20971520, 100, 10485760, 2);
	updatePackageSeedBaseline($wrongSettings, $root, $twigPath, "{# official prior template #}\n");
	$wrongService = new UpdatePackageService($db, $wrongSettings, $wrongProvider);
	updatePackageRejects(fn() => $wrongService->prepareManual($archive), 'Archive outside GitHub SHA256SUMS was accepted');

	$noSpaceSettings = new UpdateSettings($root, $temporaryRoot . '/no-space-work', 10485760, 20971520, 100, PHP_INT_MAX, 2);
	updatePackageSeedBaseline($noSpaceSettings, $root, $twigPath, "{# official prior template #}\n");
	$noSpaceService = new UpdatePackageService($db, $noSpaceSettings, $provider);
	updatePackageRejects(fn() => $noSpaceService->prepareManual($archive), 'Insufficient staging space was accepted');

	$traversal = $temporaryRoot . '/traversal.tar.gz';
	$tar = updatePackageTarEntry('h-script/VERSION', $version . "\n")
		. updatePackageTarEntry('h-script/SCHEMA_VERSION', $schemaVersion . "\n")
		. updatePackageTarEntry('h-script/../outside.php', "<?php\n") . str_repeat("\0", 1024);
	file_put_contents($traversal, gzencode($tar, 6));
	$traversalSettings = new UpdateSettings($root, $temporaryRoot . '/traversal-work', 10485760, 20971520, 100, 10485760, 2);
	updatePackageSeedBaseline($traversalSettings, $root, $twigPath, "{# official prior template #}\n");
	$traversalService = new UpdatePackageService($db, $traversalSettings, new TestOfficialReleaseProvider($traversal, $version));
	updatePackageRejects(fn() => $traversalService->prepareManual($traversal), 'tar.gz path traversal entry was accepted');

	$unsafeArchives = array(
		'executable' => updatePackageTarEntry('h-script/VERSION', $version . "\n", '0', 0755),
		'symlink' => updatePackageTarEntry('h-script/VERSION', $version . "\n") . updatePackageTarEntry('h-script/link', '', '2', 0777, '../outside'),
		'duplicate' => updatePackageTarEntry('h-script/VERSION', $version . "\n") . updatePackageTarEntry('h-script/VERSION', $version . "\n"),
	);
	foreach ($unsafeArchives as $name => $entries)
	{
		$unsafe = $temporaryRoot . '/' . $name . '.tar.gz';
		file_put_contents($unsafe, gzencode($entries . str_repeat("\0", 1024), 6));
		$unsafeSettings = new UpdateSettings($root, $temporaryRoot . '/' . $name . '-work', 10485760, 20971520, 100, 10485760, 2);
		updatePackageSeedBaseline($unsafeSettings, $root, $twigPath, "{# official prior template #}\n");
		$unsafeService = new UpdatePackageService($db, $unsafeSettings, new TestOfficialReleaseProvider($unsafe, $version));
		updatePackageRejects(fn() => $unsafeService->prepareManual($unsafe), 'Unsafe tar.gz entry was accepted: ' . $name);
	}

	$corrupt = $temporaryRoot . '/corrupt.tar.gz';
	file_put_contents($corrupt, 'not a tar.gz');
	$corruptSettings = new UpdateSettings($root, $temporaryRoot . '/corrupt-work', 10485760, 20971520, 100, 10485760, 2);
	updatePackageSeedBaseline($corruptSettings, $root, $twigPath, "{# official prior template #}\n");
	$corruptService = new UpdatePackageService($db, $corruptSettings, new TestOfficialReleaseProvider($corrupt, $version));
	updatePackageRejects(fn() => $corruptService->prepareManual($corrupt), 'Corrupted tar.gz was accepted');

	echo "Update package tests passed.\n";
}
finally
{
	if (isset($coreCustomPath) && is_file($root . '/' . $coreCustomPath)) unlink($root . '/' . $coreCustomPath);
	if (isset($coreRemovedPath) && is_file($root . '/' . $coreRemovedPath)) unlink($root . '/' . $coreRemovedPath);
	updatePackageRemove($temporaryRoot);
}
