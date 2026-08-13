<?php

use HScript\Mail\Mailer;

if (isset_IN('bSave')) {

	function chkwr($n) 
	{
		if (file_exists($n) and !is_writeable($n))
			addMsg('Please set 777 permissions for "' . $n . '"');
	}
	chkwr('logs');
	chkwr('module');
	chkwr('tpl_c');
	chkwr('_config.php');

	$sysID = substr(md5(uniqid()), -8);
	$k = md5($_GS['domain'] . $sysID);

		$fn = '_config.php';
	if ($f = fopen($fn, 'w'))
	{
		fputs($f,
"<?php

\$_cfg = array(
	'sys_id' => '" . $sysID . "',
	'sys_mail' => '" . addslashes(_IN('sysMail')) . "',
	'cfg_link' => '" . addslashes(_IN('cfgLink')) . "',
	'db_host' => '" . addslashes(_IN('dbHost')) . "',
	'db_name' => '" . addslashes(_IN('dbName')) . "',
	'db_login' => '" . addslashes(encode1(_IN('dbLogin'), $k, false, 1)) . "',
	'db_pass' => '" . addslashes(encode1(_IN('dbPass'), $k, false, 2)) . "',
	'db_type' => '" . addslashes(_IN('dbType')) . "',
	'demo_mode' => '" . ((!empty($_cfg['demo_mode']) || file_exists('tpl_c/demo')) ? 1 : 0) . "'
);"
		);
		fclose($f);
		require($fn);
		addMsg(cfg_t('Настройки успешно сохранены!', 'Configuration saved!'));
		
		if (Mailer::sendNow(_IN('sysMail'), 'Test mail', 'This is a test mail from ' . $_GS['root_url']))
			addMsg('Test mail sended to "' . _IN('sysMail') . '"');
		else
			addMsg('Can\'t send test mail to "' . _IN('sysMail') .'"');
		
		if (is_file('tpl_c/nt_db') && !unlink('tpl_c/nt_db'))
			addMsg('Can\'t remove "tpl_c/nt_db"');

		require_once('module/dbinit.php');
		
			addMsg('Please do not forget to make "_config.php" writable only during configuration');
	} 
	else
		addMsg("Can't open \"$fn\" for writing");
		
	goToURL($_cfg['cfg_link'] . '?install');
	
}

include('module/_config/_header.php');

?>

