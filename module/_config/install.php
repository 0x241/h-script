<?php

use HScript\Application;
use HScript\Telemetry\TelemetryReporter;
use HScript\Update\ConfiguratorCsrf;
use HScript\Util\StringHelper;

require_once('module/dbinit.php');
$installObjects = $db->fetchRows($db->query('SHOW FULL TABLES'));
$installDatabasePopulated = count($installObjects) > 0;

if ($installDatabasePopulated)
{
	addMsg(cfg_t('configurator.install.the_database_is_already_initialized_use_update_to_change_versions'));
	goToURL($_cfg['cfg_link'] . '?modules');
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST' && isset_IN('bStart'))
{
	try
	{
		ConfiguratorCsrf::consume($_POST['csrf'] ?? '');
		if (!file_exists('_dbstru.php'))
			throw new RuntimeException('Database structure "_dbstru.php" required');
		if (count($db->fetchRows($db->query('SHOW FULL TABLES'))) > 0)
			throw new RuntimeException('Database is not empty; initial installation requires an empty database');

	require('_dbstru.php');
	
	$db->query("ALTER DATABASE " . $db->field($_cfg['db_name']) . " DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci");

//	if (in_array('InnoDB', $db->fetchRows($db->query('SHOW TABLE TYPES'), 'Engine')))
//		addMsg('* Server can process transactions');

	$dt = StringHelper::valueIf($_cfg['db_type'], ' ENGINE=' . StringHelper::valueIf($_cfg['db_type'] == 1, 'InnoDB', 'MYISAM'));
	foreach ($_dbstru as $t => $cmnd)
		$db->query("CREATE TABLE $t ($cmnd)$dt CHARACTER SET utf8 COLLATE utf8_general_ci");

	$psalt = substr(md5(uniqid(rand(), true).time()), 0, rand(6, 10));
	clearstatcache();
	$cfg = array(
		'Const' => array(
			'Salt' => $psalt,
			'NoLogins' => 0 + isset_IN('noLogins'),
			'IntCurr' => 0 + isset_IN('intCurr'),
			'AppVersion' => Application::version(),
			'SchemaVersion' => Application::schemaVersion()
		),
		'Sec' => array(
			'BFC' => 1
		),
		'Confirm' => array(
			'Captcha' => 2
		),
		'Sys' => array(
			'AdminMail' => _IN('aMail'),
			'NeedReConfig' => 1
		),
		'UI' => array(
			'_Langs' => "en\r\nru",
			'NumDec' => 2
		),
		'FAQ' => array(
			'ShowCount' => 10,
			'InBlock' => 0,
			'_Cats' => "General"
		),
		'Cron' => array(
			'Enabled' => 1
			),
			'Account' => array(
				'LoginCaptcha' => 1,
				'ChangeMailCaptcha' => 2,
				'ResetPassCaptcha' => 2
			),
		'Depo' => array(
			'ChargeMode' => 1
		),
		'Bal' => array(
			('Rate' . _IN('intCurrID')) => 1
		),
		'Demo' => array(
			'Mode' => (!empty($_cfg['demo_mode']) || file_exists('tpl_c/demo')) ? 1 : 0
		),
		'Telemetry' => array(
			'Enabled' => 1,
			'SharePublicStats' => 0 + (empty($_cfg['demo_mode']) && isset_IN('telemetryStats')),
			'InstalledAt' => time()
		)
	);
	if (!empty($_cfg['Captcha_Service']) || !empty($_cfg['Turnstile_SiteKey']) || !empty($_cfg['Turnstile_SecretKey'])) {
		$cfg['Captcha'] = array(
			'Service' => !empty($_cfg['Captcha_Service']) ? $_cfg['Captcha_Service'] : 'turnstile',
			'Turnstile_SiteKey' => isset($_cfg['Turnstile_SiteKey']) ? $_cfg['Turnstile_SiteKey'] : '',
			'Turnstile_SecretKey' => isset($_cfg['Turnstile_SecretKey']) ? $_cfg['Turnstile_SecretKey'] : ''
		);
	}
	if (isset_IN('intCurr'))
		$cfg['Bal'] = array('UpdateRates' => 1);

	foreach ($cfg as $m => $a)
		foreach ($a as $p => $v)
			$db->insert('Cfg',
				array(
					'Module' => $m,
					'Prop' => $p,
					'Val' => $v
				)
			);
	$db->insert('SchemaState', array(
		'ssKey' => 'current',
		'ssVersion' => Application::schemaVersion(),
		'ssUpdatedAt' => time()
	));
		
	$admin = (isset_IN('noLogins') ? _IN('aMail') : _IN('aLogin'));
	$db->insert('Users',
		array(
			'uID' => 1,
			'uLogin' => $admin,
			'uPass' => hashPassword(_IN('aPass')),
			'uMail' => _IN('aMail'),
			'uPIN' => hashPassword(_IN('aPIN')),
			'uState' => 1,
			'uLevel' => 99,
			'uPTS' => timeToStamp()
		)
	);
	$db->insert('AddInfo',
		array(
			'auID' => 1,
			'aName' => _IN('aName'),
			'aSQuestion' => _IN('aSQuest'),
			'aSAnswer' => hashPassword(_IN('aSAnsw')),
			'aIPSec' => 4
		)
	);
	$db->insert('Currs', 
		array(
			'cID' => 1,
			'cDisabled' => !isset_IN('intCurr'),
			'cHidden' => 1,
			'cCID' => '*', 
			'cCurrID' => _IN('intCurrID'),
			'cCurr' => _IN('intCurrID'),
			'cName' => 'Internal',
			'cEXMode' => 2,
			'cTRMode' => 2,
			'cBUYMode' => 2,
			'cBUY2Mode' => 2,
			'cGIVEMode' => 2,
			'cTAKEMode' => 2
		)
	);
	require_once('module/faq/lib.php');
	faqSeedDefaultRows($db);
	$telemetryConfig = $_cfg;
	$telemetryConfig['Telemetry_Enabled'] = 1;
	$telemetryConfig['Telemetry_SharePublicStats'] = 0 + (empty($_cfg['demo_mode']) && isset_IN('telemetryStats'));
	$telemetryConfig['Telemetry_InstalledAt'] = $cfg['Telemetry']['InstalledAt'];
	(new TelemetryReporter(
		$db,
		$telemetryConfig,
		(string)($_GS['domain'] ?? '')
	))->register();
	$cfgSecurity->audit('initial_setup', 'success', $cfgClientIp);
	
	
	addMsg(cfg_t('configurator.install.installation_complete'));
	goToURL($_cfg['cfg_link'] . '?modules');
	}
	catch (Throwable $exception)
	{
		$cfgSecurity->audit('initial_setup', 'failed', $cfgClientIp, array('reason' => 'operation'));
		error_log('Web installation stopped: ' . $exception->getMessage());
		addMsg(cfg_t('configurator.install.installation_stopped') . cfg_t('configurator.common.technical_error'), true);
	}
}

include('module/_config/_header.php');

?>

<section class="space-y-8">
	<header>
		<span class="mb-3 inline-flex items-center gap-2 text-xs font-extrabold uppercase tracking-wider text-amber-600 dark:text-amber-400"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i><?php echo cfg_t('configurator.install.bootstrap'); ?></span>
		<h1 class="text-3xl font-black text-brand dark:text-white sm:text-4xl"><?php echo cfg_t('configurator.install.system_installation'); ?></h1>
		<p class="mt-2 max-w-3xl text-sm font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('configurator.install.create_the_database_structure_and_the_first_administrator_account'); ?></p>
	</header>

	<aside class="flex items-start gap-4 rounded-lg border border-emerald-200 bg-emerald-50 p-5 text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-100">
		<span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-emerald-100 text-emerald-600 dark:bg-emerald-500/20 dark:text-emerald-300"><i class="fa-solid fa-database" aria-hidden="true"></i></span>
		<div><strong class="block text-base font-extrabold"><?php echo cfg_t('configurator.install.database_is_ready_for_initial_setup'); ?></strong><p class="mt-1 text-sm font-medium opacity-80"><?php echo cfg_t('configurator.install.the_initial_h_script_structure_will_be_created_this_step_is_available_only_for_a'); ?></p></div>
	</aside>

	<form method="post" class="space-y-6">
		<input type="hidden" name="csrf" value="<?php echo htmlspecialchars(ConfiguratorCsrf::token(), ENT_QUOTES, 'UTF-8'); ?>">
		<section class="overflow-hidden rounded-lg border border-gray-100 bg-white shadow-sm dark:border-gray-800 dark:bg-[#151515]">
			<header class="flex items-center gap-3 border-b border-gray-100 bg-gray-50/70 px-6 py-4 dark:border-gray-800 dark:bg-[#1A1A1A]"><span class="flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i></span><div><h2 class="text-base font-extrabold text-brand dark:text-white"><?php echo cfg_t('configurator.install.initial_setup'); ?></h2><p class="text-xs font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('configurator.install.create_a_new_database_without_deleting_data'); ?></p></div></header>
			<div class="p-6"><strong class="block text-sm font-extrabold text-brand dark:text-white"><?php echo cfg_t('configurator.install.create_and_populate_database'); ?></strong><small class="mt-1 block text-xs font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('configurator.install.the_database_will_be_checked_again_to_ensure_it_is_completely_empty_before_setup'); ?></small></div>
		</section>

		<section class="overflow-hidden rounded-lg border border-gray-100 bg-white shadow-sm dark:border-gray-800 dark:bg-[#151515]">
			<header class="flex items-center gap-3 border-b border-gray-100 bg-gray-50/70 px-6 py-4 dark:border-gray-800 dark:bg-[#1A1A1A]"><span class="flex h-9 w-9 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-500/10 dark:text-blue-300"><i class="fa-solid fa-sliders" aria-hidden="true"></i></span><div><h2 class="text-base font-extrabold text-brand dark:text-white"><?php echo cfg_t('configurator.install.initial_parameters'); ?></h2><p class="text-xs font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('configurator.install.authentication_mode_and_currency'); ?></p></div></header>
			<div class="divide-y divide-gray-100 dark:divide-gray-800">
				<label class="flex cursor-pointer items-center justify-between gap-5 px-6 py-5"><span><strong class="block text-sm font-extrabold text-brand dark:text-white"><?php echo cfg_t('configurator.install.use_e_mail_instead_of_login'); ?></strong><small class="mt-1 block text-xs font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('configurator.install.authenticate_users_with_their_e_mail_address'); ?></small></span><span class="relative inline-flex shrink-0 items-center"><input name="noLogins" value="1" type="checkbox" class="peer sr-only"><span class="h-6 w-11 rounded-full bg-gray-200 transition after:absolute after:left-0.5 after:top-0.5 after:h-5 after:w-5 after:rounded-full after:bg-white after:shadow after:transition-transform peer-checked:bg-emerald-500 peer-checked:after:translate-x-5 dark:bg-gray-700"></span></span></label>
				<label class="flex cursor-pointer items-center justify-between gap-5 px-6 py-5"><span><strong class="block text-sm font-extrabold text-brand dark:text-white"><?php echo cfg_t('configurator.install.internal_currency_only'); ?></strong><small class="mt-1 block text-xs font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('configurator.install.disable_multi_currency_accounts'); ?></small></span><span class="relative inline-flex shrink-0 items-center"><input name="intCurr" value="1" type="checkbox" class="peer sr-only"><span class="h-6 w-11 rounded-full bg-gray-200 transition after:absolute after:left-0.5 after:top-0.5 after:h-5 after:w-5 after:rounded-full after:bg-white after:shadow after:transition-transform peer-checked:bg-emerald-500 peer-checked:after:translate-x-5 dark:bg-gray-700"></span></span></label>
				<label class="grid gap-3 px-6 py-5 md:grid-cols-[minmax(0,1fr)_minmax(240px,360px)] md:items-center"><span><strong class="block text-sm font-extrabold text-brand dark:text-white"><?php echo cfg_t('configurator.install.internal_currency'); ?></strong><small class="mt-1 block text-xs font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('configurator.install.base_accounting_unit'); ?></small></span><select name="intCurrID" class="<?php echo $cfgInputClass; ?>"><option value="USD">USD</option><option value="EUR">EUR</option><option value="RUB">RUB</option><option value="BTC">BTC</option><option value="ETH">ETH</option><option value="XRP">XRP</option></select></label>
			</div>
		</section>

		<section class="overflow-hidden rounded-lg border border-gray-100 bg-white shadow-sm dark:border-gray-800 dark:bg-[#151515]">
			<header class="flex items-center gap-3 border-b border-gray-100 bg-gray-50/70 px-6 py-4 dark:border-gray-800 dark:bg-[#1A1A1A]"><span class="flex h-9 w-9 items-center justify-center rounded-lg bg-violet-50 text-violet-600 dark:bg-violet-500/10 dark:text-violet-300"><i class="fa-solid fa-user-shield" aria-hidden="true"></i></span><div><h2 class="text-base font-extrabold text-brand dark:text-white"><?php echo cfg_t('configurator.install.administrator'); ?></h2><p class="text-xs font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('configurator.install.first_account_with_full_access'); ?></p></div></header>
			<div class="grid gap-6 p-6 md:grid-cols-2">
				<label class="block"><span class="mb-2 block text-sm font-extrabold text-brand dark:text-gray-200"><?php echo cfg_t('configurator.install.name'); ?></span><input name="aName" value="Administrator" type="text" class="<?php echo $cfgInputClass; ?>"></label>
				<label class="block"><span class="mb-2 block text-sm font-extrabold text-brand dark:text-gray-200"><?php echo cfg_t('configurator.install.login'); ?></span><input name="aLogin" value="admin" type="text" autocomplete="username" class="<?php echo $cfgInputClass; ?>"></label>
				<label class="block"><span class="mb-2 block text-sm font-extrabold text-brand dark:text-gray-200"><?php echo cfg_t('configurator.common.password'); ?></span><input name="aPass" value="admin" type="password" autocomplete="new-password" class="<?php echo $cfgInputClass; ?>"></label>
				<label class="block"><span class="mb-2 block text-sm font-extrabold text-brand dark:text-gray-200">E-mail</span><input name="aMail" value="<?php echo htmlspecialchars(isset($_cfg['sys_mail']) ? $_cfg['sys_mail'] : ''); ?>" type="email" class="<?php echo $cfgInputClass; ?>"></label>
				<label class="block"><span class="mb-2 block text-sm font-extrabold text-brand dark:text-gray-200"><?php echo cfg_t('configurator.install.secret_question'); ?></span><input name="aSQuest" value="That is your name" type="text" class="<?php echo $cfgInputClass; ?>"></label>
				<label class="block"><span class="mb-2 block text-sm font-extrabold text-brand dark:text-gray-200"><?php echo cfg_t('configurator.install.secret_answer'); ?></span><input name="aSAnsw" value="John" type="text" class="<?php echo $cfgInputClass; ?>"></label>
				<label class="block md:col-span-2"><span class="mb-2 block text-sm font-extrabold text-brand dark:text-gray-200"><?php echo cfg_t('configurator.install.pin_code'); ?></span><input name="aPIN" value="1234" type="text" inputmode="numeric" class="<?php echo $cfgInputClass; ?>"></label>
			</div>
		</section>

		<section class="overflow-hidden rounded-lg border border-gray-100 bg-white shadow-sm dark:border-gray-800 dark:bg-[#151515]">
			<header class="flex items-center gap-3 border-b border-gray-100 bg-gray-50/70 px-6 py-4 dark:border-gray-800 dark:bg-[#1A1A1A]"><span class="flex h-9 w-9 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-500/10 dark:text-blue-300"><i class="fa-solid fa-satellite-dish" aria-hidden="true"></i></span><div><h2 class="text-base font-extrabold text-brand dark:text-white"><?php echo cfg_t('configurator.install.telemetry'); ?></h2><p class="text-xs font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('configurator.install.installation_registration_is_required_public_aggregates_are_enabled_by_default_a'); ?></p></div></header>
			<div class="divide-y divide-gray-100 dark:divide-gray-800">
				<div class="flex items-start justify-between gap-5 px-6 py-5"><span><strong class="block text-sm font-extrabold text-brand dark:text-white"><?php echo cfg_t('configurator.install.installation_registration_and_heartbeat'); ?></strong><small class="mt-1 block text-xs font-medium leading-relaxed text-gray-500 dark:text-gray-400"><?php echo cfg_t('configurator.install.the_domain_h_script_version_installation_date_and_a_random_id_are_sent_this_syst'); ?></small></span><span class="shrink-0 rounded-full bg-blue-50 px-3 py-1 text-xs font-extrabold text-blue-700 dark:bg-blue-500/10 dark:text-blue-200"><?php echo cfg_t('configurator.install.required'); ?></span></div>
				<label class="flex cursor-pointer items-start justify-between gap-5 px-6 py-5"><span><strong class="block text-sm font-extrabold text-brand dark:text-white"><?php echo cfg_t('configurator.install.share_public_aggregates_daily'); ?></strong><small class="mt-1 block text-xs font-medium leading-relaxed text-gray-500 dark:text-gray-400"><?php echo cfg_t('configurator.install.user_deposit_counts_online_count_and_totals_without_logins_or_individual_operati'); ?></small></span><input name="telemetryStats" value="1" type="checkbox" checked class="mt-1 h-5 w-5 shrink-0 rounded border-gray-300 text-emerald-600"></label>
			</div>
		</section>

		<div class="flex justify-center rounded-lg border border-emerald-100 bg-white p-5 shadow-sm dark:border-emerald-500/20 dark:bg-[#151515]">
			<button name="bStart" value="1" type="submit" class="<?php echo $cfgButtonClass; ?>"><i class="fa-solid fa-check" aria-hidden="true"></i><?php echo cfg_t('configurator.install.complete_initial_setup'); ?></button>
		</div>
	</form>
</section>

<?php

include('module/_config/_footer.php');
	
?>
