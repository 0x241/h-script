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
$platformCount = max(0, (int)($publicMetrics['platforms'] ?? 0));
$gatewayCount = 25;
$metricForm = static function (int $count): string
{
	$language = strtolower(View::getLang());
	if (str_starts_with($language, 'ru'))
	{
		$count = abs($count);
		$mod100 = $count % 100;
		$mod10 = $count % 10;
		if ($mod10 === 1 && $mod100 !== 11)
			return 'one';
		if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14))
			return 'few';
		return 'many';
	}
	return $count === 1 ? 'one' : 'many';
};
$metricLabel = static fn(string $key, int $count): string => View::_t($key . '.' . $metricForm($count));
View::setPage('public_metrics', array(
	'processed' => number_format($processed, $processedDecimals, '.', ','),
	'platforms' => number_format($platformCount, 0, '.', ','),
	'platforms_label' => $metricLabel('home.metric.platforms', $platformCount),
	'gateways' => number_format($gatewayCount, 0, '.', ',') . '+',
	'gateways_label' => $metricLabel('home.metric.gateways', $gatewayCount),
), 0);

View::showPage();

?>
