<?php

use HScript\Http\ApiTokenRepository;
use HScript\Telemetry\CollectorMode;
use HScript\Telemetry\TelemetryServiceTokenRepository;
use HScript\Util\StringHelper;

use HScript\Template\View;

$_auth = 1;
require_once('module/auth.php');

$accountTranslate = static function (string $key, string $default): string {
	return (string)View::tplTranslate(array(
		'key' => $key,
		'default' => $default,
	));
};

$apiTokenRepository = new ApiTokenRepository($db);
$apiRedirect = static function (): void {
	goToURL(moduleToLink('account') . '?tab=api');
};
$apiSetFlash = static function (string $type, string $message, string $secret = ''): void {
	$_SESSION['_account_api_flash'] = array(
		'type' => $type,
		'message' => $message,
		'secret' => $secret,
	);
};
$apiParseExpiration = static function ($value, bool $mustBeFuture = false) use ($accountTranslate): int {
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
		throw new InvalidArgumentException($accountTranslate('account.api.error.date_invalid', 'Укажите корректную дату окончания токена'));

	$timestamp = $date->getTimestamp();
	if ($mustBeFuture && $timestamp <= time())
		throw new InvalidArgumentException($accountTranslate('account.api.error.date_future', 'Дата окончания нового токена должна быть в будущем'));
	return $timestamp;
};

$serviceTokenSelfService = CollectorMode::enabled(
	$_cfg,
	(string)($_GS['domain'] ?? '')
) && (int)$_user['uLevel'] === TelemetryServiceTokenRepository::ISSUER_LEVEL;
$serviceTokenRedirect = static function (): void {
	goToURL(moduleToLink('account') . '?tab=service');
};
$serviceTokenSetFlash = static function (string $type, string $message, string $secret = ''): void {
	$_SESSION['_account_service_token_flash'] = array(
		'type' => $type,
		'message' => $message,
		'secret' => $secret,
	);
};

$serviceTokenAction = '';
if (isset_IN('account_service_token_create_btncreate'))
	$serviceTokenAction = 'create';
elseif (isset_IN('account_service_token_manage_btnreissue'))
	$serviceTokenAction = 'reissue';
elseif (isset_IN('account_service_token_manage_btnrevoke'))
	$serviceTokenAction = 'revoke';

if ($serviceTokenAction !== '')
{
	try
	{
		if (!$serviceTokenSelfService)
			throw new InvalidArgumentException($accountTranslate('account.service_token.error.unavailable', 'Самостоятельный выпуск сервисного токена недоступен'));
		$form = $serviceTokenAction === 'create'
			? 'account_service_token_create'
			: 'account_service_token_manage';
		View::checkFormSecurity($form);
		if (!empty($_GS['demo']) && (int)_uid() <= 3)
			throw new InvalidArgumentException($accountTranslate('account.service_token.error.demo', 'Изменения сервисного токена недоступны в демонстрационном режиме'));

		$serviceTokenRepository = new TelemetryServiceTokenRepository($db);
		if ($serviceTokenAction === 'create')
		{
			$result = $serviceTokenRepository->issue(
				(int)_uid(),
				(string)_IN('name'),
				$apiParseExpiration(_IN('expires_at'), true)
			);
			$serviceTokenSetFlash(
				'success',
				$accountTranslate('account.service_token.flash.created', 'Сервисный токен создан. Скопируйте секрет сейчас: повторно он показан не будет.'),
				$result['token']
			);
		}
		elseif ($serviceTokenAction === 'reissue')
		{
			$tokenId = (int)_IN('account_service_token_manage_btnreissue');
			$tokens = (array)_IN('tokens');
			$token = isset($tokens[$tokenId]) && is_array($tokens[$tokenId])
				? $tokens[$tokenId]
				: array();
			$result = $serviceTokenRepository->reissue(
				$tokenId,
				(int)_uid(),
				(string)($token['name'] ?? ''),
				$apiParseExpiration($token['expires_at'] ?? '', true)
			);
			$serviceTokenSetFlash(
				'success',
				$accountTranslate('account.service_token.flash.reissued', 'Сервисный токен перевыпущен, предыдущий секрет отозван.'),
				$result['token']
			);
		}
		else
		{
			$tokenId = (int)_IN('account_service_token_manage_btnrevoke');
			if (!$serviceTokenRepository->revoke($tokenId, (int)_uid()))
				throw new InvalidArgumentException($accountTranslate('account.service_token.error.not_found', 'Сервисный токен не найден'));
			$serviceTokenSetFlash('success', $accountTranslate('account.service_token.flash.revoked', 'Сервисный токен отозван без возможности восстановления'));
		}
	}
	catch (InvalidArgumentException $e)
	{
		$messages = array(
			'Collector account not found' => $accountTranslate('account.service_token.error.collector_not_found', 'Аккаунт сборщика не найден или отключён'),
			'Service token not found' => $accountTranslate('account.service_token.error.not_found', 'Сервисный токен не найден'),
			'Token name must contain 1 to 100 characters' => $accountTranslate('account.api.error.name_length', 'Название токена должно содержать от 1 до 100 символов'),
		);
		$serviceTokenSetFlash('error', $messages[$e->getMessage()] ?? $e->getMessage());
	}
	catch (Throwable $e)
	{
		error_log('Account service token action failed: ' . $e->getMessage());
		$serviceTokenSetFlash('error', $accountTranslate('account.service_token.error.failed', 'Операцию с сервисным токеном выполнить не удалось'));
	}
	$serviceTokenRedirect();
}

