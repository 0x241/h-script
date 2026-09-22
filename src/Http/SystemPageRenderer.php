<?php

namespace HScript\Http;

use HScript\Application;
use RuntimeException;

/** Renders dependency-free error and update pages before the CMS is initialized. */
final class SystemPageRenderer
{
	private const PAGES = array(
		'not_found' => array('class' => 'not-found', 'code' => '404', 'key' => 'system.404'),
		'maintenance' => array('class' => 'maintenance', 'code' => '503', 'key' => 'system.maintenance'),
		'update_required' => array('class' => 'blocked', 'code' => '503', 'key' => 'system.update_required'),
	);

	public static function normalizeLanguage(mixed $language): ?string
	{
		if (!is_string($language)) return null;
		$language = strtolower(str_replace('_', '-', trim($language)));
		$language = explode('-', $language, 2)[0];
		return in_array($language, array('ru', 'en'), true) ? $language : null;
	}

	public static function selectLanguage(mixed $requested, mixed $cookie, string $acceptLanguage): string
	{
		foreach (array($requested, $cookie) as $candidate)
			if (($language = self::normalizeLanguage($candidate)) !== null) return $language;

		$accepted = array();
		foreach (explode(',', $acceptLanguage) as $position => $part)
		{
			$segments = array_map('trim', explode(';', $part));
			$language = self::normalizeLanguage($segments[0] ?? '');
			if ($language === null) continue;
			$quality = 1.0;
			foreach (array_slice($segments, 1) as $parameter)
				if (preg_match('/^q=(0(?:\.\d+)?|1(?:\.0+)?)$/i', $parameter, $match))
					$quality = (float)$match[1];
			$accepted[] = array('language' => $language, 'quality' => $quality, 'position' => $position);
		}
		usort($accepted, static fn(array $a, array $b): int => $b['quality'] <=> $a['quality'] ?: $a['position'] <=> $b['position']);
		return (string)($accepted[0]['language'] ?? 'ru');
	}

	public static function requestPath(string $requestUri): string
	{
		$path = parse_url($requestUri, PHP_URL_PATH);
		if (!is_string($path) || $path === '') return '/';
		return '/' . ltrim($path, '/');
	}

	public static function render(string $page, string $language, string $requestPath, string $configuratorUrl = ''): string
	{
		if (!isset(self::PAGES[$page])) throw new RuntimeException('Unknown system page');
		$language = self::normalizeLanguage($language) ?? 'ru';
		$template = dirname(__DIR__, 2) . '/system-page.html';
		if (!is_readable($template)) throw new RuntimeException('System page template is unavailable');
		$definition = self::PAGES[$page];
		$key = $definition['key'];
		$path = self::requestPath($requestPath);
		$translations = self::translations($language);
		$translate = static function (string $translationKey, array $params = array()) use ($translations): string
		{
			if (!array_key_exists($translationKey, $translations))
				throw new RuntimeException('Missing system page translation: ' . $translationKey);
			$value = (string)$translations[$translationKey];
			foreach ($params as $name => $replacement)
				$value = str_replace('{{' . $name . '}}', (string)$replacement, $value);
			return $value;
		};
		$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$languageUrl = static fn(string $target): string => $escape($path . '?system_lang=' . $target);

		$actions = array();
		if ($page === 'not_found')
			$actions[] = self::button('/', $translate('system.action.home'), 'primary', '→');
		elseif ($page === 'update_required')
		{
			$actions[] = self::button($configuratorUrl ?: '/_cfg?update', $translate('system.action.configurator'), 'primary', '→');
			$actions[] = self::button($path, $translate('system.action.retry'), 'secondary');
		}
		else
			$actions[] = self::button($path, $translate('system.action.retry'), 'primary', '↻');

		return strtr((string)file_get_contents($template), array(
			'{{html_lang}}' => $escape($language),
			'{{body_class}}' => $escape((string)$definition['class']),
			'{{document_title}}' => $escape($translate($key . '.document_title')),
			'{{logo_label}}' => $escape(Application::NAME),
			'{{language_label}}' => $escape($translate('language.switch')),
			'{{language_ru_url}}' => $languageUrl('ru'),
			'{{language_en_url}}' => $languageUrl('en'),
			'{{language_ru_current}}' => $language === 'ru' ? ' aria-current="true"' : '',
			'{{language_en_current}}' => $language === 'en' ? ' aria-current="true"' : '',
			'{{eyebrow}}' => $escape($translate($key . '.eyebrow')),
			'{{status_code}}' => $escape((string)$definition['code']),
			'{{title}}' => $escape($translate($key . '.title')),
			'{{description}}' => $escape($translate($key . '.description')),
			'{{actions}}' => implode("\n", $actions),
			'{{note}}' => $escape($translate($key . '.note')),
			'{{pulse_class}}' => $page === 'maintenance' ? ' system-kicker__dot--pulse' : '',
			'{{copyright}}' => '&copy; ' . gmdate('Y') . ' ' . $escape($translate('site.product_copyright', array(
				'version' => Application::version(),
				'license' => Application::LICENSE,
			))),
		));
	}

	private static function translations(string $language): array
	{
		$path = dirname(__DIR__, 2) . '/lang/' . $language . '.json';
		$data = is_readable($path) ? json_decode((string)file_get_contents($path), true) : null;
		if (!is_array($data)) throw new RuntimeException('System page translation catalog is unavailable');
		return $data;
	}

	private static function button(string $url, string $label, string $style, string $symbol = ''): string
	{
		$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$suffix = $symbol === '' ? '' : ' <span aria-hidden="true">' . $escape($symbol) . '</span>';
		return sprintf(
			'<a class="system-button system-button--%s" href="%s">%s%s</a>',
			$escape($style),
			$escape($url),
			$escape($label),
			$suffix
		);
	}
}
