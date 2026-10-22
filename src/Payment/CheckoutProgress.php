<?php

declare(strict_types=1);

namespace HScript\Payment;

use HScript\Database\Connection;
use HScript\Database\TransactionScope;
use HScript\Payment\Gateways\CryptoCurrencyApi;
use PDOException;

/** Local, owner-scoped observation. Never contacts a gateway or settles money. */
final class CheckoutProgress
{
    public static function read(Connection $db, int $owner, int $id): ?array
    {
        if ($owner < 1 || $id < 1) return null;
        $operation = $db->fetch1Row($db->select('Opers', 'oState',
            'oID=?d AND ouID=?d AND oOper=?', [$id, $owner, 'CASHIN']));
        if (!$operation) return null;
        $state = (int)$operation['oState'];
        $result = ['state' => match ($state) {
            0, 1 => 'waiting',
            2 => 'confirming',
            3 => 'credited',
            default => 'closed',
        }];
        try {
            $query=$db->link->prepare('SELECT pcCount FROM PaymentConfirmations WHERE pcOperationID=?');$query->execute([$id]);$row=$query->fetch(\PDO::FETCH_ASSOC);
            if($row && preg_match('/^(?:0|[1-9][0-9]{0,9})$/D',(string)$row['pcCount']))$result['confirmations']=(int)$row['pcCount'];
        }catch(PDOException $e){if((int)($e->errorInfo[1]??0)!==1146)throw $e;}
        return $result;
    }

    /** Called only after accepted settlement. A diagnostic failure cannot change provider acknowledgement. */
    public static function record(Connection $db,string $gateway,int $currency,array $verified): void
    {
        $count=$verified['ccapi_confirmations']??null;$event=$verified['ccapi_event_hash']??null;
        if(!CryptoCurrencyApi::supports($gateway) || !empty($verified['outbound']) || ($verified['correct']??false)!==true || ($verified['authenticated']??false)!==true
            || !in_array($verified['outcome']??null,['pending','verified'],true) || !is_int($count) || $count<0 || $count>9999999999 || in_array($count,[100,101],true)
            || !is_string($event) || !preg_match('/^[a-f0-9]{64}$/D',$event) || $db->link->inTransaction())return;
        $batch=$verified['batch']??null;if(!is_string($batch) || $batch==='')return;
        $id=filter_var($verified['tag']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>2147483647]]);
        if(!$id)return;
        try {
            TransactionScope::run($db->link,static function()use($db,$gateway,$currency,$id,$event,$count,$batch):void{
                $o=$db->fetch1Row($db->query('SELECT oID,ocID,oState,oOper,cCID FROM Opers JOIN Currs ON cID=ocID WHERE oID=?d FOR UPDATE',[$id]));
                if(!$o || (int)$o['ocID']!==$currency || $o['cCID']!==$gateway || $o['oOper']!=='CASHIN' || !in_array((int)$o['oState'],[2,3],true))return;
                $proof=$db->fetch1($db->query("SELECT ikResponse FROM IdempotencyKeys WHERE ikNamespace=? AND ikKeyHash=? AND ikOperationID=?d AND ikState='completed'",['gateway:'.strtolower($gateway).':currency:'.$currency.':identity',hash('sha256',"0\0".hash('sha256',$batch)),$id]));
                $proof=is_string($proof)?json_decode($proof,true,32):null;
                if(($proof['data']['contract']['ccapi_event_hash']??null)!==$event)return;
                $s=$db->link->prepare('INSERT INTO PaymentConfirmations(pcOperationID,pcEventHash,pcCount,pcUpdatedAt) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE pcUpdatedAt=IF(pcEventHash=VALUES(pcEventHash) AND VALUES(pcCount)>pcCount,VALUES(pcUpdatedAt),pcUpdatedAt),pcCount=IF(pcEventHash=VALUES(pcEventHash),GREATEST(pcCount,VALUES(pcCount)),pcCount)');
                $s->execute([$id,$event,$count,time()]);
            });
        }catch(\Throwable $e){if(!$e instanceof PDOException || (int)($e->errorInfo[1]??0)!==1146)error_log('Payment confirmation metadata could not be recorded');}
    }
}
