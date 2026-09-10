<?php

use HScript\Update\ConfiguratorCsrf;
use HScript\Update\UpdateRunState;
use HScript\Update\UpdateService;
use HScript\Security\ProductionPreflight;

require_once('module/_config/database_state.php');

if (!function_exists('cfg_update_conflict_reason'))
{
	function cfg_update_conflict_reason(string $reason): string
	{
		return match ($reason) {
			'new_path_collision' => cfg_t('Локальный файл занял новый путь релиза', 'A local file occupies a new release path'),
			'removed_but_locally_changed' => cfg_t('Релиз удаляет локально изменённый файл', 'The release removes a locally changed file'),
			'locally_removed_and_release_changed' => cfg_t('Локально файл удалён, а в релизе изменён', 'The file was removed locally and changed in the release'),
			'local_and_release_changed' => cfg_t('Файл изменён и локально, и в релизе', 'The file changed both locally and in the release'),
			default => cfg_t('Требуется выбор оператора', 'Operator choice is required'),
		};
	}
}

if (!function_exists('cfg_update_error_message'))
{
	function cfg_update_error_message(string $message): string
	{
		$translations = array(
			'The installed CMS already matches or exceeds the latest official GitHub Release' => 'Установлена актуальная или более новая версия CMS.',
			'Only an official release newer than the installed CMS can be prepared' => 'Для установки подходит только официальный релиз новее текущей версии CMS.',
			'Official GitHub release download failed' => 'Не удалось скачать официальный релиз GitHub',
			'PHP curl extension is required for GitHub releases' => 'Для проверки релизов GitHub требуется расширение PHP cURL.',
			'GitHub release response is invalid' => 'GitHub вернул некорректные данные релиза.',
			'GitHub release does not contain the shared-hosting archive and SHA256SUMS' => 'В релизе GitHub отсутствует архив shared hosting или файл SHA256SUMS.',
			'SHA256SUMS does not contain the expected release archive' => 'В SHA256SUMS отсутствует ожидаемый архив релиза.',
			'GitHub release tag is invalid' => 'Тег релиза GitHub имеет некорректный формат.',
			'Only stable SemVer GitHub releases are accepted' => 'Поддерживаются только стабильные SemVer-релизы GitHub.',
			'Draft and prerelease GitHub releases are not accepted' => 'Черновики и предварительные релизы GitHub не устанавливаются.',
			'Another H-Script update is already running' => 'Уже выполняется другое обновление H-Script.',
			'Explicit schema version must be initialized before update' => 'Перед обновлением необходимо один раз зафиксировать текущую версию схемы базы.',
			'Release archive exceeds UPDATE_MAX_PACKAGE_BYTES' => 'Архив релиза превышает разрешённый размер UPDATE_MAX_PACKAGE_BYTES.',
			'Not enough free space to stage release archive' => 'Недостаточно свободного места для подготовки архива релиза.',
			'Release tar.gz upload failed' => 'Не удалось загрузить архив tar.gz.',
			'Release tar.gz was not received through HTTP upload' => 'Архив tar.gz не был получен через безопасную HTTP-загрузку.',
			'Update service is unavailable' => 'Сервис обновления недоступен.',
		);
		foreach ($translations as $english => $russian)
		{
			if ($message === $english) return cfg_t($russian, $message);
			if (str_starts_with($message, $english . ': '))
				return cfg_t(rtrim($russian, '.') . ': ' . substr($message, strlen($english) + 2), $message);
		}
		return cfg_t('Не удалось выполнить операцию обновления. Техническая причина записана в журнал.', $message);
	}
}

if (!function_exists('cfg_update_run_message'))
{
	function cfg_update_run_message(string $code, string $summary): string
	{
		$russian = match ($code) {
			'backup_required' => 'Перед изменением базы требуется проверенный SQL-бэкап.',
			'code_only' => 'Версия схемы не меняется — бэкап базы для этого обновления не требуется.',
			'backup_verified' => 'Проверенный SQL-бэкап создан и привязан к обновлению.',
			'code_activated' => 'Код релиза активирован; состояние сохранено для следующего шага.',
			'current_migration' => str_starts_with($summary, 'Applying migration ')
				? 'Выполняется миграция ' . substr($summary, strlen('Applying migration '))
				: 'Выполняется миграция базы.',
			'migrations_applied' => 'Миграции базы из релиза выполнены.',
			'update_completed' => 'Обновление CMS и базы завершено.',
			'code_rolled_back' => 'Предыдущий набор кода восстановлен; база не изменялась.',
			'docker_image_rolled_back' => 'Возврат к предыдущему Docker-образу зафиксирован; база осталась на исходной версии схемы.',
			'update_cancelled' => 'Обновление отменено до изменения рабочего кода и базы.',
			'retry_update' => 'Безопасное действие: повторить запуск — рабочий релиз ещё не изменён.',
			'retry_migration' => 'Безопасное действие: повторить текущую идемпотентную миграцию.',
			'retry_health' => 'Безопасное действие: повторить итоговую проверку.',
			'restore_backup' => 'Безопасное действие: восстановить привязанный SQL-бэкап по инструкции.',
			'rollback_code' => 'Безопасное действие: вернуть предыдущий набор кода.',
			default => '',
		};
		return $russian !== '' ? cfg_t($russian, $summary) : cfg_t('Техническое состояние запуска сохранено.', $summary);
	}
}