$apiAction = '';
if (isset_IN('account_api_create_btncreate'))
	$apiAction = 'create';
elseif (isset_IN('account_api_manage_btnsave'))
	$apiAction = 'update';
elseif (isset_IN('account_api_manage_btnrevoke'))
	$apiAction = 'revoke';

if ($apiAction !== '')
{
	try
	{
		$form = $apiAction === 'create' ? 'account_api_create' : 'account_api_manage';
		View::checkFormSecurity($form);
		if (!empty($_GS['demo']) && (int)_uid() <= 3)
			throw new InvalidArgumentException($accountTranslate('account.api.error.demo', 'Изменения API недоступны в демонстрационном режиме'));

		if ($apiAction === 'create')
		{
			if (empty($_cfg['API_Enabled']))
				throw new InvalidArgumentException($accountTranslate('account.api.error.disabled', 'API временно отключён администратором'));
			$result = $apiTokenRepository->issue(
				(int)_uid(),
				(string)_IN('name'),
				(array)_IN('scopes'),
				$apiParseExpiration(_IN('expires_at'), true)
			);
			$apiSetFlash(
				'success',
				$accountTranslate('account.api.flash.created', 'Токен создан. Скопируйте секрет сейчас: повторно он показан не будет.'),
				$result['token']
			);
		}
		elseif ($apiAction === 'update')
		{
			$tokenId = (int)_IN('account_api_manage_btnsave');
			$tokens = (array)_IN('tokens');
			$token = isset($tokens[$tokenId]) && is_array($tokens[$tokenId])
				? $tokens[$tokenId]
				: array();
			$state = (string)($token['state'] ?? '');
			if (!in_array($state, array('active', 'paused'), true))
				throw new InvalidArgumentException($accountTranslate('account.api.error.state', 'Выберите корректное состояние токена'));
			if (!$apiTokenRepository->update(
				$tokenId,
				(string)($token['name'] ?? ''),
				(array)($token['scopes'] ?? array()),
				$apiParseExpiration($token['expires_at'] ?? ''),
				$state === 'active',
				(int)_uid()
			))
				throw new InvalidArgumentException($accountTranslate('account.api.error.not_found', 'Токен не найден'));
			$apiSetFlash('success', $accountTranslate('account.api.flash.saved', 'Настройки токена сохранены'));
		}
		elseif ($apiAction === 'revoke')
		{
			$tokenId = (int)_IN('account_api_manage_btnrevoke');
			if (!$apiTokenRepository->revoke($tokenId, (int)_uid()))
				throw new InvalidArgumentException($accountTranslate('account.api.error.not_found', 'Токен не найден'));
			$apiSetFlash('success', $accountTranslate('account.api.flash.revoked', 'Токен отозван без возможности восстановления'));
		}
	}
	catch (InvalidArgumentException $e)
	{
		$messages = array(
			'Active user not found' => $accountTranslate('account.api.error.user_not_found', 'Активный пользователь не найден'),
			'Token name must contain 1 to 100 characters' => $accountTranslate('account.api.error.name_length', 'Название токена должно содержать от 1 до 100 символов'),
			'At least one API scope is required' => $accountTranslate('account.api.error.scope_required', 'Выберите хотя бы одно разрешение'),
		);
		$apiSetFlash('error', $messages[$e->getMessage()] ?? $e->getMessage());
	}
	catch (Throwable $e)
	{
		error_log('Account API action failed: ' . $e->getMessage());
		$apiSetFlash('error', $accountTranslate('account.api.error.failed', 'Операцию выполнить не удалось'));
	}
	$apiRedirect();
}

