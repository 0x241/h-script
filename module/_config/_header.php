<?php

$cfgActive = 'modules';
foreach (array('update', 'install', 'setup', 'pass', 'login') as $cfgItem)
{
	if (isset($_GET[$cfgItem]))
	{
		$cfgActive = $cfgItem;
		break;
	}
}

if (!function_exists('cfg_url'))
{
	function cfg_url($section, $params = array())
	{
		$url = '?' . rawurlencode($section);
		foreach ($params as $key => $value)
			$url .= '&' . rawurlencode($key) . '=' . rawurlencode($value);
		return $url;
	}
}

$cfgSectionTitles = array(
	'modules' => cfg_t('Модули', 'Modules'),
	'setup' => cfg_t('Настройки', 'Setup'),
	'install' => cfg_t('Установка', 'Install'),
	'update' => cfg_t('Обновление', 'Update'),
	'pass' => cfg_t('Смена пароля', 'Change password'),
	'login' => cfg_t('Вход', 'Sign in')
);
$cfgPageTitle = isset($cfgSectionTitles[$cfgActive]) ? $cfgSectionTitles[$cfgActive] : cfg_t('Конфигуратор', 'Configurator');
$cfgTheme = isset($_SESSION['cfg_theme']) && $_SESSION['cfg_theme'] === 'light' ? 'light' : 'dark';
$cfgNextTheme = $cfgTheme === 'dark' ? 'light' : 'dark';
$cfgLogged = !empty($_SESSION['cfg_logged']);
$cfgInputClass = 'w-full rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 font-semibold text-brand outline-none transition placeholder:text-gray-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 dark:border-gray-800 dark:bg-[#1A1A1A] dark:text-white dark:placeholder:text-gray-500 dark:focus:border-blue-500 dark:focus:ring-blue-500/20';
$cfgButtonClass = 'inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-brand px-6 py-3 text-sm font-extrabold text-white shadow-lg transition hover:-translate-y-0.5 hover:bg-black focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-500/20 dark:bg-white dark:text-brand dark:hover:bg-gray-100';

$cfgAllLangs = array('ru', 'en');
if (!empty($_cfg['_Langs']))
	$cfgAllLangs = is_array($_cfg['_Langs']) ? $_cfg['_Langs'] : explode("\n", str_replace("\r", '', $_cfg['_Langs']));
elseif (!empty($_cfg['UI__Langs']))
	$cfgAllLangs = is_array($_cfg['UI__Langs']) ? $_cfg['UI__Langs'] : explode("\n", str_replace("\r", '', $_cfg['UI__Langs']));
else
{
	$jsonLangs = array();
	foreach ((array)glob('lang/*.json') as $file)
	{
		$lang = basename($file, '.json');
		if ($lang)
			$jsonLangs[] = $lang;
	}
	if ($jsonLangs)
		$cfgAllLangs = $jsonLangs;
}
$cfgLanguages = array();
foreach ($cfgAllLangs as $lang)
{
	$lang = trim($lang);
	if ($lang && !in_array($lang, $cfgLanguages, true))
		$cfgLanguages[] = $lang;
}

$cfgMessages = array();
$cfgMessageIsError = false;
if ($cfgMessage = getMsg())
{
	unset($_SESSION['cfg_info_message']);
	$cfgMessages = preg_split('/<br\s*\/?\s*>/i', trim($cfgMessage));
	$cfgMessages = array_values(array_filter(array_map('trim', $cfgMessages)));
	$cfgMessageText = strtolower(implode(' ', $cfgMessages));
	$cfgMessageIsError = strpos($cfgMessageText, 'wrong') !== false
		|| strpos($cfgMessageText, "can't") !== false
		|| strpos($cfgMessageText, 'required') !== false
		|| strpos($cfgMessageText, 'error') !== false;
}

