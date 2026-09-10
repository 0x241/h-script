<?php

use HScript\Backup\BackupService;
use HScript\Security\ProductionPreflight;
use HScript\Update\ConfiguratorCsrf;

require_once('module/dbinit.php');
$backupPreflight = new ProductionPreflight(dirname(__DIR__, 2), hsIsHttpsRequest(), trim((string)(getenv('APP_RELEASE_VERSION') ?: '')) !== '');
$backupPreflightBlockers = $backupPreflight->forOperation('backup');

$backupService = null;
$backupServiceError = '';
try
{
	$backupService = BackupService::fromConfig(
		$db,
		$_cfg,
		(string)($_GS['domain'] ?? 'localhost'),
		dirname(__DIR__, 2)
	);
}
catch (Throwable $exception)
{
	$backupServiceError = $exception->getMessage();
	error_log('Configurator backup initialization failed: ' . $backupServiceError);
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST')
{
	try
	{
		if (!$backupService instanceof BackupService)
			throw new RuntimeException($backupServiceError !== '' ? $backupServiceError : 'Backup service is unavailable');
		$action = (string)($_POST['backupAction'] ?? '');
		if ($action === 'download')
			ConfiguratorCsrf::validate($_POST['csrf'] ?? '');
		else
			ConfiguratorCsrf::consume($_POST['csrf'] ?? '');
		if ($action === 'create')
		{
			$backupPreflight->assertAllows('backup');
			$created = $backupService->create('plain');
			addMsg(cfg_t(
				'Проверенный бэкап создан. ID: ',
				'Verified backup created. ID: '
			) . $created['id']);
			$cfgSecurity->audit('backup_create', 'success', $cfgClientIp, array('id' => $created['id']));
		}
		elseif ($action === 'delete')
		{
			$backupService->delete((string)($_POST['backupId'] ?? ''));
			$cfgSecurity->audit('backup_delete', 'success', $cfgClientIp, array('id' => (string)($_POST['backupId'] ?? '')));
			addMsg(cfg_t('Бэкап удалён.', 'Backup deleted.'));
		}
		elseif ($action === 'download')
		{
			$archive = $backupService->archive((string)($_POST['backupId'] ?? ''));
			$path = $archive['path'];
			$manifest = $archive['manifest'];
			$cfgSecurity->audit('backup_download', 'success', $cfgClientIp, array('id' => (string)($_POST['backupId'] ?? '')));
			header('Content-Type: ' . ($manifest['compression'] === 'gzip' ? 'application/gzip' : 'application/sql'));
			header('Content-Disposition: attachment; filename="' . $manifest['archive'] . '"');
			header('Content-Length: ' . filesize($path));
			header('Cache-Control: no-store');
			header('X-Content-Type-Options: nosniff');
			$stream = fopen($path, 'rb');
			if ($stream === false)
				throw new RuntimeException('Backup archive could not be opened');
			while (!feof($stream))
			{
				$chunk = fread($stream, 65536);
				if ($chunk === false)
				{
					fclose($stream);
					throw new RuntimeException('Backup archive could not be read');
				}
				echo $chunk;
			}
			fclose($stream);
			exit;
		}
		else
			throw new RuntimeException('Unknown backup action');
	}
	catch (Throwable $exception)
	{
		$cfgSecurity->audit('backup_action', 'failed', $cfgClientIp, array('reason' => 'operation'));
		error_log('Configurator backup action failed: ' . $exception->getMessage());
		addMsg(cfg_t('Ошибка бэкапа: ', 'Backup error: ') . $exception->getMessage());
	}
	goToURL($_cfg['cfg_link'] . '?backup');
}

$backupItems = array();
if ($backupService instanceof BackupService)
{
	try
	{
		$backupItems = $backupService->list(50);
	}
	catch (Throwable $exception)
	{
		$backupServiceError = $exception->getMessage();
		error_log('Configurator backup list failed: ' . $backupServiceError);
	}
}
$backupCsrf = ConfiguratorCsrf::token();
$backupStorageExternal = $backupService instanceof BackupService && $backupService->storageLocation() === 'external';
$backupSize = static function (int $bytes): string {
	if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
	if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . ' MB';
	return number_format(max(1, $bytes / 1024), 1) . ' KB';
};

include('module/_config/_header.php');
?>

<section class="mx-auto max-w-5xl space-y-8">
	<header class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
		<div>
			<span class="mb-3 inline-flex items-center gap-2 text-xs font-extrabold uppercase tracking-wider text-violet-600 dark:text-violet-400"><i class="fa-solid fa-box-archive" aria-hidden="true"></i><?php echo cfg_t('База данных', 'Database'); ?></span>
			<h1 class="text-3xl font-black text-brand dark:text-white sm:text-4xl"><?php echo cfg_t('Резервные копии', 'Backups'); ?></h1>
			<p class="mt-2 max-w-2xl text-sm font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('Одна кнопка создаёт потоковый дамп, проверяет его SHA-256, структуру и завершённость.', 'One button creates a streaming dump and verifies its SHA-256, structure, and completion.'); ?></p>
		</div>
		<form method="post" hx-boost="false">
			<input type="hidden" name="csrf" value="<?php echo htmlspecialchars($backupCsrf, ENT_QUOTES, 'UTF-8'); ?>">
			<input type="hidden" name="backupAction" value="create">
			<button type="submit" class="<?php echo $cfgButtonClass; ?>" <?php echo $backupService instanceof BackupService ? '' : 'disabled'; ?>><i class="fa-solid fa-plus" aria-hidden="true"></i><?php echo cfg_t('Создать проверенный бэкап', 'Create verified backup'); ?></button>
		</form>
	</header>
	<?php if ($backupPreflightBlockers) { ?>
		<aside class="rounded-lg border border-red-200 bg-red-50 p-5 text-sm text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-100"><strong class="font-extrabold"><?php echo cfg_t('Бэкап заблокирован предварительной проверкой:', 'Backup is blocked by preflight:'); ?></strong><span class="ml-1"><?php echo htmlspecialchars(implode(', ', array_column($backupPreflightBlockers, 'label')), ENT_QUOTES, 'UTF-8'); ?></span> <a class="font-extrabold underline" href="?security"><?php echo cfg_t('Открыть безопасность', 'Open security'); ?></a></aside>
	<?php } ?>

	<?php if ($backupServiceError !== '') { ?>
		<aside class="rounded-lg border border-red-200 bg-red-50 p-5 text-sm font-bold text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200"><?php echo htmlspecialchars(cfg_t('Бэкап недоступен: ', 'Backup is unavailable: ') . $backupServiceError, ENT_QUOTES, 'UTF-8'); ?></aside>
	<?php } ?>

	<?php if ($backupService instanceof BackupService) { ?><aside class="flex items-start gap-4 rounded-lg border p-5 <?php echo $backupStorageExternal ? 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-100' : 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100'; ?>">
		<i class="fa-solid <?php echo $backupStorageExternal ? 'fa-shield-halved' : 'fa-lock'; ?> mt-0.5" aria-hidden="true"></i>
		<div class="min-w-0 flex-1">
			<strong class="block text-sm font-extrabold"><?php echo $backupStorageExternal ? cfg_t('Хранилище вне document root', 'Storage outside document root') : cfg_t('Защищённое локальное хранилище', 'Protected local storage'); ?></strong>
			<p class="mt-1 text-xs font-medium opacity-80"><?php echo $backupStorageExternal ? cfg_t('Архивы не доступны через веб-сервер.', 'Archives are not reachable through the web server.') : cfg_t('Apache блокирует прямой доступ через backup/.htaccess. Если используется Nginx, добавьте правило ниже в server для сайта.', 'Apache blocks direct access through backup/.htaccess. If you use Nginx, add the rule below to the site server block.'); ?></p>
			<?php if (!$backupStorageExternal) { ?><details class="mt-4 rounded-lg border border-current/15 bg-white/50 p-4 dark:bg-black/10">
				<summary class="cursor-pointer text-xs font-extrabold"><?php echo cfg_t('Показать правило для Nginx', 'Show the Nginx rule'); ?></summary>
				<pre class="mt-3 overflow-x-auto rounded-lg bg-white/70 p-4 text-xs text-brand dark:bg-black/20 dark:text-white"><code>location ^~ /backup/ {
    deny all;
    return 404;
}</code></pre>
			</details><?php } ?>
		</div>
	</aside><?php } ?>

	<section class="overflow-hidden rounded-lg border border-gray-100 bg-white shadow-sm dark:border-gray-800 dark:bg-[#151515]">
		<header class="border-b border-gray-100 px-6 py-4 dark:border-gray-800"><h2 class="text-lg font-extrabold text-brand dark:text-white"><?php echo cfg_t('Готовые бэкапы', 'Available backups'); ?></h2></header>
		<?php if (!$backupItems) { ?>
			<p class="p-8 text-center text-sm font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('Бэкапов пока нет.', 'No backups yet.'); ?></p>
		<?php } else { ?>
			<div class="divide-y divide-gray-100 dark:divide-gray-800">
				<?php foreach ($backupItems as $item) { ?>
					<article class="flex flex-col gap-4 p-5 lg:flex-row lg:items-center lg:justify-between">
						<div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><strong class="text-sm font-extrabold text-brand dark:text-white"><?php echo htmlspecialchars(gmdate('d.m.Y H:i', (int)strtotime($item['created_at'])), ENT_QUOTES, 'UTF-8'); ?> UTC</strong><span class="rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-extrabold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300"><?php echo cfg_t('Проверен', 'Verified'); ?></span></div><p class="mt-2 break-all font-mono text-xs text-gray-400">ID <?php echo htmlspecialchars($item['id'], ENT_QUOTES, 'UTF-8'); ?></p><p class="mt-1 text-xs font-medium text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($backupSize((int)$item['stored_size']) . ' · ' . $item['adapter'] . ' · CMS ' . $item['application_version'] . ' · DB ' . $item['schema_version'] . ' · ' . $item['table_count'] . ' ' . cfg_t('таблиц', 'tables'), ENT_QUOTES, 'UTF-8'); ?></p></div>
						<div class="flex flex-wrap gap-2">
							<form method="post" hx-boost="false"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars($backupCsrf, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="backupAction" value="download"><input type="hidden" name="backupId" value="<?php echo htmlspecialchars($item['id'], ENT_QUOTES, 'UTF-8'); ?>"><button type="submit" class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-gray-200 px-4 text-xs font-extrabold text-brand dark:border-gray-700 dark:text-white"><i class="fa-solid fa-download" aria-hidden="true"></i><?php echo cfg_t('Скачать', 'Download'); ?></button></form>
							<form method="post" hx-boost="false" onsubmit="return confirm('<?php echo cfg_t('Удалить этот бэкап?', 'Delete this backup?'); ?>')"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars($backupCsrf, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="backupAction" value="delete"><input type="hidden" name="backupId" value="<?php echo htmlspecialchars($item['id'], ENT_QUOTES, 'UTF-8'); ?>"><button type="submit" class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-red-200 px-4 text-xs font-extrabold text-red-600 dark:border-red-500/30 dark:text-red-300"><i class="fa-solid fa-trash" aria-hidden="true"></i><?php echo cfg_t('Удалить', 'Delete'); ?></button></form>
						</div>
					</article>
				<?php } ?>
			</div>
		<?php } ?>
	</section>

	<aside id="restore-guide" class="rounded-lg border border-blue-200 bg-blue-50 p-5 text-sm text-blue-900 dark:border-blue-500/30 dark:bg-blue-500/10 dark:text-blue-100">
		<strong class="block font-extrabold"><?php echo cfg_t('Автоматическое восстановление выполняется через CLI', 'Automated restore runs through the CLI'); ?></strong>
		<p class="mt-1 text-xs font-medium opacity-80"><?php echo cfg_t('Браузер никогда не перезаписывает рабочую базу. Сначала создайте отдельную пустую базу, затем выберите команду для своего способа установки.', 'The browser never overwrites the live database. First create a separate empty database, then choose the command for your installation type.'); ?></p>
		<div class="mt-4 grid gap-4 lg:grid-cols-2">
			<section class="rounded-lg bg-white/70 p-4 dark:bg-black/20">
				<strong class="text-xs font-extrabold"><?php echo cfg_t('Обычный сервер без Docker', 'Regular server without Docker'); ?></strong>
				<p class="mt-1 text-xs font-medium opacity-80"><?php echo cfg_t('Запустите из каталога H-Script через установленный PHP CLI.', 'Run from the H-Script directory using the installed PHP CLI.'); ?></p>
				<pre class="mt-3 overflow-x-auto text-xs"><code>RESTORE_DB_PASSWORD='...' APP_DOMAIN=example.com \
php bin/backup.php restore &lt;backup-id&gt; \
  --target-host=127.0.0.1:3306 --target-database=hscript_restore \
  --target-user=hscript_restore --confirm-target=hscript_restore</code></pre>
			</section>
			<section class="rounded-lg bg-white/70 p-4 dark:bg-black/20">
				<strong class="text-xs font-extrabold">Docker Compose</strong>
				<p class="mt-1 text-xs font-medium opacity-80"><?php echo cfg_t('Запустите из каталога, где находится docker-compose.yml.', 'Run from the directory containing docker-compose.yml.'); ?></p>
				<pre class="mt-3 overflow-x-auto text-xs"><code>docker compose exec \
  -e RESTORE_DB_PASSWORD='...' \
  -e APP_DOMAIN=example.com app \
  php bin/backup.php restore &lt;backup-id&gt; \
  --target-host=database:3306 \
  --target-database=hscript_restore \
  --target-user=hscript_restore \
  --confirm-target=hscript_restore</code></pre>
			</section>
		</div>
		<p class="mt-4 text-xs font-medium opacity-80"><?php echo cfg_t('Если хостинг не предоставляет PHP CLI или SSH, скачайте проверенный SQL и импортируйте его средствами панели хостинга только в отдельную пустую базу.', 'If the host provides no PHP CLI or SSH, download the verified SQL and import it with the hosting control panel into a separate empty database only.'); ?></p>
	</aside>
</section>

<?php include('module/_config/_footer.php'); ?>
