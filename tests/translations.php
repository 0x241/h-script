<?php

$root = dirname(__DIR__);
$translations = array();
foreach (array('en', 'ru') as $language)
{
	$json = (string)file_get_contents($root . '/lang/' . $language . '.json');
	preg_match_all('/^\s*"([^"]+)"\s*:/m', $json, $declaredKeys);
	if (count($declaredKeys[1]) !== count(array_unique($declaredKeys[1])))
		throw new RuntimeException('Duplicate translation key in ' . $language . ' catalog');
	$translations[$language] = json_decode(
		$json,
		true,
		512,
		JSON_THROW_ON_ERROR
	);
}

$keys = array();
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/tpl'));
foreach ($iterator as $file)
{
	if (!$file->isFile() || $file->getExtension() !== 'twig')
		continue;
	$content = (string)file_get_contents($file->getPathname());
	if (!preg_match_all('/["\']key["\']\s*:\s*["\']([^"\']+)["\']/', $content, $matches))
		continue;
	foreach ($matches[1] as $key)
		$keys[$key] = true;
}

foreach (array($root . '/module', $root . '/src') as $phpRoot)
{
	$phpIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($phpRoot));
	foreach ($phpIterator as $file)
	{
		if (!$file->isFile() || $file->getExtension() !== 'php')
			continue;
		$content = (string)file_get_contents($file->getPathname());
		if (!preg_match_all('/\$(?:[A-Za-z]+Translate|translate)\(\s*["\']([^"\']+)["\']/', $content, $matches))
			continue;
		foreach ($matches[1] as $key)
			$keys[$key] = true;
	}
}

$missing = array();
foreach (array('en', 'ru') as $language)
	foreach ($keys as $key => $_)
		if (!array_key_exists($key, $translations[$language]))
			$missing[] = $language . ':' . $key;
if ($missing)
	throw new RuntimeException("Missing static translations:\n" . implode("\n", $missing));

$englishKeys = array_keys($translations['en']);
$russianKeys = array_keys($translations['ru']);
sort($englishKeys, SORT_NATURAL | SORT_FLAG_CASE);
sort($russianKeys, SORT_NATURAL | SORT_FLAG_CASE);
if ($englishKeys !== $russianKeys)
	throw new RuntimeException('English and Russian translation catalog keys differ');

$placeholders = static function (string $value): array
{
	preg_match_all('/{{\s*([A-Za-z0-9_]+)\s*}}/', $value, $matches);
	$result = array_values(array_unique($matches[1] ?? array()));
	sort($result, SORT_NATURAL | SORT_FLAG_CASE);
	return $result;
};
foreach ($englishKeys as $key)
{
	if ($placeholders((string)$translations['en'][$key]) !== $placeholders((string)$translations['ru'][$key]))
		throw new RuntimeException('Translation placeholders differ for key: ' . $key);
	if (preg_match('/[А-Яа-яЁё]/u', (string)$translations['en'][$key]))
		throw new RuntimeException('English translation contains Cyrillic text: ' . $key);
	foreach (array('en', 'ru') as $language)
		if (preg_match('/(?:�|Ã|Â)/u', (string)$translations[$language][$key]))
			throw new RuntimeException('Translation contains invalid encoding artifacts: ' . $language . ':' . $key);
}

$russianLatinOnlyAllowlist = array_fill_keys(array(
	'auth.field.email',
	'auth.placeholder.email',
	'auth.placeholder.login',
	'auth.placeholder.login_email',
	'auth.placeholder.new_email',
	'auth.placeholder.referrer',
	'auth.placeholder.username',
	'common.id',
	'faq.eyebrow',
	'home.badge',
	'home.meta.title',
	'home.metric.security.value',
	'home.modules.api.title',
	'mail.admin.header',
	'mail.user.noticetomail.body',
	'mail.user.noticetomail.subject',
	'nav.faq',
), true);
foreach ($translations['ru'] as $key => $value)
	if (preg_match('/[A-Za-z]{3}/', (string)$value)
		&& !preg_match('/[А-Яа-яЁё]/u', (string)$value)
		&& !isset($russianLatinOnlyAllowlist[$key]))
		throw new RuntimeException('Russian translation is still English-only: ' . $key);

foreach (array('en', 'ru') as $language)
	foreach ($translations[$language] as $key => $value)
		if ($value === '' && !in_array($key, array('mail.admin.footer', 'mail.user.footer'), true))
			throw new RuntimeException('Unexpected empty translation: ' . $language . ':' . $key);

$mailKeys = array();
foreach (array('en', 'ru') as $language)
{
	$languageMailKeys = array_values(array_filter(
		array_keys($translations[$language]),
		static fn(string $key): bool => str_starts_with($key, 'mail.')
	));
	sort($languageMailKeys, SORT_NATURAL | SORT_FLAG_CASE);
	$mailKeys[$language] = $languageMailKeys;
}
if ($mailKeys['en'] !== $mailKeys['ru'])
	throw new RuntimeException('English and Russian email translation keys differ');
if (count($mailKeys['en']) < 80)
	throw new RuntimeException('Email translation catalog is incomplete');
foreach (array(
	'mail.admin.newticket.subject',
	'mail.admin.ticket.body',
	'mail.user.askconfirmseclogin.subject',
	'mail.user.operation.body',
	'mail.user.ticket.body',
) as $requiredMailKey)
	if (!array_key_exists($requiredMailKey, $translations['en']))
		throw new RuntimeException('Missing required email translation: ' . $requiredMailKey);
if (is_file($root . '/tpl/e-mails.lng') || is_file($root . '/tpl/admin/e-mails.lng'))
	throw new RuntimeException('Legacy email .lng catalog was not removed');
foreach (array(
	'/module/account/ulogin/index.php',
	'/module/account/loginza/index.php',
	'/module/account/isp/index.php',
	'/tpl/account/ulogin/index.twig',
	'/tpl/account/loginza/index.twig',
	'/tpl/account/isp/_box.twig',
) as $legacySocialPath)
	if (file_exists($root . $legacySocialPath))
		throw new RuntimeException('Legacy social authentication path was not removed: ' . $legacySocialPath);
$accountConfig = (string)file_get_contents($root . '/module/_config.php')
	. (string)file_get_contents($root . '/tpl/account/admin/setup.twig');
if (preg_match('/ulogin|loginza|investorsstartpage|account\/isp/i', $accountConfig))
	throw new RuntimeException('Legacy social authentication configuration is still reachable');

$englishDateFile = $root . '/tpl/en/date.lng';
$dateLines = file($englishDateFile, FILE_IGNORE_NEW_LINES);
if (!is_array($dateLines) || count($dateLines) < 3 || count(explode('|', $dateLines[1])) !== 12)
	throw new RuntimeException('English date localization is incomplete');

echo "Translation catalog audit passed.\n";
