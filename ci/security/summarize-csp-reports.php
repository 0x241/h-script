<?php

/** Aggregate sanitized CSP reports without printing URL paths or request data. */

if (PHP_SAPI !== 'cli') exit(1);

$path = $argv[1] ?? dirname(__DIR__, 2) . '/logs/csp-violations.ndjson';
if (!is_file($path) || !is_readable($path))
{
	fwrite(STDERR, "CSP report log is unavailable: $path\n");
	exit(1);
}

$counts = array();
$total = 0;
$invalid = 0;
$stream = fopen($path, 'rb');
if ($stream === false) exit(1);
try
{
	while (($line = fgets($stream)) !== false)
	{
		$record = json_decode($line, true);
		if (!is_array($record)) { $invalid++; continue; }
		$fields = array();
		foreach (array('document_surface', 'directive', 'blocked_origin', 'source_kind', 'source_asset', 'disposition') as $field)
		{
			$value = $record[$field] ?? '';
			$fields[] = is_string($value) && strlen($value) <= 160 ? $value : '';
		}
		$key = json_encode($fields, JSON_THROW_ON_ERROR);
		$counts[$key] = ($counts[$key] ?? 0) + 1;
		$total++;
	}
}
finally { fclose($stream); }

arsort($counts, SORT_NUMERIC);
echo "Reports: $total; invalid lines: $invalid\n";
echo "Count | Surface | Directive | Blocked origin | Source kind | Source asset | Disposition\n";
foreach ($counts as $key => $count)
	echo $count . ' | ' . implode(' | ', json_decode($key, true, 8, JSON_THROW_ON_ERROR)) . "\n";
