<?php

namespace HScript\Finance;

use DateTimeImmutable;
use DateTimeZone;
use HScript\Database\Connection;
use HScript\Database\TransactionScope;
use JsonException;

/** Original receiving-account and verification policy, separate from money v1. */
final class InboundProfile
{
    private const FIELDS=['version','operation_id','owner_id','currency_id','gateway','key_hash','receiver_hash','timezone'];

    public static function required(string $gateway): bool
    {
        return in_array($gateway,['YM','YMC','WM','PKB','PKE','PKL','PKTU','PKTC'],true);
    }

    public static function managed(string $gateway): bool
    {
        return self::required($gateway) || in_array($gateway,['CP','CPE','CPL','CPR'],true);
    }

    public static function timezone(mixed $zone): bool
    {
        return is_string($zone) && in_array($zone,DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),true)
            && (new DateTimeZone($zone))->getTransitions(0,1)!==false;
    }

    public static function capture(array $operation): ?array
    {
        if (!self::managed($operation['cCID'])) return null;
        opDecodeCurrParams($operation,$pay,$sci,$api);
        if (!is_string($sci['key'] ?? null) || trim($sci['key'])==='')
            throw new CallbackValidationException('callback_configuration_missing');
        $coin=in_array($operation['cCID'],['CP','CPE','CPL','CPR','PKB','PKE','PKL','PKTU','PKTC'],true);
        $receiver=$operation['cCID']==='WM' ? ($pay['acc'] ?? null) : ($sci[$coin ? 'id' : 'acc'] ?? null);
        if (!is_string($receiver) || ($coin ? $receiver==='' || strlen($receiver)>256
            : !preg_match($operation['cCID']==='WM' ? '/^Z[0-9]{12}$/D' : '/^[0-9]{13,16}$/D',$receiver)))
            throw new CallbackValidationException('callback_destination_mismatch');
        $zone=$operation['cCID']==='WM' ? ($sci['timezone'] ?? null) : '';
        if ($operation['cCID']==='WM' && !self::timezone($zone))
            throw new CallbackValidationException('webmoney_timezone_unsupported');
        return ['version'=>1,'operation_id'=>(int)$operation['oID'],'owner_id'=>(int)$operation['ouID'],
            'currency_id'=>(int)$operation['ocID'],'gateway'=>$operation['cCID'],'key_hash'=>hash('sha256',$sci['key']),
            'receiver_hash'=>hash('sha256',$receiver),'timezone'=>$zone];
    }

    public static function assertProof(mixed $proof): void
    {
        if (!is_array($proof) || array_diff(self::FIELDS,array_keys($proof)) || array_diff(array_keys($proof),self::FIELDS)
            || $proof['version']!==1 || !is_int($proof['operation_id']) || $proof['operation_id']<1
            || !is_int($proof['owner_id']) || $proof['owner_id']<1 || !is_int($proof['currency_id']) || $proof['currency_id']<1
            || !is_string($proof['gateway']) || !self::managed($proof['gateway'])
            || !is_string($proof['key_hash']) || !preg_match('/^[a-f0-9]{64}$/D',$proof['key_hash'])
            || !is_string($proof['receiver_hash']) || !preg_match('/^[a-f0-9]{64}$/D',$proof['receiver_hash'])
            || ($proof['gateway']==='WM' ? !self::timezone($proof['timezone']) : $proof['timezone']!==''))
            throw new CallbackValidationException('callback_profile_binding_missing');
    }

    public static function assertOperation(array $proof,array $operation): void
    {
        self::assertProof($proof);
        if (self::capture($operation)!==$proof) throw new CallbackValidationException('callback_profile_configuration_changed');
    }

    public static function assertForm(array $proof,array $form): void
    {
        self::assertProof($form['inbound_profile'] ?? null);
        if ($form['inbound_profile']!==$proof) throw new CallbackValidationException('callback_profile_binding_missing');
        $field=$proof['gateway']==='WM' ? 'LMI_PAYEE_PURSE' : (isset(PaykassaContract::SYSTEMS[$proof['gateway']])
            ? 'paykassa_merchant_id' : (in_array($proof['gateway'],['YM','YMC'],true) ? 'receiver' : 'merchant'));
        $receiver=$form[$field] ?? null;
        if (!is_string($receiver) || !hash_equals($proof['receiver_hash'],hash('sha256',$receiver)))
            throw new CallbackValidationException('callback_destination_mismatch');
    }

    public static function original(Connection $db,array $operation): array
    {
        $statement=$db->link->prepare("SELECT ikOwnerID,ikOperationID,ikState,ikResponse FROM IdempotencyKeys WHERE ikNamespace='invoice:attempt' AND ikKeyHash=? FOR UPDATE");
        $statement->execute([hash('sha256',(int)$operation['ouID']."\0".(int)$operation['oID'])]);
        $row=$statement->fetch();
        // Configured CP profiles bind current merchant/key at first signed
        // identity. Existing attempts/invoices must never backfill authority.
        if (!$row && !self::required($operation['cCID']) && DepositCheckout::storedForm($operation)===null)
            return self::capture($operation);
        try { $data=$row && is_string($row['ikResponse']) ? json_decode($row['ikResponse'],true,32,JSON_THROW_ON_ERROR) : null; }
        catch (JsonException) { throw new CallbackValidationException('callback_profile_binding_missing'); }
        if (!$row || (int)$row['ikOwnerID']!==(int)$operation['ouID'] || (int)$row['ikOperationID']!==(int)$operation['oID']
            || $row['ikState']!=='completed' || ($data['data']['started'] ?? null)!==true)
            throw new CallbackValidationException('callback_profile_binding_missing');
        $proof=$data['data']['profile'] ?? null;
        self::assertProof($proof);
        self::assertOperation($proof,$operation);
        $form=DepositCheckout::storedForm($operation);
        if ($form===null) throw new CallbackValidationException('callback_invoice_unavailable');
        self::assertForm($proof,$form);
        return $proof;
    }

    public static function assertBinding(array $proof,array $verified): void
    {
        self::assertProof($proof);
        $receiver=$verified[isset(PaykassaContract::SYSTEMS[$proof['gateway']]) ? 'merchant_id' : 'accto'] ?? null;
        if (($verified['profile_key_hash'] ?? null)!==$proof['key_hash']
            || ($verified['profile_timezone'] ?? null)!==$proof['timezone'])
            throw new CallbackValidationException('callback_profile_configuration_changed');
        if (!is_string($receiver) || !hash_equals($proof['receiver_hash'],hash('sha256',$receiver)))
            throw new CallbackValidationException(isset(PaykassaContract::SYSTEMS[$proof['gateway']])
                ? 'callback_merchant_mismatch' : 'callback_destination_mismatch');
    }

    /** Completed replies still require the original profile and current key/zone. */
    public static function assertReplay(Connection $db,int $operationId,mixed $proof,array $verified): void
    {
        self::assertProof($proof);
        TransactionScope::run($db->link,static function() use($db,$operationId,$proof,$verified): void {
            $operation=$db->fetch1Row($db->query('SELECT Opers.*,Currs.* FROM Opers JOIN Currs ON cID=ocID WHERE oID=?d FOR UPDATE',[$operationId]));
            if (!$operation || self::original($db,$operation)!==$proof)
                throw new CallbackValidationException('callback_profile_binding_missing');
            self::assertBinding($proof,$verified);
        });
    }

    /** A zone-free provider wall time must identify exactly one instant. */
    public static function wallTime(string $wire,string $zone): int
    {
        if (!self::timezone($zone)) throw new CallbackValidationException('webmoney_timezone_unsupported');
        $naive=DateTimeImmutable::createFromFormat('!Ymd H:i:s',$wire,new DateTimeZone('UTC'));
        if (!$naive || $naive->format('Ymd H:i:s')!==$wire) throw new CallbackValidationException('callback_time_invalid');
        $timezone=new DateTimeZone($zone);
        $epoch=$naive->getTimestamp(); $offsets=[];
        foreach ($timezone->getTransitions($epoch-172800,$epoch+172800) ?: [] as $transition) $offsets[$transition['offset']]=true;
        $instants=[];
        foreach(array_keys($offsets) as $offset) {
            $candidate=$epoch-$offset;
            if ((new DateTimeImmutable('@'.$candidate))->setTimezone($timezone)->format('Ymd H:i:s')===$wire) $instants[$candidate]=true;
        }
        if (count($instants)!==1) throw new CallbackValidationException('callback_time_ambiguous_or_invalid');
        return (int)array_key_first($instants);
    }
}
