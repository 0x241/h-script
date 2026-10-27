<?php
declare(strict_types=1);
namespace HScript\Payment\Provider;

use HScript\Database\Connection;
use PDO;
use RuntimeException;

/** Explicit backup-protected migration and fresh installation only. */
final class CurrencyArchiveSchema
{
    public const MODES=['CASHIN','CASHOUT','EX','TR','BUY','SELL','BUY2','SELL2','GIVE','TAKE'];
    public static function guard(): string
    {
        return 'cArchivedAt IS NULL OR (cArchivedAt>0 AND cCID<>\'*\' AND cDisabled IS NOT NULL AND cDisabled=1 AND cHidden IS NOT NULL AND cHidden=1 AND cProviderSelected=0 AND '.implode(' AND ',array_map(static fn($mode)=>'c'.$mode.'Mode IS NOT NULL AND c'.$mode.'Mode=0',self::MODES)).')';
    }
    private static function column(PDO $pdo): bool
    {
        $column=$pdo->query("SHOW COLUMNS FROM Currs LIKE 'cArchivedAt'")->fetch(PDO::FETCH_ASSOC);
        if(!$column)return false;
        $type=preg_replace('/bigint\([0-9]+\)/','bigint',strtolower($column['Type']));
        if($type!=='bigint unsigned'||$column['Null']!=='YES'||$column['Default']!==null||$column['Extra']!=='')throw new RuntimeException('Incompatible currency archive column');
        return true;
    }
    public static function ready(PDO $pdo): bool
    {
        if(!self::column($pdo))return false;
        PaymentProviderSchema::check($pdo,'Currs','chk_currency_archived',self::guard(),false);return true;
    }
    public static function ensure(Connection $db): void
    {
        $pdo=$db->link;
        if(str_contains((string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION),'MariaDB')&&(int)$pdo->query('SELECT @@check_constraint_checks')->fetchColumn()!==1)throw new RuntimeException('Provider CHECK constraints must be enforced');
        foreach(['Currs','PaymentProviders']as$table){$q=$pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);if(strtolower((string)$q->fetchColumn())!=='innodb')throw new RuntimeException('Incompatible currency archive engine');}
        $column=self::column($pdo);
        $present=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='Currs' AND CONSTRAINT_NAME='chk_currency_archived'")->fetchColumn()>0;
        if($present){if(!$column)throw new RuntimeException('Incompatible currency archive guard');PaymentProviderSchema::check($pdo,'Currs','chk_currency_archived',self::guard(),false);}
        if(!$column)$pdo->exec('ALTER TABLE Currs ADD COLUMN cArchivedAt bigint unsigned NULL DEFAULT NULL, ADD CONSTRAINT chk_currency_archived CHECK ('.self::guard().')');
        elseif(!$present)PaymentProviderSchema::check($pdo,'Currs','chk_currency_archived',self::guard(),true);
        if(!self::ready($pdo))throw new RuntimeException('Currency archive migration unavailable');
        // Historical disabled/unselected rows remain unclassified. Only an
        // explicit guarded administrator action may archive an existing row.
    }
}
