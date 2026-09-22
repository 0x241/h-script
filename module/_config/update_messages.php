<?php

use HScript\Template\View;

function cfg_update_error_message(string $message): string
{
	if (preg_match('/^Recovery evidence expired: (backup|drill|backup_archive|update_backup); timestamp=(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z); max_age=([1-9][0-9]{0,9})$/D', $message, $match))
	{
		$evidenceKey = 'configurator.update.recovery.kind_' . $match[1];
		return cfg_t('configurator.update.recovery.expired_detail', array('evidence' => cfg_t($evidenceKey), 'timestamp' => $match[2], 'seconds' => $match[3]));
	}
	if (preg_match('/^Recovery evidence (invalid|future): (backup|drill|backup_archive|update_backup)$/D', $message, $match))
	{
		$messageKey = 'configurator.update.recovery.' . $match[1] . '_detail';
		$evidenceKey = 'configurator.update.recovery.kind_' . $match[2];
		return cfg_t($messageKey, array('evidence' => cfg_t($evidenceKey)));
	}
	if (preg_match('/^(RECOVERY_(?:(?:CMS|COLLECTOR|SERVICE_TOKENS)_(?:RPO|RTO)_SECONDS|DRILL_MAX_AGE_SECONDS)) must be a positive integer$/D', $message, $match))
		return cfg_t('configurator.update.recovery.policy_missing', array('setting' => $match[1]));
	$key = match ($message) {
		'Verified recovery evidence is missing or incomplete: backup' => 'configurator.update.recovery.backup_missing',
		'Verified recovery evidence is missing or incomplete: drill' => 'configurator.update.recovery.drill_missing',
		'Recovery evidence is missing, stale or future-dated',
		'Recovery evidence timestamp is invalid' => 'configurator.update.recovery.evidence_stale',
		'Restore drill exceeds the recovery time policy' => 'configurator.update.recovery.drill_too_slow',
		'Recovery bundle file is missing', 'Recovery bundle file checksum does not match' => 'configurator.update.recovery.bundle_invalid',
		'Update preflight requires a healthy queue with no processing jobs; pause workers and drain them' => 'configurator.update.recovery.queue_not_ready',
		default => null,
	};
	if ($key !== null) return cfg_t($key);
	// Services keep their technical English diagnostics; UI translations live in JSON.
	foreach (View::translationReadBundledFile('en') as $key => $english)
		if (str_starts_with($key, 'configurator.update.error.')
			&& ($message === $english || str_starts_with($message, $english . ': ')))
			return cfg_t($key);
	return cfg_t('configurator.update.operation_failed');
}

function cfg_update_run_message(string $code, string $summary): string
{
	if ($code === 'current_migration' && preg_match('/^Applying migration ([A-Za-z0-9_.-]+)$/D', $summary, $match))
		return cfg_t('configurator.update.run.current_migration_named', array('migration' => $match[1]));
	$key = 'configurator.update.run.' . $code;
	$catalog = View::translationReadBundledFile('en');
	return array_key_exists($key, $catalog) ? cfg_t($key) : cfg_t('configurator.update.run_status_saved');
}