$cfgDomain = (string)($_GS['domain'] ?? 'localhost');
$updatePreflight = new ProductionPreflight(dirname(__DIR__, 2), hsIsHttpsRequest(), trim((string)(getenv('APP_RELEASE_VERSION') ?: '')) !== '');
$updatePreflightBlockers = $updatePreflight->forOperation('update');
$updateStatus = cfg_update_status($_cfg, $cfgDomain);
$latestRun = $updateStatus['latest_run'];
$schemaGateReason = (string)($updateStatus['schema_gate']['reason'] ?? '');
$metadataMissing = !$updateStatus['framework_ready'] || in_array($schemaGateReason, array('schema_metadata_missing', 'application_metadata_missing'), true);
$acknowledgedApplication = $updateStatus['installed_application_version'] ?? 'SOURCE_CMS_VERSION';
$acknowledgedSchema = $updateStatus['installed_schema_version'] ?? 'SOURCE_SCHEMA_VERSION';
$bootstrapCommand = 'APP_DOMAIN=' . escapeshellarg($cfgDomain)
	. ' php bin/update.php bootstrap --acknowledge-application=' . $acknowledgedApplication
	. ' --acknowledge-schema=' . $acknowledgedSchema;
$updateService = null;
$updateServiceError = '';
if ($updateStatus['framework_ready'])
{
	try
	{
		require_once('module/dbinit.php');
		$updateService = new UpdateService($db, $_cfg, $cfgDomain, dirname(__DIR__, 2));
		$updateStatus = cfg_update_status($_cfg, $cfgDomain);
		$latestRun = $updateStatus['latest_run'];
		$schemaGateReason = (string)($updateStatus['schema_gate']['reason'] ?? '');
		$metadataMissing = in_array($schemaGateReason, array('schema_metadata_missing', 'application_metadata_missing'), true);
	}
	catch (Throwable $exception)
	{
		$updateServiceError = $exception->getMessage();
		error_log('Configurator update initialization failed: ' . $updateServiceError);
	}
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST')
{
	$redirectPrepared = '';
	try
	{
		ConfiguratorCsrf::consume($_POST['csrf'] ?? '');
		if (!$updateService instanceof UpdateService)
			throw new RuntimeException($updateServiceError !== '' ? $updateServiceError : 'Update service is unavailable');
		$action = (string)($_POST['updateAction'] ?? '');
		if (in_array($action, array('prepare_github', 'prepare_upload', 'apply_bundled', 'apply', 'resume'), true))
			$updatePreflight->assertAllows('update');
		if ($action === 'prepare_github')
		{
			$prepared = $updateService->prepareLatest();
			$redirectPrepared = $prepared['id'];
			addMsg(cfg_t('Официальный GitHub Release проверен по SHA256SUMS и подготовлен.', 'Official GitHub Release verified against SHA256SUMS and prepared.'));
		}
		elseif ($action === 'prepare_upload')
		{
			$upload = $_FILES['releasePackage'] ?? null;
			if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)
				throw new RuntimeException('Release tar.gz upload failed');
			$temporary = (string)($upload['tmp_name'] ?? '');
			if (!is_uploaded_file($temporary))
				throw new RuntimeException('Release tar.gz was not received through HTTP upload');
			$prepared = $updateService->prepareManual($temporary);
			$redirectPrepared = $prepared['id'];
			addMsg(cfg_t('Архив совпадает с официальным GitHub Release и подготовлен.', 'Archive matches the official GitHub Release and is prepared.'));
		}
		elseif ($action === 'apply_bundled')
		{
			$result = $updateService->applyBundled();
			addMsg(cfg_t('Обновление из текущего Docker-образа завершено. Запуск: ', 'Update from the current Docker image completed. Run: ') . $result['run']['urID']);
		}
		elseif ($action === 'apply')
		{
			$choices = isset($_POST['fileChoice']) && is_array($_POST['fileChoice']) ? $_POST['fileChoice'] : array();
			$result = $updateService->apply((string)($_POST['preparedId'] ?? ''), $choices);
			addMsg(cfg_t('CMS и база успешно обновлены. Запуск: ', 'CMS and database updated successfully. Run: ') . $result['run']['urID']);
		}
		elseif ($action === 'resume')
		{
			$result = $updateService->resume((string)($_POST['runId'] ?? ''), array());
			addMsg(cfg_t('Обновление успешно продолжено. Запуск: ', 'Update resumed successfully. Run: ') . $result['run']['urID']);
		}
		elseif ($action === 'rollback_code')
		{
			$result = $updateService->rollbackCode((string)($_POST['runId'] ?? ''));
			addMsg(cfg_t('Предыдущий набор кода восстановлен; база не изменялась.', 'Previous code set restored; database was not changed.'));
		}
		elseif ($action === 'cancel')
		{
			$result = $updateService->cancel((string)($_POST['runId'] ?? ''));
			addMsg(($result['urMessageCode'] ?? '') === 'docker_image_rolled_back'
				? cfg_t('Откат к предыдущему Docker-образу зафиксирован; схема базы не менялась.', 'Rollback to the previous Docker image was recorded; the database schema was unchanged.')
				: cfg_t('Обновление отменено до изменения рабочего кода и базы.', 'Update cancelled before live code and database changes.'));
		}
		else
			throw new RuntimeException('Unknown update action');
		$cfgSecurity->audit('update_' . $action, 'success', $cfgClientIp, array('run' => (string)($result['run']['urID'] ?? '')));
	}
	catch (Throwable $exception)
	{
		$noNewRelease = $exception->getMessage() === 'The installed CMS already matches or exceeds the latest official GitHub Release';
		$cfgSecurity->audit('update_action', $noNewRelease ? 'no_change' : 'failed', $cfgClientIp, array('reason' => $noNewRelease ? 'current_version' : 'operation'));
		if (!$noNewRelease) error_log('Configurator update action failed: ' . $exception->getMessage());
		addMsg(($noNewRelease ? cfg_t('Обновления не найдены: ', 'No update found: ') : cfg_t('Обновление остановлено: ', 'Update stopped: ')) . cfg_update_error_message($exception->getMessage()));
	}
	goToURL($_cfg['cfg_link'] . '?update' . ($redirectPrepared !== '' ? '&prepared=' . rawurlencode($redirectPrepared) : ''));
}

