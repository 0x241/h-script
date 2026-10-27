<?php
declare(strict_types=1);
namespace HScript\Payment\Provider;

use HScript\Database\Connection;
use HScript\Payment\Routing\PaymentRoutes;

/** Display codes never replace ledger, adapter or immutable invoice identities. */
final class CurrencyPresentation
{
    /** Archive labels are presentation only: adapter IDs and encrypted contexts stay intact. */
    public static function annotate(Connection $database, array $rows): array
    {
        $ids=[];
        foreach ($rows as $row) {
            $id=(int)($row['cID']??$row['ocID']??0);
            if ($id>0) $ids[$id]=$id;
        }
        if (!$ids) return $rows;
        foreach($rows as&$row)$row['currency_status']=empty($row['cDisabled'])?'enabled':'disabled';
        unset($row);
        try {
        $column=(bool)$database->link->query("SHOW COLUMNS FROM Currs LIKE 'cArchivedAt'")->fetch();
        $query=$database->link->prepare('SELECT cID,cDisabled,'.($column?'cArchivedAt,':'').'ppState FROM Currs LEFT JOIN PaymentProviders ON ppID=cProviderID WHERE cID IN ('.implode(',',array_fill(0,count($ids),'?')).')');
        $query->execute(array_values($ids));$states=$query->fetchAll(\PDO::FETCH_UNIQUE|\PDO::FETCH_ASSOC);
        } catch(\PDOException $error) {
            // A source-first deployment must still reach the provider's pre-migration guard.
            if(in_array($error->getCode(),['42S02','42S22'],true))return $rows;
            throw $error;
        }
        foreach ($rows as &$row) {
            $id=(int)($row['cID']??$row['ocID']??0);$state=$states[$id]??null;
            if (!$state) continue;
            $row['currency_status']=(($state['cArchivedAt']??null)!==null||$state['ppState']==='retired')?'retired':((int)$state['cDisabled']?'disabled':'enabled');
            if ($row['currency_status']==='retired' && isset($row['cName'])) {
                $row['currency_original_name']??=$row['cName'];
                $label=\HScript\Template\View::_t('currency.archived')??'Архив';
                $name=\HScript\Util\StringHelper::textLangFilter($row['currency_original_name'],$GLOBALS['_GS']['lang']??'ru');
                $row['cName']=$name.' ['.$label.' #'.$id.']';
            }
        }
        unset($row);
        return $rows;
    }

    public static function describe(array $currency): array
    {
        $result=['code'=>(string)($currency['cCID']??''),'network'=>null,'contract'=>null];
        if($result['code']==='CPN'){
            try{$asset=CoinPaymentsNewService::asset($currency);$result+=['asset'=>$asset['symbol']];$result['network']=$asset['network'];$result['contract']=$asset['contract'];}catch(\Throwable){}
            return $result;
        }
        if(isset(\HScript\Payment\PaykassaAssets::ASSETS[$result['code']])){$result['network']=\HScript\Payment\PaykassaAssets::ASSETS[$result['code']][3];return $result;}
        if(!in_array($result['code'],['CCT','CCTU','CCTC'],true))return $result;
        try {
            $routes=PaymentRoutes::catalog($result['code'],$currency);
            if(count($routes)!==1){$result['code']='CCAT';return $result;}
            $route=reset($routes);
            $result['code']=match($route['network']){'ethereum'=>'CCAET','tron'=>'CCATT','polygon'=>'CCAMT','bsc'=>'CCANT',default=>'CCAT'};
            $result['network']=$route['label'];$result['contract']=$route['contract'];
        }catch(\Throwable){/* Malformed settings must not prevent reading the currency list. */}
        return $result;
    }
}
