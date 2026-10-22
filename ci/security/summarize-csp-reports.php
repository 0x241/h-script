<?php

/** Aggregate every retained CSP generation without printing URL paths or request data. */

use HScript\Http\CspReportSummary;

if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$arguments = array_values(array_filter(array_slice($argv, 1), static fn(string $value): bool => $value !== '--json'));
$path = $arguments[0] ?? dirname(__DIR__, 2) . '/logs/csp-violations.ndjson';
set_error_handler(static fn(): bool => true);
try
{
	$summary = CspReportSummary::summarize($path);
	if (in_array('--json', $argv, true))
	{
		echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
	}
	else
	{
		$history = $summary['history'];
		echo 'Reports: ' . $history['records'] . '; invalid lines: ' . $history['invalid_lines'] . '; retained files: ' . $history['files'] . "\n";
		echo 'Retention: ' . $history['status'] . '; retained seconds: ' . $history['retained_seconds'] . '; required seconds: ' . $history['required_seconds'] . "\n";
		echo 'Capacity: ' . $summary['capacity']['total_byte_ceiling'] . ' bytes; peak observed day: ' . $history['peak_daily_bytes'] . " bytes\n";
		echo "Count | Surface | Directive | Blocked origin | Source kind | Asset class | Disposition\n";
		foreach ($summary['groups'] as $group)
			echo $group['count'] . ' | ' . implode(' | ', $group['fields']) . "\n";
	}
	exit(0);
}
catch (Throwable)
{
	fwrite(STDERR, "CSP report history is unavailable; check private storage and writer recovery.\n");
	exit(1);
}
finally { restore_error_handler(); }
