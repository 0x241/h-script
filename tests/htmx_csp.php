<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$meta = '<meta name="htmx-config" content=\'{"allowEval":false}\'>';
$script = '<script src="static/js/htmx.min.js"></script>';
$shells = array(
	'tpl/base.twig',
	'tpl/header.twig',
	'tpl/admin/header.twig',
	'tpl/external/header.twig',
	'tpl/external/auth.header.twig',
	'module/_config/_header.php',
);

foreach ($shells as $relativePath)
{
	$source = (string) file_get_contents($root . '/' . $relativePath);
	$metaPosition = strpos($source, $meta);
	$scriptPosition = strpos($source, $script);
	if ($metaPosition === false)
		throw new RuntimeException($relativePath . ' does not disable HTMX eval.');
	if ($scriptPosition === false || $metaPosition > $scriptPosition)
		throw new RuntimeException($relativePath . ' must configure HTMX before loading it.');
}

$directories = array($root . '/tpl', $root . '/module');
foreach ($directories as $directory)
{
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
		$directory,
		FilesystemIterator::SKIP_DOTS
	));
	foreach ($iterator as $file)
	{
		if (!$file->isFile() || !in_array($file->getExtension(), array('php', 'twig'), true))
			continue;
		$source = (string) file_get_contents($file->getPathname());
		$relativePath = substr($file->getPathname(), strlen($root) + 1);
		if (preg_match('/\b(?:data-)?hx-on(?::[a-z0-9_.:-]+)?\s*=/i', $source))
			throw new RuntimeException($relativePath . ' uses eval-dependent hx-on.');
		if (preg_match('/\b(?:data-)?hx-(?:vals|headers)\s*=\s*([\'\"])\s*(?:js|javascript):/i', $source))
			throw new RuntimeException($relativePath . ' uses eval-dependent HTMX values.');
		if (preg_match_all('/\b(?:data-)?hx-trigger\s*=\s*([\'\"])(.*?)\1/is', $source, $triggers))
			foreach ($triggers[2] as $trigger)
				if (preg_match('/\[[^]]+\]/', $trigger))
					throw new RuntimeException($relativePath . ' uses an eval-dependent HTMX trigger filter.');
	}
}

echo "HTMX CSP tests passed.\n";
