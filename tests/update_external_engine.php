<?php
declare(strict_types=1);

use HScript\Database\Connection;
use HScript\Recovery\RuntimeArchiveService;
use HScript\Update\OfficialReleaseProvider;
use HScript\Update\ReleaseBaselineRepository;
use HScript\Update\SchemaStateRepository;
use HScript\Update\UpdateCliContext;
use HScript\Update\UpdateService;
use HScript\Update\UpdateSettings;

if (getenv('H_SCRIPT_UPDATE_SERVICE_TEST') !== '1' || getenv('DB_NAME') !== 'fixture') throw new RuntimeException('Disposable DB required');
$engine = dirname(__DIR__);
require $engine . '/vendor/autoload.php';
$db = new Connection();
if (!$db->open(getenv('DB_HOST'), getenv('DB_NAME'), getenv('DB_USER'), getenv('DB_PASSWORD'))) throw new RuntimeException('Fixture unavailable');
$temporary = sys_get_temp_dir() . '/hscript-external-engine-' . bin2hex(random_bytes(8));
$target = $temporary . '/site';
mkdir($target, 0700, true);
$environment = getenv();
$state = new SchemaStateRepository($db);
$priorVersion = $state->installedApplicationVersion();
$priorSchema = $state->currentVersion();
$runId = '';
function externalCli(array $arguments): array
{
	$process = proc_open(array_merge(array(PHP_BINARY, dirname(__DIR__) . '/bin/update.php'), $arguments),
		array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, dirname(__DIR__), getenv());
	if (!is_resource($process)) throw new RuntimeException('CLI unavailable');
	fclose($pipes[0]);
	$out = stream_get_contents($pipes[1]); fclose($pipes[1]);
	$err = stream_get_contents($pipes[2]); fclose($pipes[2]);
	return array(proc_close($process), $out, $err);
}
try
{
	putenv('APP_RELEASE_VERSION');
	putenv('UPDATE_WORK_PATH=' . $target . '/.cfg/update');
	putenv('BACKUP_STORAGE_PATH=' . $target . '/backup');
	putenv('REDIS_ENABLED=1'); putenv('REDIS_HOST=redis'); putenv('REDIS_PORT=6379');
	$cache = HScript\Cache\RedisCache::fromEnvironment();
	$cacheVersion = (int)$cache->get('catalog:config:version', static fn(): int => 0, 0);
	foreach (array('vendor','resources','backup','module/_config','module/admin','module/cron','module/account/login','module/api/v1/balance',
		'module/api/v1/user','module/api/v1/operations','module/api/v1/installations/register','migrations/versioned','upload') as $path)
		mkdir($target . '/' . $path, 0700, true);
	file_put_contents($target . '/vendor/autoload.php', '<?php throw new RuntimeException("Old autoloader must not run");');
	file_put_contents($target . '/module/_config/pass', 'synthetic-fixture-configurator');
	foreach (array('module/admin/onstart.php','module/cron/index.php','module/account/login/index.php','module/api/v1/balance/index.php',
		'module/api/v1/user/index.php','module/api/v1/operations/index.php','module/api/v1/installations/register/index.php') as $path)
		file_put_contents($target . '/' . $path, '<?php');
	copy($engine . '/module/_config.php', $target . '/module/_config.php');
	$config = array('db_credentials_env' => 1, 'db_host' => getenv('DB_HOST'), 'db_name' => getenv('DB_NAME'));
	file_put_contents($target . '/_config.php', '<?php $_cfg=' . var_export($config, true) . ';');
	file_put_contents($target . '/VERSION', "1.0.3\n");
	file_put_contents($target . '/SCHEMA_VERSION', "1.0.1\n");
	$legacy = json_encode(array('format'=>1,'application'=>array('min'=>'1.0.0','max'=>'1.0.3'),'schema'=>array('min'=>'1.0.0','max'=>'1.0.1')));
	file_put_contents($target . '/resources/update-compatibility.json', $legacy);
	$state->setInstalledApplicationVersion('1.0.3');
	$state->setCurrentVersion('1.0.1');
	$settings = UpdateSettings::fromEnvironment($target);
	$files = array('VERSION'=>file_get_contents($engine . '/VERSION'), 'SCHEMA_VERSION'=>"1.0.1\n",
		'resources/update-compatibility.json'=>file_get_contents($engine . '/resources/update-compatibility.json'));
	$baseline = array(); $installed = array();
	foreach ($files as $path => $bytes)
	{
		$baseline[$path] = array('sha256'=>hash('sha256',$bytes),'size'=>strlen($bytes),'class'=>'core_strict');
		$installed[] = array('path'=>$path,'sha256'=>hash_file('sha256',$target . '/' . $path),'size'=>filesize($target . '/' . $path),'class'=>'core_strict');
	}
	ksort($baseline);
	(new ReleaseBaselineRepository($settings))->publish('1.0.3', $installed);
	$files['resources/release-baseline.json'] = json_encode(array('format'=>2,'version'=>trim($files['VERSION']),'files'=>$baseline));
	$tar = new PharData($temporary . '/release.tar');
	foreach ($files as $path => $bytes) $tar->addFromString('h-script/' . $path, $bytes);
	$tar->compress(Phar::GZ); unset($tar);
	$archive = $temporary . '/release.tar.gz';
	$provider = new class($archive) implements OfficialReleaseProvider {
		public function __construct(private string $archive) {}
		public function latest(): array { return $this->byVersion('1.0.4'); }
		public function byVersion(string $version): array { return array('version'=>$version,'archive_name'=>'h-script-' . $version . '-shared-hosting.tar.gz',
			'archive_sha256'=>hash_file('sha256',$this->archive),'released_at'=>'2026-09-14T00:00:00Z','summary'=>'Synthetic engine handoff','changes'=>array('Fixture')); }
		public function downloadArchive(array $release, string $target, int $maximumBytes): void { copy($this->archive,$target); }
	};
	foreach (array('relative', '/', $engine, $engine . '/src', dirname($engine)) as $invalid)
	{
		$rejected = false;
		try { UpdateCliContext::resolve(array('update.php','status','--project-root=' . $invalid), $engine); }
		catch (InvalidArgumentException) { $rejected = true; }
		if (!$rejected) throw new RuntimeException('Unsafe external root accepted');
	}
	foreach (array('bootstrap','migrate-bundled','plan') as $command)
		if (externalCli(array($command,'--project-root=' . $target))[0] !== 1) throw new RuntimeException('Unsafe external command accepted');
	[$exit,$out] = externalCli(array('status','--project-root=' . $target));
	if ($exit !== 0 || json_decode($out,true)['application_version'] !== '1.0.3') throw new RuntimeException('Status used engine metadata');
	$service = new UpdateService($db,$config,'fixture.invalid',$target,$settings,$provider);
	$prepared = $service->prepareManual($archive);
	if (file_get_contents($target . '/resources/update-compatibility.json') !== $legacy) throw new RuntimeException('Prepare changed source contract');
	[$exit,$out,$err] = externalCli(array('apply',$prepared['id'],'--project-root=' . $target));
	if ($exit !== 0) throw new RuntimeException('External apply failed: ' . $err);
	$result = json_decode($out,true,32,JSON_THROW_ON_ERROR); $runId = $result['run']['urID'];
	if ($result['run']['urState'] !== 'completed' || $result['run']['urSourceVersion'] !== '1.0.3'
		|| json_decode(file_get_contents($target . '/resources/update-compatibility.json'),true)['format'] !== 2)
		throw new RuntimeException('External lifecycle did not preserve source or install contract');
	if ($state->currentVersion() !== '1.0.1' || $result['prepared']['backup_id'] !== '') throw new RuntimeException('Code-only handoff changed schema/backup policy');
	if ((int)$cache->get('catalog:config:version', static fn(): int => 0, 0) <= $cacheVersion) throw new RuntimeException('External engine did not invalidate the configuration cache');
	echo "External CLI format-1 to format-2 lifecycle and target isolation passed (synthetic catalog, no live signature claim).\n";
}
finally
{
	if ($runId !== '') $db->delete('UpdateRuns','urID=?',array($runId));
	$state->setInstalledApplicationVersion($priorVersion);
	$state->setCurrentVersion($priorSchema);
	foreach (array('APP_RELEASE_VERSION','UPDATE_WORK_PATH','BACKUP_STORAGE_PATH','REDIS_ENABLED','REDIS_HOST','REDIS_PORT') as $name) putenv(isset($environment[$name]) ? $name . '=' . $environment[$name] : $name);
	RuntimeArchiveService::removeTree($temporary);
}
