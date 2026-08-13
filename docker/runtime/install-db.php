<?php

declare(strict_types=1);

use HScript\Util\StringHelper;
use HScript\Telemetry\TelemetryReporter;

function hs_install_env(string $name, string $default = ''): string
{
    $file = getenv($name . '_FILE');
    if ($file !== false && $file !== '' && is_readable($file)) {
        $value = trim((string)file_get_contents($file));
        return $value === '' ? $default : $value;
    }
    $value = getenv($name);
    return ($value === false || $value === '') ? $default : $value;
}

function hs_install_bool(string $name, bool $default = false): bool
{
    $value = strtolower(hs_install_env($name, $default ? '1' : '0'));
    return in_array($value, array('1', 'true', 'yes', 'on'), true);
}

function hs_install_secret(string $name): string
{
    $value = hs_install_env($name);
    if ($value !== '') {
        return $value;
    }
    $encoded = hs_install_env($name . '_B64');
    if ($encoded === '') {
        return '';
    }
    $decoded = base64_decode($encoded, true);
    return $decoded === false ? '' : $decoded;
}

function hs_install_seed_user(
    $db,
    int $id,
    string $login,
    string $password,
    string $mail,
    string $pin,
    int $level,
    string $name,
    string $secretQuestion,
    string $secretAnswer,
    string $salt,
    int $ipSecurity = 0
): void {
    $db->insert('Users', array(
        'uID' => $id,
        'uLogin' => $login,
        'uPass' => hashPassword($password),
        'uMail' => $mail,
        'uPIN' => hashPassword($pin),
        'uState' => 1,
        'uLevel' => $level,
        'uPTS' => timeToStamp()
    ));
    $db->insert('AddInfo', array(
        'auID' => $id,
        'aName' => $name,
        'aSQuestion' => $secretQuestion,
        'aSAnswer' => hashPassword($secretAnswer),
        'aIPSec' => $ipSecurity
    ));
}

$autoInstall = hs_install_bool('APP_AUTO_INSTALL');

$domain = hs_install_env('APP_DOMAIN', 'localhost');
$_SERVER += array(
    'SERVER_NAME' => $domain,
    'SCRIPT_NAME' => '/rw.php',
    'SERVER_PORT' => hs_install_env('APP_HTTPS', '0') === '1' ? 443 : 80,
    'REQUEST_URI' => '/',
    'SERVER_ADDR' => '127.0.0.1',
    'REMOTE_ADDR' => '127.0.0.1'
);

chdir('/var/www/html');
require_once 'vendor/autoload.php';

global $_cfg;
$_cfg = array();
require '_config.php';

if (!file_exists('_dbstru.php')) {
    fwrite(STDERR, "Database structure _dbstru.php required\n");
    exit(1);
}

require_once 'module/dbinit.php';

// Older Configurator updates renamed Cfg before attempting an incompatible
// CREATE TABLE ... TYPE= statement. Recover only that exact interrupted state.
$startupTables = $db->fetchRows($db->query('SHOW TABLES'), 1);
if (
    !in_array('Cfg', $startupTables, true)
    && in_array('_Cfg', $startupTables, true)
) {
    $db->query('RENAME TABLE `_Cfg` TO `Cfg`');
    echo "Recovered Cfg after an interrupted legacy database update\n";
    $startupTables = array_values(array_diff($startupTables, array('_Cfg')));
    $startupTables[] = 'Cfg';
}

if (!$autoInstall) {
    exit(0);
}

$adminPassword = hs_install_env('INSTALL_ADMIN_PASSWORD', hs_install_env('ADMIN_PASSWORD'));
$adminSecretAnswer = hs_install_env('INSTALL_ADMIN_SECRET_ANSWER');
$adminPin = hs_install_env('INSTALL_ADMIN_PIN');
$demoMode = !empty($_cfg['demo_mode']) || file_exists('tpl_c/demo');
$demoAdminPassword = hs_install_env('INSTALL_DEMO_ADMIN_PASSWORD');
$missing = array();
if ($adminPassword === '') {
    $missing[] = 'INSTALL_ADMIN_PASSWORD';
}
if ($adminSecretAnswer === '') {
    $missing[] = 'INSTALL_ADMIN_SECRET_ANSWER';
}
if ($adminPin === '') {
    $missing[] = 'INSTALL_ADMIN_PIN';
}
if ($demoMode && $demoAdminPassword === '') {
    $missing[] = 'INSTALL_DEMO_ADMIN_PASSWORD';
}
if ($missing) {
    fwrite(STDERR, implode(', ', $missing) . " required when APP_AUTO_INSTALL=1\n");
    exit(1);
}

