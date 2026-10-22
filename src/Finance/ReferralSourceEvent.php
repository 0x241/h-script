<?php

namespace HScript\Finance;

use PDO;
use LogicException;

/** First issuance belongs to the real financial source transition, never a replay of old history. */
final class ReferralSourceEvent
{
    private static array $contexts = [];

    public static function dispatch(PDO $pdo, string $event, int $uid, array $params, callable $dispatch): mixed
    {
        if (!$pdo->inTransaction()) throw new LogicException('Referral source requires its financial transaction');
        $context = self::context($event, $uid, $params);
        self::$contexts[] = [$pdo, $context];
        try { return $dispatch(); }
        finally { array_pop(self::$contexts); }
    }

    public static function owns(PDO $pdo, string $event, int $uid, array $params): bool
    {
        if (!$pdo->inTransaction() || !self::$contexts) return false;
        [$owner, $context] = self::$contexts[array_key_last(self::$contexts)];
        return $owner === $pdo && $context === self::context($event, $uid, $params);
    }

    private static function context(string $event, int $uid, array $params): array
    {
        if ($event === 'Oper' && !empty($params['cid2']) && isset($params['sum2'])) {
            $params['cid'] = $params['cid2'];
            $params['sum'] = $params['sum2'];
        }
        return [$event, $uid, (int)($params[$event === 'Oper' ? 'oid' : 'did'] ?? 0),
            $event === 'DepoCharge' ? (int)($params['nper'] ?? 0) : 0,
            (int)($params['cid'] ?? 0), RequestDecimal::normalize($params['sum'], true)];
    }
}