try
{
	if (View::sendedForm('', 'avatar'))
	{
		View::checkFormSecurity('avatar');

		if ($_GS['demo'] and ($_user['uLevel'] < 99) and (_uid() <= 3))
			View::showInfo('*Denied');

		if (!isset($_FILES['Avatar']) or ($_FILES['Avatar']['error'] != UPLOAD_ERR_OK))
			View::setError('avatar_empty', 'avatar');
		if ($_FILES['Avatar']['size'] > 5 * 1024 * 1024)
			View::setError('avatar_large', 'avatar');

		require_once('module/files/lib.php');
		$avatar = imageLoad('Avatar');
		if (!$avatar)
			View::setError('avatar_invalid', 'avatar');
		if ((imagesx($avatar) > 6000) or (imagesy($avatar) > 6000))
			View::setError('avatar_large', 'avatar');

		$resized = imageResize($avatar, 256, 256);
		if (!is_dir(AVATAR_DIR) and !mkdir(AVATAR_DIR, 0775, true))
			View::setError('avatar_write', 'avatar');
		$tmp_name = AVATAR_DIR . _uid() . '.tmp';
		$file_name = AVATAR_DIR . _uid() . '.jpg';
		if (!imagejpeg($resized, $tmp_name, 90) or !rename($tmp_name, $file_name))
			View::setError('avatar_write', 'avatar');
		chmod($file_name, 0644);
		imagedestroy($resized);
		imagedestroy($avatar);

		$db->update('AddInfo', array('aAvatar' => time()), 'aAvatar', 'auID=?d', array(_uid()));
		View::showFormInfo('Saved', 'avatar');
	}

	if (View::sendedForm())
	{
		View::checkFormSecurity();
		
		if ($_GS['demo'] and ($_user['uLevel'] < 99) and (_uid() <= 3))
			View::showInfo('*Denied');
			
		$a = $_IN;
		if (($_cfg['Sec_MinPIN'] > 0) and !verifyPasswordWithLegacyDigest($a['PIN'], $_user['uPIN'], $_cfg['Const_Salt'], false))
			View::setError('pin_wrong');
		if (($_cfg['Account_UseName'] > 0) and !$a['aName'])
			View::setError('name_empty');
		if ($_cfg['SMS_REG'])
		{
			$a['aTel'] = preg_replace('|[^\d]|', '', $a['aTel']);
			if (StringHelper::textLen($a['aTel']) < 11)
				View::setError('tel_wrong');
		}
		list($h, $m) = explode(':', $a['TZ'], 2);
		if ((abs($h) > 12) or ($m < 0) or ($m >= 60))
			View::setError('tz_wrong');
		$a['aTZ'] = $h * 60 + $m;
		$a['aTimeOut'] = abs($a['aTimeOut']);
		if ($_cfg['Sec_MinSQA'] > 0)
		{
			if (!$a['aSQuestion'])
				View::setError('secq_empty');
			if (strlen($a['aSQuestion']) < $_cfg['Sec_MinSQA'])
				View::setError('secq_short');
			$f = 'aSQuestion, ';
			if (!StringHelper::sEmpty($a['aSAnswer']))
			{
				if (strlen($a['aSAnswer']) < $_cfg['Sec_MinSQA'])
					View::setError('seca_short');
				if ($a['aSAnswer'] == $a['aSQuestion'])
					View::setError('seqa_equal_secq');
				$a['aSAnswer'] = hashPassword($a['aSAnswer']);
				$f .= 'aSAnswer, ';
			}
		}
		else
			$f = '';
		if ($_cfg['SMS_REG'])
			$f .= 'aTel, ';
		View::strArrayToStamp($a, 'aBD', 1);
		$ga = '';
		if ($gacode = trim($a['GACode']))
		{
			require_once('module/account/ga/class.GoogleAuthenticator.php');
			$ga = new GoogleAuthenticator();
			if (!$ga->checkCode(StringHelper::exValue($a['GAKey'], $_user['aGA']), $gacode))
				View::setError('ga_wrong');
			$a['aGA'] = StringHelper::valueIf($_user['aGA'], '', $a['GAKey']);
			$ga = 'aGA, ';
		}
		$db->update('AddInfo', $a, 
			StringHelper::valueIf($_cfg['Account_UseName'] == 2, 'aName, ') . $f . $ga .
			'aTZ, aIPSec, aSessIP, aSessUniq, aTimeOut, aNoMail, aNeedReConfig', 'auID=?d', array(_uid()));
		View::showInfo('Saved');
	}

} 
catch (FormAbortException $e)
{
}

