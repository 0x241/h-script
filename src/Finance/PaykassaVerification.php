<?php

namespace HScript\Finance;

use HScript\Database\Connection;
use HScript\Database\TransactionScope;
use PDOException;
use RuntimeException;

/** Bound unauthenticated confirmation work; cache only a verified token digest. */
final class PaykassaVerification
{
    public const COOLDOWN=30;
    public const CACHE_TTL=604800;
    private const MERCHANT='verification:paykassa:merchant';
    private const ORDER='verification:paykassa:order';

    public static function intent(Connection $db,string $gateway,int $id,array $config): array
    {
        return self::transaction($db,static function() use($db,$gateway,$id,$config): array {
            $operation=$db->fetch1Row($db->query('SELECT Opers.*,Currs.* FROM Opers JOIN Currs ON cID=ocID WHERE oID=?d FOR UPDATE',[$id]));
            if (!$operation || $operation['oOper']!=='CASHIN' || $operation['cCID']!==$gateway
                || !in_array((int)$operation['oState'],[0,1,2,3],true))
                throw new CallbackValidationException('callback_order_unavailable');
            opDecodeCurrParams($operation,$pay,$sci,$api);
            if (($config['id'] ?? null)!==($sci['id'] ?? null) || ($config['key'] ?? null)!==($sci['key'] ?? null))
                throw new CallbackValidationException('callback_profile_configuration_changed');
            $profile=InboundProfile::original($db,$operation);
            $intent=InboundIntent::callbackProof($db,$operation);
            $form=DepositCheckout::storedForm($operation);
            $amount=RequestDecimal::normalize($intent['gross']);
            $verified=['outcome'=>'verified','reason'=>'provider_confirmed','authenticated'=>true,'correct'=>true,
                'sum'=>$amount,'sum2'=>$amount,'curr'=>$operation['cCurrID'],'tag'=>(string)$id,
                'accto'=>$form['paykassa_address'] ?? null,'destination_tag'=>$form['paykassa_tag'] ?? null,
                'merchant_id'=>$form['paykassa_merchant_id'] ?? null,'provider_invoice_hash'=>$form['paykassa_hash'] ?? null,
                'batch'=>$form['paykassa_invoice'] ?? null,'response'=>$id.'|success',
                'profile_key_hash'=>$profile['key_hash'],'profile_timezone'=>''];
            if(isset(\HScript\Payment\Routing\PaymentRoutes::GATEWAYS[$gateway])) {
                $route=\HScript\Payment\Routing\PaymentRoutes::assertSnapshot($gateway,$form['payment_route']??null);
                $verified+=['payment_route_hash'=>\HScript\Payment\Routing\PaymentRoutes::hash($route),'paykassa_system'=>\HScript\Payment\Routing\PaymentRoutes::paykassaSystem($route)];
            }
            InboundProfile::assertBinding($profile,$verified);
            PaykassaContract::validate($operation,$verified,$form,$sci,$intent['scale'],$intent['gross']);
            return ['operation_id'=>$id,'owner_id'=>(int)$operation['ouID'],'profile'=>$profile,'verified'=>$verified,
                'account'=>hash('sha256',$sci['id']."\0".$profile['key_hash']),
                'binding'=>IdempotencyRequest::fingerprint(['intent'=>$intent,'profile'=>$profile,
                    'invoice'=>$form['paykassa_invoice'],'invoice_hash'=>$form['paykassa_hash'],
                    'receiver_hash'=>hash('sha256',$form['paykassa_address']),'destination_tag_hash'=>hash('sha256',$form['paykassa_tag'])]+(isset($verified['payment_route_hash'])?['payment_route_hash'=>$verified['payment_route_hash'],'system'=>$verified['paykassa_system']]:[]))];
        });
    }

    private static function budget(): int
    {
        $value=getenv('PAYKASSA_VERIFICATION_BUDGET_PER_MINUTE');
        if ($value===false || $value==='') return 10;
        if (!preg_match('/^[1-9][0-9]{0,3}$/D',$value) || (int)$value>1000)
            throw new RuntimeException('paykassa_verification_policy_invalid');
        return (int)$value;
    }

