<?php
declare(strict_types=1);
namespace HScript\Finance;

use HScript\Database\Connection;
use HScript\Payment\Provider\CurrencyPresentation;
use PDO;

/** Owner-only read projection; never added to the operational currency catalog. */
final class ArchivedHoldings
{
    public static function forUser(Connection $db,int $owner): array
    {
        if($owner<1)return [];
        try{
            $archive=(bool)$db->link->query("SHOW COLUMNS FROM Currs LIKE 'cArchivedAt'")->fetch();
            $q=$db->link->prepare('SELECT cID,cCID,cCurrID,cName,cCurr,wBal,wLock,wOut FROM Wallets JOIN Currs ON cID=wcID LEFT JOIN PaymentProviders ON ppID=cProviderID WHERE wuID=? AND ('.($archive?'cArchivedAt IS NOT NULL OR ':'')."ppState='retired') AND (wBal<>0 OR wLock<>0 OR wOut<>0) ORDER BY cID");
            $q->execute([$owner]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        }catch(\PDOException $e){if(in_array($e->getCode(),['42S02','42S22'],true))return [];throw $e;}
        $rows=CurrencyPresentation::annotate($db,$rows);
        foreach($rows as&$row){
            // Archived amounts retain all six stored places, including small fiat remnants.
            foreach(['wBal','wLock','wOut']as$field)$row[$field]=DecimalOutput::stored(Decimal::of($row[$field]));
        }unset($row);
        return $rows;
    }
}