require '_dbstru.php';

$tables = $startupTables;
$force = hs_install_bool('APP_INSTALL_FORCE');
if ($tables) {
    if (in_array('Cfg', $tables, true) && $db->count('Cfg') > 0 && !$force) {
        echo "H-Script database already installed, skipping APP_AUTO_INSTALL\n";
        exit(0);
    }
    if (!$force) {
        fwrite(STDERR, "Database is not empty. Set APP_INSTALL_FORCE=1 to recreate it.\n");
        exit(1);
    }
}

$db->query("ALTER DATABASE " . $db->field($_cfg['db_name']) . " DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci");
foreach ($tables as $table) {
    $db->query('DROP TABLE IF EXISTS ' . $db->field($table));
}

$engine = StringHelper::valueIf($_cfg['db_type'], ' ENGINE=' . StringHelper::valueIf($_cfg['db_type'] == 1, 'InnoDB', 'MYISAM'));
foreach ($_dbstru as $table => $command) {
    $db->query("CREATE TABLE $table ($command)$engine CHARACTER SET utf8 COLLATE utf8_general_ci");
}

$adminMail = hs_install_env('INSTALL_ADMIN_MAIL', hs_install_env('APP_SYS_MAIL', $_cfg['sys_mail']));
$adminLogin = hs_install_env('INSTALL_ADMIN_LOGIN', 'admin');
$adminName = hs_install_env('INSTALL_ADMIN_NAME', 'Administrator');
$adminSecretQuestion = hs_install_env('INSTALL_ADMIN_SECRET_QUESTION', 'That is your name');
$mailHost = hs_install_env('INSTALL_MAIL_HOST');
$mailPort = (int)hs_install_env('INSTALL_MAIL_PORT', $mailHost !== '' ? '587' : '25');
if ($mailPort < 1 || $mailPort > 65535) {
    $mailPort = $mailHost !== '' ? 587 : 25;
}
$mailSecure = hs_install_bool('INSTALL_MAIL_SECURE', $mailHost !== '');
$mailUsername = hs_install_env('INSTALL_MAIL_USERNAME');
$mailPassword = hs_install_secret('INSTALL_MAIL_PASSWORD');
$mailFromAddress = hs_install_env('INSTALL_MAIL_FROM_ADDRESS', hs_install_env('APP_SYS_MAIL', $adminMail));
$mailAdminAddress = hs_install_env('INSTALL_MAIL_ADMIN_ADDRESS', $adminMail);
$mailAdminLang = strtolower(hs_install_env('INSTALL_MAIL_ADMIN_LANG', 'en'));
if (!preg_match('/^[a-z]{2,3}(?:-[a-z]{2})?$/', $mailAdminLang)) {
    $mailAdminLang = 'en';
}
$demoAdminMail = hs_install_env('INSTALL_DEMO_ADMIN_MAIL', 'demo-admin@' . $domain);
$demoAdminLogin = hs_install_env('INSTALL_DEMO_ADMIN_LOGIN', 'demo-admin');
$demoAdminName = hs_install_env('INSTALL_DEMO_ADMIN_NAME', 'Demo Administrator');
$demoAdminSecretQuestion = hs_install_env('INSTALL_DEMO_ADMIN_SECRET_QUESTION', 'That is your demo name');
$demoAdminSecretAnswer = hs_install_env('INSTALL_DEMO_ADMIN_SECRET_ANSWER', $adminSecretAnswer);
$demoAdminPin = hs_install_env('INSTALL_DEMO_ADMIN_PIN', $adminPin);
$intCurrId = hs_install_env('INSTALL_INT_CURR_ID', 'USD');
$noLogins = hs_install_bool('INSTALL_NO_LOGINS');
$intCurr = hs_install_bool('INSTALL_INT_CURR');
$telemetryShareStats = hs_install_bool('INSTALL_TELEMETRY_STATS', true);
$telemetryInstalledAt = time();

