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
			'new_path_collision' => cfg_t('configurator.update.a_local_file_occupies_a_new_release_path'),
			'removed_but_locally_changed' => cfg_t('configurator.update.the_release_removes_a_locally_changed_file'),
			'locally_removed_and_release_changed' => cfg_t('configurator.update.the_file_was_removed_locally_and_changed_in_the_release'),
			'local_and_release_changed' => cfg_t('configurator.update.the_file_changed_both_locally_and_in_the_release'),
			default => cfg_t('configurator.update.operator_choice_is_required'),
		};
	}
}

require_once __DIR__ . '/update_messages.php';

$cfgDomain = (string)($_GS['domain'] ?? 'localhost');
$updatePreflight = new ProductionPreflight(dirname(__DIR__, 2), hsIsHttpsRequest(), trim((string)(getenv('APP_RELEASE_VERSION') ?: '')) !== '', cfg_language());
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
			addMsg(cfg_t('configurator.update.official_github_release_verified_against_sha256sums_and_prepared'));
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
			addMsg(cfg_t('configurator.update.archive_matches_the_official_github_release_and_is_prepared'));
		}
		elseif ($action === 'apply_bundled')
		{
			$result = $updateService->applyBundled();
			addMsg(cfg_t('configurator.update.update_from_the_current_docker_image_completed_run') . $result['run']['urID']);
		}
		elseif ($action === 'apply')
		{
			$choices = isset($_POST['fileChoice']) && is_array($_POST['fileChoice']) ? $_POST['fileChoice'] : array();
			$result = $updateService->apply((string)($_POST['preparedId'] ?? ''), $choices);
			addMsg(cfg_t('configurator.update.cms_and_database_updated_successfully_run') . $result['run']['urID']);
		}
		elseif ($action === 'resume')
		{
			$result = $updateService->resume((string)($_POST['runId'] ?? ''), array());
			addMsg(cfg_t('configurator.update.update_resumed_successfully_run') . $result['run']['urID']);
		}
		elseif ($action === 'rollback_code')
		{
			$result = $updateService->rollbackCode((string)($_POST['runId'] ?? ''));
			addMsg(cfg_t('configurator.update.previous_code_set_restored_database_was_not_changed'));
		}
		elseif ($action === 'cancel')
		{
			$result = $updateService->cancel((string)($_POST['runId'] ?? ''));
			addMsg(($result['urMessageCode'] ?? '') === 'docker_image_rolled_back'
				? cfg_t('configurator.update.rollback_to_the_previous_docker_image_was_recorded_the_database_schema_was_uncha')
				: cfg_t('configurator.update.update_cancelled_before_live_code_and_database_changes'));
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
		addMsg(($noNewRelease ? cfg_t('configurator.update.no_update_found') : cfg_t('configurator.update.update_stopped')) . cfg_update_error_message($exception->getMessage()), !$noNewRelease);
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
$statusTitle = cfg_t('configurator.update.ready_to_check_for_updates');
$statusText = cfg_t('configurator.update.the_check_is_safe_live_files_and_the_database_stay_unchanged_until_you_confirm_a');
if ($metadataMissing)
{
	$statusTone = 'amber';
	$statusIcon = 'fa-triangle-exclamation';
	$statusTitle = cfg_t('configurator.update.record_versions_once');
	$statusText = cfg_t('configurator.update.the_configurator_cannot_build_a_safe_update_plan_without_source_versions');
}
elseif ($updateServiceError !== '' || $schemaGateReason === 'unsupported_source_version')
{
	$statusTone = 'red';
	$statusIcon = 'fa-circle-xmark';
	$statusTitle = cfg_t('configurator.update.update_is_blocked');
	$statusText = cfg_t('configurator.update.no_data_was_changed_read_the_reason_below_and_resolve_it_before_continuing');
}
elseif ($prepared && $prepared['run_id'] === '')
{
	$statusTitle = cfg_t('configurator.update.release_verified_and_ready');
	$statusText = cfg_t('configurator.update.see_what_will_change_below_the_update_starts_only_after_your_confirmation');
}
elseif ($latestRunState === UpdateRunState::COMPLETED && \HScript\Update\UpdateStatusService::isReconciled($updateStatus))
{
	$statusTitle = cfg_t('configurator.update.last_update_completed');
	$statusText = cfg_t('configurator.update.the_cms_and_database_state_passed_the_final_health_check');
}
elseif ($latestRunState === UpdateRunState::FAILED)
{
	$statusTone = 'red';
	$statusIcon = 'fa-circle-xmark';
	$statusTitle = cfg_t('configurator.update.last_update_stopped');
	$statusText = cfg_t('configurator.update.success_was_not_recorded_the_reason_and_available_action_are_shown_in_the_latest');
}
elseif ($latestRunState !== '' && !UpdateRunState::terminal($latestRunState))
{
	$statusTone = 'amber';
	$statusIcon = 'fa-clock-rotate-left';
	$statusTitle = cfg_t('configurator.update.update_is_waiting_to_continue');
	$statusText = cfg_t('configurator.update.the_configurator_saved_its_state_use_the_safe_action_in_the_current_run_block');
}
elseif (!\HScript\Update\UpdateStatusService::isReconciled($updateStatus))
{
	$statusTone = 'amber';
	$statusIcon = 'fa-box-open';
	$statusTitle = cfg_t('configurator.lifecycle.pending');
	$statusText = cfg_t('configurator.lifecycle.pending_detail');
}
$statusClasses = array(
	'emerald' => 'border-emerald-200 bg-emerald-50 text-emerald-950 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-100',
	'amber' => 'border-amber-200 bg-amber-50 text-amber-950 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100',
	'red' => 'border-red-200 bg-red-50 text-red-950 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-100',
);
$runStateLabels = array(
	UpdateRunState::PREFLIGHT => cfg_t('configurator.update.preflight'),
	UpdateRunState::BACKUP => cfg_t('configurator.update.creating_backup'),
	UpdateRunState::PACKAGE => cfg_t('configurator.update.updating_files'),
	UpdateRunState::MIGRATION => cfg_t('configurator.update.updating_database'),
	UpdateRunState::HEALTH => cfg_t('configurator.update.health_check'),
	UpdateRunState::COMPLETED => cfg_t('configurator.update.completed'),
	UpdateRunState::FAILED => cfg_t('configurator.update.stopped'),
);

include('module/_config/_header.php');
?>

<section class="mx-auto max-w-5xl space-y-6">
	<header>
		<span class="mb-3 inline-flex items-center gap-2 text-xs font-extrabold uppercase tracking-wider text-emerald-600 dark:text-emerald-400"><i class="fa-solid fa-rotate" aria-hidden="true"></i><?php echo cfg_t('configurator.common.maintenance'); ?></span>
		<h1 class="text-3xl font-black text-brand dark:text-white sm:text-4xl"><?php echo cfg_t('configurator.update.h_script_update'); ?></h1>
		<p class="mt-2 max-w-3xl text-sm font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('configurator.update.release_verification_backup_when_needed_and_cms_database_update_in_one_flow'); ?></p>
	</header>

	<div class="flex flex-col gap-5 rounded-lg border p-6 sm:flex-row sm:items-center sm:justify-between <?php echo $statusClasses[$statusTone]; ?>">
		<div class="flex min-w-0 items-start gap-4">
			<span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-white/70 text-lg shadow-sm dark:bg-black/20"><i class="fa-solid <?php echo $statusIcon; ?>" aria-hidden="true"></i></span>
			<div><h2 class="text-lg font-black"><?php echo $statusTitle; ?></h2><p class="mt-1 max-w-2xl text-sm font-medium opacity-80"><?php echo $statusText; ?></p></div>
		</div>
		<div class="flex shrink-0 gap-2 text-sm font-extrabold">
			<span class="rounded-full bg-white/70 px-4 py-2 shadow-sm dark:bg-black/20"><?php echo cfg_t('configurator.lifecycle.installed'); ?> CMS <?php echo htmlspecialchars($recordedCmsVersion, ENT_QUOTES, 'UTF-8'); ?></span>
			<span class="rounded-full bg-white/70 px-4 py-2 shadow-sm dark:bg-black/20"><?php echo cfg_t('configurator.update.db'); ?> <?php echo htmlspecialchars($installedSchemaVersion, ENT_QUOTES, 'UTF-8'); ?></span>
		</div>
	</div>
	<?php if ($dockerUpdatePending) { ?><p class="text-sm font-bold text-amber-700 dark:text-amber-300"><?php echo cfg_t('configurator.lifecycle.target'); ?>: CMS <?php echo htmlspecialchars($currentCmsVersion, ENT_QUOTES, 'UTF-8'); ?> / <?php echo cfg_t('configurator.update.db'); ?> <?php echo htmlspecialchars($targetSchemaVersion, ENT_QUOTES, 'UTF-8'); ?></p><?php } ?>
	<?php if ($updatePreflightBlockers) { ?>
		<aside class="rounded-lg border border-red-200 bg-red-50 p-5 text-sm text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-100"><strong class="font-extrabold"><?php echo cfg_t('configurator.update.update_is_blocked_by_preflight'); ?></strong><span class="ml-1"><?php echo htmlspecialchars(implode(', ', array_column($updatePreflightBlockers, 'label')), ENT_QUOTES, 'UTF-8'); ?></span> <a class="font-extrabold underline" href="?security"><?php echo cfg_t('configurator.common.open_security'); ?></a></aside>
	<?php } ?>

	<?php if ($schemaGateReason === 'unsupported_source_version') { ?>
		<aside class="rounded-lg border border-red-200 bg-red-50 p-5 text-sm font-bold text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-100"><?php echo cfg_t('configurator.update.the_new_docker_image_does_not_support_the_recorded_source_cms_or_database_versio'); ?></aside>
	<?php } ?>

	<?php if ($metadataMissing) { ?>
		<aside class="rounded-lg border border-amber-200 bg-amber-50 p-5 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
			<strong class="block text-sm font-extrabold"><?php echo cfg_t('configurator.update.record_the_source_cms_and_schema_versions'); ?></strong>
			<p class="mt-2 text-xs font-medium"><?php echo cfg_t('configurator.update.replace_the_command_placeholders_with_the_actual_versions_installed_before_the_d'); ?></p>
			<pre class="mt-4 overflow-x-auto rounded-lg bg-white/70 p-4 text-xs dark:bg-black/20"><code><?php echo htmlspecialchars($bootstrapCommand, ENT_QUOTES, 'UTF-8'); ?></code></pre>
		</aside>
	<?php } elseif ($updateServiceError !== '') { ?>
		<aside class="rounded-lg border border-red-200 bg-red-50 p-5 text-sm font-bold text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200"><?php echo htmlspecialchars(cfg_t('configurator.update.update_unavailable') . cfg_update_error_message($updateServiceError), ENT_QUOTES, 'UTF-8'); ?></aside>
	<?php } elseif ($deploymentMode === 'docker') { ?>
		<section class="rounded-lg border border-gray-100 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-[#151515]">
			<div class="flex items-start gap-4"><span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-500/10 dark:text-blue-300"><i class="fa-brands fa-docker" aria-hidden="true"></i></span><div><h2 class="text-lg font-extrabold text-brand dark:text-white"><?php echo cfg_t('configurator.update.update_through_a_docker_image'); ?></h2><p class="mt-1 text-sm font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('configurator.update.first_change_the_exact_official_image_tag_after_the_new_container_starts_return'); ?></p></div></div>
			<?php if ($schemaGateReason !== 'unsupported_source_version' && $dockerUpdatePending) { ?>
				<form method="post" hx-boost="false" class="mt-6 rounded-lg border border-amber-200 bg-amber-50 p-5 dark:border-amber-500/30 dark:bg-amber-500/10">
					<input type="hidden" name="csrf" value="<?php echo htmlspecialchars($updateCsrf, ENT_QUOTES, 'UTF-8'); ?>">
					<input type="hidden" name="updateAction" value="apply_bundled">
					<strong class="block text-sm font-extrabold"><?php echo $databaseWillChange ? cfg_t('configurator.update.sql_backup_first_then_database_migrations') : cfg_t('configurator.common.the_database_will_not_change'); ?></strong>
					<p class="mt-2 text-xs font-medium"><?php echo $databaseWillChange ? cfg_t('configurator.update.the_configurator_creates_and_verifies_a_backup_applies_image_migrations_in_order') : cfg_t('configurator.update.the_schema_version_matches_only_the_final_health_check_and_cms_version_recording'); ?></p>
					<button type="submit" class="<?php echo $cfgButtonClass; ?> mt-4"><i class="fa-solid fa-play" aria-hidden="true"></i><?php echo cfg_t('configurator.update.verify_and_complete'); ?></button>
				</form>
			<?php } elseif ($schemaGateReason !== 'unsupported_source_version') { ?>
				<p class="mt-6 rounded-lg bg-emerald-50 p-4 text-sm font-bold text-emerald-900 dark:bg-emerald-500/10 dark:text-emerald-100"><i class="fa-solid fa-circle-check mr-2" aria-hidden="true"></i><?php echo cfg_t('configurator.update.cms_code_and_database_schema_are_aligned'); ?></p>
			<?php } ?>
			<details class="mt-5 rounded-lg border border-gray-100 p-4 dark:border-gray-800">
				<summary class="cursor-pointer text-sm font-extrabold text-brand dark:text-white"><?php echo cfg_t('configurator.update.image_update_commands'); ?></summary>
				<pre class="mt-4 overflow-x-auto rounded-lg bg-gray-950 p-4 text-xs text-gray-100"><code># <?php echo htmlspecialchars(cfg_t('configurator.update.image_version_instruction'), ENT_QUOTES, 'UTF-8'); ?>
APP_IMAGE_TAG=&lt;version&gt;
docker compose pull app cron
docker compose up -d --no-build app
# <?php echo htmlspecialchars(cfg_t('configurator.update.after_schema_migration'), ENT_QUOTES, 'UTF-8'); ?>
docker compose up -d --no-build cron</code></pre>
				<a class="mt-3 inline-flex text-sm font-extrabold text-emerald-600" href="https://github.com/0x241/h-script/releases" target="_blank" rel="noopener noreferrer"><?php echo cfg_t('configurator.update.open_official_releases'); ?></a>
			</details>
			<p class="mt-4 text-xs font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('configurator.update.important_changes_inside_a_container_do_not_survive_replacement_keep_customizati'); ?></p>
		</section>
	<?php } else { ?>
		<section class="rounded-lg border border-gray-100 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-[#151515]">
			<div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
				<div><h2 class="text-lg font-extrabold text-brand dark:text-white"><?php echo cfg_t('configurator.update.check_for_a_new_version'); ?></h2><p class="mt-1 max-w-xl text-sm font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('configurator.update.the_configurator_downloads_the_official_github_release_archive_and_verifies_its'); ?></p></div>
				<form method="post" hx-boost="false" class="shrink-0">
					<input type="hidden" name="csrf" value="<?php echo htmlspecialchars($updateCsrf, ENT_QUOTES, 'UTF-8'); ?>">
					<input type="hidden" name="updateAction" value="prepare_github">
					<button type="submit" class="<?php echo $cfgButtonClass; ?>"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><?php echo cfg_t('configurator.update.check_for_updates'); ?></button>
				</form>
			</div>
			<details class="mt-5 rounded-lg border border-gray-100 p-4 dark:border-gray-800">
				<summary class="cursor-pointer text-sm font-extrabold text-brand dark:text-white"><?php echo cfg_t('configurator.update.i_already_downloaded_the_official_archive'); ?></summary>
				<form method="post" enctype="multipart/form-data" hx-boost="false" class="mt-4">
					<input type="hidden" name="csrf" value="<?php echo htmlspecialchars($updateCsrf, ENT_QUOTES, 'UTF-8'); ?>">
					<input type="hidden" name="updateAction" value="prepare_upload">
					<label class="block text-sm font-extrabold" for="releasePackage"><?php echo cfg_t('configurator.update.h_script_x_y_z_shared_hosting_tar_gz_archive'); ?></label>
					<input id="releasePackage" name="releasePackage" type="file" accept=".tar.gz,application/gzip" required class="<?php echo $cfgInputClass; ?> mt-3">
					<button type="submit" class="<?php echo $cfgButtonClass; ?> mt-4"><?php echo cfg_t('configurator.update.upload_and_verify'); ?></button>
				</form>
			</details>
		</section>

		<?php if ($prepared && ($prepared['run_id'] === '' || ($latestRunState !== '' && !UpdateRunState::terminal($latestRunState)))) { $release = $prepared['manifest']['release']; ?>
			<section class="rounded-lg border border-emerald-200 bg-white p-6 shadow-sm dark:border-emerald-500/30 dark:bg-[#151515]">
				<div class="flex flex-wrap items-start justify-between gap-4"><div><span class="text-xs font-extrabold uppercase tracking-wider text-emerald-600"><?php echo cfg_t('configurator.update.official_release_verified'); ?></span><h2 class="mt-1 text-xl font-black"><?php echo cfg_t('configurator.update.update_to_version'); ?><?php echo htmlspecialchars($release['application_version'], ENT_QUOTES, 'UTF-8'); ?></h2></div><span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-extrabold dark:bg-gray-800"><?php echo $preparedClassification === 'code-only' ? cfg_t('configurator.update.cms_only') : ($preparedClassification === 'irreversible' ? cfg_t('configurator.update.irreversible_migration') : cfg_t('configurator.update.cms_and_database')); ?></span></div>
				<p class="mt-4 text-sm font-bold"><?php echo htmlspecialchars($release['summary'], ENT_QUOTES, 'UTF-8'); ?></p>
				<ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-gray-500 dark:text-gray-400"><?php foreach ($release['changes'] as $change) { ?><li><?php echo htmlspecialchars($change, ENT_QUOTES, 'UTF-8'); ?></li><?php } ?></ul>
				<div class="mt-5 flex items-start gap-3 rounded-lg <?php echo $databaseWillChange ? 'bg-amber-50 text-amber-900 dark:bg-amber-500/10 dark:text-amber-100' : 'bg-blue-50 text-blue-900 dark:bg-blue-500/10 dark:text-blue-100'; ?> p-4"><i class="fa-solid <?php echo $databaseWillChange ? 'fa-database' : 'fa-circle-check'; ?> mt-0.5" aria-hidden="true"></i><div><strong class="block text-sm font-extrabold"><?php echo $databaseWillChange ? cfg_t('configurator.update.the_database_will_update_automatically') : cfg_t('configurator.common.the_database_will_not_change'); ?></strong><p class="mt-1 text-xs font-medium opacity-80"><?php echo $databaseWillChange ? cfg_t('configurator.update.before_migrations_the_configurator_creates_and_verifies_an_sql_backup_migrations') : cfg_t('configurator.update.the_schema_version_is_unchanged_so_backup_and_migrations_are_skipped'); ?></p></div></div>
				<?php if ($prepared['run_id'] === '') { ?><form method="post" hx-boost="false" class="mt-6 space-y-5">
					<input type="hidden" name="csrf" value="<?php echo htmlspecialchars($updateCsrf, ENT_QUOTES, 'UTF-8'); ?>">
					<input type="hidden" name="updateAction" value="apply">
					<input type="hidden" name="preparedId" value="<?php echo htmlspecialchars($prepared['id'], ENT_QUOTES, 'UTF-8'); ?>">
					<?php if ($prepared['conflicts']) { ?>
						<div class="rounded-lg border border-amber-200 bg-amber-50 p-5 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
							<strong class="text-sm font-extrabold"><?php echo cfg_t('configurator.update.resolve_local_customization_conflicts'); ?></strong>
							<p class="mt-1 text-xs font-medium"><?php echo cfg_t('configurator.update.the_local_copy_is_kept_by_default_available_local_and_release_copies_are_already'); ?></p>
							<?php foreach ($prepared['conflicts'] as $conflict) { $path = $conflict['path']; ?>
								<fieldset class="mt-4"><legend class="break-all font-mono text-xs font-bold"><?php echo htmlspecialchars($path, ENT_QUOTES, 'UTF-8'); ?> <span class="font-sans text-gray-500">— <?php echo htmlspecialchars(cfg_update_conflict_reason((string)$conflict['reason']), ENT_QUOTES, 'UTF-8'); ?></span></legend><div class="mt-2 flex flex-wrap gap-4 text-sm font-bold"><label><input type="radio" name="fileChoice[<?php echo htmlspecialchars($path, ENT_QUOTES, 'UTF-8'); ?>]" value="local" required checked> <?php echo cfg_t('configurator.update.keep_local_state'); ?></label><label><input type="radio" name="fileChoice[<?php echo htmlspecialchars($path, ENT_QUOTES, 'UTF-8'); ?>]" value="release" required> <?php echo $conflict['release_sha256'] === null ? cfg_t('configurator.update.accept_release_removal') : cfg_t('configurator.update.use_release'); ?></label></div></fieldset>
							<?php } ?>
						</div>
					<?php } ?>
					<button type="submit" class="<?php echo $cfgButtonClass; ?>"><i class="fa-solid fa-play" aria-hidden="true"></i><?php echo $databaseWillChange ? cfg_t('configurator.update.back_up_and_update') : cfg_t('configurator.update.update_cms'); ?></button>
				</form><?php } else { ?><p class="mt-5 rounded-lg bg-blue-50 p-4 text-sm font-bold text-blue-900 dark:bg-blue-500/10 dark:text-blue-100"><?php echo cfg_t('configurator.update.this_package_is_already_attached_to_a_run_use_the_resume_action_in_the_status_bl'); ?></p><?php } ?>
				<details class="mt-5 border-t border-gray-100 pt-4 text-xs font-medium text-gray-500 dark:border-gray-800 dark:text-gray-400"><summary class="cursor-pointer font-extrabold text-brand dark:text-white"><?php echo cfg_t('configurator.update.archive_verification_details'); ?></summary><p class="mt-3 break-all"><?php echo htmlspecialchars($prepared['manifest']['artifact']['name'], ENT_QUOTES, 'UTF-8'); ?><br>SHA-256: <?php echo htmlspecialchars($prepared['manifest']['artifact']['sha256'], ENT_QUOTES, 'UTF-8'); ?></p><?php if ($prepared['manifest']['artifact']['sigstore_url'] !== '') { ?><a class="mt-2 inline-flex text-emerald-600" href="<?php echo htmlspecialchars($prepared['manifest']['artifact']['sigstore_url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer"><?php echo cfg_t('configurator.update.open_sigstore_evidence'); ?></a><?php } ?></details>
			</section>
		<?php } ?>
	<?php } ?>

	<section class="rounded-lg border border-gray-100 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-[#151515]">
		<h2 class="text-lg font-extrabold text-brand dark:text-white"><?php echo cfg_t('configurator.update.how_the_update_works'); ?></h2>
		<div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
			<?php foreach (array(
				array('fa-magnifying-glass', cfg_t('configurator.update.1_verification'), cfg_t('configurator.update.release_version_compatibility_and_integrity')),
				array('fa-box-archive', cfg_t('configurator.update.2_backup'), cfg_t('configurator.update.only_when_the_release_changes_the_database_schema')),
				array('fa-rotate', cfg_t('configurator.update.3_update'), cfg_t('configurator.update.cms_files_and_database_migrations_in_order')),
				array('fa-heart-pulse', cfg_t('configurator.update.4_health_check'), cfg_t('configurator.update.versions_are_recorded_only_after_success')),
			) as $step) { ?>
				<div class="rounded-lg bg-gray-50 p-4 dark:bg-[#1A1A1A]"><i class="fa-solid <?php echo $step[0]; ?> text-emerald-600" aria-hidden="true"></i><strong class="mt-3 block text-sm font-extrabold text-brand dark:text-white"><?php echo $step[1]; ?></strong><p class="mt-1 text-xs font-medium text-gray-500 dark:text-gray-400"><?php echo $step[2]; ?></p></div>
			<?php } ?>
		</div>
		<details class="mt-5 rounded-lg border border-blue-100 bg-blue-50 p-4 text-blue-950 dark:border-blue-500/20 dark:bg-blue-500/10 dark:text-blue-100">
			<summary class="cursor-pointer text-sm font-extrabold"><?php echo cfg_t('configurator.update.how_exactly_is_the_database_updated'); ?></summary>
			<p class="mt-3 text-xs font-medium leading-relaxed opacity-80"><?php echo cfg_t('configurator.update.the_configurator_does_not_recreate_the_database_from_dbstru_php_a_release_raises'); ?></p>
		</details>
		<details class="mt-5 border-t border-gray-100 pt-4 dark:border-gray-800"><summary class="cursor-pointer text-sm font-extrabold text-brand dark:text-white"><?php echo cfg_t('configurator.update.technical_versions'); ?></summary><dl class="mt-4 grid gap-4 text-xs sm:grid-cols-2 lg:grid-cols-4"><div><dt class="font-bold uppercase text-gray-400"><?php echo cfg_t('configurator.update.cms_in_files'); ?></dt><dd class="mt-1 font-mono font-bold"><?php echo htmlspecialchars($currentCmsVersion, ENT_QUOTES, 'UTF-8'); ?></dd></div><div><dt class="font-bold uppercase text-gray-400"><?php echo cfg_t('configurator.update.cms_recorded'); ?></dt><dd class="mt-1 font-mono font-bold"><?php echo htmlspecialchars($recordedCmsVersion, ENT_QUOTES, 'UTF-8'); ?></dd></div><div><dt class="font-bold uppercase text-gray-400"><?php echo cfg_t('configurator.update.schema_installed'); ?></dt><dd class="mt-1 font-mono font-bold"><?php echo htmlspecialchars($installedSchemaVersion, ENT_QUOTES, 'UTF-8'); ?></dd></div><div><dt class="font-bold uppercase text-gray-400"><?php echo cfg_t('configurator.update.schema_bundled'); ?></dt><dd class="mt-1 font-mono font-bold"><?php echo htmlspecialchars($targetSchemaVersion, ENT_QUOTES, 'UTF-8'); ?></dd></div></dl></details>
	</section>

	<?php if ($latestRun) { ?>
	<section class="rounded-lg border border-gray-100 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-[#151515]">
		<h2 class="text-lg font-extrabold text-brand dark:text-white"><?php echo cfg_t('configurator.update.latest_run'); ?></h2>
			<dl class="mt-4 grid gap-4 text-sm sm:grid-cols-3"><div><dt class="text-xs font-bold uppercase text-gray-400">ID</dt><dd class="mt-1 break-all font-mono"><?php echo htmlspecialchars($latestRun['urID'], ENT_QUOTES, 'UTF-8'); ?></dd></div><div><dt class="text-xs font-bold uppercase text-gray-400"><?php echo cfg_t('configurator.update.step'); ?></dt><dd class="mt-1 font-bold"><?php echo htmlspecialchars($runStateLabels[$latestRunState] ?? $latestRunState, ENT_QUOTES, 'UTF-8'); ?></dd></div><div><dt class="text-xs font-bold uppercase text-gray-400"><?php echo cfg_t('configurator.update.target'); ?></dt><dd class="mt-1 font-bold"><?php echo htmlspecialchars($latestRun['urTargetVersion'] . ' / ' . $latestRun['urTargetSchemaVersion'], ENT_QUOTES, 'UTF-8'); ?></dd></div></dl>
			<?php if ($latestRun['urMessageSummary'] !== '') { ?><p class="mt-4 rounded-lg bg-gray-50 p-4 text-sm font-bold dark:bg-[#1A1A1A]"><?php echo htmlspecialchars(cfg_update_run_message((string)$latestRun['urMessageCode'], (string)$latestRun['urMessageSummary']), ENT_QUOTES, 'UTF-8'); ?></p><?php } ?>
			<?php if ($prepared && $prepared['run_id'] === $latestRun['urID'] && $prepared['backup_id'] !== '') { ?><p class="mt-3 break-all font-mono text-xs text-gray-500 dark:text-gray-400"><?php echo cfg_t('configurator.update.attached_verified_sql_backup'); ?><?php echo htmlspecialchars($prepared['backup_id'], ENT_QUOTES, 'UTF-8'); ?></p><?php } ?>
			<?php if (!UpdateRunState::terminal((string)$latestRun['urState'])) { ?><div class="mt-5 flex flex-wrap gap-3"><?php if ($latestRun['urMessageCode'] === 'rollback_code') { ?><form method="post" hx-boost="false"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars($updateCsrf, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="updateAction" value="rollback_code"><input type="hidden" name="runId" value="<?php echo htmlspecialchars($latestRun['urID'], ENT_QUOTES, 'UTF-8'); ?>"><button class="inline-flex min-h-11 items-center rounded-lg border border-red-200 px-5 text-sm font-extrabold text-red-600" type="submit"><?php echo cfg_t('configurator.update.restore_previous_code'); ?></button></form><?php } elseif ($latestRun['urMessageCode'] === 'restore_backup') { ?><a class="inline-flex min-h-11 items-center rounded-lg border border-amber-200 px-5 text-sm font-extrabold text-amber-700" href="?backup#restore-guide" hx-boost="false"><?php echo cfg_t('configurator.update.open_restore_instructions'); ?></a><?php } else { ?><form method="post" hx-boost="false"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars($updateCsrf, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="updateAction" value="resume"><input type="hidden" name="runId" value="<?php echo htmlspecialchars($latestRun['urID'], ENT_QUOTES, 'UTF-8'); ?>"><button class="<?php echo $cfgButtonClass; ?>" type="submit"><?php echo cfg_t('configurator.update.resume_safe_step'); ?></button></form><?php if ($latestRun['urMessageCode'] === 'retry_update' || $canRecordDockerRollback) { ?><form method="post" hx-boost="false"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars($updateCsrf, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="updateAction" value="cancel"><input type="hidden" name="runId" value="<?php echo htmlspecialchars($latestRun['urID'], ENT_QUOTES, 'UTF-8'); ?>"><button class="inline-flex min-h-11 items-center rounded-lg border border-gray-200 px-5 text-sm font-extrabold" type="submit"><?php echo $canRecordDockerRollback ? cfg_t('configurator.update.record_image_rollback') : cfg_t('configurator.update.cancel_run'); ?></button></form><?php } ?><?php } ?></div><?php } ?>
	</section>
	<?php } ?>
</section>

<?php include('module/_config/_footer.php'); ?>
