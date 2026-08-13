<?php

require_once('module/_config/password.php');

if (isset($_GET['out']))
{
	resetSessionSafely();
	goToURL($_cfg['cfg_link']);
}

if (!$pass)
{
	$_SESSION['cfg_logged'] = 1;
	goToURL($_cfg['cfg_link']);
}
if (isset_IN('bLogin'))
{
	if (cfgPasswordVerify(_IN('pass'), $pass, $_GS['domain']))
	{
		if (cfgPasswordNeedsRehash($pass))
			file_put_contents('module/_config/pass', cfgPasswordHash(_IN('pass')), LOCK_EX);
		startSessionSafely(true);
		$_SESSION['cfg_logged'] = 1;
		goToURL($_cfg['cfg_link']);
	}
	else
		addMsg('Wrong password');
}

include('module/_config/_header.php');

?>

<div class="flex min-h-screen">
	<section class="relative z-10 flex w-full flex-col justify-between bg-white p-6 dark:bg-[#0A0A0A] sm:p-12 lg:w-1/2">
		<div class="mb-10 flex items-center justify-between gap-4">
			<a href="?login" class="group flex items-center gap-3 text-2xl font-bold tracking-tight text-brand dark:text-white">
				<span class="flex h-10 w-10 items-center justify-center rounded-lg border-[2.5px] border-brand bg-transparent text-brand transition-transform group-hover:scale-105 dark:border-white dark:text-white">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5" aria-hidden="true"><path d="M6 3v18"/><path d="M18 3v18"/><path d="M6 12h12"/></svg>
				</span>
				<span class="-ml-1">Script</span>
			</a>
			<div class="flex items-center gap-2">
				<details class="relative" hx-boost="false">
					<summary class="flex h-10 cursor-pointer list-none items-center gap-2 rounded-full border border-black/5 bg-black/[0.03] px-3 text-sm font-extrabold text-brand transition hover:bg-black/[0.06] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-500/20 dark:border-white/10 dark:bg-white/[0.05] dark:text-white dark:hover:bg-white/10">
						<i class="fa-solid fa-globe text-gray-400 dark:text-gray-300" aria-hidden="true"></i><?php echo strtoupper($_SESSION['cfg_lang']); ?>
					</summary>
					<div class="absolute right-0 top-full z-50 mt-2 min-w-32 overflow-hidden rounded-lg border border-gray-100 bg-white p-1 shadow-xl dark:border-gray-800 dark:bg-[#1A1A1A]">
						<?php foreach ($cfgLanguages as $lang) { ?>
							<a href="<?php echo cfg_url('login', array('lang' => $lang)); ?>" hx-boost="false" class="flex items-center justify-between gap-4 rounded-md px-3 py-2 text-sm font-bold text-brand transition hover:bg-gray-50 dark:text-white dark:hover:bg-[#202020]"><span><?php echo strtoupper($lang); ?></span><?php if ($_SESSION['cfg_lang'] === $lang) { ?><i class="fa-solid fa-check text-blue-500" aria-hidden="true"></i><?php } ?></a>
						<?php } ?>
					</div>
				</details>
				<a href="<?php echo cfg_url('login', array('theme' => $cfgNextTheme)); ?>" hx-boost="false" class="flex h-10 w-10 items-center justify-center rounded-full border border-transparent text-brand transition hover:border-black/10 hover:bg-black/5 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-500/20 dark:text-white dark:hover:border-white/10 dark:hover:bg-white/5" aria-label="<?php echo cfg_t('Сменить тему', 'Toggle theme'); ?>">
					<i class="fa-solid fa-moon block text-lg dark:!hidden" aria-hidden="true"></i><i class="fa-solid fa-sun !hidden text-lg text-yellow-400 dark:!block" aria-hidden="true"></i>
				</a>
			</div>
		</div>

		<div class="mx-auto my-auto w-full max-w-md">
			<span class="text-xs font-extrabold uppercase tracking-wider text-blue-600 dark:text-blue-400">H-Script Configurator</span>
			<h1 class="mt-2 text-3xl font-bold text-brand dark:text-white"><?php echo cfg_t('Вход в конфигуратор', 'Configurator sign in'); ?></h1>
			<p class="mb-8 mt-3 font-medium leading-7 text-gray-500 dark:text-gray-400"><?php echo cfg_t('Войдите для управления установкой и системными настройками.', 'Sign in to manage installation and system settings.'); ?></p>
			<form method="post" class="space-y-5">
				<label class="block"><span class="mb-2 block text-sm font-bold text-brand dark:text-gray-200"><?php echo cfg_t('Пароль', 'Password'); ?></span><input name="pass" value="" type="password" autocomplete="current-password" placeholder="••••••••" required autofocus class="<?php echo $cfgInputClass; ?> !rounded-2xl px-5 py-4"></label>
				<button class="<?php echo $cfgButtonClass; ?> w-full !rounded-2xl py-4" name="bLogin" value="1" type="submit"><i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i><?php echo cfg_t('Войти в систему', 'Sign in'); ?></button>
			</form>
		</div>

		<div class="mt-10 text-center text-sm font-medium text-gray-400">&copy; <?php echo date('Y'); ?> H-Script CMS</div>
	</section>

	<aside class="relative hidden w-1/2 flex-col items-center justify-center overflow-hidden bg-brand p-12 lg:flex">
		<div class="relative z-10 max-w-lg text-center">
			<div class="mx-auto mb-8 flex h-20 w-20 items-center justify-center rounded-3xl border border-white/20 bg-white/10 text-white shadow-2xl"><i class="fa-solid fa-shield-halved text-4xl" aria-hidden="true"></i></div>
			<h2 class="mb-6 text-4xl font-bold text-white"><?php echo cfg_t('Системный доступ', 'System access'); ?></h2>
			<p class="text-xl font-medium leading-relaxed text-gray-400"><?php echo cfg_t('Конфигуратор управляет подключением к базе, установкой и обновлениями. Доступ должен быть только у доверенных администраторов.', 'The configurator controls database connectivity, installation and updates. Access should be limited to trusted administrators.'); ?></p>
		</div>
	</aside>
</div>

<?php

include('module/_config/_footer.php');
	
?>
