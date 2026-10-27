<?php

declare(strict_types=1);
namespace HScript\Payment\Provider;

use HScript\Database\Connection;
use PDO;
use RuntimeException;

/** Versioned capability upgrade; existing currencies remain disabled for payouts. */
final class PawPayoutSchema
{
    public const CHECK = "cCID<>'PAW' OR (cCASHINMode IS NOT NULL AND cCASHINMode IN (0,2) AND cCASHOUTMode IS NOT NULL AND cCASHOUTMode IN (0,1,2) AND cCurrID IS NOT NULL AND cAssetKey IS NOT NULL AND HEX(cAssetKey)=HEX(CONCAT('asset:',cCurrID)))";

    public static function ready(PDO $pdo, bool $outgoing = false): bool
    {
        if (str_contains((string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION), 'MariaDB')
            && (int)$pdo->query('SELECT @@check_constraint_checks')->fetchColumn() !== 1)
            throw new RuntimeException('PAW CHECK constraints must be enforced');
        $names = $pdo->query("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='Currs' AND CONSTRAINT_NAME IN ('chk_paw_inbound_only','chk_paw_native')")->fetchAll(PDO::FETCH_COLUMN);
        $native = in_array('chk_paw_native', $names, true);
        $inbound = in_array('chk_paw_inbound_only', $names, true);
        if ($native) PaymentProviderSchema::check($pdo, 'Currs', 'chk_paw_native', self::CHECK, false);
        if ($inbound) PaymentProviderSchema::check($pdo, 'Currs', 'chk_paw_inbound_only', PawNativeSchema::CHECK, false);
        // Until the predecessor is removed it still prohibits outgoing modes.
        return $outgoing ? $native && !$inbound : $native || $inbound;
    }

    public static function ensure(Connection $db): void
    {
        $pdo = $db->link;
        if (!self::ready($pdo)) throw new RuntimeException('PAW native predecessor is required');
        PaymentProviderSchema::unique($pdo, 'Currs', 'uq_currency_provider_fiat', ['cProviderID','cAssetKey'], false);
        if ((int)$pdo->query("SELECT COUNT(*) FROM Currs LEFT JOIN PaymentProviders ON ppID=cProviderID WHERE cCID='PAW' AND (cProviderID IS NULL OR ppKind IS NULL OR ppKind<>'PAW' OR cProviderKeyVersion IS NOT NULL OR NOT (".self::CHECK."))")->fetchColumn() > 0)
            throw new RuntimeException('PAW identity requires reconciliation');
        foreach($pdo->query("SELECT cCurrID FROM Currs WHERE cCID='PAW'")->fetchAll(PDO::FETCH_COLUMN) as $fiat)
            if(!in_array($fiat,PawProviderService::FIATS,true))throw new RuntimeException('PAW fiat identity requires reconciliation');
        PaymentProviderSchema::check($pdo, 'Currs', 'chk_paw_native', self::CHECK, true);
        $old = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='Currs' AND CONSTRAINT_NAME='chk_paw_inbound_only'")->fetchColumn();
        if ($old) $pdo->exec('ALTER TABLE Currs '.(str_contains((string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION), 'MariaDB') ? 'DROP CONSTRAINT' : 'DROP CHECK').' chk_paw_inbound_only');
        if (!self::ready($pdo, true)) throw new RuntimeException('PAW outgoing guard unavailable');
    }
}
