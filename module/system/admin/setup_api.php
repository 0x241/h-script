<?php

use HScript\Http\ApiTokenRepository;
use HScript\Template\View;

$_auth = 90;
require_once('module/auth.php');

$repository = new ApiTokenRepository($db);
$redirect = static function (): void {
	goToURL(moduleToLink('system/admin/setup_api'));
};
$setFlash = static function (string $type, string $message, string $secret = ''): void {
	$_SESSION['_api_admin_flash'] = array(
		'type' => $type,
		'message' => $message,
		'secret' => $secret,
	);
};
$requireMutationAllowed = static function () use ($_GS, $_user): void {
	if (!empty($_GS['demo']) && (int)$_user['uLevel'] < 99)
		throw new RuntimeException('Изменения API недоступны в демонстрационном режиме');
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
		$date === false ||
		($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) ||
		$date->format('Y-m-d\TH:i') !== $value
	)
		throw new InvalidArgumentException('Укажите корректную дату окончания токена');

	$timestamp = $date->getTimestamp();
	if ($mustBeFuture && $timestamp <= time())
		throw new InvalidArgumentException('Дата окончания нового токена должна быть в будущем');
	return $timestamp;
};

$action = '';
if (isset_IN('api_settings_btnsave'))
	$action = 'settings';
elseif (isset_IN('api_token_create_btncreate'))
	$action = 'create';
elseif (isset_IN('api_token_manage_btnsave'))
	$action = 'update';
elseif (isset_IN('api_token_manage_btnrevoke'))
	$action = 'revoke';
elseif (isset_IN('api_rate_clear_btnclear'))
	$action = 'rate_clear';

if ($action !== '')
{
	try
	{
		$requireMutationAllowed();

		if ($action === 'settings')
		{
			View::checkFormSecurity('api_settings');
			$ipLimit = filter_var(_IN('rate_limit_ip'), FILTER_VALIDATE_INT, array(
				'options' => array('min_range' => 1, 'max_range' => 10000),
			));
			$tokenLimit = filter_var(_IN('rate_limit_token'), FILTER_VALIDATE_INT, array(
				'options' => array('min_range' => 1, 'max_range' => 10000),
			));
			if ($ipLimit === false || $tokenLimit === false)
				throw new InvalidArgumentException('Лимиты должны быть целыми числами от 1 до 10000');

			$values = array(
				'Enabled' => isset_IN('enabled') ? 1 : 0,
				'RateLimitIP' => (int)$ipLimit,
				'RateLimitToken' => (int)$tokenLimit,
			);
			foreach ($values as $prop => $value)
				if ($db->replace('Cfg', array('Module' => 'API', 'Prop' => $prop, 'Val' => $value)) === false)
					throw new RuntimeException('Настройки API не удалось сохранить');

			$setFlash('success', 'Настройки API сохранены');
		}
		elseif ($action === 'create')
		{
			View::checkFormSecurity('api_token_create');
			$result = $repository->issue(
				(int)_IN('user_id'),
				(string)_IN('name'),
				(array)_IN('scopes'),
				$parseExpiration(_IN('expires_at'), true)
			);
			$setFlash(
				'success',
				'Токен создан. Скопируйте секрет сейчас: повторно он показан не будет.',
				$result['token']
			);
		}
		elseif ($action === 'update')
		{
			View::checkFormSecurity('api_token_manage');
			$tokenId = (int)_IN('api_token_manage_btnsave');
			$tokens = (array)_IN('tokens');
			$token = isset($tokens[$tokenId]) && is_array($tokens[$tokenId])
				? $tokens[$tokenId]
				: array();
			$state = (string)($token['state'] ?? '');
			if (!in_array($state, array('active', 'paused'), true))
				throw new InvalidArgumentException('Выберите корректное состояние токена');
			if (!$repository->update(
				$tokenId,
				(string)($token['name'] ?? ''),
				(array)($token['scopes'] ?? array()),
				$parseExpiration($token['expires_at'] ?? ''),
				$state === 'active'
			))
				throw new InvalidArgumentException('Активный или приостановленный токен не найден');
			$setFlash('success', 'Настройки токена сохранены');
		}
		elseif ($action === 'revoke')
		{
			View::checkFormSecurity('api_token_manage');
			$tokenId = (int)_IN('api_token_manage_btnrevoke');
			if (!$repository->revoke($tokenId))
				throw new InvalidArgumentException('Активный или приостановленный токен не найден');
			$setFlash('success', 'Токен отозван без возможности восстановления');
		}
		elseif ($action === 'rate_clear')
		{
			View::checkFormSecurity('api_rate_clear');
			if (!isset_IN('confirm'))
				throw new InvalidArgumentException('Подтвердите очистку счётчиков');
			if ($db->delete('ApiRateLimits') === false)
				throw new RuntimeException('Счётчики не удалось очистить');
			$setFlash('success', 'Счётчики ограничений очищены');
		}
	}
	catch (InvalidArgumentException $e)
	{
		$messages = array(
			'Active user not found' => 'Активный пользователь не найден',
			'Token name must contain 1 to 100 characters' => 'Название токена должно содержать от 1 до 100 символов',
			'At least one API scope is required' => 'Выберите хотя бы одно разрешение',
		);
		$setFlash('error', $messages[$e->getMessage()] ?? $e->getMessage());
	}
	catch (Throwable $e)
	{
		error_log('API admin action failed: ' . $e->getMessage());
		$setFlash('error', 'Операцию выполнить не удалось');
	}
	$redirect();
}

