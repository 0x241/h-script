<?php

use HScript\Application;

require_once('module/_config/database_state.php');

$cfgLifecycle = cfg_update_status($_cfg, (string)($_GS['domain'] ?? ''));
$cfgDbVersion = $cfgLifecycle['installed_schema_version'];
$cfgLifecycleReady = \HScript\Update\UpdateStatusService::isReconciled($cfgLifecycle);

include('module/_config/_header.php');

?>

<section class="space-y-8">
		<header class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
		<div>
			<span class="mb-3 inline-flex items-center gap-2 text-xs font-extrabold uppercase tracking-wider text-emerald-600 dark:text-emerald-400"><i class="fa-solid fa-boxes-stacked" aria-hidden="true"></i><?php echo cfg_t('configurator.modules.system_overview'); ?></span>
			<h1 class="text-3xl font-black text-brand dark:text-white sm:text-4xl"><?php echo cfg_t('configurator.common.modules'); ?></h1>
			<p class="mt-2 max-w-2xl text-sm font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('configurator.modules.core_health_connectivity_and_h_script_maintenance_tools'); ?></p>
		</div>
			<span class="inline-flex self-start items-center gap-2 rounded-full border <?php echo $cfgLifecycleReady ? 'border-emerald-200 bg-emerald-50' : 'border-amber-200 bg-amber-50'; ?> px-4 py-2 text-xs font-extrabold uppercase tracking-wider text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300 sm:self-auto"><span class="h-2 w-2 rounded-full <?php echo $cfgLifecycleReady ? 'bg-emerald-500' : 'bg-amber-500'; ?>"></span><?php echo $cfgLifecycleReady ? cfg_t('configurator.modules.system_active') : cfg_t('configurator.lifecycle.pending'); ?></span>
		</header>
		<?php if (!$cfgLifecycleReady) { ?><aside class="rounded-lg border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900 dark:bg-amber-500/10 dark:text-amber-100"><p><?php echo cfg_t('configurator.lifecycle.pending_detail'); ?></p><p class="mt-2 font-bold"><?php echo cfg_t('configurator.lifecycle.target'); ?>: CMS <?php echo htmlspecialchars((string)$cfgLifecycle['application_version'], ENT_QUOTES, 'UTF-8'); ?> / <?php echo cfg_t('configurator.update.db'); ?> <?php echo htmlspecialchars((string)$cfgLifecycle['target_schema_version'], ENT_QUOTES, 'UTF-8'); ?></p><a class="mt-2 inline-block underline" href="?update"><?php echo cfg_t('configurator.update.h_script_update'); ?></a></aside><?php } ?>

		<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
			<article class="rounded-lg border border-blue-100 bg-cardBlue p-5 dark:border-blue-500/20 dark:bg-blue-500/10">
				<div class="flex items-start justify-between gap-4"><div><p class="text-xs font-extrabold uppercase tracking-wider text-blue-600 dark:text-blue-300"><?php echo cfg_t('configurator.common.core'); ?></p><strong class="mt-2 block text-2xl font-black text-brand dark:text-white"><?php echo Application::NAME . ' ' . htmlspecialchars((string)($cfgLifecycle['installed_application_version'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></strong><small class="mt-1 block font-semibold text-gray-500 dark:text-gray-400"><?php echo cfg_t('configurator.lifecycle.installed'); ?></small></div><span class="flex h-10 w-10 items-center justify-center rounded-lg bg-white text-blue-600 shadow-sm dark:bg-[#151515] dark:text-blue-300"><i class="fa-solid fa-microchip" aria-hidden="true"></i></span></div>
			</article>
			<article class="rounded-lg border border-emerald-100 bg-cardGreen p-5 dark:border-emerald-500/20 dark:bg-emerald-500/10">
				<div class="flex items-start justify-between gap-4"><div><p class="text-xs font-extrabold uppercase tracking-wider text-emerald-600 dark:text-emerald-300">PHP</p><strong class="mt-2 block text-2xl font-black text-brand dark:text-white"><?php echo PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION; ?></strong><small class="mt-1 block font-semibold text-gray-500 dark:text-gray-400"><?php echo cfg_t('configurator.modules.server_runtime'); ?></small></div><span class="flex h-10 w-10 items-center justify-center rounded-lg bg-white text-emerald-600 shadow-sm dark:bg-[#151515] dark:text-emerald-300"><i class="fa-solid fa-code" aria-hidden="true"></i></span></div>
			</article>
			<article class="rounded-lg border border-violet-100 bg-cardPurple p-5 dark:border-violet-500/20 dark:bg-violet-500/10">
				<div class="flex items-start justify-between gap-4"><div><p class="text-xs font-extrabold uppercase tracking-wider text-violet-600 dark:text-violet-300"><?php echo cfg_t('configurator.modules.db_schema'); ?></p><strong class="mt-2 block text-2xl font-black text-brand dark:text-white"><?php echo htmlspecialchars($cfgDbVersion ?? '—', ENT_QUOTES, 'UTF-8'); ?></strong><small class="mt-1 block font-semibold text-gray-500 dark:text-gray-400"><?php echo cfg_t('configurator.modules.installed_structure_version'); ?></small></div><span class="flex h-10 w-10 items-center justify-center rounded-lg bg-white text-violet-600 shadow-sm dark:bg-[#151515] dark:text-violet-300"><i class="fa-solid fa-database" aria-hidden="true"></i></span></div>
			</article>
			<article class="rounded-lg border border-orange-100 bg-cardPeach p-5 dark:border-orange-500/20 dark:bg-orange-500/10">
				<div class="flex items-start justify-between gap-4"><div><p class="text-xs font-extrabold uppercase tracking-wider text-orange-600 dark:text-orange-300"><?php echo cfg_t('configurator.modules.interface'); ?></p><strong class="mt-2 block text-2xl font-black text-brand dark:text-white"><?php echo count($cfgLanguages); ?></strong><small class="mt-1 block font-semibold text-gray-500 dark:text-gray-400"><?php echo cfg_t('configurator.modules.available_languages'); ?></small></div><span class="flex h-10 w-10 items-center justify-center rounded-lg bg-white text-orange-600 shadow-sm dark:bg-[#151515] dark:text-orange-300"><i class="fa-solid fa-language" aria-hidden="true"></i></span></div>
			</article>
		</div>

		<div class="grid gap-5 lg:grid-cols-3">
		<article class="rounded-lg border border-gray-100 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-[#151515] lg:col-span-2">
			<div class="mb-6 flex items-center justify-between gap-4">
				<div>
					<p class="text-xs font-extrabold uppercase tracking-wider text-gray-400"><?php echo cfg_t('configurator.modules.architecture'); ?></p>
					<h2 class="mt-1 text-xl font-extrabold text-brand dark:text-white"><?php echo cfg_t('configurator.modules.system_environment'); ?></h2>
				</div>
				<span class="flex h-11 w-11 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-500/10 dark:text-blue-300"><i class="fa-solid fa-server" aria-hidden="true"></i></span>
			</div>
			<div class="divide-y divide-gray-100 border-y border-gray-100 dark:divide-gray-800 dark:border-gray-800">
				<div class="flex flex-col gap-4 py-5 sm:flex-row sm:items-center sm:justify-between">
					<div class="flex items-start gap-4">
						<span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300"><i class="fa-solid fa-microchip" aria-hidden="true"></i></span>
						<div><strong class="block text-sm font-extrabold text-brand dark:text-white"><?php echo cfg_t('configurator.modules.core_framework'); ?></strong><small class="mt-1 block text-xs font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('configurator.modules.the_platform_root_environment_is_initialized'); ?></small></div>
					</div>
					<span class="inline-flex self-start rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-extrabold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300 sm:self-auto"><?php echo cfg_t('configurator.modules.active'); ?></span>
				</div>
				<div class="flex flex-col gap-4 py-5 sm:flex-row sm:items-center sm:justify-between">
					<div class="flex items-start gap-4">
						<span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-violet-50 text-violet-600 dark:bg-violet-500/10 dark:text-violet-300"><i class="fa-solid fa-database" aria-hidden="true"></i></span>
						<div><strong class="block text-sm font-extrabold text-brand dark:text-white"><?php echo cfg_t('configurator.modules.database_connection'); ?></strong><small class="mt-1 block text-xs font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('configurator.modules.connection_settings_are_stored_in_config_php'); ?></small></div>
					</div>
					<a href="?setup" class="inline-flex self-start items-center gap-2 rounded-lg border border-gray-200 px-4 py-2 text-xs font-extrabold text-brand transition hover:bg-gray-50 dark:border-gray-700 dark:text-white dark:hover:bg-[#202020] sm:self-auto"><?php echo cfg_t('configurator.modules.configure'); ?><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
				</div>
			</div>
		</article>

		<aside class="rounded-lg border border-gray-100 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-[#151515]">
			<p class="text-xs font-extrabold uppercase tracking-wider text-gray-400"><?php echo cfg_t('configurator.common.maintenance'); ?></p>
			<h2 class="mt-1 text-xl font-extrabold text-brand dark:text-white"><?php echo cfg_t('configurator.modules.quick_actions'); ?></h2>
			<div class="mt-6 space-y-2">
				<a href="?setup" class="flex items-center justify-between rounded-lg bg-gray-50 px-4 py-3 text-sm font-bold text-brand transition hover:bg-gray-100 dark:bg-[#1A1A1A] dark:text-white dark:hover:bg-[#202020]"><span class="flex items-center gap-3"><i class="fa-solid fa-sliders text-blue-500" aria-hidden="true"></i><?php echo cfg_t('configurator.common.connection'); ?></span><i class="fa-solid fa-chevron-right text-xs text-gray-300" aria-hidden="true"></i></a>
				<a href="?backup" class="flex items-center justify-between rounded-lg bg-gray-50 px-4 py-3 text-sm font-bold text-brand transition hover:bg-gray-100 dark:bg-[#1A1A1A] dark:text-white dark:hover:bg-[#202020]"><span class="flex items-center gap-3"><i class="fa-solid fa-box-archive text-violet-500" aria-hidden="true"></i><?php echo cfg_t('configurator.common.backups'); ?></span><i class="fa-solid fa-chevron-right text-xs text-gray-300" aria-hidden="true"></i></a>
				<a href="?update" class="flex items-center justify-between rounded-lg bg-gray-50 px-4 py-3 text-sm font-bold text-brand transition hover:bg-gray-100 dark:bg-[#1A1A1A] dark:text-white dark:hover:bg-[#202020]"><span class="flex items-center gap-3"><i class="fa-solid fa-rotate text-emerald-500" aria-hidden="true"></i><?php echo cfg_t('configurator.common.update'); ?></span><i class="fa-solid fa-chevron-right text-xs text-gray-300" aria-hidden="true"></i></a>
				<a href="?pass" class="flex items-center justify-between rounded-lg bg-gray-50 px-4 py-3 text-sm font-bold text-brand transition hover:bg-gray-100 dark:bg-[#1A1A1A] dark:text-white dark:hover:bg-[#202020]"><span class="flex items-center gap-3"><i class="fa-solid fa-key text-amber-500" aria-hidden="true"></i><?php echo cfg_t('configurator.common.change_password_header'); ?></span><i class="fa-solid fa-chevron-right text-xs text-gray-300" aria-hidden="true"></i></a>
			</div>
		</aside>
	</div>
</section>

<?php

include('module/_config/_footer.php');

?>
