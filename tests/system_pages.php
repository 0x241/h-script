<?php

declare(strict_types=1);

use HScript\Application;
use HScript\Http\SystemPageRenderer;

function systemPagesAssert(bool $condition, string $message): void
{
	if (!$condition)
		throw new RuntimeException($message);
}

$root = dirname(__DIR__);
chdir($root);
require $root . '/vendor/autoload.php';

systemPagesAssert(SystemPageRenderer::selectLanguage('en', 'ru', 'ru') === 'en', 'Explicit system language is not preferred');
systemPagesAssert(SystemPageRenderer::selectLanguage(null, 'ru-RU', 'en') === 'ru', 'Language cookie is not normalized');
systemPagesAssert(SystemPageRenderer::selectLanguage(null, null, 'en-US;q=0.6,ru;q=0.9') === 'ru', 'Accept-Language quality is ignored');
systemPagesAssert(SystemPageRenderer::selectLanguage(null, null, 'de-DE') === 'ru', 'System language fallback is not Russian');
systemPagesAssert(SystemPageRenderer::requestPath('/missing?q=1') === '/missing', 'System language URL keeps the query string');

$pages = array(
	'not_found' => array('404', 'Страница не найдена', 'Page not found'),
	'maintenance' => array('503', 'Применяем обновление', 'Applying the update'),
	'update_required' => array('503', 'Завершите обновление', 'Complete the update'),
);

foreach ($pages as $page => $fragments)
{
	$russian = SystemPageRenderer::render($page, 'ru', '/missing?old=1', '/private-config?update&from=gate');
	$english = SystemPageRenderer::render($page, 'en', '/missing?old=1', '/private-config?update&from=gate');
	foreach (array($russian, $english) as $html)
	{
		systemPagesAssert(str_starts_with($html, '<!doctype html>'), $page . ' is not a complete HTML document');
		systemPagesAssert(str_contains($html, 'name="robots" content="noindex, nofollow"'), $page . ' can be indexed');
		systemPagesAssert(str_contains($html, 'href="/static/css/system-page.css"'), $page . ' omits the shared stylesheet');
		systemPagesAssert(str_contains($html, '<span>Script</span>'), $page . ' does not use the main wordmark');
		systemPagesAssert(str_contains($html, 'stroke-width="4"'), $page . ' does not use the main logo stroke');
		systemPagesAssert(str_contains($html, 'href="/missing?system_lang=ru"'), $page . ' omits the Russian switch link');
		systemPagesAssert(str_contains($html, 'href="/missing?system_lang=en"'), $page . ' omits the English switch link');
		systemPagesAssert(!str_contains($html, '<script'), $page . ' adds script overhead');
		systemPagesAssert(!preg_match('/{{[a-z_]+}}/', $html), $page . ' contains an unresolved placeholder');
	}
	systemPagesAssert(str_contains($russian, 'lang="ru"'), $page . ' does not declare Russian');
	systemPagesAssert(str_contains($english, 'lang="en"'), $page . ' does not declare English');
	systemPagesAssert(str_contains($russian, $fragments[0]) && str_contains($russian, $fragments[1]), $page . ' is missing Russian content');
	systemPagesAssert(str_contains($english, $fragments[0]) && str_contains($english, $fragments[2]), $page . ' is missing English content');
	systemPagesAssert(str_contains($russian, 'H-Script версии ' . Application::version()), $page . ' footer differs from the Russian main footer');
	systemPagesAssert(str_contains($english, 'H-Script version ' . Application::version()), $page . ' footer differs from the English main footer');
}

$update = SystemPageRenderer::render('update_required', 'ru', '/', '/private-config?update&from=gate');
systemPagesAssert(str_contains($update, 'href="/private-config?update&amp;from=gate"'), 'Update page does not use the configured Configurator route');
systemPagesAssert(str_contains($update, 'Открыть конфигуратор'), 'Update page omits the Configurator action');

$requiredKeys = array(
	'system.404.description', 'system.404.document_title', 'system.404.eyebrow', 'system.404.note', 'system.404.title',
	'system.action.configurator', 'system.action.home', 'system.action.retry',
	'system.maintenance.description', 'system.maintenance.document_title', 'system.maintenance.eyebrow', 'system.maintenance.note', 'system.maintenance.title',
	'system.update_required.description', 'system.update_required.document_title', 'system.update_required.eyebrow', 'system.update_required.note', 'system.update_required.title',
);
foreach (array('ru', 'en') as $language)
{
	$catalog = json_decode((string)file_get_contents($root . '/lang/' . $language . '.json'), true, 512, JSON_THROW_ON_ERROR);
	foreach ($requiredKeys as $key)
		systemPagesAssert(isset($catalog[$key]), $language . ' catalog is missing ' . $key);
}

$css = (string)file_get_contents($root . '/static/css/system-page.css');
foreach (array('prefers-color-scheme: dark', 'prefers-reduced-motion: reduce', '@media (max-width: 460px)', '.system-language', 'width: 40px', 'border: 2.5px') as $fragment)
	systemPagesAssert(str_contains($css, $fragment), 'System page stylesheet is missing ' . $fragment);

$router = (string)file_get_contents($root . '/rw.php');
foreach (array("hsRenderSystemPage('not_found')", "'maintenance' : 'update_required'", "'Cache-Control: no-store, max-age=0'", "'X-Robots-Tag: noindex, nofollow'") as $fragment)
	systemPagesAssert(str_contains($router, $fragment), 'Router is missing ' . $fragment);

$dockerfile = (string)file_get_contents($root . '/Dockerfile');
systemPagesAssert(str_contains($dockerfile, 'system-page.html'), 'Docker image omits the system page template');
foreach (array('404.html', 'maintenance.html', 'update-required.html') as $legacyPage)
	systemPagesAssert(!file_exists($root . '/' . $legacyPage), 'Legacy duplicate system page remains: ' . $legacyPage);

echo "System page tests passed.\n";