$psalt = substr(md5(uniqid((string)rand(), true) . time()), 0, rand(6, 10));
$cfg = array(
    'Const' => array(
        'Salt' => $psalt,
        'NoLogins' => $noLogins ? 1 : 0,
        'IntCurr' => $intCurr ? 1 : 0,
        'DBVer' => @filemtime('_dbstru.php')
    ),
    'Sec' => array(
        'BFC' => 1
    ),
    'Confirm' => array(
        'Captcha' => 2
    ),
    'Sys' => array(
        'AdminMail' => $mailAdminAddress,
        'NotifyMail' => $mailFromAddress,
        'AdminLang' => $mailAdminLang,
        'NeedReConfig' => $demoMode ? 0 : 1
    ),
    'Mail' => array(
        'Host' => $mailHost,
        'Port' => $mailPort,
        'Secure' => $mailSecure ? 1 : 0,
        'Username' => $mailUsername,
        'Password' => $mailPassword
    ),
    'UI' => array(
        '_Langs' => "en\r\nru",
        'NumDec' => 2
    ),
    'FAQ' => array(
        'ShowCount' => 10,
        'InBlock' => 0,
        '_Cats' => 'General'
    ),
    'Cron' => array(
        'Enabled' => 1,
        'ByHost' => 1
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
        'Rate' . $intCurrId => 1
    ),
    'Demo' => array(
        'Mode' => $demoMode ? 1 : 0
    ),
    'Telemetry' => array(
        'Enabled' => 1,
        'SharePublicStats' => $telemetryShareStats ? 1 : 0,
        'InstalledAt' => $telemetryInstalledAt
    )
);
if (!empty($_cfg['Captcha_Service']) || !empty($_cfg['Turnstile_SiteKey']) || !empty($_cfg['Turnstile_SecretKey'])) {
    $cfg['Captcha'] = array(
        'Service' => !empty($_cfg['Captcha_Service']) ? $_cfg['Captcha_Service'] : 'turnstile',
        'Turnstile_SiteKey' => isset($_cfg['Turnstile_SiteKey']) ? $_cfg['Turnstile_SiteKey'] : '',
        'Turnstile_SecretKey' => isset($_cfg['Turnstile_SecretKey']) ? $_cfg['Turnstile_SecretKey'] : ''
    );
}
if ($intCurr) {
    $cfg['Bal'] = array('UpdateRates' => 1);
}

foreach ($cfg as $module => $values) {
    foreach ($values as $property => $value) {
        $db->insert('Cfg', array(
            'Module' => $module,
            'Prop' => $property,
            'Val' => $value
        ));
    }
}

$admin = $noLogins ? $adminMail : $adminLogin;
hs_install_seed_user($db, 1, $admin, $adminPassword, $adminMail, $adminPin, 99, $adminName, $adminSecretQuestion, $adminSecretAnswer, $psalt, 4);
if ($demoMode) {
    $demoAdmin = $noLogins ? $demoAdminMail : $demoAdminLogin;
    hs_install_seed_user($db, 2, $demoAdmin, $demoAdminPassword, $demoAdminMail, $demoAdminPin, 90, $demoAdminName, $demoAdminSecretQuestion, $demoAdminSecretAnswer, $psalt);
}
$db->insert('Currs', array(
    'cID' => 1,
    'cDisabled' => !$intCurr,
    'cHidden' => 1,
    'cCID' => '*',
    'cCurrID' => $intCurrId,
    'cCurr' => $intCurrId,
    'cName' => 'Internal',
    'cEXMode' => 2,
    'cTRMode' => 2,
    'cBUYMode' => 2,
    'cBUY2Mode' => 2,
    'cGIVEMode' => 2,
    'cTAKEMode' => 2
));

require_once 'module/faq/lib.php';
faqSeedDefaultRows($db);

$telemetryConfig = $_cfg;
$telemetryConfig['Telemetry_Enabled'] = 1;
$telemetryConfig['Telemetry_SharePublicStats'] = $telemetryShareStats ? 1 : 0;
$telemetryConfig['Telemetry_InstalledAt'] = $telemetryInstalledAt;
$result = (new TelemetryReporter($db, $telemetryConfig, $domain))->register();
echo !empty($result['ok'])
    ? "H-Script installation registered\n"
    : "H-Script installation registry unavailable; cron will retry\n";

echo "H-Script database installation complete\n";
