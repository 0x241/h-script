<?php
declare(strict_types=1);
namespace HScript\Payment\Provider;

use HScript\Database\Connection;
use HScript\Database\TransactionScope;
use PDO;

/** Adds reviewed legacy currency rows without moving identities or encrypted fields. */
final class LegacyProviderService
{
    public const KINDS=['BANKWIRE','YOOMONEY','WEBMONEY','SBERBANK'];
    // A bounded two-decimal fiat catalog; zero-decimal ISO currencies need a different scale contract.
    public const BANK_FIATS=['USD','EUR','GBP','CHF','RUB','CAD','AUD','NZD','SGD','HKD','CNY','PLN','CZK','NOK','SEK','DKK','RON','TRY','KZT','AED','INR','BRL','MXN','ZAR'];
    public static function choices(string $kind): array
    {
        if(!in_array($kind,self::KINDS,true))throw new ProviderException('provider_unsupported');
        if($kind==='BANKWIRE'){
            $result=[];foreach(self::BANK_FIATS as$fiat)$result[$fiat]=['gateway'=>'BW','currency'=>$fiat,'label'=>$fiat];return $result;
        }
        return match($kind){
            'YOOMONEY'=>['YM'=>['gateway'=>'YM','currency'=>'RUB','label'=>'RUB · YooMoney'],'YMC'=>['gateway'=>'YMC','currency'=>'RUB','label'=>'RUB · Visa / Mastercard']],
            'WEBMONEY'=>['USD'=>['gateway'=>'WM','currency'=>'USD','label'=>'USD · WMZ']],
            'SBERBANK'=>['RUB'=>['gateway'=>'SB','currency'=>'RUB','label'=>'RUB']],
        };
    }
    public static function rows(Connection $db,string $kind,bool $lock=false): array
    {
        self::choices($kind);$ids=ProviderCatalog::GROUPS[$kind][1];
        $q=$db->link->prepare('SELECT * FROM Currs WHERE cProviderID IS NULL AND cCID IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY cID'.($lock?' FOR UPDATE':''));
        $q->execute($ids);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function revision(array $rows): string
    {
        return hash('sha256',serialize($rows));
    }
    public static function select(Connection $db,int $level,string $kind,array $input): void
    {
        if($level<90)throw new ProviderException('forbidden');
        if($db->link->inTransaction())throw new ProviderException('configuration_busy');
        CurrencyArchive::requireReady($db);
        foreach(array_keys($input)as$field)if(is_string($field)&&(preg_match('/^c[A-Z]/D',$field)||in_array($field,['credentials','bindings','profile_id'],true)||(str_ends_with($field,'_btn')&&$field!=='legacy_add_btn')))throw new ProviderException('input_invalid');
        $choices=self::choices($kind);$assets=$input['assets']??[];
        if(($input['form_complete']??null)!=='1'||!is_string($input['revision']??null)||!is_array($assets)||!array_is_list($assets)||count($assets)>count($choices)||count(array_unique($assets,SORT_REGULAR))!==count($assets))throw new ProviderException('input_invalid');
        foreach($assets as$asset)if(!is_string($asset)||!isset($choices[$asset]))throw new ProviderException('input_invalid');
        $activationScope=CurrencySelection::scope($input,array_keys($choices));
        TransactionScope::run($db->link,static function()use($db,$kind,$input,$assets,$choices,$activationScope):void{
            self::lock($db,reset($choices)['gateway']);
            (new ProviderCatalog($db))->requireAvailable($kind);
            $rows=self::rows($db,$kind,true);
            if(!hash_equals(self::revision($rows),$input['revision']))throw new ProviderException('configuration_changed');
            $tuple=$db->link->prepare('SELECT cID FROM Currs WHERE cProviderID IS NULL AND cCID=? AND cCurrID=? ORDER BY cID FOR UPDATE');
            foreach($choices as$asset=>$choice){
                $tuple->execute([$choice['gateway'],$choice['currency']]);$ids=array_map('intval',$tuple->fetchAll(PDO::FETCH_COLUMN));
                $matches=array_values(array_filter($rows,static fn($row)=>in_array((int)$row['cID'],$ids,true)));
                if(!in_array($asset,$assets,true)){foreach($matches as$row)CurrencyArchive::archive($db,$row);continue;}
                if(array_filter($matches,static fn($r)=>($r['cArchivedAt']??null)===null))continue;
                // A legacy tuple can contain several historical accounts. Restore the
                // whole group, retaining every ID and balance, rather than picking one.
                if($matches){foreach($matches as$row)CurrencyArchive::restore($db,$row);continue;}
                $q=$db->link->prepare('INSERT INTO Currs(cCID,cCurrID,cName,cCurr,cNumDec,cDisabled,cHidden,cCASHINMode,cCASHOUTMode,cEXMode,cTRMode,cBUYMode,cSELLMode,cBUY2Mode,cSELL2Mode,cGIVEMode,cTAKEMode) VALUES(?,?,?,?,2,1,1,0,0,0,0,0,0,0,0,0,0)');
                $q->execute([$choice['gateway'],$choice['currency'],ProviderCatalog::GROUPS[$kind][0].' '.$choice['label'],$choice['currency']]);
            }
            foreach($choices as$asset=>$choice){
                if(!in_array($asset,$assets,true)||!in_array($asset,$activationScope,true))continue;
                $q=$db->link->prepare('SELECT * FROM Currs WHERE cProviderID IS NULL AND cCID=? AND cCurrID=? ORDER BY cID FOR UPDATE');$q->execute([$choice['gateway'],$choice['currency']]);$matches=$q->fetchAll(PDO::FETCH_ASSOC);
                if(count($matches)!==1)throw new ProviderException('activation_ambiguous');$row=$matches[0];
                $original=null;foreach($rows as$old)if((int)$old['cID']===(int)$row['cID']){$original=$old;break;}
                CurrencySelection::activate($db,$row,static function(array$c)use($db):void{
                    if(self::enabledConflict($db,$c))throw new ProviderException('binding_required');
                    $definition=(new \HScript\Payment\PaymentManager($db))->definitions($c['cCID']);
                    if((in_array((int)$c['cCASHINMode'],[2,3],true)&&empty($definition[2]))||((int)$c['cCASHOUTMode']===2&&empty($definition[3])))throw new ProviderException('automatic_withdrawal_unsupported');
                },$original===null||($original['cArchivedAt']??null)!==null);
            }
        });
    }
    /** Shared catalog row is the mutex for both addition and legacy settings saves. */
    public static function lock(Connection $db,string $gateway): void
    {
        $kind=ProviderCatalog::kind($gateway);if($kind===null)return;
        $q=$db->link->prepare('SELECT pgAvailable FROM PaymentGatewayCatalog WHERE pgCode=? FOR UPDATE');$q->execute([$kind]);
        if($q->fetchColumn()===false)throw new ProviderException('provider_unavailable');
    }
    public static function enabledConflict(Connection $db,array $currency): bool
    {
        $sql='SELECT cID FROM Currs WHERE cCID=? AND cDisabled=0 AND cID<>?';$args=[$currency['cCID'],$currency['cID']];
        if($currency['cCID']==='BW'){$sql.=' AND cCurrID=?';$args[]=$currency['cCurrID'];}
        $q=$db->link->prepare($sql.' LIMIT 1 FOR UPDATE');$q->execute($args);return $q->fetchColumn()!==false;
    }
    public static function add(Connection $db,int $level,string $kind,array $input): array
    {
        if($level<90)throw new ProviderException('forbidden');
        if($db->link->inTransaction())throw new ProviderException('configuration_busy');
        foreach(array_keys($input)as$field)if(is_string($field)&&(preg_match('/^c[A-Z]/D',$field)||in_array($field,['credentials','bindings','profile_id'],true)||(str_ends_with($field,'_btn')&&$field!=='legacy_add_btn')))throw new ProviderException('input_invalid');
        $choices=self::choices($kind);$assets=$input['assets']??null;
        if(($input['form_complete']??null)!=='1'||!is_array($assets)||!array_is_list($assets)||!$assets||count($assets)>count($choices)||count(array_unique($assets,SORT_REGULAR))!==count($assets))throw new ProviderException('input_invalid');
        foreach($assets as$asset)if(!is_string($asset)||!isset($choices[$asset]))throw new ProviderException('input_invalid');
        return TransactionScope::run($db->link,static function()use($db,$kind,$assets,$choices):array{
            self::lock($db,$choices[$assets[0]]['gateway']);
            $q=$db->link->prepare('SELECT pgAvailable FROM PaymentGatewayCatalog WHERE pgCode=?');$q->execute([$kind]);
            if((int)$q->fetchColumn()!==1)throw new ProviderException('provider_unavailable');
            self::rows($db,$kind,true);
            $occupied=$db->link->prepare('SELECT cID FROM Currs WHERE cCID=? AND cCurrID=? ORDER BY cID LIMIT 1 FOR UPDATE');
            $added=[];
            foreach($assets as$asset){
                $choice=$choices[$asset];$occupied->execute([$choice['gateway'],$choice['currency']]);if($occupied->fetchColumn()!==false)continue;
                $q=$db->link->prepare('INSERT INTO Currs(cCID,cCurrID,cName,cCurr,cNumDec,cDisabled,cHidden,cCASHINMode,cCASHOUTMode,cEXMode,cTRMode,cBUYMode,cSELLMode,cBUY2Mode,cSELL2Mode,cGIVEMode,cTAKEMode) VALUES(?,?,?,?,2,1,1,0,0,0,0,0,0,0,0,0,0)');
                $q->execute([$choice['gateway'],$choice['currency'],ProviderCatalog::GROUPS[$kind][0].' '.$choice['label'],$choice['currency']]);
                $added[]=(int)$db->link->lastInsertId();
            }
            return $added;
        });
    }
}