$prepared = null;
$preparedId = (string)($_GET['prepared'] ?? '');
if ($preparedId !== '' && $updateService instanceof UpdateService)
{
	try
	{
		$prepared = $updateService->prepared($preparedId);
	}
	catch (Throwable $exception)
	{
		$updateServiceError = $exception->getMessage();
	}
}
if ($prepared === null && $latestRun && $updateService instanceof UpdateService)
{
	try { $prepared = $updateService->preparedForRun((string)$latestRun['urID']); }
	catch (Throwable) {}
}
$updateCsrf = ConfiguratorCsrf::token();
$deploymentMode = $updateService instanceof UpdateService ? $updateService->deploymentMode() : 'shared-hosting';
$canRecordDockerRollback = $deploymentMode === 'docker' && $prepared && $latestRun
	&& $prepared['source'] === 'bundled' && (string)$latestRun['urState'] === UpdateRunState::HEALTH
	&& (string)$latestRun['urSourceVersion'] === $updateStatus['application_version']
	&& (string)$latestRun['urSourceVersion'] === ($updateStatus['installed_application_version'] ?? null)
	&& (string)$latestRun['urSourceSchemaVersion'] === ($updateStatus['installed_schema_version'] ?? null);
$currentCmsVersion = (string)$updateStatus['application_version'];
$recordedCmsVersion = (string)($updateStatus['installed_application_version'] ?? '—');
$installedSchemaVersion = (string)($updateStatus['installed_schema_version'] ?? '—');
$targetSchemaVersion = (string)$updateStatus['target_schema_version'];
$dockerUpdatePending = $deploymentMode === 'docker'
	&& ($recordedCmsVersion !== $currentCmsVersion || $installedSchemaVersion !== $targetSchemaVersion);
$latestRunState = (string)($latestRun['urState'] ?? '');
$preparedClassification = (string)($prepared['manifest']['classification'] ?? '');
$databaseWillChange = $preparedClassification !== ''
	? $preparedClassification !== 'code-only'
	: $installedSchemaVersion !== '—' && $installedSchemaVersion !== $targetSchemaVersion;
$statusTone = 'emerald';
$statusIcon = 'fa-circle-check';
$statusTitle = cfg_t('Готово к проверке обновлений', 'Ready to check for updates');
$statusText = cfg_t('Проверка безопасна: рабочие файлы и база не меняются, пока вы не подтвердите найденный релиз.', 'The check is safe: live files and the database stay unchanged until you confirm a release.');
if ($metadataMissing)
{
	$statusTone = 'amber';
	$statusIcon = 'fa-triangle-exclamation';
	$statusTitle = cfg_t('Нужно один раз зафиксировать версии', 'Record versions once');
	$statusText = cfg_t('Без исходных версий конфигуратор не сможет безопасно построить план обновления.', 'The Configurator cannot build a safe update plan without source versions.');
}
elseif ($updateServiceError !== '' || $schemaGateReason === 'unsupported_source_version')
{
	$statusTone = 'red';
	$statusIcon = 'fa-circle-xmark';
	$statusTitle = cfg_t('Обновление заблокировано', 'Update is blocked');
	$statusText = cfg_t('Данные не изменялись. Прочитайте причину ниже и устраните её перед продолжением.', 'No data was changed. Read the reason below and resolve it before continuing.');
}
elseif ($latestRunState === UpdateRunState::COMPLETED)
{
	$statusTitle = cfg_t('Последнее обновление завершено', 'Last update completed');
	$statusText = cfg_t('CMS и состояние базы прошли итоговую проверку.', 'The CMS and database state passed the final health check.');
}
elseif ($latestRunState === UpdateRunState::FAILED)
{
	$statusTone = 'red';
	$statusIcon = 'fa-circle-xmark';
	$statusTitle = cfg_t('Последнее обновление остановлено', 'Last update stopped');
	$statusText = cfg_t('Успех не зафиксирован. Причина и доступное действие показаны в блоке последнего запуска.', 'Success was not recorded. The reason and available action are shown in the latest-run block.');
}
elseif ($latestRunState !== '' && !UpdateRunState::terminal($latestRunState))
{
	$statusTone = 'amber';
	$statusIcon = 'fa-clock-rotate-left';
	$statusTitle = cfg_t('Обновление ждёт продолжения', 'Update is waiting to continue');
	$statusText = cfg_t('Конфигуратор сохранил состояние. Используйте безопасное действие в блоке текущего запуска.', 'The Configurator saved its state. Use the safe action in the current-run block.');
}
elseif ($prepared)
{
	$statusIcon = 'fa-circle-check';
	$statusTitle = cfg_t('Релиз проверен и готов', 'Release verified and ready');
	$statusText = cfg_t('Ниже показано, что изменится. Запуск начнётся только после вашего подтверждения.', 'See what will change below. The update starts only after your confirmation.');
}
elseif ($dockerUpdatePending)
{
	$statusTone = 'amber';
	$statusIcon = 'fa-box-open';
	$statusTitle = cfg_t('Новый Docker-образ ждёт завершения', 'New Docker image is waiting');
	$statusText = cfg_t('Код уже обновлён образом. Конфигуратор проверит и при необходимости обновит базу.', 'The image already updated the code. The Configurator will verify and update the database if needed.');
}
$statusClasses = array(
	'emerald' => 'border-emerald-200 bg-emerald-50 text-emerald-950 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-100',
	'amber' => 'border-amber-200 bg-amber-50 text-amber-950 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100',
	'red' => 'border-red-200 bg-red-50 text-red-950 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-100',
);
$runStateLabels = array(
	UpdateRunState::PREFLIGHT => cfg_t('Предварительная проверка', 'Preflight'),
	UpdateRunState::BACKUP => cfg_t('Создание бэкапа', 'Creating backup'),
	UpdateRunState::PACKAGE => cfg_t('Обновление файлов', 'Updating files'),
	UpdateRunState::MIGRATION => cfg_t('Обновление базы', 'Updating database'),
	UpdateRunState::HEALTH => cfg_t('Итоговая проверка', 'Health check'),
	UpdateRunState::COMPLETED => cfg_t('Завершено', 'Completed'),
	UpdateRunState::FAILED => cfg_t('Остановлено', 'Stopped'),
);

