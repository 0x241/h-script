<?php

use HScript\Template\View;
use HScript\Telemetry\CollectorMode;
use HScript\Telemetry\CollectorSchema;
use HScript\Telemetry\InstallationListQuery;
use HScript\Telemetry\InstallationRepository;
use HScript\Telemetry\ServiceTokenListQuery;
use HScript\Telemetry\TelemetryServiceTokenRepository;

$_auth = 99;
require_once('module/auth.php');

if (!CollectorMode::enabled($_cfg, (string)($_GS['domain'] ?? '')))
	View::showInfo('*Denied', moduleToLink('admin'));
$collectorSchemaReady = CollectorSchema::ready($db);
$collectorQueryInput = array();
foreach (array('page', 'per_page', 'q', 'version', 'connection', 'sharing', 'dns_status', 'ip') as $field)
	if (array_key_exists($field, $_GET))
		$collectorQueryInput[$field] = $_GET[$field];
$collectorFilterError = '';
try
{
	$collectorListQuery = InstallationListQuery::fromArray($collectorQueryInput);
}
catch (InvalidArgumentException $exception)
{
	$collectorFilterError = 'Некорректный фильтр: ' . str_replace('query_invalid:', '', $exception->getMessage());
	$collectorListQuery = InstallationListQuery::fromArray(array());
}
$tokenQueryInput = array();
foreach (array('token_page', 'token_per_page', 'token_q', 'token_status') as $field)
	if (array_key_exists($field, $_GET))
		$tokenQueryInput[$field] = $_GET[$field];
$tokenFilterError = '';
try
{
	$tokenListQuery = ServiceTokenListQuery::fromArray($tokenQueryInput);
}
catch (InvalidArgumentException $exception)
{
	$tokenFilterError = 'Некорректный фильтр service token: ' . str_replace('query_invalid:', '', $exception->getMessage());
	$tokenListQuery = ServiceTokenListQuery::fromArray(array());
}

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
		if (!$collectorSchemaReady)
			throw new RuntimeException('Collector schema is not ready');
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
$tokenList = array(
	'pagination' => array('page' => 1, 'per_page' => 25, 'total' => 0, 'total_pages' => 0),
);
$collectorAccounts = (array)$db->fetchRows($db->select(
	'Users',
	'uID, uLogin, uMail, uState',
	'uLevel=?d',
	array(TelemetryServiceTokenRepository::ISSUER_LEVEL),
	'uLogin'
));
$collectorUsers = array();
foreach ($collectorAccounts as &$account)
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
	if ((int)$account['uState'] === 1)
		$collectorUsers[] = $account;
}
unset($account);

if ($collectorSchemaReady)
{
	try
	{
		$collector = array_merge(
			$collector,
			(new InstallationRepository($db))->dashboard($collectorListQuery, true),
			array('ready' => true)
		);
		$tokenRepository = new TelemetryServiceTokenRepository($db);
		$tokenList = $tokenRepository->listPage($tokenListQuery);
		$tokens = $tokenList['tokens'];
		$tokenCounts = $tokenList['summary'];
		$ownerSummaries = $tokenRepository->ownerSummaries();
		foreach ($collectorAccounts as &$account)
		{
			$owner = $ownerSummaries[(int)$account['uID']] ?? array();
			$account['TokenTotal'] = (int)($owner['token_total'] ?? 0);
			$account['TokenActive'] = (int)($owner['token_active'] ?? 0);
			$account['TokenPaused'] = (int)($owner['token_paused'] ?? 0);
			$account['TokenRevoked'] = (int)($owner['token_revoked'] ?? 0);
			$account['LastTokenCreatedAt'] = (int)($owner['last_created_at'] ?? 0);
			$account['LastTokenUsedAt'] = (int)($owner['last_used_at'] ?? 0);
		}
		unset($account);
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
			$installation['dns_checked_at_text'] = $formatDate($installation['dns_checked_at']);
			$installation['domain_verified_until_text'] = $formatDate($installation['domain_verified_until'] ?? 0);
			$installation['active_24h'] = $installation['connection_state'] === 'active';
			foreach ($installation['ip_history'] as &$history)
			{
				$history['first_seen_at_text'] = $formatDate($history['first_seen_at']);
				$history['last_seen_at_text'] = $formatDate($history['last_seen_at']);
			}
			unset($history);
		}
		unset($installation);
	}
	catch (Throwable $exception)
	{
		error_log('Telemetry collector dashboard failed: ' . $exception->getMessage());
		$collector['error'] = 'Таблицы collector ещё не созданы или недоступны.';
	}
}
else
{
	$collector['error'] = 'Требуется миграция схемы collector до версии ' . HScript\Application::schemaVersion() . '.';
}

