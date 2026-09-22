<?php

use HScript\Mail\Mailer;
use HScript\Update\ConfiguratorCsrf;

if (isset_IN('bSave')) {
	$setupDatabaseIsEmpty = false;
	try { ConfiguratorCsrf::consume($_POST['csrf'] ?? ''); }
	catch (Throwable)
	{
		$cfgSecurity->audit('connection_save', 'failed', $cfgClientIp, array('reason' => 'csrf'));
		addMsg(cfg_t('configurator.common.the_form_session_expired_try_again'), true);
		goToURL($_cfg['cfg_link'] . '?setup');
	}

	function chkwr($n)
	{
		if (file_exists($n) and !is_writeable($n))
			addMsg(cfg_t('configurator.common.file_not_writable', array('file' => $n)), true);
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
		addMsg(cfg_t('configurator.setup.configuration_saved'));

		if (Mailer::sendNow(_IN('sysMail'), cfg_t('configurator.setup.test_mail_subject'), cfg_t('configurator.setup.test_mail_body', array('url' => $_GS['root_url']))))
			addMsg(cfg_t('configurator.setup.test_mail_sent', array('email' => _IN('sysMail'))));
		else
			addMsg(cfg_t('configurator.setup.test_mail_failed', array('email' => _IN('sysMail'))), true);

		if (is_file('tpl_c/nt_db') && !unlink('tpl_c/nt_db'))
			addMsg(cfg_t('configurator.common.file_not_removed', array('file' => 'tpl_c/nt_db')), true);

		require_once('module/dbinit.php');
		$setupDatabaseIsEmpty = count($db->fetchRows($db->query('SHOW FULL TABLES'))) === 0;
		$cfgSecurity->audit('connection_save', 'success', $cfgClientIp);

			addMsg(cfg_t('configurator.setup.config_permissions_reminder'));
	}
	else
	{
		$cfgSecurity->audit('connection_save', 'failed', $cfgClientIp, array('reason' => 'write'));
		addMsg(cfg_t('configurator.common.file_not_writable', array('file' => $fn)), true);
	}

	goToURL($_cfg['cfg_link'] . ($setupDatabaseIsEmpty ? '?install' : '?modules'));

}

include('module/_config/_header.php');

?>

