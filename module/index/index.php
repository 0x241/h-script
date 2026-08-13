<?php

use HScript\Template\View;
use HScript\Telemetry\CollectorMode;
use HScript\Telemetry\InstallationRepository;

require_once('module/auth.php');

View::setPage('demo', !empty($_GS['demo']));

$publicMetrics = array();
if (CollectorMode::enabled($_cfg, (string)($_GS['domain'] ?? '')))
{
	try
	{
		$publicMetrics = $cache->get(
			'telemetry:public-metrics',
			static fn(): array => (new InstallationRepository($db))->publicMetrics(),
			300
		);
		if (!is_array($publicMetrics))
			$publicMetrics = array();
	}
	catch (Throwable $exception)
	{
		error_log('Public telemetry metrics could not be loaded: ' . $exception->getMessage());
	}
}
if (!$publicMetrics)
{
	$storedMetrics = json_decode((string)($_cfg['Telemetry_PublicMetrics'] ?? ''), true);
	if (is_array($storedMetrics))
		$publicMetrics = $storedMetrics;
}

$processed = max(0.0, (float)($publicMetrics['processed'] ?? 0));
$processedDecimals = abs($processed - round($processed)) < 0.000001 ? 0 : 2;
View::setPage('public_metrics', array(
	'processed' => number_format($processed, $processedDecimals, '.', ','),
	'platforms' => number_format(max(0, (int)($publicMetrics['platforms'] ?? 0)), 0, '.', ','),
), 0);

View::showPage();

?>