View::stampArrayToStr($_user, 'aBD', 1);
View::setPage('user', $_user);
View::setPage('utz', sprintf("%+02d:%02d", floor($_user['aTZ'] / 60), abs($_user['aTZ'] % 60)));

$apiScopeOptions = array(
	'*' => $accountTranslate('account.api.scope.all', 'Полный доступ ко всем текущим и будущим методам'),
	'user:read' => $accountTranslate('account.api.scope.user_read', 'Чтение профиля'),
	'balance:read' => $accountTranslate('account.api.scope.balance_read', 'Чтение балансов'),
	'operations:read' => $accountTranslate('account.api.scope.operations_read', 'Чтение истории операций'),
	'deposit:write' => $accountTranslate('account.api.scope.deposit_write', 'Создание операций пополнения'),
	'withdraw:write' => $accountTranslate('account.api.scope.withdraw_write', 'Создание заявок на вывод'),
);
$apiTokens = $apiTokenRepository->listForUser((int)_uid());
$apiTokenCounts = array('active' => 0, 'paused' => 0, 'revoked' => 0);
foreach ($apiTokens as &$apiToken)
{
	$apiToken['ScopeList'] = preg_split('/[\s,]+/', trim((string)$apiToken['atScopes'])) ?: array();
	$apiToken['ExpiresInput'] = (int)$apiToken['atExpiresAt'] > 0
		? gmdate('Y-m-d\TH:i', (int)$apiToken['atExpiresAt'])
		: '';
	$apiToken['CreatedText'] = gmdate('d.m.Y H:i', (int)$apiToken['atCreatedAt']) . ' UTC';
	$apiToken['ExpiresText'] = (int)$apiToken['atExpiresAt'] > 0
		? gmdate('d.m.Y H:i', (int)$apiToken['atExpiresAt']) . ' UTC'
		: $accountTranslate('account.api.no_expiration', 'Без срока');
	$apiToken['LastUsedText'] = (int)$apiToken['atLastUsedAt'] > 0
		? gmdate('d.m.Y H:i', (int)$apiToken['atLastUsedAt']) . ' UTC'
		: $accountTranslate('account.api.never_used', 'Не использовался');
	$apiToken['Expired'] = (int)$apiToken['atExpiresAt'] > 0 && (int)$apiToken['atExpiresAt'] <= time();
	if ((int)$apiToken['atState'] === 1)
		$apiTokenCounts['active']++;
	elseif ((int)$apiToken['atState'] === 2)
		$apiTokenCounts['paused']++;
	else
		$apiTokenCounts['revoked']++;
}
unset($apiToken);

