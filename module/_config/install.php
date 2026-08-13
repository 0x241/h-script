<?php

use HScript\Telemetry\TelemetryReporter;
use HScript\Util\StringHelper;

if (isset_IN('doFill'))
{

	if (!file_exists('_dbstru.php'))
		addMsg('Database structure "_dbstru.php" required');
	else
	{

	require_once('module/dbinit.php');
	
	require('_dbstru.php');
	
	$db->query("ALTER DATABASE " . $db->field($_cfg['db_name']) . " DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci");

//	if (in_array('InnoDB', $db->fetchRows($db->query('SHOW TABLE TYPES'), 'Engine')))
//		addMsg('* Server can process transactions');

	$ts = $db->fetchRows($db->query('SHOW TABLES'));
	if (count($ts) > 0)
		foreach ($ts as $t) 
			$db->query('DROP TABLE IF EXISTS ' . reset($t));

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
			'DBVer' => is_file('_dbstru.php') ? intval(filemtime('_dbstru.php')) : 0
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
			'SharePublicStats' => 0 + isset_IN('telemetryStats'),
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
	$telemetryConfig['Telemetry_SharePublicStats'] = 0 + isset_IN('telemetryStats');
	$telemetryConfig['Telemetry_InstalledAt'] = $cfg['Telemetry']['InstalledAt'];
	(new TelemetryReporter(
		$db,
		$telemetryConfig,
		(string)($_GS['domain'] ?? '')
	))->register();
	
	
	addMsg(cfg_t('Установка успешно завершена!', 'Installation complete!'));
	goToURL($_cfg['cfg_link'] . '?modules');
	
	}
	
}
elseif (isset_IN('bStart'))
	addMsg('To process, markup "Create and fill base.." checkbox below');

include('module/_config/_header.php');

?>

