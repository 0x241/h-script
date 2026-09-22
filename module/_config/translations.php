<?php

use HScript\Template\View;

/** Shared JSON catalogs and overrides, without initializing Twig or a database. */
function cfg_language(mixed $requested = null): string
{
	global $_cfg;

	$language = $requested ?? ($_SESSION['cfg_lang'] ?? ($_cfg['Sys_AdminLang'] ?? 'en'));
	$language = is_string($language) ? strtolower(trim($language)) : '';
	return in_array($language, View::translationLanguages(), true) ? $language : 'en';
}

function cfg_t(string $key, array $parameters = array()): string
{
	$translated = View::_t($key, $parameters, cfg_language());
	return $translated === $key ? View::_t($key, $parameters, 'en') : $translated;
}
