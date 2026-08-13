<?php

use HScript\Util\StringHelper;

use HScript\Template\View;

$_auth = 90;
require_once('module/auth.php');

$form = 'translations';
$add_form = 'translations_add';

function translationsNormalizeKey($key)
{
	return preg_replace('/[^a-z0-9_.-]/', '', StringHelper::textLow(trim((string)$key)));
}

$langs = View::translationLanguages();
$prefix = translationsNormalizeKey(_GET('prefix'));

if (View::sendedForm('save', $form))
{
	View::checkFormSecurity($form);
	if ($_GS['demo'] and ($_user['uLevel'] < 99))
		View::showInfo('*Denied');

	$key = translationsNormalizeKey(_IN('Key'));
	if (!$key)
		View::showInfo('*Error');

	$all = View::translationLoadAll($langs);
	$exists = false;
	foreach ($langs as $lang)
		if (isset($all[$lang]) and array_key_exists($key, $all[$lang]))
		{
			$exists = true;
			break;
		}
	if (!$exists)
		View::showInfo('*NotFound');

	$values = (isset($_IN['Value']) and is_array($_IN['Value'])) ? $_IN['Value'] : array();
	foreach ($langs as $lang)
		if (array_key_exists($lang, $values))
			$all[$lang][$key] = trim((string)$values[$lang]);

	if (!View::translationSaveAll($all))
		View::showInfo('*CantComplete');
	View::translationClearCache();
	View::showInfo('Saved');
}

if (View::sendedForm('', $add_form))
{
	View::checkFormSecurity($add_form);
	if ($_GS['demo'] and ($_user['uLevel'] < 99))
		View::showInfo('*Denied');

	$newKey = translationsNormalizeKey(_IN('Key'));
	if (!$newKey)
		View::showInfo('*Error');

	$all = View::translationLoadAll($langs);
	foreach ($langs as $lang)
		if (isset($all[$lang]) and array_key_exists($newKey, $all[$lang]))
			View::showInfo('*AlreadyUsed');

	$values = (isset($_IN['Value']) and is_array($_IN['Value'])) ? $_IN['Value'] : array();
	foreach ($langs as $lang)
		$all[$lang][$newKey] = isset($values[$lang]) ? trim((string)$values[$lang]) : '';

	if (!View::translationSaveAll($all))
		View::showInfo('*CantComplete');
	View::translationClearCache();
	View::showInfo('Added');
}

$all = View::translationLoadAll($langs);
$keys_array = array();
foreach ($langs as $lang) {
	if (isset($all[$lang]) && is_array($all[$lang])) {
		$keys_array = array_merge($keys_array, array_keys($all[$lang]));
	}
}
$keys = array_unique($keys_array);
sort($keys, SORT_NATURAL | SORT_FLAG_CASE);

$rows = array();
foreach ($keys as $key) {
	if ($prefix !== '' && !str_starts_with($key, $prefix)) {
		continue;
	}
	$r = array('key' => $key);
	foreach ($langs as $lang) {
		$r[$lang] = isset($all[$lang][$key]) ? $all[$lang][$key] : '';
	}
	$rows[] = $r;
}

View::setPage('active_langs', $langs, 0);
View::setPage('translation_rows', $rows, 0);
View::setPage('translation_form', $form);
View::setPage('translation_add_form', $add_form);
View::setPage('translation_filter', $prefix);
View::setPage('translation_base_link', moduleToLink(), 0);
View::setPage('mail_settings_link', moduleToLink('system/admin/setup_mail'), 0);

View::showPage();

?>
