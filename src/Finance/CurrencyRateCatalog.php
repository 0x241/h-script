<?php

declare(strict_types=1);
namespace HScript\Finance;

use HScript\Database\Connection;
use HScript\Payment\PaymentManager;
use HScript\Payment\Provider\{ProviderCatalog,ProviderSettings,PawProviderService};
use HScript\Database\TransactionScope;
use InvalidArgumentException;

/** Rates for reviewed ledger currencies; a token ticker never establishes identity. */
final class CurrencyRateCatalog
{
    public static function currencies(): array
    {
        $result=[];
        foreach((new PaymentManager(new Connection()))->definitions() as $id=>$d){
            $code=$d[1]??'';
            if(!is_string($code) || !preg_match('/^[A-Z][A-Z0-9]{1,9}$/D',$code) || $id==='CCT')continue;
            $kind=ProviderCatalog::kind($id);$name=$kind!==null?ProviderCatalog::GROUPS[$kind][0]:$d[0];
            $result[$code][$name]=true;
        }
        foreach(ProviderSettings::choices('STRIPE') as $code=>$d)$result[$code]['Stripe']=true;
        foreach(PawProviderService::FIATS as $code)$result[$code]['PAW Payments']=true;
        foreach(\HScript\Payment\Provider\LegacyProviderService::BANK_FIATS as $code)$result[$code]['BankWire']=true;
        ksort($result,SORT_STRING);$rows=[];
        foreach($result as $code=>$providers)$rows[$code]=['code'=>$code,'providers'=>array_keys($providers),'central_bank'=>in_array($code,['USD','EUR','RUB'],true),'fiat'=>in_array($code,[...PawProviderService::FIATS,...\HScript\Payment\Provider\LegacyProviderService::BANK_FIATS],true)];
        return $rows;
    }

    /** Validate every posted rate before the first write; keep unrelated settings. */
    public static function save(Connection $db,array $current,array $input): void
    {
        $rows=self::currencies();$values=[];
        $flags=['LockWallets','UpdateRates','Update','SumInInternal','ForcePayer','NeedPIN','AWDBONUS','AWDEXIN','AWDTRIN','AWDSELL','AWDSELL2','AWDREF','AWDTAKE','AWDCALCIN'];
        foreach($flags as$field){$v=$input[$field]??'0';if(!in_array($v,['0','1'],true))throw new InvalidArgumentException('rate_invalid');$values[$field]=$v;}
        foreach(['CASHINText','CASHOUTText']as$field){$v=$input[$field]??($current[$field]??'');if(!is_string($v)||strlen($v)>8000||!preg_match('//u',$v))throw new InvalidArgumentException('rate_invalid');$values[$field]=$v;}
        $mode=$input['AFMMode']??($current['AFMMode']??'0');if(!in_array($mode,['0','1','2','3'],true))throw new InvalidArgumentException('rate_invalid');$values['AFMMode']=$mode;
        $bonus=AdministrativeDecimal::read($input['RegBonus']??($current['RegBonus']??'0'));if($bonus->isNegative())throw new InvalidArgumentException('rate_invalid');$values['RegBonus']=AdministrativeDecimal::stored($bonus,13,6);
        foreach(array_keys($input) as $field){
            if(!is_string($field))continue;
            elseif(str_starts_with($field,'Rate') && !isset($rows[substr($field,4)]))throw new InvalidArgumentException('rate_currency_unknown');
            elseif(preg_match('/^Update(.+)Rate$/D',$field,$m) && !isset($rows[$m[1]]))throw new InvalidArgumentException('rate_currency_unknown');
        }
        foreach($rows as $code=>$row){
            $raw=$input['Rate'.$code]??($current['Rate'.$code]??'0');
            if(!is_string($raw))throw new InvalidArgumentException('rate_invalid');
            $raw=str_replace([' ', ','],['','.'],trim($raw));if($raw==='')$raw='0';
            if(!preg_match('/^[0-9]{1,12}(?:\.[0-9]{1,18})?$/D',$raw))throw new InvalidArgumentException('rate_invalid');
            $values['Rate'.$code]=(string)Decimal::of($raw);
            $flag=$input['Update'.$code.'Rate']??'0';
            if(!in_array($flag,['0','1'],true))throw new InvalidArgumentException('rate_invalid');
            $values['Update'.$code.'Rate']=$flag;
        }
        TransactionScope::run($db->link,static function()use($db,$values):void{
            foreach($values as $field=>$value){
                if(!$db->replace('Cfg',['Module'=>'Bal','Prop'=>$field,'Val'=>$value]))throw new \RuntimeException('rate_update_failed');
            }
        });
    }
}
