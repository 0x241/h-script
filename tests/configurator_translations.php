<?php
declare(strict_types=1);

use HScript\Template\View;
use HScript\Security\ProductionPreflight;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require $root . '/module/_config/translations.php';
require $root . '/module/_config/update_messages.php';
function cfgAssert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }

$en = View::translationReadBundledFile('en');
$ru = View::translationReadBundledFile('ru');
$backupUi = file_get_contents($root . '/module/_config/backup.php');
cfgAssert(str_contains($backupUi, 'return confirm(this.dataset.confirm)'), 'Translated confirmation must not be interpolated into JavaScript');
foreach (glob($root . '/module/_config/*.php') as $file)
{
	$text = file_get_contents($file);
	preg_match_all("/cfg_t\\(\\s*'([^']+)'/", $text, $matches);
	foreach ($matches[1] as $key)
		cfgAssert(str_starts_with($key, 'configurator.') && isset($en[$key], $ru[$key]), 'Missing Configurator key: ' . $key);
	cfgAssert(!preg_match('/[А-Яа-яЁё]/u', $text), 'Inline Russian text remains: ' . basename($file));
}

// No database configuration, connection, installed schema or Twig initialization.
unset($_GS, $_cfg, $_SESSION);
cfgAssert(cfg_language() === 'en', 'Fresh installer default language is invalid');
cfgAssert(cfg_t('configurator.login.wrong_password') === 'Wrong password', 'English before DB setup failed');
$_SESSION = array('cfg_lang' => 'ru');
cfgAssert(cfg_t('configurator.login.wrong_password') === 'Неверный пароль', 'Russian before DB setup failed');
cfgAssert(str_contains(cfg_t('configurator.common.file_not_removed', array('file'=>'fixture')), 'fixture'), 'Parameters were not substituted');
cfgAssert(cfg_language('../ru') === 'en' && cfg_language(array('ru')) === 'en', 'Invalid language was accepted');
cfgAssert(cfg_update_error_message('Official GitHub release download failed: secret-detail') === $ru['configurator.update.error.official_github_release_download_failed'], 'Update errors do not use catalog keys');
cfgAssert(!str_contains(cfg_update_error_message('unknown-secret'), 'unknown-secret'), 'Unknown error leaked technical details');
foreach (array('en', 'ru') as $language) {
	$_SESSION['cfg_lang'] = $language;
	$catalog = $language === 'en' ? $en : $ru;
	$expired = cfg_update_error_message('Recovery evidence expired: drill; timestamp=2026-09-21T15:48:56Z; max_age=60');
	cfgAssert(str_contains($expired, '2026-09-21T15:48:56Z') && str_contains($expired, '60') && !str_contains($expired, '{{'), 'Evidence age details were not rendered');
	foreach (array('invalid', 'future') as $reason)
		cfgAssert(cfg_update_error_message('Recovery evidence ' . $reason . ': backup') !== $catalog['configurator.update.operation_failed'], 'Evidence reason was hidden');
	cfgAssert(cfg_update_error_message('Recovery evidence expired: drill; timestamp=<script>; max_age=60') === $catalog['configurator.update.operation_failed'], 'Unsafe evidence diagnostics leaked');
	foreach (array(
		'Verified recovery evidence is missing or incomplete: backup' => 'backup_missing',
		'Verified recovery evidence is missing or incomplete: drill' => 'drill_missing',
		'Recovery evidence is missing, stale or future-dated' => 'evidence_stale',
		'Restore drill exceeds the recovery time policy' => 'drill_too_slow',
		'Recovery bundle file is missing' => 'bundle_invalid',
		'Update preflight requires a healthy queue with no processing jobs; pause workers and drain them' => 'queue_not_ready',
	) as $error => $key)
		cfgAssert(cfg_update_error_message($error) === $catalog['configurator.update.recovery.' . $key], 'Recovery error is not actionable: ' . $key);
	cfgAssert(str_contains(cfg_update_error_message('RECOVERY_CMS_RPO_SECONDS must be a positive integer'), 'RECOVERY_CMS_RPO_SECONDS'), 'Missing policy setting was hidden');
	cfgAssert(cfg_update_error_message('RECOVERY_CMS_RPO_SECONDS must be a positive integer: secret-detail') === $catalog['configurator.update.operation_failed'], 'Policy error leaked arbitrary details');
}
$_SESSION['cfg_lang'] = 'ru';
cfgAssert(str_contains(cfg_update_run_message('current_migration', 'Applying migration 20260910_example'), '20260910_example'), 'Migration placeholder missing');

$_cfg = array('Translations_ru' => json_encode(array('configurator.login.wrong_password'=>'Override')));
View::translationClearCache();
cfgAssert(cfg_t('configurator.login.wrong_password') === 'Override', 'Shared translation overrides were ignored');
$_cfg = array();
View::translationClearCache();
$englishChecks = (new ProductionPreflight($root, false, false, 'en'))->inspect()['checks'];
$russianChecks = (new ProductionPreflight($root, false, false, 'ru'))->inspect()['checks'];
cfgAssert(array_column($englishChecks,'id') === array_column($russianChecks,'id'), 'Translation changed preflight contract');
foreach ($englishChecks as $check) cfgAssert(!preg_match('/[А-Яа-яЁё]/u', $check['label'].' '.$check['detail']), 'English preflight contains Russian');

// A third installed language uses its catalog, with the same English fallback.
$temporary = sys_get_temp_dir() . '/hscript-cfg-language-' . bin2hex(random_bytes(8));
$cwd = getcwd();
mkdir($temporary . '/lang', 0700, true);
try
{
	copy($root.'/lang/en.json', $temporary.'/lang/en.json');
	file_put_contents($temporary.'/lang/fr.json', json_encode(array('configurator.login.wrong_password'=>'Mot de passe incorrect')));
	chdir($temporary);
	$_SESSION['cfg_lang']='fr';
	$_GS = array('lang' => 'fr', 'default_lang' => 'fr');
	View::translationClearCache();
	cfgAssert(in_array('fr', View::translationLanguages(), true), 'Third language missing in selector');
	cfgAssert(cfg_t('configurator.login.wrong_password') === 'Mot de passe incorrect', 'Third language text was replaced with English');
	cfgAssert(cfg_t('configurator.setup.test_mail_subject') === 'Test email', 'Missing key did not fall back to English');
	foreach ((new ProductionPreflight($root, false, false, 'fr'))->inspect()['checks'] as $check)
		cfgAssert(!str_contains($check['label'] . $check['detail'], 'configurator.'), 'Preflight missing-key fallback failed');
}
finally
{
	chdir($cwd);
	unlink($temporary.'/lang/fr.json'); unlink($temporary.'/lang/en.json');
	rmdir($temporary.'/lang'); rmdir($temporary);
}
echo "Configurator shared translations, overrides, fallback and pre-install tests passed.\n";