$collectorBaseUrl = moduleToLink('system/admin/setup_collector');
$buildCollectorUrl = static function (array $parameters) use ($collectorBaseUrl): string {
	$query = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
	return $collectorBaseUrl . ($query === '' ? '' : (str_contains($collectorBaseUrl, '?') ? '&' : '?') . $query);
};
$collectorQueryParameters = $collectorListQuery->queryParameters((int)($collector['pagination']['page'] ?? 1));
$tokenQueryParameters = $tokenListQuery->queryParameters((int)($tokenList['pagination']['page'] ?? 1));
$collectorBuildUrl = static function (int $page) use ($buildCollectorUrl, $collectorQueryParameters, $tokenQueryParameters): string {
	$collectorQueryParameters['page'] = $page;
	return $buildCollectorUrl(array_merge(
		$collectorQueryParameters,
		$tokenQueryParameters
	));
};
$tokenBuildUrl = static function (int $page) use ($buildCollectorUrl, $collectorQueryParameters, $tokenQueryParameters): string {
	$tokenQueryParameters['token_page'] = $page;
	return $buildCollectorUrl(array_merge(
		$collectorQueryParameters,
		$tokenQueryParameters
	));
};
$collectorPagination = array(
	'page' => (int)($collector['pagination']['page'] ?? 1),
	'total_pages' => (int)($collector['pagination']['total_pages'] ?? 0),
	'total' => (int)($collector['pagination']['total'] ?? 0),
	'previous_url' => '',
	'next_url' => '',
	'first_url' => '',
	'last_url' => '',
	'pages' => array(),
);
if ($collectorPagination['total_pages'] > 0)
{
	$currentPage = $collectorPagination['page'];
	$totalPages = $collectorPagination['total_pages'];
	$collectorPagination['first_url'] = $collectorBuildUrl(1);
	$collectorPagination['last_url'] = $collectorBuildUrl($totalPages);
	if ($currentPage > 1)
		$collectorPagination['previous_url'] = $collectorBuildUrl($currentPage - 1);
	if ($currentPage < $totalPages)
		$collectorPagination['next_url'] = $collectorBuildUrl($currentPage + 1);
	$startPage = max(1, $currentPage - 2);
	$endPage = min($totalPages, $currentPage + 2);
	for ($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++)
		$collectorPagination['pages'][] = array(
			'number' => $pageNumber,
			'url' => $collectorBuildUrl($pageNumber),
			'current' => $pageNumber === $currentPage,
		);
}
$tokenPagination = array(
	'page' => (int)($tokenList['pagination']['page'] ?? 1),
	'total_pages' => (int)($tokenList['pagination']['total_pages'] ?? 0),
	'total' => (int)($tokenList['pagination']['total'] ?? 0),
	'previous_url' => '',
	'next_url' => '',
	'first_url' => '',
	'last_url' => '',
	'pages' => array(),
);
if ($tokenPagination['total_pages'] > 0)
{
	$currentPage = $tokenPagination['page'];
	$totalPages = $tokenPagination['total_pages'];
	$tokenPagination['first_url'] = $tokenBuildUrl(1);
	$tokenPagination['last_url'] = $tokenBuildUrl($totalPages);
	if ($currentPage > 1)
		$tokenPagination['previous_url'] = $tokenBuildUrl($currentPage - 1);
	if ($currentPage < $totalPages)
		$tokenPagination['next_url'] = $tokenBuildUrl($currentPage + 1);
	for ($pageNumber = max(1, $currentPage - 2); $pageNumber <= min($totalPages, $currentPage + 2); $pageNumber++)
		$tokenPagination['pages'][] = array(
			'number' => $pageNumber,
			'url' => $tokenBuildUrl($pageNumber),
			'current' => $pageNumber === $currentPage,
		);
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
View::setPage('collector_filter_error', $collectorFilterError);
View::setPage('collector_token_filter_error', $tokenFilterError);
View::setPage('collector_filters', array_merge(
	$collectorListQuery->filters(),
	array(
		'per_page' => $collectorListQuery->perPage(),
		'page_sizes' => InstallationListQuery::PAGE_SIZES,
	)
));
View::setPage('collector_pagination', $collectorPagination);
View::setPage('collector_token_filters', array_merge(
	$tokenListQuery->filters(),
	array(
		'token_per_page' => $tokenListQuery->perPage(),
		'page_sizes' => ServiceTokenListQuery::PAGE_SIZES,
	)
));
View::setPage('collector_token_pagination', $tokenPagination);
View::setPage('collector_token_query', $tokenQueryParameters);
View::setPage('collector_installation_query', $collectorQueryParameters);
View::setPage('collector_clear_url', $buildCollectorUrl($tokenQueryParameters));
View::setPage('collector_token_clear_url', $buildCollectorUrl($collectorQueryParameters));
View::setPage(
	'collector_endpoint',
	getRootURL(!empty($_GS['https'])) . 'api/v1/installations/stats'
);
View::setPage('collector_domain', CollectorMode::expectedDomain($_cfg));
View::setPage(
	'collector_ingestion_enabled',
	CollectorMode::ingestionEnabled($_cfg, (string)($_GS['domain'] ?? ''), $collectorSchemaReady)
);
View::showPage();

?>