<section class="space-y-8">
	<header>
		<span class="mb-3 inline-flex items-center gap-2 text-xs font-extrabold uppercase tracking-wider text-amber-600 dark:text-amber-400"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i><?php echo cfg_t('Инициализация', 'Bootstrap'); ?></span>
		<h1 class="text-3xl font-black text-brand dark:text-white sm:text-4xl"><?php echo cfg_t('Установка системы', 'System installation'); ?></h1>
		<p class="mt-2 max-w-3xl text-sm font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('Создание структуры базы данных и первой учетной записи администратора.', 'Create the database structure and the first administrator account.'); ?></p>
	</header>

	<aside class="flex items-start gap-4 rounded-lg border border-red-200 bg-red-50 p-5 text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-100">
		<span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-red-100 text-red-600 dark:bg-red-500/20 dark:text-red-300"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i></span>
		<div><strong class="block text-base font-extrabold"><?php echo cfg_t('Полная перезапись данных', 'Complete data replacement'); ?></strong><p class="mt-1 text-sm font-medium opacity-80"><?php echo cfg_t('Запуск установки безвозвратно удалит все существующие таблицы и данные.', 'Running the installer permanently removes all existing tables and data.'); ?></p></div>
	</aside>

	<form method="post" class="space-y-6">
		<section class="overflow-hidden rounded-lg border border-gray-100 bg-white shadow-sm dark:border-gray-800 dark:bg-[#151515]">
			<header class="flex items-center gap-3 border-b border-gray-100 bg-gray-50/70 px-6 py-4 dark:border-gray-800 dark:bg-[#1A1A1A]"><span class="flex h-9 w-9 items-center justify-center rounded-lg bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-300"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i></span><div><h2 class="text-base font-extrabold text-brand dark:text-white"><?php echo cfg_t('Подтверждение установки', 'Installation confirmation'); ?></h2><p class="text-xs font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('Обязательное подтверждение опасной операции', 'Required confirmation for a destructive action'); ?></p></div></header>
			<label class="flex cursor-pointer items-center justify-between gap-5 p-6">
				<span><strong class="block text-sm font-extrabold text-brand dark:text-white"><?php echo cfg_t('Создать и заполнить базу данных', 'Create and populate database'); ?></strong><small class="mt-1 block text-xs font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('Удалить текущие данные и загрузить начальную структуру.', 'Remove current data and load the initial structure.'); ?></small></span>
				<span class="relative inline-flex shrink-0 items-center"><input name="doFill" value="1" type="checkbox" class="peer sr-only"><span class="h-6 w-11 rounded-full bg-gray-200 transition after:absolute after:left-0.5 after:top-0.5 after:h-5 after:w-5 after:rounded-full after:bg-white after:shadow after:transition-transform peer-checked:bg-red-500 peer-checked:after:translate-x-5 dark:bg-gray-700"></span></span>
			</label>
		</section>

		<section class="overflow-hidden rounded-lg border border-gray-100 bg-white shadow-sm dark:border-gray-800 dark:bg-[#151515]">
			<header class="flex items-center gap-3 border-b border-gray-100 bg-gray-50/70 px-6 py-4 dark:border-gray-800 dark:bg-[#1A1A1A]"><span class="flex h-9 w-9 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-500/10 dark:text-blue-300"><i class="fa-solid fa-sliders" aria-hidden="true"></i></span><div><h2 class="text-base font-extrabold text-brand dark:text-white"><?php echo cfg_t('Начальные параметры', 'Initial parameters'); ?></h2><p class="text-xs font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('Режим авторизации и валюта', 'Authentication mode and currency'); ?></p></div></header>
			<div class="divide-y divide-gray-100 dark:divide-gray-800">
				<label class="flex cursor-pointer items-center justify-between gap-5 px-6 py-5"><span><strong class="block text-sm font-extrabold text-brand dark:text-white"><?php echo cfg_t('Использовать e-mail вместо логина', 'Use e-mail instead of login'); ?></strong><small class="mt-1 block text-xs font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('Авторизация пользователей по адресу электронной почты.', 'Authenticate users with their e-mail address.'); ?></small></span><span class="relative inline-flex shrink-0 items-center"><input name="noLogins" value="1" type="checkbox" class="peer sr-only"><span class="h-6 w-11 rounded-full bg-gray-200 transition after:absolute after:left-0.5 after:top-0.5 after:h-5 after:w-5 after:rounded-full after:bg-white after:shadow after:transition-transform peer-checked:bg-emerald-500 peer-checked:after:translate-x-5 dark:bg-gray-700"></span></span></label>
				<label class="flex cursor-pointer items-center justify-between gap-5 px-6 py-5"><span><strong class="block text-sm font-extrabold text-brand dark:text-white"><?php echo cfg_t('Только внутренняя валюта', 'Internal currency only'); ?></strong><small class="mt-1 block text-xs font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('Отключить механизм мультивалютных счетов.', 'Disable multi-currency accounts.'); ?></small></span><span class="relative inline-flex shrink-0 items-center"><input name="intCurr" value="1" type="checkbox" class="peer sr-only"><span class="h-6 w-11 rounded-full bg-gray-200 transition after:absolute after:left-0.5 after:top-0.5 after:h-5 after:w-5 after:rounded-full after:bg-white after:shadow after:transition-transform peer-checked:bg-emerald-500 peer-checked:after:translate-x-5 dark:bg-gray-700"></span></span></label>
				<label class="grid gap-3 px-6 py-5 md:grid-cols-[minmax(0,1fr)_minmax(240px,360px)] md:items-center"><span><strong class="block text-sm font-extrabold text-brand dark:text-white"><?php echo cfg_t('Внутренняя валюта', 'Internal currency'); ?></strong><small class="mt-1 block text-xs font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('Базовая расчетная единица.', 'Base accounting unit.'); ?></small></span><select name="intCurrID" class="<?php echo $cfgInputClass; ?>"><option value="USD">USD</option><option value="EUR">EUR</option><option value="RUB">RUB</option><option value="BTC">BTC</option><option value="ETH">ETH</option><option value="XRP">XRP</option></select></label>
			</div>
		</section>

		<section class="overflow-hidden rounded-lg border border-gray-100 bg-white shadow-sm dark:border-gray-800 dark:bg-[#151515]">
			<header class="flex items-center gap-3 border-b border-gray-100 bg-gray-50/70 px-6 py-4 dark:border-gray-800 dark:bg-[#1A1A1A]"><span class="flex h-9 w-9 items-center justify-center rounded-lg bg-violet-50 text-violet-600 dark:bg-violet-500/10 dark:text-violet-300"><i class="fa-solid fa-user-shield" aria-hidden="true"></i></span><div><h2 class="text-base font-extrabold text-brand dark:text-white"><?php echo cfg_t('Администратор', 'Administrator'); ?></h2><p class="text-xs font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('Первая учетная запись с полным доступом', 'First account with full access'); ?></p></div></header>
			<div class="grid gap-6 p-6 md:grid-cols-2">
				<label class="block"><span class="mb-2 block text-sm font-extrabold text-brand dark:text-gray-200"><?php echo cfg_t('Имя', 'Name'); ?></span><input name="aName" value="Administrator" type="text" class="<?php echo $cfgInputClass; ?>"></label>
				<label class="block"><span class="mb-2 block text-sm font-extrabold text-brand dark:text-gray-200"><?php echo cfg_t('Логин', 'Login'); ?></span><input name="aLogin" value="admin" type="text" autocomplete="username" class="<?php echo $cfgInputClass; ?>"></label>
				<label class="block"><span class="mb-2 block text-sm font-extrabold text-brand dark:text-gray-200"><?php echo cfg_t('Пароль', 'Password'); ?></span><input name="aPass" value="admin" type="password" autocomplete="new-password" class="<?php echo $cfgInputClass; ?>"></label>
				<label class="block"><span class="mb-2 block text-sm font-extrabold text-brand dark:text-gray-200">E-mail</span><input name="aMail" value="<?php echo htmlspecialchars(isset($_cfg['sys_mail']) ? $_cfg['sys_mail'] : ''); ?>" type="email" class="<?php echo $cfgInputClass; ?>"></label>
				<label class="block"><span class="mb-2 block text-sm font-extrabold text-brand dark:text-gray-200"><?php echo cfg_t('Секретный вопрос', 'Secret question'); ?></span><input name="aSQuest" value="That is your name" type="text" class="<?php echo $cfgInputClass; ?>"></label>
				<label class="block"><span class="mb-2 block text-sm font-extrabold text-brand dark:text-gray-200"><?php echo cfg_t('Секретный ответ', 'Secret answer'); ?></span><input name="aSAnsw" value="John" type="text" class="<?php echo $cfgInputClass; ?>"></label>
				<label class="block md:col-span-2"><span class="mb-2 block text-sm font-extrabold text-brand dark:text-gray-200"><?php echo cfg_t('PIN-код', 'PIN code'); ?></span><input name="aPIN" value="1234" type="text" inputmode="numeric" class="<?php echo $cfgInputClass; ?>"></label>
			</div>
		</section>

		<section class="overflow-hidden rounded-lg border border-gray-100 bg-white shadow-sm dark:border-gray-800 dark:bg-[#151515]">
			<header class="flex items-center gap-3 border-b border-gray-100 bg-gray-50/70 px-6 py-4 dark:border-gray-800 dark:bg-[#1A1A1A]"><span class="flex h-9 w-9 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-500/10 dark:text-blue-300"><i class="fa-solid fa-satellite-dish" aria-hidden="true"></i></span><div><h2 class="text-base font-extrabold text-brand dark:text-white"><?php echo cfg_t('Телеметрия', 'Telemetry'); ?></h2><p class="text-xs font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('Регистрация установки обязательна; публичные агрегаты включены по умолчанию и могут быть отключены позже.', 'Installation registration is required; public aggregates are enabled by default and can be disabled later.'); ?></p></div></header>
			<div class="divide-y divide-gray-100 dark:divide-gray-800">
				<div class="flex items-start justify-between gap-5 px-6 py-5"><span><strong class="block text-sm font-extrabold text-brand dark:text-white"><?php echo cfg_t('Регистрация установки и heartbeat', 'Installation registration and heartbeat'); ?></strong><small class="mt-1 block text-xs font-medium leading-relaxed text-gray-500 dark:text-gray-400"><?php echo cfg_t('Передаются домен, версия H-Script, дата установки и случайный ID. Эта системная регистрация не отключается.', 'The domain, H-Script version, installation date, and a random ID are sent. This system registration cannot be disabled.'); ?></small></span><span class="shrink-0 rounded-full bg-blue-50 px-3 py-1 text-xs font-extrabold text-blue-700 dark:bg-blue-500/10 dark:text-blue-200"><?php echo cfg_t('Обязательно', 'Required'); ?></span></div>
				<label class="flex cursor-pointer items-start justify-between gap-5 px-6 py-5"><span><strong class="block text-sm font-extrabold text-brand dark:text-white"><?php echo cfg_t('Передавать публичные агрегаты раз в сутки', 'Share public aggregates daily'); ?></strong><small class="mt-1 block text-xs font-medium leading-relaxed text-gray-500 dark:text-gray-400"><?php echo cfg_t('Число пользователей и вкладов, онлайн и суммарные показатели без логинов и отдельных операций.', 'User/deposit counts, online count, and totals without logins or individual operations.'); ?></small></span><input name="telemetryStats" value="1" type="checkbox" checked class="mt-1 h-5 w-5 shrink-0 rounded border-gray-300 text-emerald-600"></label>
			</div>
		</section>

		<div class="flex justify-center rounded-lg border border-red-100 bg-white p-5 shadow-sm dark:border-red-500/20 dark:bg-[#151515]">
			<button name="bStart" value="1" type="submit" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-red-600 px-6 py-3 text-sm font-extrabold text-white shadow-lg transition hover:-translate-y-0.5 hover:bg-red-700 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-500/20"><i class="fa-solid fa-database" aria-hidden="true"></i><?php echo cfg_t('Выполнить установку', 'Run installation'); ?></button>
		</div>
	</form>
</section>

<?php

include('module/_config/_footer.php');
	
?>