$scopeOptions = array(
	'*' => 'Полный доступ ко всем текущим и будущим методам',
	'user:read' => 'Чтение профиля пользователя',
	'balance:read' => 'Чтение балансов и платёжных систем',
	'operations:read' => 'Чтение истории операций',
	'deposit:write' => 'Создание операций пополнения',
	'withdraw:write' => 'Создание заявок на вывод',
);
$tokens = $repository->listAll();
if (!is_array($tokens))
	$tokens = array();
$tokenCounts = array('active' => 0, 'paused' => 0, 'revoked' => 0);
foreach ($tokens as &$token)
{
	if (empty($token['uLogin']))
		$token['uLogin'] = '[пользователь удалён]';
	$token['ScopeList'] = preg_split('/[\s,]+/', trim((string)$token['atScopes'])) ?: array();
	$token['ExpiresInput'] = (int)$token['atExpiresAt'] > 0
		? gmdate('Y-m-d\TH:i', (int)$token['atExpiresAt'])
		: '';
	$token['CreatedText'] = gmdate('d.m.Y H:i', (int)$token['atCreatedAt']) . ' UTC';
	$token['ExpiresText'] = (int)$token['atExpiresAt'] > 0
		? gmdate('d.m.Y H:i', (int)$token['atExpiresAt']) . ' UTC'
		: 'Без срока';
	$token['LastUsedText'] = (int)$token['atLastUsedAt'] > 0
		? gmdate('d.m.Y H:i', (int)$token['atLastUsedAt']) . ' UTC'
		: 'Не использовался';
	$token['Expired'] = (int)$token['atExpiresAt'] > 0 && (int)$token['atExpiresAt'] <= time();
	if ((int)$token['atState'] === 1)
		$tokenCounts['active']++;
	elseif ((int)$token['atState'] === 2)
		$tokenCounts['paused']++;
	else
		$tokenCounts['revoked']++;
}
unset($token);

$rateStats = $db->fetch1Row($db->select(
	'ApiRateLimits',
	'COUNT(*) AS RowsCount, COALESCE(SUM(arlCount), 0) AS RequestCount, MAX(arlUpdatedAt) AS LastUpdatedAt'
));
$rateStats['RowsCount'] = (int)($rateStats['RowsCount'] ?? 0);
$rateStats['RequestCount'] = (int)($rateStats['RequestCount'] ?? 0);
$rateStats['LastUpdatedText'] = !empty($rateStats['LastUpdatedAt'])
	? gmdate('d.m.Y H:i:s', (int)$rateStats['LastUpdatedAt']) . ' UTC'
	: 'Нет данных';

$flash = isset($_SESSION['_api_admin_flash']) && is_array($_SESSION['_api_admin_flash'])
	? $_SESSION['_api_admin_flash']
	: array();
unset($_SESSION['_api_admin_flash']);

View::setPage('api_settings', array(
	'enabled' => !empty($_cfg['API_Enabled']),
	'rate_limit_ip' => (int)$_cfg['API_RateLimitIP'],
	'rate_limit_token' => (int)$_cfg['API_RateLimitToken'],
));
View::setPage('api_scope_options', $scopeOptions);
View::setPage('api_users', $db->fetchRows($db->select(
	'Users',
	'uID, uLogin, uMail',
	'uState=1',
	array(),
	'uLogin'
)));
View::setPage('api_tokens', $tokens);
View::setPage('api_token_counts', $tokenCounts);
View::setPage('api_rate_stats', $rateStats);
View::setPage('api_flash', $flash);
View::setPage('api_base_url', getRootURL(!empty($_GS['https'])) . 'api/v1');
View::showPage();

?>
