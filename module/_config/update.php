<?php

if (!isset_IN('bUpdate'))
{
	require_once('module/_config/database_state.php');
	$currentDbVersion = cfg_installed_database_version($_cfg, (string)($_GS['domain'] ?? ''));
	clearstatcache(true, '_dbstru.php');
	$targetDbVersion = is_file('_dbstru.php') ? intval(filemtime('_dbstru.php')) : null;
	include('module/_config/_header.php');
	?>
	<section class="mx-auto max-w-3xl space-y-8">
		<header>
			<span class="mb-3 inline-flex items-center gap-2 text-xs font-extrabold uppercase tracking-wider text-emerald-600 dark:text-emerald-400"><i class="fa-solid fa-database" aria-hidden="true"></i><?php echo cfg_t('Обслуживание', 'Maintenance'); ?></span>
			<h1 class="text-3xl font-black text-brand dark:text-white sm:text-4xl"><?php echo cfg_t('Обновление базы данных', 'Database update'); ?></h1>
			<p class="mt-2 text-sm font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('Сверка текущих таблиц со структурой поставки без удаления пользовательских записей.', 'Reconcile current tables with the bundled structure without deleting user records.'); ?></p>
		</header>

		<div class="grid gap-4 sm:grid-cols-2">
			<div class="rounded-lg border border-gray-100 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-[#151515]"><span class="text-xs font-extrabold uppercase tracking-wider text-gray-400"><?php echo cfg_t('Текущая версия', 'Current version'); ?></span><strong class="mt-2 block text-lg text-brand dark:text-white"><?php echo $currentDbVersion ? date('d.m.Y H:i', $currentDbVersion) : '—'; ?></strong><?php if ($currentDbVersion) { ?><small class="mt-1 block font-mono text-xs text-gray-400">ID <?php echo $currentDbVersion; ?></small><?php } ?></div>
			<div class="rounded-lg border border-gray-100 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-[#151515]"><span class="text-xs font-extrabold uppercase tracking-wider text-gray-400"><?php echo cfg_t('Целевая версия', 'Target version'); ?></span><strong class="mt-2 block text-lg text-brand dark:text-white"><?php echo $targetDbVersion ? date('d.m.Y H:i', $targetDbVersion) : '—'; ?></strong><?php if ($targetDbVersion) { ?><small class="mt-1 block font-mono text-xs text-gray-400">ID <?php echo $targetDbVersion; ?></small><?php } ?></div>
		</div>

		<form method="post" class="overflow-hidden rounded-lg border border-gray-100 bg-white shadow-sm dark:border-gray-800 dark:bg-[#151515]">
			<div class="flex items-start gap-4 border-b border-amber-200 bg-amber-50 p-5 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
				<span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-amber-600 dark:bg-amber-500/20 dark:text-amber-300"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i></span>
				<div class="min-w-0 flex-1">
					<strong class="block text-sm font-extrabold"><?php echo cfg_t('Сделайте резервную копию', 'Create a backup'); ?></strong>
					<p class="mt-1 text-xs font-medium opacity-80"><?php echo cfg_t('Перед обновлением структуры создайте проверенный дамп production-базы. Для Docker Compose выполните команду из каталога проекта:', 'Create and verify a production database dump before updating the structure. For Docker Compose, run this command from the project directory:'); ?></p>
					<div class="mt-4 max-w-full overflow-x-auto rounded-lg border border-amber-200 bg-white/70 p-4 text-xs text-brand dark:border-amber-500/30 dark:bg-black/20 dark:text-amber-100"><pre><code>mkdir -p backup
docker compose exec -T database sh -c \
  'if [ -n &quot;${MYSQL_PASSWORD_FILE:-}&quot; ]; then MYSQL_PWD=&quot;$(cat &quot;$MYSQL_PASSWORD_FILE&quot;)&quot;; else MYSQL_PWD=&quot;$MYSQL_PASSWORD&quot;; fi; export MYSQL_PWD; exec mysqldump --single-transaction --routines --triggers --events -u&quot;$MYSQL_USER&quot; &quot;$MYSQL_DATABASE&quot;' \
  &gt; &quot;backup/h-script-$(date +%Y%m%d-%H%M%S).sql&quot;</code></pre></div>
					<p class="mt-3 text-xs font-medium opacity-80"><?php echo cfg_t('На VPS или shared-хостинге создайте полный SQL-дамп средствами панели управления либо mysqldump.', 'On a VPS or shared host, create a full SQL dump with the hosting control panel or mysqldump.'); ?></p>
				</div>
			</div>
			<label class="flex cursor-pointer items-center gap-3 p-6 text-sm font-bold text-brand dark:text-white"><input name="confirmUpdate" value="1" type="checkbox" required class="h-5 w-5 rounded border-gray-300 text-blue-600 focus:outline-none focus:ring-0 focus:ring-offset-0 dark:border-gray-700"><span><?php echo cfg_t('Резервная копия создана, начать обновление', 'A backup exists; start the update'); ?></span></label>
			<div class="flex justify-center border-t border-gray-100 bg-gray-50/60 p-5 dark:border-gray-800 dark:bg-[#1A1A1A]"><button name="bUpdate" value="1" type="submit" class="<?php echo $cfgButtonClass; ?>"><i class="fa-solid fa-rotate" aria-hidden="true"></i><?php echo cfg_t('Обновить базу данных', 'Update database'); ?></button></div>
		</form>
	</section>
	<?php
	include('module/_config/_footer.php');
	return;
}