include('module/_config/_header.php');
?>

<section class="mx-auto max-w-5xl space-y-6">
	<header>
		<span class="mb-3 inline-flex items-center gap-2 text-xs font-extrabold uppercase tracking-wider text-emerald-600 dark:text-emerald-400"><i class="fa-solid fa-rotate" aria-hidden="true"></i><?php echo cfg_t('Обслуживание', 'Maintenance'); ?></span>
		<h1 class="text-3xl font-black text-brand dark:text-white sm:text-4xl"><?php echo cfg_t('Обновление H-Script', 'H-Script update'); ?></h1>
		<p class="mt-2 max-w-3xl text-sm font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('Проверка релиза, резервная копия при необходимости и обновление CMS с базой — в одном сценарии.', 'Release verification, backup when needed, and CMS/database update in one flow.'); ?></p>
	</header>

	<div class="flex flex-col gap-5 rounded-lg border p-6 sm:flex-row sm:items-center sm:justify-between <?php echo $statusClasses[$statusTone]; ?>">
		<div class="flex min-w-0 items-start gap-4">
			<span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-white/70 text-lg shadow-sm dark:bg-black/20"><i class="fa-solid <?php echo $statusIcon; ?>" aria-hidden="true"></i></span>
			<div><h2 class="text-lg font-black"><?php echo $statusTitle; ?></h2><p class="mt-1 max-w-2xl text-sm font-medium opacity-80"><?php echo $statusText; ?></p></div>
		</div>
		<div class="flex shrink-0 gap-2 text-sm font-extrabold">
			<span class="rounded-full bg-white/70 px-4 py-2 shadow-sm dark:bg-black/20">CMS <?php echo htmlspecialchars($currentCmsVersion, ENT_QUOTES, 'UTF-8'); ?></span>
			<span class="rounded-full bg-white/70 px-4 py-2 shadow-sm dark:bg-black/20"><?php echo cfg_t('База', 'DB'); ?> <?php echo htmlspecialchars($installedSchemaVersion, ENT_QUOTES, 'UTF-8'); ?></span>
		</div>
	</div>
	<?php if ($updatePreflightBlockers) { ?>
		<aside class="rounded-lg border border-red-200 bg-red-50 p-5 text-sm text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-100"><strong class="font-extrabold"><?php echo cfg_t('Обновление заблокировано предварительной проверкой:', 'Update is blocked by preflight:'); ?></strong><span class="ml-1"><?php echo htmlspecialchars(implode(', ', array_column($updatePreflightBlockers, 'label')), ENT_QUOTES, 'UTF-8'); ?></span> <a class="font-extrabold underline" href="?security"><?php echo cfg_t('Открыть безопасность', 'Open security'); ?></a></aside>
	<?php } ?>

	<?php if ($schemaGateReason === 'unsupported_source_version') { ?>
		<aside class="rounded-lg border border-red-200 bg-red-50 p-5 text-sm font-bold text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-100"><?php echo cfg_t('Новый Docker-образ не поддерживает записанную исходную версию CMS или базы. Верните предыдущий точный image tag; завершение обновления из этого образа заблокировано до изменения базы.', 'The new Docker image does not support the recorded source CMS or database version. Restore the previous exact image tag; update completion from this image is blocked before database changes.'); ?></aside>
	<?php } ?>

	<?php if ($metadataMissing) { ?>
		<aside class="rounded-lg border border-amber-200 bg-amber-50 p-5 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
			<strong class="block text-sm font-extrabold"><?php echo cfg_t('Укажите исходные версии CMS и схемы', 'Record the source CMS and schema versions'); ?></strong>
			<p class="mt-2 text-xs font-medium"><?php echo cfg_t('Замените placeholders в команде на фактические версии установки до замены Docker-образа. Команда добавляет только служебные записи обновлений и не перестраивает рабочие таблицы.', 'Replace the command placeholders with the actual versions installed before the Docker image was changed. The command only adds update metadata and never rebuilds application tables.'); ?></p>
			<pre class="mt-4 overflow-x-auto rounded-lg bg-white/70 p-4 text-xs dark:bg-black/20"><code><?php echo htmlspecialchars($bootstrapCommand, ENT_QUOTES, 'UTF-8'); ?></code></pre>
		</aside>
	<?php } elseif ($updateServiceError !== '') { ?>
		<aside class="rounded-lg border border-red-200 bg-red-50 p-5 text-sm font-bold text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200"><?php echo htmlspecialchars(cfg_t('Обновление недоступно: ', 'Update unavailable: ') . cfg_update_error_message($updateServiceError), ENT_QUOTES, 'UTF-8'); ?></aside>
	<?php } elseif ($deploymentMode === 'docker') { ?>
		<section class="rounded-lg border border-gray-100 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-[#151515]">
			<div class="flex items-start gap-4"><span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-500/10 dark:text-blue-300"><i class="fa-brands fa-docker" aria-hidden="true"></i></span><div><h2 class="text-lg font-extrabold text-brand dark:text-white"><?php echo cfg_t('Обновление через Docker-образ', 'Update through a Docker image'); ?></h2><p class="mt-1 text-sm font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('Сначала смените точный тег официального образа. После запуска нового контейнера вернитесь сюда и завершите обновление.', 'First change the exact official image tag. After the new container starts, return here and complete the update.'); ?></p></div></div>
			<?php if ($schemaGateReason !== 'unsupported_source_version' && $dockerUpdatePending) { ?>
				<form method="post" hx-boost="false" class="mt-6 rounded-lg border border-amber-200 bg-amber-50 p-5 dark:border-amber-500/30 dark:bg-amber-500/10">
					<input type="hidden" name="csrf" value="<?php echo htmlspecialchars($updateCsrf, ENT_QUOTES, 'UTF-8'); ?>">
					<input type="hidden" name="updateAction" value="apply_bundled">
					<strong class="block text-sm font-extrabold"><?php echo $databaseWillChange ? cfg_t('Сначала SQL-бэкап, затем миграции базы', 'SQL backup first, then database migrations') : cfg_t('База не изменится', 'The database will not change'); ?></strong>
					<p class="mt-2 text-xs font-medium"><?php echo $databaseWillChange ? cfg_t('Конфигуратор создаст и проверит бэкап, применит миграции из образа по порядку и выполнит итоговую проверку.', 'The Configurator creates and verifies a backup, applies image migrations in order, and runs a final health check.') : cfg_t('Версия структуры совпадает. Будет выполнена только итоговая проверка и фиксация новой версии CMS.', 'The schema version matches. Only the final health check and CMS version recording will run.'); ?></p>
					<button type="submit" class="<?php echo $cfgButtonClass; ?> mt-4"><i class="fa-solid fa-play" aria-hidden="true"></i><?php echo cfg_t('Проверить и завершить', 'Verify and complete'); ?></button>
				</form>
			<?php } elseif ($schemaGateReason !== 'unsupported_source_version') { ?>
				<p class="mt-6 rounded-lg bg-emerald-50 p-4 text-sm font-bold text-emerald-900 dark:bg-emerald-500/10 dark:text-emerald-100"><i class="fa-solid fa-circle-check mr-2" aria-hidden="true"></i><?php echo cfg_t('Код CMS и схема базы согласованы.', 'CMS code and database schema are aligned.'); ?></p>
			<?php } ?>
			<details class="mt-5 rounded-lg border border-gray-100 p-4 dark:border-gray-800">
				<summary class="cursor-pointer text-sm font-extrabold text-brand dark:text-white"><?php echo cfg_t('Команды обновления образа', 'Image update commands'); ?></summary>
				<pre class="mt-4 overflow-x-auto rounded-lg bg-gray-950 p-4 text-xs text-gray-100"><code># Укажите точную новую версию в .env
APP_IMAGE_TAG=&lt;новая-версия&gt;
docker compose pull app cron
docker compose up -d --no-build app
# После успешной миграции базы:
docker compose up -d --no-build cron</code></pre>
				<a class="mt-3 inline-flex text-sm font-extrabold text-emerald-600" href="https://github.com/0x241/h-script/releases" target="_blank" rel="noopener noreferrer"><?php echo cfg_t('Открыть официальные релизы', 'Open official releases'); ?></a>
			</details>
			<p class="mt-4 text-xs font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('Важно: изменения внутри контейнера не сохраняются при его замене. Доработки держите в собственном image, модуле, теме или подключённом volume.', 'Important: changes inside a container do not survive replacement. Keep customizations in a derived image, module, theme, or mounted volume.'); ?></p>
		</section>
	<?php } else { ?>
		<section class="rounded-lg border border-gray-100 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-[#151515]">
			<div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
				<div><h2 class="text-lg font-extrabold text-brand dark:text-white"><?php echo cfg_t('Проверить новую версию', 'Check for a new version'); ?></h2><p class="mt-1 max-w-xl text-sm font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('Конфигуратор скачает готовый архив официального GitHub Release и сверит его SHA-256. На этом шаге ничего не устанавливается.', 'The Configurator downloads the official GitHub Release archive and verifies its SHA-256. Nothing is installed at this step.'); ?></p></div>
				<form method="post" hx-boost="false" class="shrink-0">
					<input type="hidden" name="csrf" value="<?php echo htmlspecialchars($updateCsrf, ENT_QUOTES, 'UTF-8'); ?>">
					<input type="hidden" name="updateAction" value="prepare_github">
					<button type="submit" class="<?php echo $cfgButtonClass; ?>"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><?php echo cfg_t('Проверить обновления', 'Check for updates'); ?></button>
				</form>
			</div>
			<details class="mt-5 rounded-lg border border-gray-100 p-4 dark:border-gray-800">
				<summary class="cursor-pointer text-sm font-extrabold text-brand dark:text-white"><?php echo cfg_t('У меня уже скачан официальный архив', 'I already downloaded the official archive'); ?></summary>
				<form method="post" enctype="multipart/form-data" hx-boost="false" class="mt-4">
					<input type="hidden" name="csrf" value="<?php echo htmlspecialchars($updateCsrf, ENT_QUOTES, 'UTF-8'); ?>">
					<input type="hidden" name="updateAction" value="prepare_upload">
					<label class="block text-sm font-extrabold" for="releasePackage"><?php echo cfg_t('Архив h-script-X.Y.Z-shared-hosting.tar.gz', 'h-script-X.Y.Z-shared-hosting.tar.gz archive'); ?></label>
					<input id="releasePackage" name="releasePackage" type="file" accept=".tar.gz,application/gzip" required class="<?php echo $cfgInputClass; ?> mt-3">
					<button type="submit" class="<?php echo $cfgButtonClass; ?> mt-4"><?php echo cfg_t('Загрузить и проверить', 'Upload and verify'); ?></button>
				</form>
			</details>
		</section>

		<?php if ($prepared && ($prepared['run_id'] === '' || ($latestRunState !== '' && !UpdateRunState::terminal($latestRunState)))) { $release = $prepared['manifest']['release']; ?>
			<section class="rounded-lg border border-emerald-200 bg-white p-6 shadow-sm dark:border-emerald-500/30 dark:bg-[#151515]">
				<div class="flex flex-wrap items-start justify-between gap-4"><div><span class="text-xs font-extrabold uppercase tracking-wider text-emerald-600"><?php echo cfg_t('Официальный релиз проверен', 'Official release verified'); ?></span><h2 class="mt-1 text-xl font-black"><?php echo cfg_t('Обновление до версии ', 'Update to version '); ?><?php echo htmlspecialchars($release['application_version'], ENT_QUOTES, 'UTF-8'); ?></h2></div><span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-extrabold dark:bg-gray-800"><?php echo $preparedClassification === 'code-only' ? cfg_t('Только CMS', 'CMS only') : ($preparedClassification === 'irreversible' ? cfg_t('Необратимая миграция', 'Irreversible migration') : cfg_t('CMS и база', 'CMS and database')); ?></span></div>
				<p class="mt-4 text-sm font-bold"><?php echo htmlspecialchars($release['summary'], ENT_QUOTES, 'UTF-8'); ?></p>
				<ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-gray-500 dark:text-gray-400"><?php foreach ($release['changes'] as $change) { ?><li><?php echo htmlspecialchars($change, ENT_QUOTES, 'UTF-8'); ?></li><?php } ?></ul>
				<div class="mt-5 flex items-start gap-3 rounded-lg <?php echo $databaseWillChange ? 'bg-amber-50 text-amber-900 dark:bg-amber-500/10 dark:text-amber-100' : 'bg-blue-50 text-blue-900 dark:bg-blue-500/10 dark:text-blue-100'; ?> p-4"><i class="fa-solid <?php echo $databaseWillChange ? 'fa-database' : 'fa-circle-check'; ?> mt-0.5" aria-hidden="true"></i><div><strong class="block text-sm font-extrabold"><?php echo $databaseWillChange ? cfg_t('База будет обновлена автоматически', 'The database will update automatically') : cfg_t('База не изменится', 'The database will not change'); ?></strong><p class="mt-1 text-xs font-medium opacity-80"><?php echo $databaseWillChange ? cfg_t('Перед миграциями конфигуратор сам создаст и проверит SQL-бэкап. Миграции выполняются строго по порядку.', 'Before migrations, the Configurator creates and verifies an SQL backup. Migrations run strictly in order.') : cfg_t('Версия схемы не меняется, поэтому бэкап и миграции будут пропущены.', 'The schema version is unchanged, so backup and migrations are skipped.'); ?></p></div></div>
				<?php if ($prepared['run_id'] === '') { ?><form method="post" hx-boost="false" class="mt-6 space-y-5">
					<input type="hidden" name="csrf" value="<?php echo htmlspecialchars($updateCsrf, ENT_QUOTES, 'UTF-8'); ?>">
					<input type="hidden" name="updateAction" value="apply">
					<input type="hidden" name="preparedId" value="<?php echo htmlspecialchars($prepared['id'], ENT_QUOTES, 'UTF-8'); ?>">
					<?php if ($prepared['conflicts']) { ?>
						<div class="rounded-lg border border-amber-200 bg-amber-50 p-5 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
							<strong class="text-sm font-extrabold"><?php echo cfg_t('Разрешите конфликты локальных доработок', 'Resolve local customization conflicts'); ?></strong>
							<p class="mt-1 text-xs font-medium"><?php echo cfg_t('Локальная копия сохраняется по умолчанию. Доступные локальная и релизная копии уже помещены в защищённый каталог обновления.', 'The local copy is kept by default. Available local and release copies are already stored in the protected update directory.'); ?></p>
							<?php foreach ($prepared['conflicts'] as $conflict) { $path = $conflict['path']; ?>
								<fieldset class="mt-4"><legend class="break-all font-mono text-xs font-bold"><?php echo htmlspecialchars($path, ENT_QUOTES, 'UTF-8'); ?> <span class="font-sans text-gray-500">— <?php echo htmlspecialchars(cfg_update_conflict_reason((string)$conflict['reason']), ENT_QUOTES, 'UTF-8'); ?></span></legend><div class="mt-2 flex flex-wrap gap-4 text-sm font-bold"><label><input type="radio" name="fileChoice[<?php echo htmlspecialchars($path, ENT_QUOTES, 'UTF-8'); ?>]" value="local" required checked> <?php echo cfg_t('Оставить локальное состояние', 'Keep local state'); ?></label><label><input type="radio" name="fileChoice[<?php echo htmlspecialchars($path, ENT_QUOTES, 'UTF-8'); ?>]" value="release" required> <?php echo $conflict['release_sha256'] === null ? cfg_t('Принять удаление из релиза', 'Accept release removal') : cfg_t('Взять из релиза', 'Use release'); ?></label></div></fieldset>
							<?php } ?>
						</div>
					<?php } ?>
					<button type="submit" class="<?php echo $cfgButtonClass; ?>"><i class="fa-solid fa-play" aria-hidden="true"></i><?php echo $databaseWillChange ? cfg_t('Создать бэкап и обновить', 'Back up and update') : cfg_t('Обновить CMS', 'Update CMS'); ?></button>
				</form><?php } else { ?><p class="mt-5 rounded-lg bg-blue-50 p-4 text-sm font-bold text-blue-900 dark:bg-blue-500/10 dark:text-blue-100"><?php echo cfg_t('Пакет уже связан с запуском. Используйте действие продолжения в блоке состояния.', 'This package is already attached to a run. Use the resume action in the status block.'); ?></p><?php } ?>
				<details class="mt-5 border-t border-gray-100 pt-4 text-xs font-medium text-gray-500 dark:border-gray-800 dark:text-gray-400"><summary class="cursor-pointer font-extrabold text-brand dark:text-white"><?php echo cfg_t('Техническая проверка архива', 'Archive verification details'); ?></summary><p class="mt-3 break-all"><?php echo htmlspecialchars($prepared['manifest']['artifact']['name'], ENT_QUOTES, 'UTF-8'); ?><br>SHA-256: <?php echo htmlspecialchars($prepared['manifest']['artifact']['sha256'], ENT_QUOTES, 'UTF-8'); ?></p><?php if ($prepared['manifest']['artifact']['sigstore_url'] !== '') { ?><a class="mt-2 inline-flex text-emerald-600" href="<?php echo htmlspecialchars($prepared['manifest']['artifact']['sigstore_url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer"><?php echo cfg_t('Открыть Sigstore evidence', 'Open Sigstore evidence'); ?></a><?php } ?></details>
			</section>
		<?php } ?>
	<?php } ?>

	<section class="rounded-lg border border-gray-100 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-[#151515]">
		<h2 class="text-lg font-extrabold text-brand dark:text-white"><?php echo cfg_t('Как проходит обновление', 'How the update works'); ?></h2>
		<div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
			<?php foreach (array(
				array('fa-magnifying-glass', cfg_t('1. Проверка', '1. Verification'), cfg_t('Версия, совместимость и целостность релиза.', 'Release version, compatibility, and integrity.')),
				array('fa-box-archive', cfg_t('2. Бэкап', '2. Backup'), cfg_t('Только если релиз меняет структуру базы.', 'Only when the release changes the database schema.')),
				array('fa-rotate', cfg_t('3. Обновление', '3. Update'), cfg_t('Файлы CMS и миграции базы по порядку.', 'CMS files and database migrations in order.')),
				array('fa-heart-pulse', cfg_t('4. Проверка', '4. Health check'), cfg_t('Версии фиксируются только после успеха.', 'Versions are recorded only after success.')),
			) as $step) { ?>
				<div class="rounded-lg bg-gray-50 p-4 dark:bg-[#1A1A1A]"><i class="fa-solid <?php echo $step[0]; ?> text-emerald-600" aria-hidden="true"></i><strong class="mt-3 block text-sm font-extrabold text-brand dark:text-white"><?php echo $step[1]; ?></strong><p class="mt-1 text-xs font-medium text-gray-500 dark:text-gray-400"><?php echo $step[2]; ?></p></div>
			<?php } ?>
		</div>
		<details class="mt-5 rounded-lg border border-blue-100 bg-blue-50 p-4 text-blue-950 dark:border-blue-500/20 dark:bg-blue-500/10 dark:text-blue-100">
			<summary class="cursor-pointer text-sm font-extrabold"><?php echo cfg_t('Как именно обновляется база?', 'How exactly is the database updated?'); ?></summary>
			<p class="mt-3 text-xs font-medium leading-relaxed opacity-80"><?php echo cfg_t('Конфигуратор не пересоздаёт базу из _dbstru.php. Релиз повышает версию схемы и содержит последовательные миграции в migrations/versioned. Перед изменениями проверяется непрерывность цепочки, создаётся SQL-бэкап, включается режим обслуживания и общий lock, затем миграции выполняются по порядку. Новая версия фиксируется только после итоговой проверки. Если версия схемы не меняется, весь шаг базы пропускается.', 'The Configurator does not recreate the database from _dbstru.php. A release raises the schema version and contains ordered migrations in migrations/versioned. Before changes, it verifies the complete chain, creates an SQL backup, enables maintenance and a shared lock, then runs migrations in order. The new version is recorded only after the final health check. If the schema version is unchanged, the entire database step is skipped.'); ?></p>
		</details>
		<details class="mt-5 border-t border-gray-100 pt-4 dark:border-gray-800"><summary class="cursor-pointer text-sm font-extrabold text-brand dark:text-white"><?php echo cfg_t('Технические версии', 'Technical versions'); ?></summary><dl class="mt-4 grid gap-4 text-xs sm:grid-cols-2 lg:grid-cols-4"><div><dt class="font-bold uppercase text-gray-400"><?php echo cfg_t('CMS в файлах', 'CMS in files'); ?></dt><dd class="mt-1 font-mono font-bold"><?php echo htmlspecialchars($currentCmsVersion, ENT_QUOTES, 'UTF-8'); ?></dd></div><div><dt class="font-bold uppercase text-gray-400"><?php echo cfg_t('CMS зафиксирована', 'CMS recorded'); ?></dt><dd class="mt-1 font-mono font-bold"><?php echo htmlspecialchars($recordedCmsVersion, ENT_QUOTES, 'UTF-8'); ?></dd></div><div><dt class="font-bold uppercase text-gray-400"><?php echo cfg_t('Схема установлена', 'Schema installed'); ?></dt><dd class="mt-1 font-mono font-bold"><?php echo htmlspecialchars($installedSchemaVersion, ENT_QUOTES, 'UTF-8'); ?></dd></div><div><dt class="font-bold uppercase text-gray-400"><?php echo cfg_t('Схема в релизе', 'Schema bundled'); ?></dt><dd class="mt-1 font-mono font-bold"><?php echo htmlspecialchars($targetSchemaVersion, ENT_QUOTES, 'UTF-8'); ?></dd></div></dl></details>
	</section>

	<?php if ($latestRun) { ?>
	<section class="rounded-lg border border-gray-100 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-[#151515]">
		<h2 class="text-lg font-extrabold text-brand dark:text-white"><?php echo cfg_t('Последний запуск', 'Latest run'); ?></h2>
			<dl class="mt-4 grid gap-4 text-sm sm:grid-cols-3"><div><dt class="text-xs font-bold uppercase text-gray-400">ID</dt><dd class="mt-1 break-all font-mono"><?php echo htmlspecialchars($latestRun['urID'], ENT_QUOTES, 'UTF-8'); ?></dd></div><div><dt class="text-xs font-bold uppercase text-gray-400"><?php echo cfg_t('Шаг', 'Step'); ?></dt><dd class="mt-1 font-bold"><?php echo htmlspecialchars($runStateLabels[$latestRunState] ?? $latestRunState, ENT_QUOTES, 'UTF-8'); ?></dd></div><div><dt class="text-xs font-bold uppercase text-gray-400"><?php echo cfg_t('Цель', 'Target'); ?></dt><dd class="mt-1 font-bold"><?php echo htmlspecialchars($latestRun['urTargetVersion'] . ' / ' . $latestRun['urTargetSchemaVersion'], ENT_QUOTES, 'UTF-8'); ?></dd></div></dl>
			<?php if ($latestRun['urMessageSummary'] !== '') { ?><p class="mt-4 rounded-lg bg-gray-50 p-4 text-sm font-bold dark:bg-[#1A1A1A]"><?php echo htmlspecialchars(cfg_update_run_message((string)$latestRun['urMessageCode'], (string)$latestRun['urMessageSummary']), ENT_QUOTES, 'UTF-8'); ?></p><?php } ?>
			<?php if ($prepared && $prepared['run_id'] === $latestRun['urID'] && $prepared['backup_id'] !== '') { ?><p class="mt-3 break-all font-mono text-xs text-gray-500 dark:text-gray-400"><?php echo cfg_t('Привязанный проверенный SQL-бэкап: ', 'Attached verified SQL backup: '); ?><?php echo htmlspecialchars($prepared['backup_id'], ENT_QUOTES, 'UTF-8'); ?></p><?php } ?>
			<?php if (!UpdateRunState::terminal((string)$latestRun['urState'])) { ?><div class="mt-5 flex flex-wrap gap-3"><?php if ($latestRun['urMessageCode'] === 'rollback_code') { ?><form method="post" hx-boost="false"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars($updateCsrf, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="updateAction" value="rollback_code"><input type="hidden" name="runId" value="<?php echo htmlspecialchars($latestRun['urID'], ENT_QUOTES, 'UTF-8'); ?>"><button class="inline-flex min-h-11 items-center rounded-lg border border-red-200 px-5 text-sm font-extrabold text-red-600" type="submit"><?php echo cfg_t('Вернуть предыдущий код', 'Restore previous code'); ?></button></form><?php } elseif ($latestRun['urMessageCode'] === 'restore_backup') { ?><a class="inline-flex min-h-11 items-center rounded-lg border border-amber-200 px-5 text-sm font-extrabold text-amber-700" href="?backup#restore-guide" hx-boost="false"><?php echo cfg_t('Открыть инструкцию восстановления', 'Open restore instructions'); ?></a><?php } else { ?><form method="post" hx-boost="false"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars($updateCsrf, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="updateAction" value="resume"><input type="hidden" name="runId" value="<?php echo htmlspecialchars($latestRun['urID'], ENT_QUOTES, 'UTF-8'); ?>"><button class="<?php echo $cfgButtonClass; ?>" type="submit"><?php echo cfg_t('Продолжить безопасный шаг', 'Resume safe step'); ?></button></form><?php if ($latestRun['urMessageCode'] === 'retry_update' || $canRecordDockerRollback) { ?><form method="post" hx-boost="false"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars($updateCsrf, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="updateAction" value="cancel"><input type="hidden" name="runId" value="<?php echo htmlspecialchars($latestRun['urID'], ENT_QUOTES, 'UTF-8'); ?>"><button class="inline-flex min-h-11 items-center rounded-lg border border-gray-200 px-5 text-sm font-extrabold" type="submit"><?php echo $canRecordDockerRollback ? cfg_t('Зафиксировать откат образа', 'Record image rollback') : cfg_t('Отменить запуск', 'Cancel run'); ?></button></form><?php } ?><?php } ?></div><?php } ?>
	</section>
	<?php } ?>
</section>

<?php include('module/_config/_footer.php'); ?>
