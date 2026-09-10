<?php

namespace HScript\Security;

use HScript\Mail\Mailer;

final class IntegrityNotifier
{
	public static function notifyIfNeeded(array $state, array $config, IntegrityStateRepository $states): bool
	{
		$enabled = in_array(strtolower(trim((string)(getenv('INTEGRITY_NOTIFY') ?: '0'))), array('1', 'true', 'yes', 'on'), true);
		$signature = (string)($state['alert_signature'] ?? '');
		if (!$enabled || ($state['status'] ?? '') !== 'completed' || empty($state['new_alert']) || $signature === ''
			|| hash_equals((string)($state['notified_signature'] ?? ''), $signature))
			return false;
		$recipient = trim((string)($config['Sys_AdminMail'] ?? $config['sys_mail'] ?? ''));
		if ($recipient === '') return false;
		$critical = (int)($state['counts']['critical'] ?? 0);
		$sent = Mailer::sendNow(
			$recipient,
			'H-Script: file integrity alert',
			'The local integrity scan found ' . $critical . ' critical file change(s). Open the authenticated Configurator security page for details.'
		);
		if ($sent) $states->markNotified($signature);
		return $sent;
	}
}
