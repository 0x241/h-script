<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$templates = array(
	'tpl/tickets/index.twig' => '?add',
	'tpl/message/index.twig' => '?new',
	'tpl/depo/index.twig' => '?add=DEPO',
);

foreach ($templates as $relativePath => $action)
{
	$source = (string) file_get_contents($root . '/' . $relativePath);
	if (!str_contains($source, $action) || !str_contains($source, 'class="hs-action hs-action--primary'))
	{
		throw new RuntimeException($relativePath . ' must use the shared primary action component.');
	}
}

$css = (string) file_get_contents($root . '/static/css/input.css');
foreach (array('.hs-action', 'min-height: 48px', 'padding: 12px 24px', '.hs-action--primary') as $requiredRule)
{
	if (!str_contains($css, $requiredRule))
	{
		throw new RuntimeException('Missing shared action rule: ' . $requiredRule);
	}
}

echo "Button consistency tests passed.\n";
