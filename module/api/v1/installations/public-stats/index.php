<?php

use HScript\Http\ApiResponse;

require dirname(__DIR__) . '/bootstrap.php';
telemetryApiRequireMethod(array('GET'));

if (!headers_sent())
	header('Cache-Control: public, max-age=300, stale-while-revalidate=60');

ApiResponse::success($cache->get(
	'telemetry:public-metrics',
	static fn(): array => $telemetryRepository->publicMetrics(),
	300
));
