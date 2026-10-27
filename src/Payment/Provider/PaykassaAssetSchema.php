<?php

declare(strict_types=1);
namespace HScript\Payment\Provider;

use HScript\Database\Connection;
use HScript\Payment\PaykassaAssets;
use PDO;
use RuntimeException;

/** Explicit catalog expansion. Existing IDs, settings, balances and credentials are untouched. */
final class PaykassaAssetSchema
{
    public static function check(): string
    {
        $extra=array_diff(array_keys(PaykassaAssets::ASSETS),['PKB','PKE','PKL']);
        return str_replace(ProviderNetworkSchema::EXTRA,ProviderNetworkSchema::EXTRA.",'".implode("','",$extra)."'",ProviderNetworkSchema::CURRENCY_CHECK);
    }

    public static function ready(PDO $pdo): bool
    {
        if(str_contains((string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION),'MariaDB') && (int)$pdo->query('SELECT @@check_constraint_checks')->fetchColumn()!==1)
            throw new RuntimeException('Provider CHECK constraints must be enforced');
        try { PaymentProviderSchema::check($pdo,'Currs','chk_currency_provider',self::check(),false);return true; }
        catch(RuntimeException) { return CoinPaymentsNewSchema::ready($pdo); }
    }

    public static function ensure(Connection $db): void
    {
        $pdo=$db->link;
        if(self::ready($pdo))return;
        // Reject unknown/missing predecessors before any autocommitting DDL.
        PaymentProviderSchema::check($pdo,'Currs','chk_currency_provider',ProviderNetworkSchema::CURRENCY_CHECK,false);
        PaymentProviderSchema::unique($pdo,'Currs','uq_currency_provider_fiat',['cProviderID','cAssetKey'],false);
        $drop=str_contains((string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION),'MariaDB')?'DROP CONSTRAINT':'DROP CHECK';
        $pdo->exec('ALTER TABLE Currs '.$drop.' chk_currency_provider, ADD CONSTRAINT chk_currency_provider CHECK ('.self::check().')');
        if(!self::ready($pdo))throw new RuntimeException('PayKassa catalog guard unavailable');
    }
}