if (!isset_IN('confirmUpdate'))
{
	addMsg(cfg_t('Подтвердите наличие резервной копии.', 'Confirm that a backup exists.'));
	goToURL($_cfg['cfg_link'] . '?update');
}

if (!file_exists('_dbstru.php'))
	addMsg('Database structure "_dbstru.php" required');
else
{

require_once('module/dbinit.php');

require('_dbstru.php');

try
{
	$dbq = $db->query('SHOW TABLES');
	$tables = $db->fetchRows($dbq);
	$existingTables = array();
	foreach ($tables as $table)
		$existingTables[] = strtolower((string)reset($table));

	$dbType = (int)($_cfg['db_type'] ?? 0);
	$engine = $dbType === 2 ? 'MyISAM' : 'InnoDB';
	foreach ($_dbstru as $tableName => $definition)
	{
		if (!preg_match('/^[A-Za-z0-9_]+$/', $tableName))
			throw new RuntimeException('Invalid database table name');

		$newTableName = '_hs_new_' . $tableName;
		$oldTableName = '_hs_old_' . $tableName;
		$table = $db->field($tableName);
		$newTable = $db->field($newTableName);
		$oldTable = $db->field($oldTableName);

		// Build and populate the replacement before touching the live table.
		// This keeps the original table available if CREATE or INSERT fails.
		$db->query("DROP TABLE IF EXISTS $newTable");
		$db->query(
			"CREATE TABLE $newTable ($definition) ENGINE=$engine "
			. 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
		);

		if (in_array(strtolower($tableName), $existingTables, true))
		{
			$oldFields = $db->fetchRows($db->query("SHOW FIELDS FROM $table"), 'Field');
			$newFields = $db->fetchRows($db->query("SHOW FIELDS FROM $newTable"), 'Field');
			$commonFields = array_values(array_intersect($newFields, $oldFields));
			if (!$commonFields && $oldFields)
				throw new RuntimeException('No compatible fields for table ' . $tableName);

			if ($commonFields)
			{
				$fields = $db->field($commonFields);
				$db->query("INSERT INTO $newTable ($fields) SELECT $fields FROM $table");
			}

			$db->query("DROP TABLE IF EXISTS $oldTable");
			$db->query("RENAME TABLE $table TO $oldTable, $newTable TO $table");
			$db->query("DROP TABLE $oldTable");
		}
		else
			$db->query("RENAME TABLE $newTable TO $table");
	}

	clearstatcache();
	// Remove the retired social-auth switch from upgraded installations.
	$db->delete('Cfg', 'Module=? and Prop=?', array('Account', 'Loginza'));
	$db->replace('Cfg',
		array(
			'Module' => 'Const',
			'Prop' => 'DBVer',
			'Val' => is_file('_dbstru.php') ? intval(filemtime('_dbstru.php')) : 0
		),
		'', 'Module=? and Prop=?', array('Const', 'DBVer')
	);
	addMsg(cfg_t('Обновление завершено!', 'Updating complete!'));
}
catch (Throwable $exception)
{
	if (isset($newTable))
	{
		try
		{
			$db->query("DROP TABLE IF EXISTS $newTable");
		}
		catch (Throwable)
		{
		}
	}
	error_log('Configurator database update failed: ' . $exception->getMessage());
	addMsg(cfg_t(
		'Обновление остановлено из-за ошибки. Исходная таблица сохранена; проверьте журнал сервера.',
		'The update stopped because of an error. The original table was preserved; check the server log.'
	));
	goToURL($_cfg['cfg_link'] . '?update');
}
}

goToURL($_cfg['cfg_link'] . '?modules');

?>
