<?php

declare(strict_types=1);
namespace HScript\Payment\Provider;

use HScript\Database\Connection;
use PDO;
use RuntimeException;

/** Expands the existing PAW store; existing migrations keep their v1 contract. */
final class ProviderConnectionSchema
{
    public const CIDS="'CCAB','CCAL','CCAD','CCAG','CCAH','AC','ACR','PKB','PKE','PKL','PKAU','PKAR','ST'";
    public const CURRENCY_CHECK="(cProviderID IS NULL AND cProviderSelected=0 AND cProviderKeyVersion IS NULL AND (cCID IS NULL OR cCID NOT IN ('PAW','ST'))) OR (cProviderID IS NOT NULL AND cCID IS NOT NULL AND cProviderSelected IN (0,1) AND ((cCID='PAW' AND cProviderKeyVersion IS NULL) OR (cCID IN (".self::CIDS.") AND cProviderKeyVersion IS NOT NULL AND cProviderKeyVersion>0)))";
    public static function ensure(Connection $database): void
    {
        $pdo=$database->link;
        if (str_contains((string)$pdo->query('SELECT VERSION()')->fetchColumn(),'MariaDB') && (int)$pdo->query('SELECT @@check_constraint_checks')->fetchColumn()!==1)
            throw new RuntimeException('Provider CHECK constraints must be enforced');
        PaymentProviderSchema::ensure($database,null);
        $columns=$pdo->query('SHOW FULL COLUMNS FROM Currs')->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
        if (!isset($columns['cProviderKeyVersion'])) $pdo->exec('ALTER TABLE Currs ADD COLUMN cProviderKeyVersion int unsigned NULL DEFAULT NULL');
        $c=$pdo->query("SHOW FULL COLUMNS FROM Currs WHERE Field='cProviderKeyVersion'")->fetch(PDO::FETCH_ASSOC);
        if (!$c || preg_replace('/int\([0-9]+\)/','int',$c['Type'])!=='int unsigned' || $c['Null']!=='YES'
            || !in_array($c['Default'],[null,'NULL'],true) || $c['Extra']!=='') throw new RuntimeException('Incompatible provider key reference');
        $checks=[
            ['PaymentProviders','chk_provider_kind',"ppKind='PAW'","ppKind IN ('PAW','CCAPI','VOLET','PAYKASSA','STRIPE')"],
            ['PaymentProviders','chk_provider_slot',"(ppState='retired' AND ppSlot IS NULL) OR (ppState<>'retired' AND ppSlot IS NOT NULL AND ppSlot='PAW')","(ppState='retired' AND ppSlot IS NULL) OR (ppState<>'retired' AND ppSlot IS NOT NULL AND ppSlot=ppKind)"],
            ['Currs','chk_currency_provider',"(cProviderID IS NULL AND cProviderSelected=0 AND (cCID IS NULL OR cCID<>'PAW')) OR (cProviderID IS NOT NULL AND cCID IS NOT NULL AND cCID='PAW' AND cProviderSelected IN (0,1))",self::CURRENCY_CHECK],
            ['Currs','chk_paw_configuration_only',"cProviderID IS NULL OR (cDisabled IS NOT NULL AND cDisabled=1 AND cCASHINMode IS NOT NULL AND cCASHINMode=0 AND cCASHOUTMode IS NOT NULL AND cCASHOUTMode=0)","cCID<>'PAW' OR (cDisabled IS NOT NULL AND cDisabled=1 AND cCASHINMode IS NOT NULL AND cCASHINMode=0 AND cCASHOUTMode IS NOT NULL AND cCASHOUTMode=0)"],
        ];
        // Validate every mutable predecessor before dropping any constraint.
        foreach ($checks as [$table,$name,$old,$new]) {
            $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?');$q->execute([$table,$name]);
            if ((int)$q->fetchColumn()===0) continue; // interrupted DROP is resumable
            try { PaymentProviderSchema::check($pdo,$table,$name,$new,false); }
            catch (RuntimeException) { PaymentProviderSchema::check($pdo,$table,$name,$old,false); }
        }
        foreach ($checks as [$table,$name,$old,$new]) self::replace($pdo,$table,$name,$old,$new);
        PaymentProviderSchema::foreign($pdo,'Currs','fk_currency_provider_key',['cProviderID','cProviderKeyVersion'],
            'PaymentProviderCredentials',['ppcProviderID','ppcVersion'],true);
        PaymentProviderSchema::ensure($database,true);
    }
    private static function replace(PDO $pdo,string $table,string $name,string $old,string $new): void
    {
        $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?');
        $q->execute([$table,$name]);
        if ((int)$q->fetchColumn()===0) {
            PaymentProviderSchema::check($pdo,$table,$name,$new,true);return;
        }
        try { PaymentProviderSchema::check($pdo,$table,$name,$new,false);return; }
        catch (RuntimeException $e) {
            // Only our exact predecessor can be replaced. Unknown constraints fail closed.
            PaymentProviderSchema::check($pdo,$table,$name,$old,false);
        }
        $drop=str_contains((string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION),'MariaDB') ? 'DROP CONSTRAINT' : 'DROP CHECK';
        $pdo->exec("ALTER TABLE $table $drop $name");
        PaymentProviderSchema::check($pdo,$table,$name,$new,true);
    }
}