$apiFlash = isset($_SESSION['_account_api_flash']) && is_array($_SESSION['_account_api_flash'])
	? $_SESSION['_account_api_flash']
	: array();
unset($_SESSION['_account_api_flash']);
View::setPage('account_api_settings', array(
	'enabled' => !empty($_cfg['API_Enabled']),
	'rate_limit_ip' => (int)$_cfg['API_RateLimitIP'],
	'rate_limit_token' => (int)$_cfg['API_RateLimitToken'],
));
View::setPage('account_api_scope_options', $apiScopeOptions);
View::setPage('account_api_tokens', $apiTokens);
View::setPage('account_api_token_counts', $apiTokenCounts);
View::setPage('account_api_flash', $apiFlash);
View::setPage('account_api_base_url', getRootURL(!empty($_GS['https'])) . 'api/v1');

$serviceTokens = array();
$serviceTokenFlash = array();
if ($serviceTokenSelfService)
{
	$serviceTokens = (new TelemetryServiceTokenRepository($db))->listForUser((int)_uid());
	foreach ($serviceTokens as &$serviceToken)
	{
		$serviceToken['ExpiresInput'] = (int)$serviceToken['tstExpiresAt'] > 0
			? gmdate('Y-m-d\TH:i', (int)$serviceToken['tstExpiresAt'])
			: '';
		$serviceToken['CreatedText'] = gmdate('d.m.Y H:i', (int)$serviceToken['tstCreatedAt']) . ' UTC';
		$serviceToken['ExpiresText'] = (int)$serviceToken['tstExpiresAt'] > 0
			? gmdate('d.m.Y H:i', (int)$serviceToken['tstExpiresAt']) . ' UTC'
			: $accountTranslate('account.api.no_expiration', 'Без срока');
		$serviceToken['LastUsedText'] = (int)$serviceToken['tstLastUsedAt'] > 0
			? gmdate('d.m.Y H:i', (int)$serviceToken['tstLastUsedAt']) . ' UTC'
			: $accountTranslate('account.api.never_used', 'Не использовался');
		$serviceToken['Expired'] = (int)$serviceToken['tstExpiresAt'] > 0
			&& (int)$serviceToken['tstExpiresAt'] <= time();
	}
	unset($serviceToken);
	$serviceTokenFlash = isset($_SESSION['_account_service_token_flash'])
		&& is_array($_SESSION['_account_service_token_flash'])
		? $_SESSION['_account_service_token_flash']
		: array();
	unset($_SESSION['_account_service_token_flash']);
}
View::setPage('account_service_token_enabled', $serviceTokenSelfService);
View::setPage('account_service_tokens', $serviceTokens);
View::setPage('account_service_token_flash', $serviceTokenFlash);
View::setPage(
	'account_service_stats_url',
	getRootURL(!empty($_GS['https'])) . 'api/v1/installations/stats'
);

if (!$_user['aGA'])
{
	require_once('module/account/ga/class.GoogleAuthenticator.php');
	$ga = new GoogleAuthenticator();
	if (empty($_SESSION['GANewCode']))
		$_SESSION['GANewCode'] = $ga->generateSecret();
	View::setPage('GACode', $_SESSION['GANewCode']);
	View::setPage('GAQR', $ga->getQRUrl($_user['uLogin'] . '@' . $_GS['domain'], $_SESSION['GANewCode']));
}
View::showPage();

?>
