<?php

declare(strict_types=1);

namespace HScript\Finance;

use Brick\Math\RoundingMode;
use InvalidArgumentException;
use HScript\Database\Connection;
use HScript\Database\TransactionScope;
use RuntimeException;

/** Collects publishable decimal quotes; invalid fiat groups produce no writes. */
final class RateUpdater
{
    public function __construct(
        private readonly CoinbaseRateProvider $crypto = new CoinbaseRateProvider(),
        private readonly CentralBankRateProvider $fiat = new CentralBankRateProvider(),
    ) {}

    /** Publish only fully validated groups and advance the stamp on success. */
    public function refresh(array $config, array $currencies, Connection $db, string $stamp): bool
    {
        // Network I/O must finish before any database lock is acquired.
        $quotes = $this->quotes($config, $currencies);
        if (!$quotes) return false;
        return TransactionScope::run($db->link, static function () use ($db, $quotes, $stamp): bool {
            $values = [];
            foreach ($quotes as $code => $value) $values['Rate' . $code] = $value;
            $values['LastUpdate'] = $stamp;
            foreach ($values as $property => $value) {
                if (!$db->replace('Cfg', ['Module' => 'Bal', 'Prop' => $property, 'Val' => $value]))
                    throw new RuntimeException('rate_update_failed');
            }
            return true;
        });
    }

    /** @return array<string, string> Base-currency value per currency unit. */
    public function quotes(array $config, array $currencies): array
    {
        $row = $currencies[1] ?? ($currencies ? reset($currencies) : []);
        $base = is_array($row) ? strtoupper(trim((string)($row['cCurrID'] ?? ''))) : '';
        if (!in_array($base, ['USD', 'EUR', 'RUB'], true)) $base = 'USD';
        $quotes = [];
        foreach (['BTC', 'ETH', 'XRP', 'LTC'] as $code) {
            if (empty($config["Bal_Update{$code}Rate"])) continue;
            $value = $this->crypto->rate($code, $base);
            if ($value !== null) $quotes[$code] = $value;
        }
        if (empty($config['Bal_UpdateRates'])) return $quotes;
        $rates = $this->fiat->rates(['USD', 'EUR']);
        if ($rates === null) return $quotes;
        try {
            $usd = Rate::of($rates['USD']);
            $eur = Rate::of($rates['EUR']);
            $one = Decimal::of(1);
            // Preserve the historical acceptance rule for the full fiat group.
            if ($usd->decimal()->compareTo($one) < 0 || $eur->decimal()->compareTo($one) < 0) return $quotes;
            $fiat = match ($base) {
                'RUB' => ['USD' => $rates['USD'], 'EUR' => $rates['EUR'], 'RUB' => '1'],
                'EUR' => ['USD' => (string)$eur->divide($usd->decimal(), 4, RoundingMode::HalfUp),
                    'EUR' => '1', 'RUB' => (string)$eur->divide($one, 4, RoundingMode::HalfUp)],
                default => ['USD' => '1', 'EUR' => (string)$usd->divide($eur->decimal(), 4, RoundingMode::HalfUp),
                    'RUB' => (string)$usd->divide($one, 4, RoundingMode::HalfUp)],
            };
            // A reciprocal rounded to zero invalidates the entire fiat group.
            foreach ($fiat as $value) Rate::of($value);
            return $quotes + $fiat;
        } catch (InvalidArgumentException) { return $quotes; }
    }
}
