<?php

use HScript\Application;
use HScript\Template\View;
use HScript\Telemetry\PublicStats;
use HScript\Telemetry\TelemetryReporter;

$_auth = 99;
require_once('module/auth.php');

$telemetryTranslate = static function (string $key, string $default): string {
	return (string)View::tplTranslate(array(
		'key' => $key,
		'default' => $default,
	));
};

$redirect = static function (): void {
	goToURL(moduleToLink('system/admin/setup_telemetry'));
};
$setFlash = static function (string $type, string $message): void {
	$_SESSION['_telemetry_admin_flash'] = array('type' => $type, 'message' => $message);
};
$save = static function (string $property, mixed $value) use ($db): void {
	if ($db->replace('Cfg', array(
		'Module' => 'Telemetry',
		'Prop' => $property,
		'Val' => $value,
	)) === false)
		throw new RuntimeException('Telemetry settings could not be saved');
};

$action = '';
if (isset_IN('telemetry_settings_btnsave'))
	$action = 'save';
elseif (isset_IN('telemetry_send_btnsend'))
	$action = 'send';

if ($action !== '')
{
	try
	{
		if ($action === 'save')
		{
			View::checkFormSecurity('telemetry_settings');
			$shareStats = empty($_GS['demo']) && isset_IN('share_public_stats');

			$save('Enabled', 1);
			$save('SharePublicStats', $shareStats ? 1 : 0);
			$_cfg['Telemetry_Enabled'] = 1;
			$_cfg['Telemetry_SharePublicStats'] = $shareStats ? 1 : 0;

			$result = (new TelemetryReporter(
				$db,
				$_cfg,
				(string)($_GS['domain'] ?? '')
			))->register();
			$setFlash(
				!empty($result['ok']) ? 'success' : 'warning',
				!empty($result['ok'])
					? $telemetryTranslate('admin.telemetry.flash.saved', 'Настройка агрегатов сохранена, установка зарегистрирована.')
					: $telemetryTranslate('admin.telemetry.flash.saved_offline', 'Настройка сохранена. Центральный сервис сейчас недоступен; cron повторит регистрацию.')
			);
		}
		else
		{
			View::checkFormSecurity('telemetry_send');
			$stats = null;
			if (empty($_GS['demo']) && !empty($_cfg['Telemetry_SharePublicStats']))
			{
				useLib('depo');
				$stats = PublicStats::fromDepositStats(depoGetStat(), $_currs);
			}
			$result = (new TelemetryReporter(
				$db,
				$_cfg,
				(string)($_GS['domain'] ?? '')
			))->report($stats);
			$setFlash(
				!empty($result['ok']) ? 'success' : 'warning',
				!empty($result['ok'])
					? $telemetryTranslate('admin.telemetry.flash.sent', 'Отчёт принят центральным сервисом.')
					: $telemetryTranslate('admin.telemetry.flash.send_failed', 'Отчёт не отправлен. Cron повторит попытку по расписанию.')
			);
		}
	}
	catch (InvalidArgumentException $exception)
	{
		$setFlash('error', $exception->getMessage());
	}
	catch (Throwable $exception)
	{
		error_log('Telemetry admin action failed: ' . $exception->getMessage());
		$setFlash('error', $telemetryTranslate('admin.telemetry.flash.failed', 'Настройки телеметрии сохранить не удалось.'));
	}
	$redirect();
}

$flash = isset($_SESSION['_telemetry_admin_flash']) && is_array($_SESSION['_telemetry_admin_flash'])
	? $_SESSION['_telemetry_admin_flash']
	: array();
unset($_SESSION['_telemetry_admin_flash']);

$formatDate = static function ($timestamp) use ($telemetryTranslate): string {
	return (int)$timestamp > 0
		? gmdate('d.m.Y H:i:s', (int)$timestamp) . ' UTC'
		: $telemetryTranslate('common.no_data', 'Нет данных');
};

$lastAttempt = (int)($_cfg['Telemetry_LastAttemptAt'] ?? 0);
$lastSuccess = (int)($_cfg['Telemetry_LastSuccessAt'] ?? 0);
$lastError = (string)($_cfg['Telemetry_LastError'] ?? '');
$connectionState = empty($_cfg['Telemetry_Registered'])
	? 'waiting'
	: ($lastError !== '' && $lastAttempt > $lastSuccess ? 'error' : 'connected');

View::setPage('telemetry', array(
	'share_public_stats' => !empty($_cfg['Telemetry_SharePublicStats']),
	'demo_stats_isolated' => !empty($_GS['demo']),
	'registered' => !empty($_cfg['Telemetry_Registered']),
	'installation_id' => (string)($_cfg['Telemetry_InstallationID'] ?? ''),
	'domain' => (string)($_cfg['Telemetry_Domain'] ?: ($_GS['domain'] ?? '')),
	'version' => Application::version(),
	'installed_at' => $formatDate($_cfg['Telemetry_InstalledAt'] ?? 0),
	'connection_state' => $connectionState,
	'last_attempt_at' => $formatDate($lastAttempt),
	'last_success_at' => $formatDate($lastSuccess),
	'next_attempt_at' => $formatDate($_cfg['Telemetry_NextAttemptAt'] ?? 0),
	'last_status' => (int)($_cfg['Telemetry_LastStatus'] ?? 0),
	'last_error' => $lastError,
	'dns_status' => (string)($_cfg['Telemetry_DnsStatus'] ?? 'unresolved'),
	'domain_verified' => (int)($_cfg['Telemetry_DomainVerifiedUntil'] ?? 0) > time(),
	'domain_verified_until' => $formatDate($_cfg['Telemetry_DomainVerifiedUntil'] ?? 0),
	'domain_proof_error' => (string)($_cfg['Telemetry_DomainProofError'] ?? ''),
	'dns_checked_at' => $formatDate($_cfg['Telemetry_DnsCheckedAt'] ?? 0),
	'dns_error_code' => (string)($_cfg['Telemetry_DnsErrorCode'] ?? ''),
	'endpoint' => (string)($_cfg['telemetry_endpoint'] ?? 'https://h-script.com/api/v1/installations'),
));
View::setPage('telemetry_flash', $flash);
View::showPage();
