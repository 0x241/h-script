<?php

use HScript\Security\IntegrityScanner;
use HScript\Security\IntegrityStateRepository;
use HScript\Security\ProductionPreflight;
use HScript\Update\ConfiguratorCsrf;

$securityRoot = dirname(__DIR__, 2);
$securityLocalOperator = $cfgSecurity->localAddress($cfgClientIp);
$securityOfficialBuild = trim((string)(getenv('APP_RELEASE_VERSION') ?: '')) !== '';
$securityBundledBaselinePresent = is_file($securityRoot . '/resources/release-baseline.json');
$securityStates = new IntegrityStateRepository($securityRoot);
$securityScanner = null;
$securityScannerError = '';
try { $securityScanner = new IntegrityScanner($securityRoot); }
catch (Throwable $exception)
{
	$securityScannerError = $exception->getMessage();
	error_log('Configurator integrity scanner unavailable: ' . $securityScannerError);
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST')
{
	$securityJson = ($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json';
	$securityReply = static function (array $body, int $status = 200): never {
		http_response_code($status);
		header('Content-Type: application/json; charset=UTF-8');
		header('Cache-Control: no-store');
		echo json_encode($body, JSON_THROW_ON_ERROR);
		exit;
	};
	try
	{
		ConfiguratorCsrf::consume($_POST['csrf'] ?? '');
		$action = (string)($_POST['securityAction'] ?? '');
		if ($securityJson && !in_array($action, array('scan', 'continue'), true)) throw new RuntimeException('Unknown security action');
		if ($action === 'scan')
		{
			if (!$securityScanner instanceof IntegrityScanner) throw new RuntimeException('Verified release integrity baseline is not available');
			$state = $securityScanner->start();
			$cfgSecurity->audit('integrity_scan', 'started', $cfgClientIp, array('scan' => (string)$state['scan_id']));
			if (!$securityJson) addMsg(($state['status'] ?? '') === 'completed'
				? cfg_t('configurator.common.integrity_scan_completed')
				: cfg_t('configurator.security.scan_started_continue_with_the_next_bounded_batch'));
		}
		elseif ($action === 'continue')
		{
			if (!$securityScanner instanceof IntegrityScanner) throw new RuntimeException('Verified release integrity baseline is not available');
			$state = $securityScanner->advance();
			$cfgSecurity->audit('integrity_scan', ($state['status'] ?? '') === 'completed' ? 'completed' : 'continued', $cfgClientIp, array('scan' => (string)$state['scan_id']));
			if (!$securityJson) addMsg(($state['status'] ?? '') === 'completed'
				? cfg_t('configurator.common.integrity_scan_completed')
				: cfg_t('configurator.security.batch_checked_remaining_files_will_resume_without_rereading_completed_entries'));
		}
		elseif ($action === 'acknowledge')
		{
			$state = $securityStates->acknowledge();
			$cfgSecurity->audit('integrity_alert', 'acknowledged', $cfgClientIp, array('scan' => (string)$state['scan_id']));
			addMsg(cfg_t('configurator.security.alert_acknowledged_the_baseline_was_not_changed_the_warning_remains_until_files'));
		}
		else throw new RuntimeException('Unknown security action');
		if ($securityJson) $securityReply(array(
			'status' => $state['status'], 'checked' => $state['checked'], 'total' => $state['total'],
			'csrf' => ConfiguratorCsrf::token(),
		));
	}
	catch (Throwable $exception)
	{
		$cfgSecurity->audit('security_action', 'failed', $cfgClientIp, array('reason' => 'operation'));
		error_log('Configurator security action failed: ' . $exception->getMessage());
		if ($securityJson) $securityReply(array('error' => cfg_t('configurator.security.auto_stopped'), 'csrf' => ConfiguratorCsrf::token()), 409);
		addMsg(cfg_t('configurator.security.security_action_stopped') . cfg_t('configurator.common.technical_error'), true);
	}
	goToURL($_cfg['cfg_link'] . '?security');
}

$securityPreflight = (new ProductionPreflight(
	$securityRoot,
	hsIsHttpsRequest(),
	trim((string)(getenv('APP_RELEASE_VERSION') ?: '')) !== '',
	cfg_language()
))->inspect();
try { $securityState = $securityStates->get(); }
catch (Throwable $exception)
{
	$securityState = null;
	if ($securityScannerError === '') $securityScannerError = $exception->getMessage();
}
$securityAudit = $cfgSecurity->recentAudit(30);
$securityCsrf = ConfiguratorCsrf::token();
$securityCounts = array_merge(
	array('unchanged' => 0, 'modified' => 0, 'missing' => 0, 'customized' => 0, 'unexpected' => 0, 'errors' => 0, 'critical' => 0),
	is_array($securityState['counts'] ?? null) ? $securityState['counts'] : array()
);
$securityRunning = ($securityState['status'] ?? '') === 'running';
$securityCritical = ($securityState['status'] ?? '') === 'completed' && (int)$securityCounts['critical'] > 0;
$securityAcknowledged = $securityCritical
	&& (string)($securityState['alert_signature'] ?? '') !== ''
	&& hash_equals((string)$securityState['alert_signature'], (string)($securityState['acknowledged_signature'] ?? ''));
$securityPercent = $securityRunning && (int)($securityState['total'] ?? 0) > 0
	? min(100, (int)floor((int)$securityState['checked'] * 100 / (int)$securityState['total']))
	: (($securityState['status'] ?? '') === 'completed' ? 100 : 0);
$securityDate = static function (int $timestamp): string {
	return $timestamp > 0 ? gmdate('d.m.Y H:i:s', $timestamp) . ' UTC' : '—';
};
$securitySize = static function ($bytes): string {
	if (!is_int($bytes)) return '—';
	if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . ' MB';
	if ($bytes >= 1024) return number_format($bytes / 1024, 1) . ' KB';
	return $bytes . ' B';
};
$securityFindingLabels = array(
	'modified' => cfg_t('configurator.common.modified'),
	'missing' => cfg_t('configurator.security.missing'),
	'unexpected' => cfg_t('configurator.security.unexpected'),
	'unexpected_link' => cfg_t('configurator.security.unexpected_link'),
	'unexpected_executable' => cfg_t('configurator.security.unexpected_executable'),
	'customized' => cfg_t('configurator.security.customized'),
	'unreadable' => cfg_t('configurator.security.unreadable'),
);

include('module/_config/_header.php');
?>

<section class="mx-auto max-w-6xl space-y-7">
	<header class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
		<div>
			<span class="mb-3 inline-flex items-center gap-2 text-xs font-extrabold uppercase tracking-wider text-blue-600 dark:text-blue-400"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i><?php echo cfg_t('configurator.common.configurator'); ?></span>
			<h1 class="text-3xl font-black text-brand dark:text-white sm:text-4xl"><?php echo cfg_t('configurator.common.security'); ?></h1>
			<p class="mt-2 max-w-3xl text-sm font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('configurator.security.production_readiness_and_a_local_file_check_against_the_exact_official_release_b'); ?></p>
		</div>
		<form method="post" hx-boost="false" data-integrity-scan data-running="<?php echo $securityRunning ? '1' : '0'; ?>" data-error="<?php echo htmlspecialchars(cfg_t('configurator.security.auto_stopped'), ENT_QUOTES, 'UTF-8'); ?>">
			<input type="hidden" name="csrf" value="<?php echo htmlspecialchars($securityCsrf, ENT_QUOTES, 'UTF-8'); ?>">
			<input type="hidden" name="securityAction" value="<?php echo $securityRunning ? 'continue' : 'scan'; ?>">
			<button type="submit" class="<?php echo $cfgButtonClass; ?> disabled:cursor-not-allowed disabled:opacity-50" <?php echo $securityScanner instanceof IntegrityScanner ? '' : 'disabled'; ?>><i class="fa-solid <?php echo $securityRunning ? 'fa-forward-step' : 'fa-magnifying-glass'; ?>" aria-hidden="true"></i><?php echo $securityRunning ? cfg_t('configurator.security.continue_scan') : cfg_t('configurator.security.scan_now'); ?></button>
		</form>
	</header>
	<p data-integrity-progress role="status" aria-live="polite" class="text-sm font-bold"><?php echo cfg_t('configurator.security.auto_batches'); ?></p>

	<?php if ($securityScannerError !== '') { ?>
		<aside class="rounded-lg border border-amber-200 bg-amber-50 p-5 text-sm text-amber-950 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
			<strong class="font-extrabold"><?php echo $securityOfficialBuild || $securityBundledBaselinePresent
				? cfg_t('configurator.security.the_release_baseline_did_not_pass_validation')
				: cfg_t('configurator.security.the_local_build_is_running_without_a_release_baseline'); ?></strong>
			<p class="mt-2 text-xs font-medium opacity-80"><?php echo $securityOfficialBuild || $securityBundledBaselinePresent
				? cfg_t('configurator.security.do_not_create_a_new_baseline_from_the_current_live_files_redeploy_the_exact_offi')
				: cfg_t('configurator.security.this_is_expected_for_hs_local_and_source_checkouts_the_scan_becomes_available_in'); ?></p>
		</aside>
	<?php } ?>

	<?php if ($securityCritical) { ?>
		<aside role="alert" class="flex flex-col gap-4 rounded-lg border border-red-200 bg-red-50 p-5 text-red-950 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-100 sm:flex-row sm:items-center sm:justify-between">
			<div class="flex items-start gap-3"><i class="fa-solid fa-shield-virus mt-1" aria-hidden="true"></i><div><strong class="font-extrabold"><?php echo cfg_t('configurator.security.critical_file_changes_detected'); ?><?php echo (int)$securityCounts['critical']; ?></strong><p class="mt-1 text-xs font-medium opacity-80"><?php echo cfg_t('configurator.security.acknowledgement_does_not_hide_the_alert_or_change_the_baseline_it_clears_only_af'); ?></p></div></div>
			<?php if (!$securityAcknowledged) { ?><form method="post" hx-boost="false"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars($securityCsrf, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="securityAction" value="acknowledge"><button class="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border border-red-300 bg-white px-4 text-xs font-extrabold text-red-700 dark:border-red-500/40 dark:bg-black/20 dark:text-red-100" type="submit"><?php echo cfg_t('configurator.security.acknowledge'); ?></button></form><?php } else { ?><span class="shrink-0 rounded-full bg-red-100 px-3 py-1.5 text-xs font-extrabold text-red-700 dark:bg-red-500/20 dark:text-red-100"><?php echo cfg_t('configurator.security.acknowledged'); ?></span><?php } ?>
		</aside>
	<?php } ?>

	<section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
		<?php
		$securityCards = array(
			array(cfg_t('configurator.security.unchanged'), (int)$securityCounts['unchanged'], 'fa-circle-check', 'text-emerald-600 bg-emerald-50 dark:bg-emerald-500/10 dark:text-emerald-300'),
			array(cfg_t('configurator.security.customized_security'), (int)$securityCounts['customized'], 'fa-palette', 'text-blue-600 bg-blue-50 dark:bg-blue-500/10 dark:text-blue-300'),
			array(cfg_t('configurator.security.critical'), (int)$securityCounts['critical'], 'fa-triangle-exclamation', 'text-red-600 bg-red-50 dark:bg-red-500/10 dark:text-red-300'),
			array(cfg_t('configurator.security.checked'), (int)($securityState['checked'] ?? 0), 'fa-list-check', 'text-violet-600 bg-violet-50 dark:bg-violet-500/10 dark:text-violet-300'),
		);
		foreach ($securityCards as $card) { ?>
			<article class="rounded-lg border border-gray-100 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-[#151515]"><span class="flex h-10 w-10 items-center justify-center rounded-lg <?php echo $card[3]; ?>"><i class="fa-solid <?php echo $card[2]; ?>" aria-hidden="true"></i></span><strong class="mt-4 block text-2xl font-black text-brand dark:text-white"><?php echo $card[1]; ?></strong><span class="text-xs font-bold text-gray-500 dark:text-gray-400"><?php echo $card[0]; ?></span></article>
		<?php } ?>
	</section>

	<?php if ($securityRunning) { ?>
		<section class="rounded-lg border border-blue-200 bg-blue-50 p-5 dark:border-blue-500/30 dark:bg-blue-500/10"><div class="flex items-center justify-between gap-4 text-sm font-extrabold text-blue-900 dark:text-blue-100"><span><?php echo cfg_t('configurator.security.scan_in_progress'); ?></span><span><?php echo $securityPercent; ?>%</span></div><div class="mt-3 h-2 overflow-hidden rounded-full bg-blue-100 dark:bg-blue-950"><div class="h-full rounded-full bg-blue-600" style="width: <?php echo $securityPercent; ?>%"></div></div><p class="mt-3 text-xs font-medium text-blue-800 dark:text-blue-200"><?php echo (int)$securityState['checked']; ?> / <?php echo (int)$securityState['total']; ?>. <?php echo cfg_t('configurator.security.each_run_is_bounded_by_time_and_file_count_cron_resumes_from_the_saved_position'); ?></p></section>
	<?php } ?>

	<section class="overflow-hidden rounded-lg border border-gray-100 bg-white shadow-sm dark:border-gray-800 dark:bg-[#151515]">
		<header class="flex flex-col gap-2 border-b border-gray-100 px-6 py-5 dark:border-gray-800 sm:flex-row sm:items-center sm:justify-between"><div><h2 class="text-lg font-extrabold text-brand dark:text-white"><?php echo cfg_t('configurator.security.file_integrity'); ?></h2><p class="mt-1 text-xs font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('configurator.security.twig_templates_are_customizable_and_never_trigger_an_alert_by_themselves'); ?></p></div><span class="text-xs font-bold text-gray-400"><?php echo cfg_t('configurator.security.last_scan'); ?><?php echo $securityDate((int)($securityState['completed_at'] ?? 0)); ?></span></header>
		<?php if (empty($securityState['findings'])) { ?>
			<p class="p-8 text-center text-sm font-medium text-gray-500 dark:text-gray-400"><?php echo ($securityState['status'] ?? '') === 'completed' ? cfg_t('configurator.security.no_baseline_deviations_found') : cfg_t('configurator.security.run_the_first_scan'); ?></p>
		<?php } else { ?>
			<div class="overflow-x-auto"><table class="w-full min-w-[850px] text-left text-sm"><thead class="bg-gray-50 text-[11px] font-extrabold uppercase tracking-wider text-gray-400 dark:bg-[#1A1A1A]"><tr><th class="px-5 py-3"><?php echo cfg_t('configurator.security.file'); ?></th><th class="px-5 py-3"><?php echo cfg_t('configurator.security.status'); ?></th><th class="px-5 py-3"><?php echo cfg_t('configurator.security.size_expected_actual'); ?></th><th class="px-5 py-3"><?php echo cfg_t('configurator.common.modified'); ?></th><th class="px-5 py-3"><?php echo cfg_t('configurator.security.checked_security'); ?></th></tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-800">
			<?php foreach ((array)$securityState['findings'] as $finding) { $neutral = ($finding['severity'] ?? '') === 'neutral'; ?>
				<tr><td class="max-w-md break-all px-5 py-4 font-mono text-xs text-brand dark:text-gray-100"><?php echo htmlspecialchars((string)$finding['path'], ENT_QUOTES, 'UTF-8'); ?><span class="mt-1 block font-sans text-[11px] font-bold text-gray-400"><?php echo ($finding['class'] ?? '') === 'customizable' ? cfg_t('configurator.security.customizable') : cfg_t('configurator.common.core'); ?></span></td><td class="px-5 py-4"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-extrabold <?php echo $neutral ? 'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300' : 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300'; ?>"><?php echo htmlspecialchars($securityFindingLabels[$finding['status']] ?? (string)$finding['status'], ENT_QUOTES, 'UTF-8'); ?></span></td><td class="px-5 py-4 text-xs font-semibold text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($securitySize($finding['expected_size'] ?? null) . ' / ' . $securitySize($finding['actual_size'] ?? null), ENT_QUOTES, 'UTF-8'); ?></td><td class="px-5 py-4 text-xs font-semibold text-gray-500 dark:text-gray-400"><?php echo $securityDate((int)($finding['mtime'] ?? 0)); ?></td><td class="px-5 py-4 text-xs font-semibold text-gray-500 dark:text-gray-400"><?php echo $securityDate((int)($finding['checked_at'] ?? 0)); ?></td></tr>
			<?php } ?>
			</tbody></table></div>
			<?php if (!empty($securityState['findings_truncated'])) { ?><p class="border-t border-amber-100 bg-amber-50 px-5 py-3 text-xs font-bold text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200"><?php echo cfg_t('configurator.security.the_list_is_bounded_counters_above_include_every_detected_deviation'); ?></p><?php } ?>
		<?php } ?>
	</section>

	<details class="overflow-hidden rounded-lg border border-gray-100 bg-white shadow-sm dark:border-gray-800 dark:bg-[#151515]" <?php echo $securityPreflight['blockers'] ? 'open' : ''; ?>>
		<summary class="cursor-pointer list-none px-6 py-5"><div class="flex items-center justify-between gap-4"><div><h2 class="text-lg font-extrabold text-brand dark:text-white"><?php echo cfg_t('configurator.security.production_preflight'); ?></h2><p class="mt-1 text-xs font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('configurator.security.blocking_conditions_are_checked_again_before_backup_and_update'); ?></p></div><div class="flex shrink-0 items-center gap-3"><span class="rounded-full px-3 py-1 text-xs font-extrabold <?php echo $securityPreflight['blockers'] ? 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300' : 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'; ?>"><?php echo $securityPreflight['blockers'] ? count($securityPreflight['blockers']) . ' ' . cfg_t('configurator.security.blockers') : cfg_t('configurator.security.ready'); ?></span><i class="fa-solid fa-chevron-down text-xs text-gray-400" aria-hidden="true"></i></div></div></summary>
		<div class="divide-y divide-gray-100 dark:divide-gray-800"><?php foreach ($securityPreflight['checks'] as $check) { ?><article class="flex items-start gap-4 px-6 py-4"><span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full <?php echo $check['passed'] ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300' : ($check['status'] === 'warning' ? 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-300' : 'bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-300'); ?>"><i class="fa-solid <?php echo $check['passed'] ? 'fa-check' : ($check['status'] === 'warning' ? 'fa-triangle-exclamation' : 'fa-xmark'); ?> text-xs" aria-hidden="true"></i></span><div class="min-w-0"><strong class="text-sm font-extrabold text-brand dark:text-white"><?php echo htmlspecialchars($check['label'], ENT_QUOTES, 'UTF-8'); ?></strong><p class="mt-1 text-xs font-medium text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($check['detail'], ENT_QUOTES, 'UTF-8'); ?></p><?php if ($securityLocalOperator && !empty($check['path'])) { ?><code class="mt-2 block break-all text-[11px] text-gray-400"><?php echo htmlspecialchars($check['path'], ENT_QUOTES, 'UTF-8'); ?></code><?php } ?></div></article><?php } ?></div>
		<?php if (!$securityLocalOperator) { ?><p class="border-t border-gray-100 bg-gray-50 px-6 py-3 text-xs font-medium text-gray-500 dark:border-gray-800 dark:bg-[#1A1A1A] dark:text-gray-400"><?php echo cfg_t('configurator.security.absolute_paths_are_hidden_for_remote_requests'); ?></p><?php } ?>
	</details>

	<details class="overflow-hidden rounded-lg border border-gray-100 bg-white shadow-sm dark:border-gray-800 dark:bg-[#151515]">
		<summary class="cursor-pointer list-none px-6 py-5 text-base font-extrabold text-brand dark:text-white"><i class="fa-solid fa-clock-rotate-left mr-2 text-gray-400" aria-hidden="true"></i><?php echo cfg_t('configurator.security.security_audit'); ?><span class="ml-2 inline-flex min-w-6 items-center justify-center rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500 dark:bg-gray-800 dark:text-gray-300"><?php echo count($securityAudit); ?></span></summary>
		<div class="divide-y divide-gray-100 border-t border-gray-100 dark:divide-gray-800 dark:border-gray-800"><?php if (!$securityAudit) { ?><p class="p-6 text-sm font-medium text-gray-500 dark:text-gray-400"><?php echo cfg_t('configurator.security.no_records_yet'); ?></p><?php } foreach ($securityAudit as $record) { ?><article class="grid gap-2 px-6 py-4 text-xs sm:grid-cols-[1fr_auto_auto]"><div><strong class="font-mono text-brand dark:text-white"><?php echo htmlspecialchars((string)($record['event'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong><span class="ml-2 rounded-full px-2 py-0.5 font-extrabold <?php echo ($record['outcome'] ?? '') === 'success' || ($record['outcome'] ?? '') === 'completed' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300'; ?>"><?php echo htmlspecialchars((string)($record['outcome'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></div><span class="font-mono text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars((string)($record['ip'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span><time class="font-semibold text-gray-400"><?php echo htmlspecialchars((string)($record['ts'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></time></article><?php } ?></div>
	</details>
</section>

<script src="static/js/configurator-security.js" defer></script>
<?php include('module/_config/_footer.php'); ?>
