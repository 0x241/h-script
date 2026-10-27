<?php
declare(strict_types=1);
namespace HScript\Payment\Provider;

use HScript\Database\Connection;
use PDO;
use RuntimeException;

/** Configuration/catalog phase. Financial modes need a separate reviewed migration. */
final class CoinPaymentsNewSchema
{
    public const KINDS="ppKind IN ('PAW','CCAPI','VOLET','PAYKASSA','STRIPE','COINNEW')";
    public const MODES=['cCASHINMode','cCASHOUTMode','cEXMode','cTRMode','cBUYMode','cSELLMode','cBUY2Mode','cSELL2Mode','cGIVEMode','cTAKEMode'];
    public static function currencyCheck(): string { return str_replace("'ST'))","'ST','CPN'))",str_replace("'PKTT'","'PKTT','CPN'",PaykassaAssetSchema::check())); }
    public static function guard(): string
    {
        return "cCID<>'CPN' OR (cProviderID IS NOT NULL AND cAssetKey IS NOT NULL AND cTokenRoute IS NOT NULL AND cDisabled IS NOT NULL AND cDisabled=1 AND cHidden IS NOT NULL AND cHidden=1 AND ".implode(' AND ',array_map(static fn($m)=>$m.' IS NOT NULL AND '.$m.'=0',self::MODES)).')';
    }
    public static function ready(PDO $pdo): bool
    {
        if(str_contains((string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION),'MariaDB')&&(int)$pdo->query('SELECT @@check_constraint_checks')->fetchColumn()!==1)throw new RuntimeException('Provider CHECK constraints must be enforced');
        try {
            self::keys($pdo);
            PaymentProviderSchema::check($pdo,'PaymentProviders','chk_provider_kind',self::KINDS,false);
            PaymentProviderSchema::check($pdo,'Currs','chk_currency_provider',self::currencyCheck(),false);
            PaymentProviderSchema::check($pdo,'Currs','chk_coinnew_configuration_only',self::guard(),false);
            return true;
        }catch(RuntimeException){return false;}
    }
    public static function ensure(Connection $database): void
    {
        $pdo=$database->link;
        if(str_contains((string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION),'MariaDB')&&(int)$pdo->query('SELECT @@check_constraint_checks')->fetchColumn()!==1)throw new RuntimeException('Provider CHECK constraints must be enforced');
        foreach(['Currs','PaymentProviders','PaymentProviderCredentials','PaymentGatewayCatalog']as$table){
            $q=$pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);
            if(strtolower((string)$q->fetchColumn())!=='innodb')throw new RuntimeException('Incompatible provider engine');
        }
        ProviderCatalogSchema::verify($database);
        $columns=$pdo->query('SHOW FULL COLUMNS FROM Currs')->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
        foreach(['cAssetKey'=>'varchar(128)','cTokenRoute'=>'text','cCurrID'=>'varchar(32)','cProviderID'=>'int unsigned','cProviderKeyVersion'=>'int unsigned']as$name=>$type){
            $column=$columns[$name]??[];$actual=preg_replace('/^int\([0-9]+\)/','int',strtolower($column['Type']??''));
            if($actual!==$type||($column['Null']??null)!=='YES'||($column['Extra']??null)!==''||($name==='cAssetKey'&&($column['Collation']??null)!=='ascii_bin'))throw new RuntimeException('Incompatible CoinPayments currency column: '.$name);
        }
        self::keys($pdo);
        $changes=[['PaymentProviders','chk_provider_kind',"ppKind IN ('PAW','CCAPI','VOLET','PAYKASSA','STRIPE')",self::KINDS],['Currs','chk_currency_provider',PaykassaAssetSchema::check(),self::currencyCheck()]];
        $replace=[];
        foreach($changes as[$table,$name,$old,$new]){
            $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?');$q->execute([$table,$name]);
            if((int)$q->fetchColumn()===0)throw new RuntimeException('Required provider predecessor is missing: '.$name);
            try{PaymentProviderSchema::check($pdo,$table,$name,$new,false);}catch(RuntimeException){PaymentProviderSchema::check($pdo,$table,$name,$old,false);$replace[]=[$table,$name,$new];}
        }
        $guardPresent=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='Currs' AND CONSTRAINT_NAME='chk_coinnew_configuration_only'")->fetchColumn()>0;
        if($guardPresent)PaymentProviderSchema::check($pdo,'Currs','chk_coinnew_configuration_only',self::guard(),false);
        else if((int)$pdo->query("SELECT COUNT(*) FROM Currs WHERE cCID='CPN'")->fetchColumn()>0)throw new RuntimeException('Unprotected CoinPayments currency requires reconciliation');
        foreach($pdo->query("SELECT Currs.*,ppKind,ppEnvironment FROM Currs LEFT JOIN PaymentProviders ON ppID=cProviderID WHERE cCID='CPN'")->fetchAll(PDO::FETCH_ASSOC)as$currency){
            $asset=CoinPaymentsNewService::asset($currency);
            if($currency['ppKind']!=='COINNEW'||$currency['ppEnvironment']!==($asset['instance']==='SANDBOX'?'sandbox':'live'))throw new RuntimeException('CoinPayments profile identity requires reconciliation');
        }
        // Install the financial prohibition first. Each existing CHECK is then
        // replaced in one ALTER, so interruption cannot leave a dropped guard.
        PaymentProviderSchema::check($pdo,'Currs','chk_coinnew_configuration_only',self::guard(),true);
        $drop=str_contains((string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION),'MariaDB')?'DROP CONSTRAINT':'DROP CHECK';
        foreach($replace as[$table,$name,$new])$pdo->exec("ALTER TABLE $table $drop $name, ADD CONSTRAINT $name CHECK ($new)");
        if(!self::ready($pdo))throw new RuntimeException('CoinPayments NEW configuration guard unavailable');
        $q=$pdo->prepare("INSERT INTO PaymentGatewayCatalog(pgCode,pgAvailable) VALUES('COINNEW',1) ON DUPLICATE KEY UPDATE pgCode=VALUES(pgCode)");$q->execute();
    }

    private static function keys(PDO $pdo): void
    {
        foreach([
            ['Currs','PRIMARY',['cID']],['Currs','uq_currency_provider_fiat',['cProviderID','cAssetKey']],
            ['PaymentProviders','PRIMARY',['ppID']],['PaymentProviders','uq_provider_slot',['ppSlot']],
            ['PaymentProviders','uq_provider_public',['ppPublicID']],['PaymentProviders','uq_provider_environment',['ppID','ppEnvironment']],
            ['PaymentProviderCredentials','PRIMARY',['ppcProviderID','ppcVersion']]
        ]as[$table,$name,$fields])PaymentProviderSchema::unique($pdo,$table,$name,$fields,false);
        PaymentProviderSchema::foreign($pdo,'Currs','fk_currency_provider',['cProviderID'],'PaymentProviders',['ppID'],false);
        PaymentProviderSchema::foreign($pdo,'Currs','fk_currency_provider_key',['cProviderID','cProviderKeyVersion'],'PaymentProviderCredentials',['ppcProviderID','ppcVersion'],false);
        PaymentProviderSchema::foreign($pdo,'PaymentProviderCredentials','fk_credential_provider',['ppcProviderID','ppcEnvironment'],'PaymentProviders',['ppID','ppEnvironment'],false);
    }
}
