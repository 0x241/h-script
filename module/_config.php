<?php

$_rwlinks = array(
	'index' => array('home', 'audience' => 'indexable public', 'indexable' => true, 'sitemap' => array('changefreq' => 'weekly', 'priority' => '1.0')), // main home page
	'contact' => array('contacts', 'audience' => 'indexable public', 'indexable' => true, 'sitemap' => array('changefreq' => 'yearly', 'priority' => '0.6')),
	'sitemap' => array('sitemap.xml', 'audience' => 'technical callback'),
	'robots' => array('robots.txt', 'audience' => 'technical callback'),
	'cabinet' => array('cabinet', 'audience' => 'authenticated'), // user home page
	
	'system' => array('interface', 'audience' => 'public noindex'), // select interface
	'ajax' => array('ajax', 'audience' => 'technical callback'),

	// REST API v1

	'api/v1/balance' => array('api/v1/balance', 'audience' => 'technical callback'),
	'api/v1/deposit' => array('api/v1/deposit', 'audience' => 'technical callback'),
	'api/v1/withdraw' => array('api/v1/withdraw', 'audience' => 'technical callback'),
	'api/v1/operations' => array('api/v1/operations', 'audience' => 'technical callback'),
	'api/v1/user' => array('api/v1/user', 'audience' => 'technical callback'),
	'api/v1/installations/register' => array('api/v1/installations/register', 'audience' => 'technical callback'),
	'api/v1/installations/report' => array('api/v1/installations/report', 'audience' => 'technical callback'),
	'api/v1/installations/domain-verification' => array('api/v1/installations/domain-verification', 'audience' => 'technical callback'),
	'api/v1/installations/domain-proof' => array('api/v1/installations/domain-proof', 'audience' => 'technical callback'),
	'api/v1/installations/public-stats' => array('api/v1/installations/public-stats', 'audience' => 'technical callback'),
	'api/v1/installations/stats' => array('api/v1/installations/stats', 'audience' => 'technical callback'),

	// Admin panel
	
	'admin' => array('admin', 'audience' => 'authenticated'),

    // Global setup

    'system/admin/setup_main' => array('admin/setup/main', 'audience' => 'authenticated', 'admin' => '{!ru!}Настройки{!en!}Settings{!!}/{!ru!}Основные{!en!}Main'),
    'system/admin/setup_sec' => array('admin/setup/security', 'audience' => 'authenticated', 'admin' => '{!ru!}Настройки{!en!}Settings{!!}/{!ru!}Безопасность{!en!}Security'),
    'system/admin/setup_ui' => array('admin/setup/ui', 'audience' => 'authenticated', 'admin' => '{!ru!}Настройки{!en!}Settings{!!}/{!ru!}Интерфейс{!en!}Interface'),
    'system/admin/setup_mail' => array('admin/setup/mail', 'audience' => 'authenticated', 'admin' => '{!ru!}Настройки{!en!}Settings{!!}/{!ru!}Почта{!en!}Mail'),
    'system/admin/setup_api' => array('admin/setup/api', 'audience' => 'authenticated', 'admin' => '{!ru!}Настройки{!en!}Settings{!!}/API'),
    'system/admin/setup_telemetry' => array(
        'admin/setup/telemetry',
        'audience' => 'authenticated',
        'admin' => '{!ru!}Настройки{!en!}Settings{!!}/{!ru!}Телеметрия{!en!}Telemetry',
        'admin_level' => 99,
    ),
    'system/admin/setup_collector' => array(
        'admin/setup/collector',
        'audience' => 'authenticated',
        'admin' => '{!ru!}Настройки{!en!}Settings{!!}/{!ru!}Collector{!en!}Collector',
        'admin_level' => 99,
        'collector_only' => true,
    ),
    'translations/admin' => array('admin/translations', 'audience' => 'authenticated', 'admin' => '{!ru!}Настройки{!en!}Settings{!!}/{!ru!}Переводы{!en!}Translations'),

    // Scheduler

    'cron' => array('cron', 'audience' => 'technical callback'),
    'cron/admin/setup' => array('admin/cron', 'audience' => 'authenticated', 'admin' => '{!ru!}Настройки{!en!}Settings{!!}/{!ru!}Планировщик{!en!}Cron'),

    // News

	'news' => array('news', 'audience' => 'indexable public', 'indexable' => true, 'sitemap' => array('changefreq' => 'weekly', 'priority' => '0.8')),
	'news/show' => array('show', 'audience' => 'indexable public', 'indexable' => true),
	    'news/admin/newses' => array('admin/newses', 'audience' => 'authenticated', 'admin' => '{!ru!}Новости{!en!}News{!!}/{!ru!}Публикации{!en!}Publications'),
	    'news/admin/news' => array('admin/news', 'audience' => 'authenticated', 'admin' => '{!ru!}Новости{!en!}News{!!}/-'),
	    'news/admin/upload' => array('admin/news/upload', 'audience' => 'authenticated'),
	    'news/admin/setup' => array('admin/news/setup', 'audience' => 'authenticated', 'admin' => '{!ru!}Новости{!en!}News{!!}/{!ru!}Настройки{!en!}Settings'),

    // Account

    'account' => array('account', 'audience' => 'authenticated', 'https' => 0),
    'account/register' => array('registration', 'audience' => 'public noindex', 'https' => 0),
    'account/login' => array('login', 'audience' => 'public noindex', 'https' => 0),
    'account/reset_pass' => array('resetpass', 'audience' => 'public noindex', 'https' => 0),
    'account/change_pass' => array('changepass', 'audience' => 'authenticated', 'https' => 0),
    'account/change_mail' => array('changemail', 'audience' => 'public noindex', 'https' => 0),
    'account/admin/users' => array('admin/account/users', 'audience' => 'authenticated', 'admin' => '{!ru!}Аккаунты{!en!}Accounts{!!}/{!ru!}Пользователи{!en!}Users'),
    'account/admin/user' => array('admin/account/user', 'audience' => 'authenticated', 'admin' => '{!ru!}Аккаунты{!en!}Accounts{!!}/-'),
    'account/admin/user2' => array('admin/account/user/addinfo', 'audience' => 'authenticated', 'admin' => '{!ru!}Аккаунты{!en!}Accounts{!!}/-'),
    'account/admin/ip_stat' => array('admin/account/ip_stat', 'audience' => 'authenticated', 'admin' => '{!ru!}Аккаунты{!en!}Accounts{!!}/{!ru!}IP статистика{!en!}IP statistics'),
    'account/admin/ip' => array('admin/account/ip', 'audience' => 'authenticated', 'admin' => '{!ru!}Аккаунты{!en!}Accounts{!!}/-'),
    'account/admin/setup' => array('admin/account/setup', 'audience' => 'authenticated', 'admin' => '{!ru!}Аккаунты{!en!}Accounts{!!}/{!ru!}Настройки{!en!}Settings'),
    // Balance

    'balance' => array('operations', 'audience' => 'authenticated'),
    'balance/oper' => array('operation', 'audience' => 'authenticated'),
    'balance/status' => array('balance/status', 'audience' => 'technical callback'),
    'balance/wallets' => array('wallets', 'audience' => 'authenticated'),
    'balance/admin/currs' => array('admin/currs', 'audience' => 'authenticated', 'admin' => '{!ru!}Баланс{!en!}Balance{!!}/{!ru!}Платежные системы{!en!}Payment systems'),
    'balance/admin/curr' => array('admin/curr', 'audience' => 'authenticated', 'admin' => '{!ru!}Баланс{!en!}Balance{!!}/-'),
    'balance/admin/opers' => array('admin/opers', 'audience' => 'authenticated', 'admin' => '{!ru!}Баланс{!en!}Balance{!!}/{!ru!}Операции{!en!}Operations'),
    'balance/admin/oper' => array('admin/oper', 'audience' => 'authenticated', 'admin' => '{!ru!}Баланс{!en!}Balance{!!}/-'),
    'balance/admin/setup' => array('admin/balance/setup', 'audience' => 'authenticated', 'admin' => '{!ru!}Баланс{!en!}Balance{!!}/{!ru!}Настройки{!en!}Settings'),
    'balance/admin/ym_api_token' => array('admin/balance/ym_api_token', 'audience' => 'authenticated'),

    // Ref. system

    'refsys' => array('refsys', 'audience' => 'authenticated'),
    'refsys/admin/setup' => array('admin/refsys', 'audience' => 'authenticated', 'admin' => '{!ru!}Настройки{!en!}Settings{!!}/{!ru!}Реф.система{!en!}Referral system'),

    // Calendar

    'calendar/admin/days' => array('admin/days', 'audience' => 'authenticated', 'admin' => '{!ru!}Календарь{!en!}Calendar{!!}/{!ru!}Дни{!en!}Days'),
    'calendar/admin/day' => array('admin/day', 'audience' => 'authenticated', 'admin' => '{!ru!}Календарь{!en!}Calendar{!!}/-'),

    // Deposits

    'depo' => array('deposits', 'audience' => 'authenticated'),
    'depo/depo' => array('deposit', 'audience' => 'authenticated'),
    'depo/admin/plans' => array('admin/plans', 'audience' => 'authenticated', 'admin' => '{!ru!}Вклады{!en!}Deposits{!!}/{!ru!}Планы{!en!}Plans'),
    'depo/admin/plan' => array('admin/plan', 'audience' => 'authenticated', 'admin' => '{!ru!}Вклады{!en!}Deposits{!!}/-'),
    'depo/admin/depos' => array('admin/depos', 'audience' => 'authenticated', 'admin' => '{!ru!}Вклады{!en!}Deposits{!!}/{!ru!}Депозиты{!en!}Deposits'),
    'depo/admin/depo' => array('admin/depo', 'audience' => 'authenticated', 'admin' => '{!ru!}Вклады{!en!}Deposits{!!}/-'),
    'depo/admin/charge' => array('admin/charge', 'audience' => 'authenticated', 'admin' => '{!ru!}Вклады{!en!}Deposits{!!}/{!ru!}Начисление{!en!}Accrual'),
    'depo/admin/setup' => array('admin/depo/setup', 'audience' => 'authenticated', 'admin' => '{!ru!}Вклады{!en!}Deposits{!!}/{!ru!}Настройки{!en!}Setting'),
    'depo/admin/stat' => array('admin/depo/info', 'audience' => 'authenticated', 'admin' => '{!ru!}Вклады{!en!}Deposits{!!}/{!ru!}Статистика{!en!}Statistics'),
    'depo/top' => array('top', 'audience' => 'authenticated'),
    'depo/topin' => array('topin', 'audience' => 'authenticated'),
    'depo/lastin' => array('lastin', 'audience' => 'authenticated'),
    'depo/lastout' => array('lastout', 'audience' => 'authenticated'),
    'depo/calc' => array('calc', 'audience' => 'authenticated'),

    // FAQ

	'faq' => array('faq', 'audience' => 'indexable public', 'indexable' => true, 'sitemap' => array('changefreq' => 'monthly', 'priority' => '0.7')),
    'faq/admin/faqs' => array('admin/faqs', 'audience' => 'authenticated', 'admin' => '{!ru!}FAQ{!en!}FAQ{!!}/{!ru!}Список{!en!}List'),
    'faq/admin/faq' => array('admin/faq', 'audience' => 'authenticated', 'admin' => 'FAQ/-'),
    'faq/admin/setup' => array('admin/faq/setup', 'audience' => 'authenticated', 'admin' => '{!ru!}FAQ{!en!}FAQ{!!}/{!ru!}Настройки{!en!}Settings'),

    // Direct messages and broadcasts

    'message' => array('messages', 'audience' => 'authenticated'),
    'message/show' => array('message', 'audience' => 'authenticated'),
    'message/admin/messages' => array('admin/messages', 'audience' => 'authenticated', 'admin' => '{!ru!}Сообщения{!en!}Messages{!!}/{!ru!}Диалоги{!en!}Conversations'),
    'message/admin/message' => array('admin/message', 'audience' => 'authenticated', 'admin' => '{!ru!}Сообщения{!en!}Messages{!!}/-'),
    'message/admin/setup' => array('admin/message/setup', 'audience' => 'authenticated', 'admin' => '{!ru!}Сообщения{!en!}Messages{!!}/{!ru!}Настройки{!en!}Settings'),
	
	// Tickets
	
	'tickets' => array('tickets', 'audience' => 'authenticated'),
	'tickets/ticket' => array('ticket', 'audience' => 'authenticated'),
	'tickets/newticket' => array('newticket', 'audience' => 'authenticated'),
	'tickets/admin' => array('admin/tickets', 'audience' => 'authenticated', 'admin' => '{!ru!}Поддержка{!en!}Support{!!}/{!ru!}Тикеты{!en!}Tickets'),
	'tickets/admin/ticket' => array('admin/ticket', 'audience' => 'authenticated', 'admin' => '{!ru!}Поддержка{!en!}Support{!!}/-'),
	'tickets/admin/setup' => array('admin/tickets/setup', 'audience' => 'authenticated', 'admin' => '{!ru!}Поддержка{!en!}Support{!!}/{!ru!}Настройки{!en!}Settings'),
	
	// SMS
	
	'sms/admin' => array('admin/sms', 'audience' => 'authenticated', 'admin' => 'SMS/{!ru!}Очередь{!en!}Queue'),
	'sms/admin/send' => array('admin/sms/send', 'audience' => 'authenticated', 'admin' => 'SMS/{!ru!}Отправить{!en!}Send'),
	'sms/admin/setup' => array('admin/sms/setup', 'audience' => 'authenticated', 'admin' => 'SMS/{!ru!}Настройки{!en!}Settings'),

    // Reviews

	'review' => array('reviews', 'audience' => 'indexable public', 'indexable' => true, 'sitemap' => array('changefreq' => 'weekly', 'priority' => '0.7')),
    'review/admin' => array('admin/reviews', 'audience' => 'authenticated', 'admin' => '{!ru!}Отзывы{!en!}Reviews{!!}/{!ru!}Список{!en!}List'),
    'review/admin/review' => array('admin/review', 'audience' => 'authenticated', 'admin' => '{!ru!}Отзывы{!en!}Reviews{!!}/-'),
    'review/admin/setup' => array('admin/reviews/setup', 'audience' => 'authenticated', 'admin' => '{!ru!}Отзывы{!en!}Reviews{!!}/{!ru!}Настройки{!en!}Settings'),

    // Confirm

    'confirm' => array('confirm', 'audience' => 'public noindex'),
    'confirm/admin/setup' => array('admin/confirm', 'audience' => 'authenticated', 'admin' => '{!ru!}Настройки{!en!}Settings{!!}/{!ru!}Подтверждение{!en!}Evidence'),

    // User Defined Pages
    // !!! Insert NEW ADDITIONAL pages here !!!

	'udp/intro' => array('intro', 'audience' => 'indexable public', 'indexable' => true, 'sitemap' => array('changefreq' => 'yearly', 'priority' => '0.4')),
	'udp/rules' => array('rules', 'audience' => 'indexable public', 'indexable' => true, 'sitemap' => array('changefreq' => 'yearly', 'priority' => '0.4')),
	'udp/about' => array('about', 'audience' => 'indexable public', 'indexable' => true, 'sitemap' => array('changefreq' => 'yearly', 'priority' => '0.4')),


    // Captcha

    'captcha' => array('captcha', 'audience' => 'technical callback'),
    'captcha/setup' => array('admin/captcha', 'audience' => 'authenticated', 'admin' => '{!ru!}Настройки{!en!}Settings{!!}/Captcha')
);

$_onload = array(
	'balance' => 0,
	'refsys' => 0,
	'depo' => 0
);

$_onstart = array( // access level (_auth)
	'captcha' => 0,
	'system' => 0,
	'geoip2' => 0,
	'news' => 0,
	'faq' => 0,
	'review' => 0,
	'depo' => 0
);

$_oncron = array( // once in N minutes
	'balance' => 5,
	'depo' => 1,
	'integrity' => 1440,
	'telemetry' => 1440
);

?>