    private static function transaction(Connection $db,callable $work): mixed
    {
        if ($db->link->inTransaction()) return TransactionScope::run($db->link,$work);
        for($attempt=0;;++$attempt) {
            try { return TransactionScope::run($db->link,$work); }
            catch(PDOException $error) {
                if ($attempt>=2 || ($error->getCode()!=='40001' && !in_array((int)($error->errorInfo[1] ?? 0),[1205,1213],true))) throw $error;
                usleep(random_int(10000,50000));
            }
        }
    }

    private static function row(Connection $db,string $namespace,string $key,array $initial,int $now,?int $operation): array
    {
        $hash=hash('sha256',"0\0".$key);
        $insert=$db->link->prepare("INSERT INTO IdempotencyKeys (ikNamespace,ikKeyHash,ikFingerprint,ikClaimToken,ikOwnerID,ikOperationID,ikState,ikResponse,ikCreatedAt,ikExpiresAt) VALUES (?,?,?,?,0,?,'completed',?,?,?) ON DUPLICATE KEY UPDATE ikID=ikID");
        $insert->execute([$namespace,$hash,hash('sha256','paykassa-verification-v1'),bin2hex(random_bytes(16)),$operation,
            json_encode($initial,JSON_THROW_ON_ERROR),$now,$operation ? $now+self::CACHE_TTL : 0]);
        $statement=$db->link->prepare('SELECT ikID,ikOperationID,ikResponse,ikState FROM IdempotencyKeys WHERE ikNamespace=? AND ikKeyHash=? FOR UPDATE');
        $statement->execute([$namespace,$hash]); $row=$statement->fetch();
        if (!$row || $row['ikState']!=='completed' || ($operation===null ? $row['ikOperationID']!==null : (int)$row['ikOperationID']!==$operation))
            throw new RuntimeException('paykassa_verification_unavailable');
        try { $data=json_decode($row['ikResponse'],true,16,JSON_THROW_ON_ERROR); }
        catch(\Throwable) { throw new RuntimeException('paykassa_verification_unavailable'); }
        if (!is_array($data) || array_keys($data)!==array_keys($initial) || ($data['version'] ?? null)!==1)
            throw new RuntimeException('paykassa_verification_unavailable');
        return ['id'=>(int)$row['ikID'],'data'=>$data];
    }

    private static function save(Connection $db,int $id,array $data,int $expires=0): void
    {
        $statement=$db->link->prepare('UPDATE IdempotencyKeys SET ikResponse=?,ikExpiresAt=? WHERE ikID=?');
        $statement->execute([json_encode($data,JSON_THROW_ON_ERROR),$expires,$id]);
    }

