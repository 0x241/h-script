<?php

declare(strict_types=1);
namespace HScript\Payment\Provider;

use HScript\Database\Connection;
use PDO;
use RuntimeException;

/** Explicit 1.0.6 -> 1.0.7 expansion; old gateway references never change. */
final class ProviderNetworkSchema
{
    public const LEGACY_EXTRA="'CCAE','CCAM','CCAN','CCAT','CCAX','CCAO','CCAS','VEUR','VUST','VUSC','VBTC','VETH','VSOL','VTRX','VXRP','VTON','VLTC'";
    public const EXTRA=self::LEGACY_EXTRA.",'CCTU','CCTC','PKTU','PKTC','CCT'";
    public const CURRENCY_CHECK="(cProviderID IS NULL AND cProviderSelected=0 AND cProviderKeyVersion IS NULL AND (cCID IS NULL OR cCID NOT IN ('PAW','ST'))) OR (cProviderID IS NOT NULL AND cCID IS NOT NULL AND cProviderSelected IN (0,1) AND ((cCID='PAW' AND cProviderKeyVersion IS NULL) OR (cCID IN (".ProviderConnectionSchema::CIDS.",".self::EXTRA.") AND cProviderKeyVersion IS NOT NULL AND cProviderKeyVersion>0)))";
    public const RETIRED="'EA','EAT','EAT1','BSC','BSCT','XRPA','TRA','TRAT'";

    public static function ensure(Connection $database): void
    {
        $pdo=$database->link;
        PaymentProviderSchema::ensure($database,null);
        $column=$pdo->query("SHOW FULL COLUMNS FROM Currs WHERE Field='cProviderKeyVersion'")->fetch(PDO::FETCH_ASSOC);
        if (!$column || preg_replace('/int\([0-9]+\)/','int',$column['Type'])!=='int unsigned' || $column['Null']!=='YES'
            || !in_array($column['Default'],[null,'NULL'],true) || $column['Extra']!=='') throw new RuntimeException('Incompatible provider key reference');
        PaymentProviderSchema::foreign($pdo,'Currs','fk_currency_provider_key',['cProviderID','cProviderKeyVersion'],'PaymentProviderCredentials',['ppcProviderID','ppcVersion'],false);
        foreach ([
            ['PaymentProviders','chk_provider_kind',"ppKind IN ('PAW','CCAPI','VOLET','PAYKASSA','STRIPE')"],
            ['PaymentProviders','chk_provider_slot',"(ppState='retired' AND ppSlot IS NULL) OR (ppState<>'retired' AND ppSlot IS NOT NULL AND ppSlot=ppKind)"],
            ['Currs','chk_paw_configuration_only',"cCID<>'PAW' OR (cDisabled IS NOT NULL AND cDisabled=1 AND cCASHINMode IS NOT NULL AND cCASHINMode=0 AND cCASHOUTMode IS NOT NULL AND cCASHOUTMode=0)"]
        ] as [$table,$name,$check]) PaymentProviderSchema::check($pdo,$table,$name,$check,false);
        $q=$pdo->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='Currs' AND CONSTRAINT_NAME='chk_currency_provider'");
        if ((int)$q->fetchColumn()>0) {
            try { PaymentProviderSchema::check($pdo,'Currs','chk_currency_provider',self::CURRENCY_CHECK,false); }
            catch (RuntimeException) {
                try { PaymentProviderSchema::check($pdo,'Currs','chk_currency_provider',str_replace(",'CCT'",'',self::CURRENCY_CHECK),false); }
                catch (RuntimeException) {
                try { PaymentProviderSchema::check($pdo,'Currs','chk_currency_provider',str_replace(self::EXTRA,self::LEGACY_EXTRA,self::CURRENCY_CHECK),false); }
                catch (RuntimeException) { PaymentProviderSchema::check($pdo,'Currs','chk_currency_provider',ProviderConnectionSchema::CURRENCY_CHECK,false); }
                }
                $drop=str_contains((string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION),'MariaDB')?'DROP CONSTRAINT':'DROP CHECK';
                $pdo->exec('ALTER TABLE Currs '.$drop.' chk_currency_provider');
            }
        }
        PaymentProviderSchema::check($pdo,'Currs','chk_currency_provider',self::CURRENCY_CHECK,true);
        PaymentProviderSchema::ensure($database,true,self::CURRENCY_CHECK);
        ProviderCatalogSchema::ensure($database);
        $pdo->exec("UPDATE Currs SET cDisabled=1,cHidden=1,cCASHINMode=0,cCASHOUTMode=0,cEXMode=0,cTRMode=0,cBUYMode=0,cSELLMode=0,cBUY2Mode=0,cSELL2Mode=0,cGIVEMode=0,cTAKEMode=0,cParams='',cParamsSCI='',cParamsAPI='' WHERE cCID IN (".self::RETIRED.")");
    }
}
