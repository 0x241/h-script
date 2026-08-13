<?php

declare(strict_types=1);

function hs_env(string $name, string $default = ''): string
{
    $file = getenv($name . '_FILE');
    if ($file !== false && $file !== '' && is_readable($file)) {
        $value = trim((string)file_get_contents($file));
        return $value === '' ? $default : $value;
    }
    $value = getenv($name);
    return ($value === false || $value === '') ? $default : $value;
}

$domain = hs_env('APP_DOMAIN', 'localhost');
$_SERVER += array(
    'SERVER_NAME' => $domain,
    'SCRIPT_NAME' => '/rw.php',
    'SERVER_PORT' => hs_env('APP_HTTPS', '0') === '1' ? 443 : 80,
    'REQUEST_URI' => '/',
    'SERVER_ADDR' => '127.0.0.1',
    'REMOTE_ADDR' => '127.0.0.1'
);

chdir('/var/www/html');
require_once 'vendor/autoload.php';
require_once 'module/_config/password.php';

$sysId = hs_env('APP_SYS_ID');
if ($sysId === '') {
    $sysId = substr(hash('sha256', $domain . hs_env('APP_KEY', 'hscript')), 0, 8);
}

$cfg = array(
    'sys_id' => $sysId,
    'sys_mail' => hs_env('APP_SYS_MAIL', 'admin@' . $domain),
    'cfg_link' => hs_env('APP_CFG_LINK', '_cfg'),
    'db_host' => hs_env('DB_HOST'),
    'db_name' => hs_env('DB_NAME'),
    'db_credentials_env' => 1,
    'db_type' => hs_env('DB_TYPE', '1'),
    'demo_mode' => hs_env('APP_DEMO_MODE', '0'),
    'telemetry_endpoint' => hs_env(
        'TELEMETRY_ENDPOINT',
        'https://h-script.com/api/v1/installations'
    ),
    'telemetry_collector_enabled' => hs_env('TELEMETRY_COLLECTOR_ENABLED', '0'),
    'telemetry_collector_domain' => hs_env('TELEMETRY_COLLECTOR_DOMAIN', 'h-script.com'),
    'telemetry_rate_limit' => hs_env('TELEMETRY_RATE_LIMIT', '30')
);

$httpUserAgent = hs_env('APP_HTTP_USER_AGENT');
if ($httpUserAgent !== '') {
    $cfg['HTTP_UserAgent'] = $httpUserAgent;
}

$turnstileSiteKey = hs_env('TURNSTILE_SITE_KEY');
$turnstileSecretKey = hs_env('TURNSTILE_SECRET_KEY');
if ($turnstileSiteKey !== '' || $turnstileSecretKey !== '') {
    $cfg['Captcha_Service'] = 'turnstile';
    $cfg['Turnstile_SiteKey'] = $turnstileSiteKey;
    $cfg['Turnstile_SecretKey'] = $turnstileSecretKey;
}

$content = "<?php\n\n\$_cfg = " . var_export($cfg, true) . ";\n";
file_put_contents('/var/www/html/_config.php', $content, LOCK_EX);
chmod('/var/www/html/_config.php', 0640);
@chown('/var/www/html/_config.php', 'www-data');
@chgrp('/var/www/html/_config.php', 'www-data');

$cfgPassword = hs_env('CONFIGURATOR_PASSWORD', hs_env('CFG_PASSWORD'));
if ($cfgPassword !== '') {
    file_put_contents('/var/www/html/module/_config/pass', cfgPasswordHash($cfgPassword), LOCK_EX);
    chmod('/var/www/html/module/_config/pass', 0640);
    @chown('/var/www/html/module/_config/pass', 'www-data');
    @chgrp('/var/www/html/module/_config/pass', 'www-data');
}
