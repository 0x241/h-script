<?php
declare(strict_types=1);
namespace HScript\Payment;

use HScript\Database\Connection;
use HScript\Payment\Provider\PaymentProviderSchema;
use PDO;
use RuntimeException;

/** Optional observation metadata; never a financial settlement authority. */
final class CheckoutProgressSchema
{
    public static function ensure(Connection $db): void
    {
        $p=$db->link;
        $p->exec("CREATE TABLE IF NOT EXISTS PaymentConfirmations (pcOperationID int NOT NULL,pcEventHash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,pcCount bigint unsigned NOT NULL,pcUpdatedAt bigint unsigned NOT NULL,PRIMARY KEY(pcOperationID),CONSTRAINT fk_confirmation_operation FOREIGN KEY(pcOperationID) REFERENCES Opers(oID) ON DELETE CASCADE ON UPDATE RESTRICT) ENGINE=InnoDB");
        $columns=$p->query('SHOW FULL COLUMNS FROM PaymentConfirmations')->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
        $expected=['pcOperationID'=>'int','pcEventHash'=>'char(64)','pcCount'=>'bigint unsigned','pcUpdatedAt'=>'bigint unsigned'];
        if(array_keys($columns)!==array_keys($expected))throw new RuntimeException('Incompatible confirmation metadata');
        foreach($expected as $name=>$type){$c=$columns[$name];$actual=preg_replace('/^(int|bigint)\([0-9]+\)/','$1',strtolower($c['Type']));
            if($actual!==$type || $c['Null']!=='NO' || $c['Extra']!=='' || !in_array($c['Default'],[null,'NULL'],true) || ($name==='pcEventHash' && $c['Collation']!=='ascii_bin'))throw new RuntimeException('Incompatible confirmation metadata');}
        if(strtolower((string)$p->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='PaymentConfirmations'")->fetchColumn())!=='innodb')throw new RuntimeException('Confirmation metadata requires InnoDB');
        PaymentProviderSchema::unique($p,'PaymentConfirmations','PRIMARY',['pcOperationID'],false);
        $q=$p->query("SELECT k.COLUMN_NAME,k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.DELETE_RULE,r.UPDATE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND k.TABLE_NAME='PaymentConfirmations' AND k.CONSTRAINT_NAME='fk_confirmation_operation'")->fetchAll(PDO::FETCH_ASSOC);
        if(count($q)!==1 || $q[0]['COLUMN_NAME']!=='pcOperationID' || $q[0]['REFERENCED_TABLE_SCHEMA']!==$p->query('SELECT DATABASE()')->fetchColumn() || $q[0]['REFERENCED_TABLE_NAME']!=='Opers' || $q[0]['REFERENCED_COLUMN_NAME']!=='oID' || $q[0]['DELETE_RULE']!=='CASCADE' || $q[0]['UPDATE_RULE']!=='RESTRICT')throw new RuntimeException('Incompatible confirmation reference');
    }
}