?>
<!doctype html>
<html lang="<?php echo htmlspecialchars($_SESSION['cfg_lang']); ?>" class="<?php echo $cfgTheme === 'dark' ? 'dark' : ''; ?>" data-theme="<?php echo $cfgTheme; ?>">
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<title><?php echo htmlspecialchars($cfgPageTitle); ?> | H-Script</title>
		<link rel="icon" type="image/svg+xml" href="favicon.svg">
		<link rel="preconnect" href="https://fonts.googleapis.com">
		<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
		<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
		<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
		<link rel="stylesheet" type="text/css" href="static/css/app.css?v=tailwind-20260813-ui-15">
		<script src="static/js/htmx.min.js"></script>
	</head>
	<body class="min-h-screen bg-none bg-[#F7F7F5] text-brand antialiased dark:bg-[#0A0A0A] dark:text-gray-100" hx-boost="true">
		<?php if ($cfgLogged) { ?>
			<input id="cfg-sidebar-toggle" type="checkbox" class="peer sr-only">
			<label for="cfg-sidebar-toggle" class="fixed inset-0 z-40 hidden cursor-pointer bg-black/40 backdrop-blur-sm peer-checked:block lg:hidden" aria-label="<?php echo cfg_t('Закрыть меню', 'Close menu'); ?>"></label>
			<div class="flex min-h-screen peer-checked:[&_#cfg-sidebar]:translate-x-0">
				<aside id="cfg-sidebar" class="fixed inset-y-0 left-0 z-50 flex w-72 -translate-x-full flex-col border-r border-gray-200 bg-white transition-transform dark:border-gray-800 dark:bg-[#111111] lg:sticky lg:top-0 lg:h-screen lg:translate-x-0">
					<div class="flex h-20 items-center border-b border-gray-100 px-6 dark:border-gray-800">
						<a href="?modules" class="group flex min-w-0 items-center gap-3 text-2xl font-bold tracking-tight text-brand dark:text-white">
							<span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border-[2.5px] border-brand bg-transparent text-brand transition-transform group-hover:scale-105 dark:border-white dark:text-white">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5" aria-hidden="true"><path d="M6 3v18"/><path d="M18 3v18"/><path d="M6 12h12"/></svg>
							</span>
							<span class="-ml-1">Script</span>
						</a>
					</div>

					<nav class="flex-1 overflow-y-auto p-4" aria-label="<?php echo cfg_t('Разделы конфигуратора', 'Configurator sections'); ?>">
						<p class="mb-2 px-3 text-[11px] font-extrabold uppercase tracking-wider text-gray-400"><?php echo cfg_t('Система', 'System'); ?></p>
						<div class="space-y-1">
							<?php
							$cfgNav = array(
								'modules' => array('fa-boxes-stacked', cfg_t('Модули', 'Modules')),
								'setup' => array('fa-sliders', cfg_t('Подключение', 'Connection')),
								'install' => array('fa-wand-magic-sparkles', cfg_t('Установка', 'Install')),
								'update' => array('fa-database', cfg_t('Обновление БД', 'Database update'))
							);
							foreach ($cfgNav as $section => $item) {
								$isActive = $cfgActive === $section;
							?>
								<a href="?<?php echo $section; ?>" class="flex items-center gap-3 rounded-lg px-3 py-3 text-sm font-bold transition <?php echo $isActive ? 'bg-brand text-white shadow-sm dark:bg-white dark:text-brand' : 'text-gray-500 hover:bg-gray-100 hover:text-brand dark:text-gray-400 dark:hover:bg-[#1A1A1A] dark:hover:text-white'; ?>">
									<i class="fa-solid <?php echo $item[0]; ?> w-5 text-center" aria-hidden="true"></i>
									<span><?php echo $item[1]; ?></span>
								</a>
							<?php } ?>
						</div>

						<p class="mb-2 mt-7 px-3 text-[11px] font-extrabold uppercase tracking-wider text-gray-400"><?php echo cfg_t('Доступ', 'Access'); ?></p>
						<div class="space-y-1">
							<a href="?pass" class="flex items-center gap-3 rounded-lg px-3 py-3 text-sm font-bold transition <?php echo $cfgActive === 'pass' ? 'bg-brand text-white shadow-sm dark:bg-white dark:text-brand' : 'text-gray-500 hover:bg-gray-100 hover:text-brand dark:text-gray-400 dark:hover:bg-[#1A1A1A] dark:hover:text-white'; ?>">
								<i class="fa-solid fa-key w-5 text-center" aria-hidden="true"></i>
								<span><?php echo cfg_t('Сменить пароль', 'Change password'); ?></span>
							</a>
						</div>
					</nav>

					<div class="border-t border-gray-100 p-4 dark:border-gray-800">
						<a href="?login&out" hx-boost="false" class="flex items-center gap-3 rounded-lg px-3 py-3 text-sm font-bold text-red-500 transition hover:bg-red-50 dark:hover:bg-red-500/10">
							<i class="fa-solid fa-right-from-bracket w-5 text-center" aria-hidden="true"></i>
							<span><?php echo cfg_t('Выйти', 'Sign out'); ?></span>
						</a>
					</div>
				</aside>

				<div class="flex min-h-screen min-w-0 flex-1 flex-col">
					<header class="sticky top-0 z-30 flex min-h-20 items-center justify-between gap-4 border-b border-black/5 bg-white/80 px-5 backdrop-blur-xl dark:border-white/5 dark:bg-[#151515]/80 lg:px-8">
						<div class="flex min-w-0 items-center gap-4">
							<label for="cfg-sidebar-toggle" class="flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center rounded-full text-brand transition hover:bg-gray-100 dark:text-white dark:hover:bg-[#202020] lg:hidden" aria-label="<?php echo cfg_t('Открыть меню', 'Open menu'); ?>">
								<i class="fa-solid fa-bars" aria-hidden="true"></i>
							</label>
							<div class="min-w-0">
								<span class="block text-xs font-bold uppercase tracking-wider text-gray-400">H-Script</span>
								<strong class="block truncate text-base font-extrabold text-brand dark:text-white"><?php echo htmlspecialchars($cfgPageTitle); ?></strong>
							</div>
						</div>
						<div class="flex shrink-0 items-center gap-2">
								<details class="relative" hx-boost="false">
									<summary class="flex h-10 cursor-pointer list-none items-center gap-2 rounded-full border border-black/5 bg-black/[0.03] px-3 text-sm font-extrabold text-brand transition hover:bg-black/[0.06] dark:border-white/10 dark:bg-white/[0.05] dark:text-white dark:hover:bg-white/10">
										<i class="fa-solid fa-globe text-gray-400 dark:text-gray-300" aria-hidden="true"></i>
									<span><?php echo strtoupper($_SESSION['cfg_lang']); ?></span>
								</summary>
								<div class="absolute right-0 top-full z-50 mt-2 min-w-32 overflow-hidden rounded-lg border border-gray-100 bg-white p-1 shadow-xl dark:border-gray-800 dark:bg-[#1A1A1A]">
									<?php foreach ($cfgLanguages as $lang) { ?>
										<a href="<?php echo cfg_url($cfgActive, array('lang' => $lang)); ?>" class="flex items-center justify-between gap-4 rounded-md px-3 py-2 text-sm font-bold text-brand transition hover:bg-gray-50 dark:text-white dark:hover:bg-[#202020]">
											<span><?php echo strtoupper($lang); ?></span>
											<?php if ($_SESSION['cfg_lang'] === $lang) { ?><i class="fa-solid fa-check text-emerald-500" aria-hidden="true"></i><?php } ?>
										</a>
									<?php } ?>
								</div>
							</details>
								<a href="<?php echo cfg_url($cfgActive, array('theme' => $cfgNextTheme)); ?>" hx-boost="false" class="flex h-10 w-10 items-center justify-center rounded-full border border-transparent text-brand transition hover:border-black/10 hover:bg-black/5 dark:text-white dark:hover:border-white/10 dark:hover:bg-white/5" title="<?php echo cfg_t('Сменить тему', 'Toggle theme'); ?>" aria-label="<?php echo cfg_t('Сменить тему', 'Toggle theme'); ?>">
									<i class="fa-solid fa-moon block text-lg dark:!hidden" aria-hidden="true"></i>
									<i class="fa-solid fa-sun !hidden text-lg text-yellow-400 dark:!block" aria-hidden="true"></i>
								</a>
						</div>
					</header>

					<?php if ($cfgMessages) { ?>
						<input id="cfg-message-close" type="checkbox" class="peer/message sr-only">
						<aside role="<?php echo $cfgMessageIsError ? 'alert' : 'status'; ?>"<?php if (!$cfgMessageIsError) { ?> hx-get="<?php echo htmlspecialchars($_GS['root_url'] . moduleToLink('ajax') . '?module=system&do=dismiss'); ?>" hx-trigger="load delay:4s" hx-swap="outerHTML"<?php } ?> class="fixed bottom-4 right-4 z-[100] flex w-[calc(100%-2rem)] max-w-md items-center gap-3 rounded-lg border p-4 shadow-2xl peer-checked/message:hidden sm:bottom-6 sm:right-6 <?php echo $cfgMessageIsError ? 'border-red-200 bg-red-50 text-red-800 dark:border-red-500/30 dark:bg-[#221315] dark:text-red-100' : 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-500/30 dark:bg-[#102019] dark:text-emerald-100'; ?>">
							<span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full <?php echo $cfgMessageIsError ? 'bg-red-100 text-red-600 dark:bg-red-500/20 dark:text-red-300' : 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/20 dark:text-emerald-300'; ?>"><i class="fa-solid <?php echo $cfgMessageIsError ? 'fa-circle-exclamation' : 'fa-circle-check'; ?>" aria-hidden="true"></i></span>
							<div class="min-w-0 flex-1 text-sm font-bold">
								<?php foreach ($cfgMessages as $message) { ?><p><?php echo htmlspecialchars($message); ?></p><?php } ?>
							</div>
							<label for="cfg-message-close" class="flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center rounded-lg opacity-60 transition hover:bg-black/5 hover:opacity-100 dark:hover:bg-white/5" aria-label="<?php echo cfg_t('Закрыть', 'Close'); ?>"><i class="fa-solid fa-xmark" aria-hidden="true"></i></label>
						</aside>
					<?php } ?>

					<main id="content" class="mx-auto w-full max-w-[1440px] flex-1 px-5 py-8 lg:px-10">
			<?php } else { ?>
				<div class="relative min-h-screen">
					<?php if ($cfgMessages) { ?>
						<div role="alert" class="fixed bottom-4 right-4 z-30 w-[calc(100%-2rem)] max-w-md rounded-lg border border-red-200 bg-red-50 p-4 text-center text-sm font-bold text-red-800 shadow-xl dark:border-red-500/30 dark:bg-[#221315] dark:text-red-100 sm:bottom-6 sm:right-6">
						<?php foreach ($cfgMessages as $message) { ?><p><?php echo htmlspecialchars($message); ?></p><?php } ?>
					</div>
				<?php } ?>
					<main id="content" class="w-full">
			<?php } ?>
