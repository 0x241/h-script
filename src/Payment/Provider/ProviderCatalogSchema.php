<?php

declare(strict_types=1);
namespace HScript\Payment\Provider;

use HScript\Database\Connection;
use PDO;
use RuntimeException;

/** Explicit updater/initial installer only; never create or seed on a web GET. */
final class ProviderCatalogSchema
{
    public const OBSOLETE=['BNBAPI','ETHERAPI','XRPAPI','TRONAPI','EPAYCORE'];
    public static function purgeRetired(Connection $database): void
    {
        $s=$database->link->prepare('DELETE FROM PaymentGatewayCatalog WHERE pgCode IN (?,?,?,?,?)');
        $s->execute(self::OBSOLETE);
    }
    public const SQL="pgCode varchar(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        pgAvailable tinyint unsigned NOT NULL DEFAULT 1,
        PRIMARY KEY(pgCode), CONSTRAINT chk_gateway_available CHECK(pgAvailable IN (0,1))";
    public static function ensure(Connection $database): void
    {
        $pdo=$database->link;
        if (str_contains((string)$pdo->query('SELECT VERSION()')->fetchColumn(),'MariaDB') && (int)$pdo->query('SELECT @@check_constraint_checks')->fetchColumn()!==1)
            throw new RuntimeException('Provider CHECK constraints must be enforced');
        $pdo->exec('CREATE TABLE IF NOT EXISTS PaymentGatewayCatalog ('.self::SQL.') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        self::verify($database);
        $s=$pdo->prepare('INSERT INTO PaymentGatewayCatalog(pgCode,pgAvailable) VALUES(?,1) ON DUPLICATE KEY UPDATE pgCode=VALUES(pgCode)');
        foreach (array_keys(ProviderCatalog::GROUPS) as $kind) $s->execute([$kind]);
        self::purgeRetired($database);
        // Retire the closed provider without destroying wallet/operation references.
        $pdo->exec("UPDATE Currs SET cDisabled=1,cHidden=1,cCASHINMode=0,cCASHOUTMode=0,cEXMode=0,cTRMode=0,
            cBUYMode=0,cSELLMode=0,cBUY2Mode=0,cSELL2Mode=0,cGIVEMode=0,cTAKEMode=0,
            cParams='',cParamsSCI='',cParamsAPI='' WHERE cCID IN ('EPCU','EPCB','EPCT')");
    }
    /** Structural validation only; safe before an updater performs any DDL. */
    public static function verify(Connection $database): void
    {
        $pdo=$database->link;
        if (str_contains((string)$pdo->query('SELECT VERSION()')->fetchColumn(),'MariaDB') && (int)$pdo->query('SELECT @@check_constraint_checks')->fetchColumn()!==1)
            throw new RuntimeException('Provider CHECK constraints must be enforced');
        // Verify before seeding or retiring anything in an existing installation.
        $columns=$pdo->query('SHOW FULL COLUMNS FROM PaymentGatewayCatalog')->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
        if (count($columns)!==2) throw new RuntimeException('Incompatible payment gateway catalog');
        foreach (['pgCode'=>['varchar(8)','NO','ascii_bin',null],'pgAvailable'=>['tinyint unsigned','NO',null,'1']] as $name=>[$type,$null,$collation,$default]) {
            $c=$columns[$name]??[];
            $actualType=preg_replace('/tinyint\([0-9]+\)/','tinyint',$c['Type']??'');
            if ($actualType!==$type || ($c['Null']??'')!==$null || ($c['Collation']??null)!==$collation
                || ($c['Extra']??null)!=='' || (($c['Default']??null)===null?null:(string)$c['Default'])!==$default)
                throw new RuntimeException('Incompatible payment gateway catalog');
        }
        $engine=$pdo->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='PaymentGatewayCatalog'")->fetchColumn();
        if ($engine!=='InnoDB') throw new RuntimeException('Incompatible payment gateway catalog');
        PaymentProviderSchema::unique($pdo,'PaymentGatewayCatalog','PRIMARY',['pgCode'],false);
        PaymentProviderSchema::check($pdo,'PaymentGatewayCatalog','chk_gateway_available','pgAvailable IN (0,1)',false);
    }

}
