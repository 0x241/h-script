<?php

require_once('module/_config/password.php');

if (isset_IN('bSave')) 
{

	if ($f = fopen('module/_config/pass', 'w'))
	{
		fputs($f, cfgPasswordHash(_IN('newPass')));
		fclose($f);
		addMsg(cfg_t('Пароль успешно сохранен!', 'Password saved!'));
		goToURL($_cfg['cfg_link'] . '?setup');
	} else
		addMsg("Can't open file for writing");
}

include('module/_config/_header.php');

?>

<section class="mx-auto max-w-3xl space-y-8">
	<header>
		<span class="mb-3 inline-flex items-center gap-2 text-xs font-extrabold uppercase tracking-wider text-amber-600 dark:text-amber-400"><i class="fa-solid fa-key" aria-hidden="true"></i><?php echo cfg_t('Доступ', 'Access'); ?></span>
		<h1 class="text-3xl font-black text-brand dark:text-white sm:text-4xl"><?php echo cfg_t('Смена пароля', 'Change password'); ?></h1>
		<p class="mt-2 text-sm font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('Установите новый пароль для входа в конфигуратор.', 'Set a new password for the configurator area.'); ?></p>
	</header>
	<form method="post" class="overflow-hidden rounded-lg border border-gray-100 bg-white shadow-sm dark:border-gray-800 dark:bg-[#151515]">
		<div class="flex items-center gap-3 border-b border-gray-100 bg-gray-50/70 px-6 py-4 dark:border-gray-800 dark:bg-[#1A1A1A]"><span class="flex h-9 w-9 items-center justify-center rounded-lg bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-300"><i class="fa-solid fa-lock" aria-hidden="true"></i></span><div><h2 class="text-base font-extrabold text-brand dark:text-white"><?php echo cfg_t('Учетные данные', 'Credentials'); ?></h2><p class="text-xs font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('Доступ к системным настройкам', 'System settings access'); ?></p></div></div>
		<label class="block p-6"><span class="mb-2 block text-sm font-extrabold text-brand dark:text-gray-200"><?php echo cfg_t('Новый пароль', 'New password'); ?></span><input name="newPass" value="" type="password" autocomplete="new-password" required class="<?php echo $cfgInputClass; ?>"><small class="mt-2 block text-xs font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('Используйте уникальную длинную парольную фразу.', 'Use a unique, long passphrase.'); ?></small></label>
		<div class="flex justify-center border-t border-gray-100 bg-gray-50/60 p-5 dark:border-gray-800 dark:bg-[#1A1A1A]"><button class="<?php echo $cfgButtonClass; ?>" name="bSave" value="1" type="submit"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i><?php echo cfg_t('Сохранить пароль', 'Save password'); ?></button></div>
	</form>
</section>

<?php

include('module/_config/_footer.php');
	
?>
