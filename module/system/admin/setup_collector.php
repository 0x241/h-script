<?php

use HScript\Template\View;
use HScript\Telemetry\CollectorMode;
use HScript\Telemetry\InstallationRepository;
use HScript\Telemetry\TelemetryServiceTokenRepository;

$_auth = 99;
require_once('module/auth.php');

if (!CollectorMode::enabled($_cfg, (string)($_GS['domain'] ?? '')))
	View::showInfo('*Denied', moduleToLink('admin'));

$redirect = static function (): void {
	goToURL(moduleToLink('system/admin/setup_collector'));
};
$setFlash = static function (string $type, string $message, string $secret = ''): void {
	$_SESSION['_telemetry_collector_flash'] = array(
		'type' => $type,
		'message' => $message,
		'secret' => $secret,
	);
};
$parseExpiration = static function ($value, bool $mustBeFuture = false): int {
	$value = trim((string)$value);
	if ($value === '')
		return 0;

	$date = DateTimeImmutable::createFromFormat(
		'!Y-m-d\TH:i',
		$value,
		new DateTimeZone('UTC')
	);
	$errors = DateTimeImmutable::getLastErrors();
	if (
		$date === false
		|| ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
		|| $date->format('Y-m-d\TH:i') !== $value
	)
		throw new InvalidArgumentException('Укажите корректную дату окончания токена');

	$timestamp = $date->getTimestamp();
	if ($mustBeFuture && $timestamp <= time())
		throw new InvalidArgumentException('Дата окончания нового токена должна быть в будущем');
	return $timestamp;
};

$action = '';
if (isset_IN('collector_token_create_btncreate'))
	$action = 'create';
elseif (isset_IN('collector_token_manage_btnsave'))
	$action = 'update';
elseif (isset_IN('collector_token_manage_btnrevoke'))
	$action = 'revoke';

if ($action !== '')
{
	try
	{
		$tokenRepository = new TelemetryServiceTokenRepository($db);
		if ($action === 'create')
		{
			View::checkFormSecurity('collector_token_create');
			$result = $tokenRepository->issue(
				(int)_IN('user_id'),
				(string)_IN('name'),
				$parseExpiration(_IN('expires_at'), true)
			);
			$setFlash(
				'success',
				'Service token создан. Скопируйте секрет сейчас: повторно он показан не будет.',
				$result['token']
			);
		}
		elseif ($action === 'update')
		{
			View::checkFormSecurity('collector_token_manage');
			$tokenId = (int)_IN('collector_token_manage_btnsave');
			$tokens = (array)_IN('tokens');
			$token = isset($tokens[$tokenId]) && is_array($tokens[$tokenId])
				? $tokens[$tokenId]
				: array();
			$state = (string)($token['state'] ?? '');
			if (!in_array($state, array('active', 'paused'), true))
				throw new InvalidArgumentException('Выберите корректное состояние токена');
			if (!$tokenRepository->update(
				$tokenId,
				(string)($token['name'] ?? ''),
				$parseExpiration($token['expires_at'] ?? ''),
				$state === 'active'
			))
				throw new InvalidArgumentException('Активный или приостановленный токен не найден');
			$setFlash('success', 'Настройки service token сохранены');
		}
		else
		{
			View::checkFormSecurity('collector_token_manage');
			$tokenId = (int)_IN('collector_token_manage_btnrevoke');
			if (!$tokenRepository->revoke($tokenId))
				throw new InvalidArgumentException('Активный или приостановленный токен не найден');
			$setFlash('success', 'Service token отозван без возможности восстановления');
		}
	}
	catch (InvalidArgumentException $exception)
	{
		$messages = array(
			'Collector account not found' => 'Выберите активного пользователя с уровнем доступа 10',
			'Token name must contain 1 to 100 characters' => 'Название токена должно содержать от 1 до 100 символов',
		);
		$setFlash('error', $messages[$exception->getMessage()] ?? $exception->getMessage());
	}
	catch (Throwable $exception)
	{
		error_log('Telemetry collector admin action failed: ' . $exception->getMessage());
		$setFlash('error', 'Операцию выполнить не удалось. Проверьте миграцию базы.');
	}
	$redirect();
}

$formatDate = static function ($timestamp, string $empty = 'Нет данных'): string {
	return (int)$timestamp > 0
		? gmdate('d.m.Y H:i:s', (int)$timestamp) . ' UTC'
		: $empty;
};
$collector = array(
	'ready' => false,
	'error' => '',
	'summary' => array(
		'installations_total' => 0,
		'platforms_total' => 0,
		'installations_active_24h' => 0,
		'versions' => array(),
	),
	'public_stats' => array(
		'installations_sharing' => 0,
		'users_total' => 0,
	),
	'installations' => array(),
);
$tokens = array();
$tokenCounts = array('active' => 0, 'paused' => 0, 'expired' => 0, 'revoked' => 0);
$collectorAccounts = (array)$db->fetchRows($db->select(
	'Users',
	'uID, uLogin, uMail, uState',
	'uLevel=?d',
	array(TelemetryServiceTokenRepository::ISSUER_LEVEL),
	'uLogin'
));
$collectorUsers = array();
$collectorAccountIndex = array();
foreach ($collectorAccounts as $accountIndex => &$account)
{
	$account['TokenTotal'] = 0;
	$account['TokenActive'] = 0;
	$account['TokenPaused'] = 0;
	$account['TokenRevoked'] = 0;
	$account['LastTokenCreatedAt'] = 0;
	$account['LastTokenUsedAt'] = 0;
	$account['LastTokenCreatedText'] = 'Токены не выпускались';
	$account['LastTokenUsedText'] = 'Не использовались';
	$account['StateText'] = match ((int)$account['uState']) {
		1 => 'Активен',
		2 => 'Наказан',
		3 => 'Заблокирован',
		4 => 'Резерв',
		default => 'Не активен',
	};
	$collectorAccountIndex[(int)$account['uID']] = $accountIndex;
	if ((int)$account['uState'] === 1)
		$collectorUsers[] = $account;
}
unset($account);