<section class="space-y-8">
	<header>
		<span class="mb-3 inline-flex items-center gap-2 text-xs font-extrabold uppercase tracking-wider text-blue-600 dark:text-blue-400"><i class="fa-solid fa-sliders" aria-hidden="true"></i><?php echo cfg_t('configurator.setup.environment'); ?></span>
		<h1 class="text-3xl font-black text-brand dark:text-white sm:text-4xl"><?php echo cfg_t('configurator.setup.connection_setup'); ?></h1>
		<p class="mt-2 max-w-3xl text-sm font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('configurator.setup.system_mail_private_configurator_path_and_database_connection_parameters'); ?></p>
	</header>

	<form method="post" class="space-y-6">
		<input type="hidden" name="csrf" value="<?php echo htmlspecialchars(ConfiguratorCsrf::token(), ENT_QUOTES, 'UTF-8'); ?>">
		<section class="overflow-hidden rounded-lg border border-gray-100 bg-white shadow-sm dark:border-gray-800 dark:bg-[#151515]">
			<header class="flex items-center gap-3 border-b border-gray-100 bg-gray-50/70 px-6 py-4 dark:border-gray-800 dark:bg-[#1A1A1A]">
				<span class="flex h-9 w-9 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-500/10 dark:text-blue-300"><i class="fa-solid fa-gears" aria-hidden="true"></i></span>
				<div><h2 class="text-base font-extrabold text-brand dark:text-white"><?php echo cfg_t('configurator.setup.system_parameters'); ?></h2><p class="text-xs font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('configurator.setup.contacts_and_configurator_route'); ?></p></div>
			</header>
			<div class="grid gap-6 p-6 md:grid-cols-2">
				<label class="block">
					<span class="mb-2 block text-sm font-extrabold text-brand dark:text-gray-200"><?php echo cfg_t('configurator.setup.notification_e_mail'); ?></span>
					<input name="sysMail" value="<?php echo htmlspecialchars(isset($_cfg['sys_mail']) ? $_cfg['sys_mail'] : ''); ?>" type="email" class="<?php echo $cfgInputClass; ?>">
					<small class="mt-2 block text-xs font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('configurator.setup.system_and_technical_notifications'); ?></small>
				</label>
				<label class="block">
					<span class="mb-2 block text-sm font-extrabold text-brand dark:text-gray-200"><?php echo cfg_t('configurator.setup.configurator_route'); ?></span>
					<input name="cfgLink" value="<?php echo htmlspecialchars(!empty($_cfg['cfg_link']) ? $_cfg['cfg_link'] : '_cfg'); ?>" type="text" class="<?php echo $cfgInputClass; ?>">
					<small class="mt-2 block text-xs font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('configurator.setup.private_path_without_a_leading_slash'); ?></small>
				</label>
			</div>
		</section>

		<section class="overflow-hidden rounded-lg border border-gray-100 bg-white shadow-sm dark:border-gray-800 dark:bg-[#151515]">
			<header class="flex items-center gap-3 border-b border-gray-100 bg-gray-50/70 px-6 py-4 dark:border-gray-800 dark:bg-[#1A1A1A]">
				<span class="flex h-9 w-9 items-center justify-center rounded-lg bg-violet-50 text-violet-600 dark:bg-violet-500/10 dark:text-violet-300"><i class="fa-solid fa-database" aria-hidden="true"></i></span>
				<div><h2 class="text-base font-extrabold text-brand dark:text-white"><?php echo cfg_t('configurator.common.database'); ?></h2><p class="text-xs font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('configurator.setup.mysql_mariadb_connection'); ?></p></div>
			</header>
			<div class="grid gap-6 p-6 md:grid-cols-2">
				<label class="block"><span class="mb-2 block text-sm font-extrabold text-brand dark:text-gray-200"><?php echo cfg_t('configurator.setup.host'); ?></span><input name="dbHost" value="<?php echo htmlspecialchars(!empty($_cfg['db_host']) ? $_cfg['db_host'] : 'localhost'); ?>" type="text" class="<?php echo $cfgInputClass; ?>"></label>
				<label class="block"><span class="mb-2 block text-sm font-extrabold text-brand dark:text-gray-200"><?php echo cfg_t('configurator.setup.database_name'); ?></span><input name="dbName" value="<?php echo htmlspecialchars(isset($_cfg['db_name']) ? $_cfg['db_name'] : ''); ?>" type="text" class="<?php echo $cfgInputClass; ?>"></label>
				<label class="block"><span class="mb-2 block text-sm font-extrabold text-brand dark:text-gray-200"><?php echo cfg_t('configurator.setup.user'); ?></span><input name="dbLogin" value="" type="text" autocomplete="username" class="<?php echo $cfgInputClass; ?>"><small class="mt-2 block text-xs font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('configurator.setup.enter_again_before_saving'); ?></small></label>
				<label class="block"><span class="mb-2 block text-sm font-extrabold text-brand dark:text-gray-200"><?php echo cfg_t('configurator.common.password'); ?></span><input name="dbPass" value="" type="password" autocomplete="new-password" class="<?php echo $cfgInputClass; ?>"><small class="mt-2 block text-xs font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('configurator.setup.the_stored_password_is_never_displayed'); ?></small></label>
				<label class="block md:col-span-2"><span class="mb-2 block text-sm font-extrabold text-brand dark:text-gray-200"><?php echo cfg_t('configurator.setup.storage_engine'); ?></span><select name="dbType" class="<?php echo $cfgInputClass; ?>"><option value="0"<?php if (empty($_cfg['db_type'])) echo ' selected'; ?>><?php echo cfg_t('configurator.setup.default'); ?></option><option value="1"<?php if (isset($_cfg['db_type']) && intval($_cfg['db_type']) === 1) echo ' selected'; ?>>InnoDB</option><option value="2"<?php if (isset($_cfg['db_type']) && intval($_cfg['db_type']) === 2) echo ' selected'; ?>>MyISAM</option></select></label>
			</div>
		</section>

		<div class="flex justify-center rounded-lg border border-gray-100 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-[#151515]">
			<button class="<?php echo $cfgButtonClass; ?>" name="bSave" value="1" type="submit"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i><?php echo cfg_t('configurator.setup.save_configuration'); ?></button>
		</div>
	</form>
</section>

<?php

include('module/_config/_footer.php');

?>
