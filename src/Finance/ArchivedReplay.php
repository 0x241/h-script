<?php
declare(strict_types=1);
namespace HScript\Finance;

use HScript\Database\{Connection,TransactionScope};
use HScript\Payment\{FormCallbackPayload,JsonCallbackPayload};
use HScript\Payment\Gateways\CryptoCurrencyApi;
use PDO;

/** A disconnected receiver can acknowledge an existing authenticated receipt, never create one. */
final class ArchivedReplay
{
    public static function inactive(array $row): bool
    {
        return ($row['cArchivedAt']??null)!==null;
    }
    public static function operationTag(string $gateway,string $body): int
    {
        $fields=CryptoCurrencyApi::supports($gateway)?JsonCallbackPayload::scalars($body):FormCallbackPayload::decode($body);
        $field=match(true){
            $gateway==='WM'=>'LMI_PAYMENT_NO',in_array($gateway,['YM','YMC'],true)=>'label',
            CryptoCurrencyApi::supports($gateway)=>'label',isset(PaykassaContract::SYSTEMS[$gateway])=>'order_id',
            in_array($gateway,['CP','CPE','CPL','CPR'],true)=>'invoice',VoletContract::supports($gateway)=>'ac_order_id',
            default=>throw new \InvalidArgumentException('callback_route_unsupported'),
        };
        $tag=$fields[$field]??null;
        if(!is_string($tag)||!preg_match('/^[1-9][0-9]{0,9}$/D',$tag)||(int)$tag>2147483647)throw new \InvalidArgumentException('callback_order_invalid');
        return (int)$tag;
    }
    private static function receipt(Connection $db,string $namespace,string $key,int $operation,string $fingerprint): array
    {
        $q=$db->link->prepare('SELECT ikOwnerID,ikOperationID,ikState,ikFingerprint,ikResponse FROM IdempotencyKeys WHERE ikNamespace=? AND ikKeyHash=? FOR UPDATE');
        $q->execute([$namespace,hash('sha256',"0\0".$key)]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row||(int)$row['ikOwnerID']!==0||(int)$row['ikOperationID']!==$operation||$row['ikState']!=='completed'||!hash_equals((string)$row['ikFingerprint'],$fingerprint))throw new CallbackValidationException('archived_callback_reconciliation_required');
        try{$snapshot=json_decode($row['ikResponse'],true,512,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new CallbackValidationException('archived_callback_reconciliation_required');}
        if(!is_array($snapshot)||($snapshot['status']??null)!==200||!is_array($snapshot['data']??null))throw new CallbackValidationException('archived_callback_reconciliation_required');
        return $snapshot['data'];
    }
    public static function gateway(Connection $db,string $gateway,int $currency,array $verified,string $fingerprint,string $namespace,string $key,string $batch,callable $validate): ?IdempotencyResult
    {
        return TransactionScope::run($db->link,static function()use($db,$gateway,$currency,$verified,$fingerprint,$namespace,$key,$batch,$validate):?IdempotencyResult{
            $q=$db->link->prepare('SELECT Opers.*,Currs.*,oSum-oComis AS callbackNetAmount FROM Opers JOIN Currs ON cID=ocID WHERE oID=? FOR UPDATE');$q->execute([$verified['tag']]);$o=$q->fetch(PDO::FETCH_ASSOC);
            if(!$o||!self::inactive($o))return null;
            if((int)$o['ocID']!==$currency||$o['cCID']!==$gateway||$o['oOper']!=='CASHIN'||(int)$o['oState']!==3||$o['oBatch']!==$batch||($verified['outcome']??null)!=='verified'||!empty($verified['wait'])||!empty($verified['outbound']))throw new CallbackValidationException('archived_callback_reconciliation_required');
            $identity=self::receipt($db,$namespace.':identity',$key,(int)$o['oID'],$fingerprint);
            $settled=self::receipt($db,$namespace.':settled',$key,(int)$o['oID'],$fingerprint);
            if(($identity['bound']??null)!==true||!is_array($identity['contract']??null)||($settled['settled']??null)!==true)throw new CallbackValidationException('archived_callback_reconciliation_required');
            $validate(new IdempotencyResult($identity,200,(int)$o['oID'],true),$o);
            return new IdempotencyResult($settled,200,(int)$o['oID'],true);
        });
    }
    /** Caller already holds the operation and has validated original VARY form/attempt/binding. */
    public static function paw(Connection $db,array $o,array $form,array $binding,string $fingerprint): IdempotencyResult
    {
        if((int)$o['oState']!==3||$o['oBatch']!=='PAW:'.$form['paw_order'])throw new CallbackValidationException('archived_callback_reconciliation_required');
        $data=self::receipt($db,'gateway:paw:currency:'.$o['ocID'].':settled',hash('sha256',$form['paw_order']),(int)$o['oID'],$fingerprint);
        if(($data['settled']??null)!==true||($data['paw_binding']??null)!==$binding)throw new CallbackValidationException('archived_callback_reconciliation_required');
        return new IdempotencyResult($data,200,(int)$o['oID'],true);
    }
}