try
{
	$collector = array_merge(
		$collector,
		(new InstallationRepository($db))->dashboard(),
		array('ready' => true)
	);
	$tokens = (new TelemetryServiceTokenRepository($db))->listAll();
	foreach ($tokens as &$token)
	{
		if (empty($token['uLogin']))
			$token['uLogin'] = '[администратор удалён]';
		$token['ExpiresInput'] = (int)$token['tstExpiresAt'] > 0
			? gmdate('Y-m-d\TH:i', (int)$token['tstExpiresAt'])
			: '';
		$token['CreatedText'] = $formatDate($token['tstCreatedAt']);
		$token['ExpiresText'] = $formatDate($token['tstExpiresAt'], 'Без срока');
		$token['LastUsedText'] = $formatDate($token['tstLastUsedAt'], 'Не использовался');
		$token['Expired'] = (int)$token['tstExpiresAt'] > 0
			&& (int)$token['tstExpiresAt'] <= time();
		if ((int)$token['tstState'] === 1 && !$token['Expired'])
			$tokenCounts['active']++;
		elseif ((int)$token['tstState'] === 2)
			$tokenCounts['paused']++;
		elseif ($token['Expired'] && (int)$token['tstState'] !== 0)
			$tokenCounts['expired']++;
		else
			$tokenCounts['revoked']++;

		$ownerId = (int)$token['tstuID'];
		if (isset($collectorAccountIndex[$ownerId]))
		{
			$accountIndex = $collectorAccountIndex[$ownerId];
			$collectorAccounts[$accountIndex]['TokenTotal']++;
			$collectorAccounts[$accountIndex]['LastTokenCreatedAt'] = max(
				(int)$collectorAccounts[$accountIndex]['LastTokenCreatedAt'],
				(int)$token['tstCreatedAt']
			);
			$collectorAccounts[$accountIndex]['LastTokenUsedAt'] = max(
				(int)$collectorAccounts[$accountIndex]['LastTokenUsedAt'],
				(int)$token['tstLastUsedAt']
			);
			if ((int)$token['tstState'] === 1 && !$token['Expired'])
				$collectorAccounts[$accountIndex]['TokenActive']++;
			elseif ((int)$token['tstState'] === 2)
				$collectorAccounts[$accountIndex]['TokenPaused']++;
			else
				$collectorAccounts[$accountIndex]['TokenRevoked']++;
		}
	}
	unset($token);

	foreach ($collectorAccounts as &$account)
	{
		$account['LastTokenCreatedText'] = $formatDate(
			$account['LastTokenCreatedAt'],
			'Токены не выпускались'
		);
		$account['LastTokenUsedText'] = $formatDate(
			$account['LastTokenUsedAt'],
			'Не использовались'
		);
	}
	unset($account);

	foreach ($collector['installations'] as &$installation)
	{
		$installation['installed_at_text'] = $formatDate($installation['installed_at']);
		$installation['registered_at_text'] = $formatDate($installation['registered_at']);
		$installation['last_seen_at_text'] = $formatDate($installation['last_seen_at']);
		$installation['last_report_at_text'] = $formatDate($installation['last_report_at']);
		$installation['active_24h'] = (int)$installation['last_seen_at'] >= time() - 86400;
	}
	unset($installation);
}
catch (Throwable $exception)
{
	error_log('Telemetry collector dashboard failed: ' . $exception->getMessage());
	$collector['error'] = 'Таблицы collector ещё не созданы или недоступны.';
}

$flash = isset($_SESSION['_telemetry_collector_flash'])
	&& is_array($_SESSION['_telemetry_collector_flash'])
	? $_SESSION['_telemetry_collector_flash']
	: array();
unset($_SESSION['_telemetry_collector_flash']);

View::setPage('collector', $collector);
View::setPage('collector_tokens', $tokens);
View::setPage('collector_token_counts', $tokenCounts);
View::setPage('collector_users', $collectorUsers);
View::setPage('collector_accounts', $collectorAccounts);
View::setPage('collector_flash', $flash);
View::setPage(
	'collector_endpoint',
	getRootURL(!empty($_GS['https'])) . 'api/v1/installations/stats'
);
View::setPage('collector_domain', CollectorMode::expectedDomain($_cfg));
View::showPage();

?>