    /** Fixed rows per real invoice/account, never one row per attacker token. */
    public static function claim(Connection $db,array $context,string $token): array
    {
        $now=time(); $budget=self::budget(); $tokenHash=hash('sha256',$token);
        // Cleanup owns no financial/account locks. Narrow primary-key deletes
        // avoid a namespace range lock across unrelated invoices.
        $expired=$db->link->prepare('SELECT ikID FROM IdempotencyKeys FORCE INDEX (ix_idempotency_expiry) WHERE ikNamespace=? AND ikExpiresAt BETWEEN 1 AND ? LIMIT 100');
        $expired->execute([self::ORDER,$now-1]);
        $delete=$db->link->prepare('DELETE FROM IdempotencyKeys WHERE ikID=? AND ikNamespace=? AND ikExpiresAt BETWEEN 1 AND ?');
        foreach($expired->fetchAll(\PDO::FETCH_COLUMN) as $id) $delete->execute([$id,self::ORDER,$now-1]);
        return self::transaction($db,static function() use($db,$context,$now,$budget,$tokenHash): array {
            $merchant=self::row($db,self::MERCHANT,$context['account'],['version'=>1,'window'=>0,'count'=>0],$now,null);
            $order=self::row($db,self::ORDER,(string)$context['operation_id'],['version'=>1,'binding'=>$context['binding'],
                'last'=>0,'claim'=>'','inflight'=>false,'token_hash'=>'','verified_at'=>0],$now,$context['operation_id']);
            $m=$merchant['data']; $o=$order['data'];
            if (!is_int($m['window']) || $m['window']<0 || !is_int($m['count']) || $m['count']<0
                || !is_int($o['last']) || $o['last']<0 || !is_int($o['verified_at']) || $o['verified_at']<0
                || !is_bool($o['inflight']) || !is_string($o['claim']) || !is_string($o['token_hash'])
                || ($o['claim']!=='' && !preg_match('/^[a-f0-9]{32}$/D',$o['claim']))
                || ($o['token_hash']!=='' && !preg_match('/^[a-f0-9]{64}$/D',$o['token_hash']))
                || $o['binding']!==$context['binding']) throw new RuntimeException('paykassa_verification_unavailable');
            if ($o['token_hash']!=='' && $o['verified_at']>$now-self::CACHE_TTL && hash_equals($o['token_hash'],$tokenHash))
                return ['cached'=>true];
            if ($o['last']>$now-self::COOLDOWN) throw new RuntimeException('paykassa_verification_throttled');
            $window=intdiv($now,60)*60;
            if ($m['window']!==$window) $m=['version'=>1,'window'=>$window,'count'=>0];
            if ($m['count']>=$budget) throw new RuntimeException('paykassa_verification_throttled');
            ++$m['count']; $o['last']=$now; $o['claim']=bin2hex(random_bytes(16)); $o['inflight']=true;
            self::save($db,$merchant['id'],$m);
            self::save($db,$order['id'],$o,$now+self::CACHE_TTL);
            return ['cached'=>false,'id'=>$order['id'],'claim'=>$o['claim'],'token_hash'=>$tokenHash];
        });
    }

    /** Save no raw token, provider address, credential or unvalidated response. */
    public static function complete(Connection $db,array $context,array $claim,array $verified): void
    {
        foreach (['sum','curr','tag','accto','destination_tag','merchant_id','provider_invoice_hash','batch','profile_key_hash','profile_timezone','payment_route_hash','paykassa_system'] as $field)
            if (($verified[$field] ?? null)!==($context['verified'][$field] ?? null))
                throw new CallbackValidationException('callback_paykassa_original_mismatch');
        self::transaction($db,static function() use($db,$context,$claim): void {
            $current=self::intent($db,$context['profile']['gateway'],$context['operation_id'],self::configuration($db,$context['operation_id']));
            if ($current['binding']!==$context['binding']) throw new CallbackValidationException('callback_paykassa_original_mismatch');
            $statement=$db->link->prepare('SELECT ikResponse FROM IdempotencyKeys WHERE ikID=? AND ikNamespace=? FOR UPDATE');
            $statement->execute([$claim['id'],self::ORDER]);
            try { $data=json_decode($statement->fetchColumn(),true,16,JSON_THROW_ON_ERROR); }
            catch(\Throwable) { throw new RuntimeException('paykassa_verification_unavailable'); }
            if (($data['binding'] ?? null)!==$context['binding'] || ($data['claim'] ?? null)!==$claim['claim'] || ($data['inflight'] ?? null)!==true)
                throw new RuntimeException('paykassa_verification_unavailable');
            $data['inflight']=false; $data['token_hash']=$claim['token_hash']; $data['verified_at']=time();
            self::save($db,$claim['id'],$data,time()+self::CACHE_TTL);
        });
    }

    private static function configuration(Connection $db,int $id): array
    {
        $operation=$db->fetch1Row($db->query('SELECT Opers.*,Currs.* FROM Opers JOIN Currs ON cID=ocID WHERE oID=?d FOR UPDATE',[$id]));
        if (!$operation) throw new CallbackValidationException('callback_order_unavailable');
        opDecodeCurrParams($operation,$pay,$sci,$api);
        return $sci;
    }
}
