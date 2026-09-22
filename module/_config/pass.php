<?php

use HScript\Update\ConfiguratorCsrf;

require_once('module/_config/password.php');

if (isset_IN('bSave')) 
{
	try { ConfiguratorCsrf::consume($_POST['csrf'] ?? ''); }
	catch (Throwable)
	{
		$cfgSecurity->audit('password_change', 'failed', $cfgClientIp, array('reason' => 'csrf'));
		addMsg(cfg_t('configurator.common.the_form_session_expired_try_again'), true);
		goToURL($_cfg['cfg_link'] . '?pass');
	}

	if ($f = fopen('module/_config/pass', 'w'))
	{
		fputs($f, cfgPasswordHash(_IN('newPass')));
		fclose($f);
		startSessionSafely(true);
		$_SESSION['cfg_logged'] = 1;
		$cfgSecurity->audit('password_change', 'success', $cfgClientIp);
		addMsg(cfg_t('configurator.pass.password_saved'));
		goToURL($_cfg['cfg_link'] . '?setup');
	} else
	{
		$cfgSecurity->audit('password_change', 'failed', $cfgClientIp, array('reason' => 'write'));
		addMsg(cfg_t('configurator.common.file_not_writable', array('file' => 'module/_config/pass')), true);
	}
}

include('module/_config/_header.php');

?>

<section class="mx-auto max-w-3xl space-y-8">
	<header>
		<span class="mb-3 inline-flex items-center gap-2 text-xs font-extrabold uppercase tracking-wider text-amber-600 dark:text-amber-400"><i class="fa-solid fa-key" aria-hidden="true"></i><?php echo cfg_t('configurator.common.access'); ?></span>
		<h1 class="text-3xl font-black text-brand dark:text-white sm:text-4xl"><?php echo cfg_t('configurator.common.change_password'); ?></h1>
		<p class="mt-2 text-sm font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('configurator.pass.set_a_new_password_for_the_configurator_area'); ?></p>
	</header>
	<form method="post" class="overflow-hidden rounded-lg border border-gray-100 bg-white shadow-sm dark:border-gray-800 dark:bg-[#151515]">
		<input type="hidden" name="csrf" value="<?php echo htmlspecialchars(ConfiguratorCsrf::token(), ENT_QUOTES, 'UTF-8'); ?>">
		<div class="flex items-center gap-3 border-b border-gray-100 bg-gray-50/70 px-6 py-4 dark:border-gray-800 dark:bg-[#1A1A1A]"><span class="flex h-9 w-9 items-center justify-center rounded-lg bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-300"><i class="fa-solid fa-lock" aria-hidden="true"></i></span><div><h2 class="text-base font-extrabold text-brand dark:text-white"><?php echo cfg_t('configurator.pass.credentials'); ?></h2><p class="text-xs font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('configurator.pass.system_settings_access'); ?></p></div></div>
		<label class="block p-6"><span class="mb-2 block text-sm font-extrabold text-brand dark:text-gray-200"><?php echo cfg_t('configurator.pass.new_password'); ?></span><input name="newPass" value="" type="password" autocomplete="new-password" required class="<?php echo $cfgInputClass; ?>"><small class="mt-2 block text-xs font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('configurator.pass.use_a_unique_long_passphrase'); ?></small></label>
		<div class="flex justify-center border-t border-gray-100 bg-gray-50/60 p-5 dark:border-gray-800 dark:bg-[#1A1A1A]"><button class="<?php echo $cfgButtonClass; ?>" name="bSave" value="1" type="submit"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i><?php echo cfg_t('configurator.pass.save_password'); ?></button></div>
	</form>
</section>

<?php

include('module/_config/_footer.php');
	
?>
