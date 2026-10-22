<?php

declare(strict_types=1);
namespace HScript\Payment\Routing;
use HScript\Database\Connection;
use HScript\Payment\Provider\{PaymentProviderSchema,ProviderNetworkSchema};
use PDO;
use RuntimeException;

/** Additive catalog/asset identity migration. Old currency and receipt IDs never change. */
final class TokenCatalogSchema
{
    public static function ensure(Connection $db): void
    {
        $p=$db->link;
        if(strtolower((string)$p->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Currs'")->fetchColumn())!=='innodb')throw new RuntimeException('Token assets require InnoDB');
        $columns=$p->query('SHOW FULL COLUMNS FROM Currs')->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
        foreach(['cAssetKey'=>'varchar(128) CHARACTER SET ascii COLLATE ascii_bin NULL','cTokenRoute'=>'text NULL'] as $name=>$ddl){
            if(!isset($columns[$name]))$p->exec('ALTER TABLE Currs ADD COLUMN '.$name.' '.$ddl);
            elseif(strtolower($columns[$name]['Type'])!==($name==='cAssetKey'?'varchar(128)':'text') || $columns[$name]['Null']!=='YES' || $columns[$name]['Extra']!=='' || !in_array($columns[$name]['Default'],[null,'NULL'],true) || ($name==='cAssetKey' && $columns[$name]['Collation']!=='ascii_bin'))throw new RuntimeException('Incompatible token asset column');
        }
        if(!in_array(strtolower($columns['cCurrID']['Type']),['varchar(10)','varchar(32)'],true) || $columns['cCurrID']['Null']!=='YES')throw new RuntimeException('Incompatible currency symbol');
        if(strtolower($columns['cCurrID']['Type'])==='varchar(10)')$p->exec("ALTER TABLE Currs MODIFY cCurrID varchar(32) DEFAULT ''");
        $p->exec("UPDATE Currs SET cAssetKey=CONCAT('asset:',cCurrID) WHERE cProviderID IS NOT NULL AND cAssetKey IS NULL AND cCID<>'CCT'");
        $indexes=array_values(array_filter($p->query('SHOW INDEX FROM Currs')->fetchAll(PDO::FETCH_ASSOC),static fn($i)=>$i['Key_name']==='uq_currency_provider_fiat'));
        usort($indexes,static fn($a,$b)=>$a['Seq_in_index']<=>$b['Seq_in_index']);
        if(array_column($indexes,'Column_name')===['cProviderID','cCurrID']){
            PaymentProviderSchema::unique($p,'Currs','uq_currency_provider_fiat',['cProviderID','cCurrID'],false);
            $p->exec('ALTER TABLE Currs DROP INDEX uq_currency_provider_fiat, ADD UNIQUE KEY uq_currency_provider_fiat(cProviderID,cAssetKey)');
        }else PaymentProviderSchema::unique($p,'Currs','uq_currency_provider_fiat',['cProviderID','cAssetKey'],true);
        $p->exec("CREATE TABLE IF NOT EXISTS ProviderTokenCatalogs (ptProviderID int unsigned NOT NULL,ptNetwork varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,ptKeyVersion int unsigned NOT NULL,ptRevision bigint unsigned NOT NULL DEFAULT 0,ptUpdatedAt bigint unsigned NOT NULL DEFAULT 0,ptAttemptedAt bigint unsigned NOT NULL DEFAULT 0,ptTokens mediumtext NOT NULL,PRIMARY KEY(ptProviderID,ptNetwork),CONSTRAINT fk_token_catalog_provider FOREIGN KEY(ptProviderID) REFERENCES PaymentProviders(ppID) ON DELETE RESTRICT ON UPDATE RESTRICT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $p->exec("CREATE TABLE IF NOT EXISTS ProviderNetworkWallets (pnProviderID int unsigned NOT NULL,pnNetwork varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,pnForwardTo varchar(128) NOT NULL DEFAULT '',pnPayoutFrom varchar(128) NOT NULL DEFAULT '',PRIMARY KEY(pnProviderID,pnNetwork),CONSTRAINT fk_network_wallet_provider FOREIGN KEY(pnProviderID) REFERENCES PaymentProviders(ppID) ON DELETE RESTRICT ON UPDATE RESTRICT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach(['ProviderTokenCatalogs'=>['ptProviderID','ptNetwork','ptKeyVersion','ptRevision','ptUpdatedAt','ptAttemptedAt','ptTokens'],'ProviderNetworkWallets'=>['pnProviderID','pnNetwork','pnForwardTo','pnPayoutFrom']] as $table=>$expected){
            $fields=$p->query('SHOW FULL COLUMNS FROM '.$table)->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);$actual=array_keys($fields);
            foreach($fields as $name=>$field){
                $type=preg_replace('/^(bigint|int)\([0-9]+\)/','$1',strtolower($field['Type']));
                $want=str_ends_with($name,'ProviderID') || $name==='ptKeyVersion'?'int unsigned':(in_array($name,['ptRevision','ptUpdatedAt','ptAttemptedAt'],true)?'bigint unsigned':($name==='ptTokens'?'mediumtext':(str_ends_with($name,'Network')?'varchar(16)':'varchar(128)')));
                if($type!==$want || $field['Null']!=='NO' || $field['Extra']!=='' || (str_ends_with($name,'Network') && $field['Collation']!=='ascii_bin'))throw new RuntimeException('Incompatible catalog column');
            }
            if($actual!==$expected || strtolower((string)$p->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=".$p->quote($table))->fetchColumn())!=='innodb')throw new RuntimeException('Incompatible token configuration table');
            PaymentProviderSchema::unique($p,$table,'PRIMARY',array_slice($expected,0,2),false);
            PaymentProviderSchema::foreign($p,$table,$table==='ProviderTokenCatalogs'?'fk_token_catalog_provider':'fk_network_wallet_provider',[$expected[0]],'PaymentProviders',['ppID'],false);
        }
        ProviderNetworkSchema::ensure($db);
    }
}
