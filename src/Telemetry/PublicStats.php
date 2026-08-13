<?php

namespace HScript\Telemetry;

/**
 * Converts local deposit statistics into the public telemetry payload schema.
 */
final class PublicStats
{
	public static function fromDepositStats(array $stat, array $currencies): array
	{
		$baseCurrency = isset($currencies[1]['cCurrID'])
			? strtoupper((string)$currencies[1]['cCurrID'])
			: '';

		return array(
			'worked_days' => max(0, (int)($stat['worked'] ?? 0)),
			'users_total' => max(0, (int)($stat['users'] ?? 0)),
			'users_online' => max(0, (int)($stat['usersonline'] ?? 0)),
			'cash_in_by_currency' => self::amountMap($stat['zin2'] ?? array()),
			'cash_out_by_currency' => self::amountMap($stat['zout2'] ?? array()),
			'base_currency' => $baseCurrency,
			'cash_in_base' => max(0, (float)($stat['zin'] ?? 0)),
			'cash_out_base' => max(0, (float)($stat['zout'] ?? 0)),
			'referral_paid_base' => max(0, (float)($stat['zref'] ?? 0)),
			'reinvested_base' => max(0, (float)($stat['zreinv'] ?? 0)),
			'active_deposits' => max(0, (int)($stat['deps'] ?? 0)),
			'closed_deposits' => max(0, (int)($stat['depsclosed'] ?? 0)),
		);
	}

	private static function amountMap(array $values): array
	{
		$result = array();
		foreach ($values as $currency => $amount)
		{
			$currency = strtoupper(trim((string)$currency));
			if (preg_match('/^[A-Z0-9]{2,10}$/', $currency) && is_numeric($amount))
				$result[$currency] = max(0, (float)$amount);
		}
		ksort($result);
		return $result;
	}
}
