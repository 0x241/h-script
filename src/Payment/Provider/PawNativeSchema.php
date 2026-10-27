<?php

declare(strict_types=1);
namespace HScript\Payment\Provider;

use HScript\Database\Connection;
use RuntimeException;

/** Explicit backup-required change; never enables an existing currency. */
final class PawNativeSchema
{
    public const CHECK="cCID<>'PAW' OR (cCASHINMode IS NOT NULL AND cCASHINMode IN (0,2) AND cCASHOUTMode IS NOT NULL AND cCASHOUTMode=0 AND cCurrID IS NOT NULL AND cAssetKey IS NOT NULL AND HEX(cAssetKey)=HEX(CONCAT('asset:',cCurrID)))";
    public static function ensure(Connection $db): void
    {
        $future = $db->link->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='Currs' AND CONSTRAINT_NAME='chk_paw_native'")->fetchColumn();
        if ($future) { PawPayoutSchema::ready($db->link); return; }
        $p=$db->link;$maria=str_contains((string)$p->getAttribute(\PDO::ATTR_SERVER_VERSION),'MariaDB');
        if($maria && (int)$p->query('SELECT @@check_constraint_checks')->fetchColumn()!==1)throw new RuntimeException('PAW CHECK constraints must be enforced');
        $names=$p->query("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='Currs' AND CONSTRAINT_NAME IN ('chk_paw_configuration_only','chk_paw_inbound_only')")->fetchAll(\PDO::FETCH_COLUMN);
        $old=in_array('chk_paw_configuration_only',$names,true);$native=in_array('chk_paw_inbound_only',$names,true);
        // Validate every existing guard and row before the first autocommitting DDL.
        if($old)PaymentProviderSchema::check($p,'Currs','chk_paw_configuration_only',"cCID<>'PAW' OR (cDisabled IS NOT NULL AND cDisabled=1 AND cCASHINMode IS NOT NULL AND cCASHINMode=0 AND cCASHOUTMode IS NOT NULL AND cCASHOUTMode=0)",false);
        if($native)PaymentProviderSchema::check($p,'Currs','chk_paw_inbound_only',self::CHECK,false);
        if((int)$p->query("SELECT COUNT(*) FROM Currs WHERE cCID='PAW' AND (cProviderID IS NULL OR cProviderKeyVersion IS NOT NULL OR cCASHINMode IS NULL OR cCASHINMode NOT IN (0,2) OR cCASHOUTMode IS NULL OR cCASHOUTMode<>0)")->fetchColumn()>0)
            throw new RuntimeException('Incompatible PAW connection identity');
        PaymentProviderSchema::unique($p,'Currs','uq_currency_provider_fiat',['cProviderID','cAssetKey'],false);
        $rows=$p->query("SELECT cID,cCurrID,cAssetKey,cProviderID,ppKind FROM Currs LEFT JOIN PaymentProviders ON ppID=cProviderID WHERE cCID='PAW' ORDER BY cID")->fetchAll(\PDO::FETCH_ASSOC);
        $seen=[];
        $collision=$p->prepare('SELECT cID FROM Currs WHERE cProviderID=? AND cAssetKey=? AND cID<>?');
        foreach($rows as $row){
            $fiat=$row['cCurrID'];$canonical='asset:'.$fiat;$identity=$row['cProviderID'].':'.$fiat;
            if($row['ppKind']!=='PAW' || !in_array($fiat,PawProviderService::FIATS,true) || isset($seen[$identity]) || ($row['cAssetKey']!==null && $row['cAssetKey']!==$canonical))
                throw new RuntimeException('PAW asset identity requires reconciliation');
            $seen[$identity]=true;$collision->execute([$row['cProviderID'],$canonical,$row['cID']]);
            if($collision->fetchColumn()!==false)throw new RuntimeException('PAW asset identity collision requires reconciliation');
        }
        // Only missing identity metadata is filled; IDs, settings and financial rows stay intact.
        $backfill=$p->prepare('UPDATE Currs SET cAssetKey=? WHERE cID=? AND cAssetKey IS NULL');
        foreach($rows as $row)if($row['cAssetKey']===null)$backfill->execute(['asset:'.$row['cCurrID'],$row['cID']]);
        // Both guards may coexist after an interrupted run; protection is continuous.
        PaymentProviderSchema::check($p,'Currs','chk_paw_inbound_only',self::CHECK,true);
        if($old)$p->exec('ALTER TABLE Currs '.($maria?'DROP CONSTRAINT':'DROP CHECK').' chk_paw_configuration_only');
    }
}