<section class="space-y-8">
	<header>
		<span class="mb-3 inline-flex items-center gap-2 text-xs font-extrabold uppercase tracking-wider text-blue-600 dark:text-blue-400"><i class="fa-solid fa-sliders" aria-hidden="true"></i><?php echo cfg_t('Окружение', 'Environment'); ?></span>
		<h1 class="text-3xl font-black text-brand dark:text-white sm:text-4xl"><?php echo cfg_t('Настройки подключения', 'Connection setup'); ?></h1>
		<p class="mt-2 max-w-3xl text-sm font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('Системная почта, приватный путь конфигуратора и параметры подключения к базе данных.', 'System mail, private configurator path and database connection parameters.'); ?></p>
	</header>

	<form method="post" class="space-y-6">
		<section class="overflow-hidden rounded-lg border border-gray-100 bg-white shadow-sm dark:border-gray-800 dark:bg-[#151515]">
			<header class="flex items-center gap-3 border-b border-gray-100 bg-gray-50/70 px-6 py-4 dark:border-gray-800 dark:bg-[#1A1A1A]">
				<span class="flex h-9 w-9 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-500/10 dark:text-blue-300"><i class="fa-solid fa-gears" aria-hidden="true"></i></span>
				<div><h2 class="text-base font-extrabold text-brand dark:text-white"><?php echo cfg_t('Системные параметры', 'System parameters'); ?></h2><p class="text-xs font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('Контакты и адрес конфигуратора', 'Contacts and configurator route'); ?></p></div>
			</header>
			<div class="grid gap-6 p-6 md:grid-cols-2">
				<label class="block">
					<span class="mb-2 block text-sm font-extrabold text-brand dark:text-gray-200"><?php echo cfg_t('E-mail центра оповещения', 'Notification e-mail'); ?></span>
					<input name="sysMail" value="<?php echo htmlspecialchars(isset($_cfg['sys_mail']) ? $_cfg['sys_mail'] : ''); ?>" type="email" class="<?php echo $cfgInputClass; ?>">
					<small class="mt-2 block text-xs font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('Системные и технические уведомления.', 'System and technical notifications.'); ?></small>
				</label>
				<label class="block">
					<span class="mb-2 block text-sm font-extrabold text-brand dark:text-gray-200"><?php echo cfg_t('Ссылка на конфигуратор', 'Configurator route'); ?></span>
					<input name="cfgLink" value="<?php echo htmlspecialchars(!empty($_cfg['cfg_link']) ? $_cfg['cfg_link'] : '_cfg'); ?>" type="text" class="<?php echo $cfgInputClass; ?>">
					<small class="mt-2 block text-xs font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('Приватный путь без начального слеша.', 'Private path without a leading slash.'); ?></small>
				</label>
			</div>
		</section>

		<section class="overflow-hidden rounded-lg border border-gray-100 bg-white shadow-sm dark:border-gray-800 dark:bg-[#151515]">
			<header class="flex items-center gap-3 border-b border-gray-100 bg-gray-50/70 px-6 py-4 dark:border-gray-800 dark:bg-[#1A1A1A]">
				<span class="flex h-9 w-9 items-center justify-center rounded-lg bg-violet-50 text-violet-600 dark:bg-violet-500/10 dark:text-violet-300"><i class="fa-solid fa-database" aria-hidden="true"></i></span>
				<div><h2 class="text-base font-extrabold text-brand dark:text-white"><?php echo cfg_t('База данных', 'Database'); ?></h2><p class="text-xs font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('MySQL / MariaDB подключение', 'MySQL / MariaDB connection'); ?></p></div>
			</header>
			<div class="grid gap-6 p-6 md:grid-cols-2">
				<label class="block"><span class="mb-2 block text-sm font-extrabold text-brand dark:text-gray-200"><?php echo cfg_t('Хост', 'Host'); ?></span><input name="dbHost" value="<?php echo htmlspecialchars(!empty($_cfg['db_host']) ? $_cfg['db_host'] : 'localhost'); ?>" type="text" class="<?php echo $cfgInputClass; ?>"></label>
				<label class="block"><span class="mb-2 block text-sm font-extrabold text-brand dark:text-gray-200"><?php echo cfg_t('Имя базы данных', 'Database name'); ?></span><input name="dbName" value="<?php echo htmlspecialchars(isset($_cfg['db_name']) ? $_cfg['db_name'] : ''); ?>" type="text" class="<?php echo $cfgInputClass; ?>"></label>
				<label class="block"><span class="mb-2 block text-sm font-extrabold text-brand dark:text-gray-200"><?php echo cfg_t('Пользователь', 'User'); ?></span><input name="dbLogin" value="" type="text" autocomplete="username" class="<?php echo $cfgInputClass; ?>"><small class="mt-2 block text-xs font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('Введите заново для сохранения.', 'Enter again before saving.'); ?></small></label>
				<label class="block"><span class="mb-2 block text-sm font-extrabold text-brand dark:text-gray-200"><?php echo cfg_t('Пароль', 'Password'); ?></span><input name="dbPass" value="" type="password" autocomplete="new-password" class="<?php echo $cfgInputClass; ?>"><small class="mt-2 block text-xs font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('Пароль не выводится из конфигурации.', 'The stored password is never displayed.'); ?></small></label>
				<label class="block md:col-span-2"><span class="mb-2 block text-sm font-extrabold text-brand dark:text-gray-200"><?php echo cfg_t('Тип хранилища', 'Storage engine'); ?></span><select name="dbType" class="<?php echo $cfgInputClass; ?>"><option value="0"<?php if (empty($_cfg['db_type'])) echo ' selected'; ?>><?php echo cfg_t('По умолчанию', 'Default'); ?></option><option value="1"<?php if (isset($_cfg['db_type']) && intval($_cfg['db_type']) === 1) echo ' selected'; ?>>InnoDB</option><option value="2"<?php if (isset($_cfg['db_type']) && intval($_cfg['db_type']) === 2) echo ' selected'; ?>>MyISAM</option></select></label>
			</div>
		</section>

		<div class="flex justify-center rounded-lg border border-gray-100 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-[#151515]">
			<button class="<?php echo $cfgButtonClass; ?>" name="bSave" value="1" type="submit"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i><?php echo cfg_t('Сохранить настройки', 'Save configuration'); ?></button>
		</div>
	</form>
</section>

<?php

include('module/_config/_footer.php');
	
?>
