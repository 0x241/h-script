<?php

use HScript\Security\IntegrityNotifier;
use HScript\Security\IntegrityScanner;
use HScript\Security\IntegrityStateRepository;

$integrityRoot = dirname(__DIR__, 2);
try
{
	$integrityStates = new IntegrityStateRepository($integrityRoot);
	$integrityState = $integrityStates->get();
	if (is_array($integrityState))
	{
		if (IntegrityNotifier::notifyIfNeeded($integrityState, $_cfg, $integrityStates))
			$integrityState = $integrityStates->get();
	}

	$integrityScanner = new IntegrityScanner($integrityRoot);
	$integrityRan = false;
	if (($integrityState['status'] ?? '') === 'running')
	{
		$integrityState = $integrityScanner->advance();
		$integrityRan = true;
	}
	elseif ($integrityState === null || !$integrityScanner->matchesBaseline($integrityState) || (int)($integrityState['completed_at'] ?? 0) <= time() - 86400)
	{
		$integrityState = $integrityScanner->start();
		$integrityRan = true;
	}

	if (is_array($integrityState))
	{
		if ($integrityRan) IntegrityNotifier::notifyIfNeeded($integrityState, $_cfg, $integrityStates);
		if (($integrityState['status'] ?? '') === 'running')
			opWriteCfg('Cron', 'integrity', timeToStamp(time() + 5 * HS2_UNIX_MINUTE));
	}
}
catch (Throwable $exception)
{
	error_log('Scheduled integrity scan failed: ' . $exception->getMessage());
}

?>
