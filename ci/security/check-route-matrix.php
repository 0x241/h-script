<?php

declare(strict_types=1);

use HScript\Update\ConfiguratorRouteRegistry;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/module/_config.php';

$matrix = json_decode((string)file_get_contents(__DIR__ . '/route-matrix.json'), true, 512, JSON_THROW_ON_ERROR);
$rows = $matrix['routes'] ?? null;
if (($matrix['version'] ?? null) !== 1 || !is_array($rows))
	throw new RuntimeException('Route matrix format is invalid');

$expected = array_keys($_rwlinks);
foreach (ConfiguratorRouteRegistry::keys() as $route)
	$expected[] = '_cfg?' . $route;
$expected[] = '_cfg preference';
$seen = array();
foreach ($rows as $row)
{
	if (!is_array($row)) throw new RuntimeException('Route matrix row is invalid');
	foreach (array('route', 'method', 'auth', 'ownership', 'demo', 'csrf', 'response') as $field)
		if (!isset($row[$field]) || !is_string($row[$field]) || $row[$field] === '')
			throw new RuntimeException('Route matrix field is missing: ' . $field);
	$route = $row['route'];
	if (isset($seen[$route])) throw new RuntimeException('Duplicate route matrix entry: ' . $route);
	$seen[$route] = true;
	if (str_contains($row['method'], 'POST') && $row['csrf'] === 'none')
		throw new RuntimeException('POST route lacks a documented authenticity control: ' . $route);
	if ($row['auth'] === 'cookie' && $row['min_level'] === null)
		throw new RuntimeException('Cookie route lacks a minimum role: ' . $route);
}
sort($expected);
$actual = array_keys($seen);
sort($actual);
if ($expected !== $actual)
	throw new RuntimeException('Route matrix does not match the application registry: ' . json_encode(array(
		'missing' => array_values(array_diff($expected, $actual)),
		'extra' => array_values(array_diff($actual, $expected)),
	)));

echo 'Route matrix covers ' . count($actual) . " routes.\n";
