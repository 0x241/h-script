<?php

declare(strict_types=1);
namespace HScript\Finance;

use HScript\Database\{Connection,TransactionScope};
use HScript\Payment\{PawInvoiceClient,PaymentManager};
use HScript\Payment\Provider\{PawProviderRepository,PawProviderService,PawPayoutSchema};
use HScript\Security\SecretCipher;
use RuntimeException;

/** One committed POST, then authenticated polling of its acknowledged order only. */
final class PawPayout
{
    private static function operation(Connection $db,int $id,bool $lock=false): array
    {
        $o=$db->fetch1Row($db->query('SELECT Opers.*,Currs.* FROM Opers JOIN Currs ON cID=ocID WHERE oID=?d'.($lock?' FOR UPDATE':''),[$id]));
        if(!$o || $o['oOper']!=='CASHOUT' || $o['cCID']!=='PAW')throw new FinancialOperationException('oper_not_found');
        return $o;
    }

    private static function record(Connection $db,string $namespace,int $id): ?array
    {
        $s=$db->link->prepare("SELECT ikOwnerID,ikOperationID,ikResponse FROM IdempotencyKeys WHERE ikNamespace=? AND ikKeyHash=? AND ikState='completed'");
        $s->execute([$namespace,hash('sha256',"0\0".$id)]);$row=$s->fetch();
        if(!$row)return null;
        if((int)$row['ikOwnerID']!==0 || (int)$row['ikOperationID']!==$id)throw new CallbackValidationException('paw_payout_proof_invalid');
        return json_decode($row['ikResponse'],true,32,JSON_THROW_ON_ERROR)['data'];
    }

    private static function prepare(Connection $db,int $id,array $nonce,bool $lock=false): array
    {
        $o=self::operation($db,$id,$lock);
        if((int)$o['oState']!==2 || (int)$o['cDisabled'] || !(int)$o['cProviderSelected'] || !in_array((int)$o['cCASHOUTMode'],[1,2],true)
            || !PawPayoutSchema::ready($db->link,true))throw new FinancialOperationException('oper_disabled');
        $repository=new PawProviderRepository($db);$config=$repository->configuration($o);
        if(!$config['_provider_enabled'])throw new FinancialOperationException('oper_disabled');
        if($config['_provider_environment']!=='live' || str_starts_with($config['key'],'sk_sandbox_'))throw new FinancialOperationException('paw_payout_sandbox_unsupported');
        if(!in_array($o['cCurrID'],PawProviderService::FIATS,true) || $o['cAssetKey']!=='asset:'.$o['cCurrID'])throw new FinancialOperationException('paw_payout_asset_mismatch');
        $params=strToArray($o['oParams']);$dest=strToArray($o['oParams2']);$asset=$params['payout_asset']??null;
        if(!is_string($asset) || !isset(PawPayoutTarget::ASSETS[$asset]) || ($dest['payout_asset']??null)!==$asset
            || !PawPayoutTarget::valid($asset,$dest['acc']??null) || (string)($dest['tag']??'')!=='')throw new FinancialOperationException('paw_payout_destination_required');
        $scale=CallbackContract::scale($o);$gross=CallbackAmount::atScale($o['oSum'],$scale);
        $commission=RequestDecimal::normalize($o['oComis'],true);
        $amount=CallbackAmount::atScale((string)Decimal::of($o['oSum'])->minus(Decimal::of($o['oComis'])),$scale);
        if(Decimal::of($amount)->compareTo(Decimal::of(0))<=0)throw new FinancialOperationException('oper_sum_wrong');
        $fields=['address'=>$dest['acc'],'amount'=>$amount,'fiat_currency'=>$o['cCurrID'],'asset'=>$asset,'ref'=>$nonce['ref'],'fee_bearer'=>'merchant'];
        $p=['version'=>1,'operation'=>$id,'owner'=>(int)$o['ouID'],'currency'=>(int)$o['ocID'],'fiat'=>$o['cCurrID'],'asset_key'=>$o['cAssetKey'],
            'scale'=>$scale,'gross'=>$gross,'commission'=>$commission,'amount'=>$amount,'created'=>$o['oCTS'],
            'provider'=>$config['_provider_id'],'public'=>$config['_provider_public'],'key_version'=>$config['_provider_version'],
            'environment'=>'live','key_hash'=>hash('sha256',$config['key']),'asset'=>$asset,'chain'=>PawPayoutTarget::ASSETS[$asset]['chain'],
            'destination_hash'=>hash('sha256',$dest['acc']),'uniq_id'=>$nonce['uniq_id'],'ref'=>$nonce['ref'],'issued_at'=>$nonce['issued_at'],
            'request_hash'=>IdempotencyRequest::fingerprint($fields),'fee_bearer'=>'merchant'];
        return ['contract'=>$p,'key'=>$config['key'],'fields'=>$fields];
    }

    public static function validRequest(array $capsule): bool
    {
        $p=$capsule['contract']??null;$f=$capsule['fields']??null;$key=$capsule['key']??null;
        return is_array($p) && is_array($f) && is_string($key) && !str_starts_with($key,'sk_sandbox_')
            && ($p['version']??null)===1 && ($p['environment']??null)==='live' && isset(PawPayoutTarget::ASSETS[$p['asset']??''])
            && ($p['key_hash']??null)===hash('sha256',$key) && ($p['request_hash']??null)===IdempotencyRequest::fingerprint($f)
            && ($f['asset']??null)===$p['asset'] && ($f['fiat_currency']??null)===($p['fiat']??null)
            && ($f['amount']??null)===($p['amount']??null) && ($f['fee_bearer']??null)==='merchant'
            && ($f['ref']??null)===($p['ref']??null) && PawPayoutTarget::valid($p['asset'],$f['address']??null)
            && ($p['destination_hash']??null)===hash('sha256',$f['address']);
    }

    public static function original(Connection $db,int $id): array
    {
        $o=self::operation($db,$id);$attempt=self::record($db,'payout:attempt',$id);
        $cipher=strToArray($o['oParams'])['paw_payout']??null;
        if(!is_string($cipher) || ($attempt['capsule_hash']??null)!==hash('sha256',$cipher))throw new CallbackValidationException('paw_payout_proof_missing');
        $plain=SecretCipher::decrypt($cipher,'paw-payout:'.$id);
        if($plain===null)throw new CallbackValidationException('paw_payout_proof_invalid');
        $original=json_decode($plain,true,32,JSON_THROW_ON_ERROR);$p=$original['contract']??null;
        if($p!==($attempt['paw_contract']??null) || !self::validRequest($original))throw new CallbackValidationException('paw_payout_proof_invalid');
        $dest=strToArray($o['oParams2']);$params=strToArray($o['oParams']);
        if($p['operation']!==$id || $p['owner']!==(int)$o['ouID'] || $p['currency']!==(int)$o['ocID'] || $p['provider']!==(int)$o['cProviderID']
            || $p['fiat']!==$o['cCurrID'] || $p['asset_key']!==$o['cAssetKey'] || $p['scale']!==CallbackContract::scale($o)
            || ((int)$o['oState']!==3 && $p['created']!==$o['oCTS']) || $p['gross']!==CallbackAmount::atScale($o['oSum'],$p['scale'])
            || $p['commission']!==RequestDecimal::normalize($o['oComis'],true) || ($params['payout_asset']??null)!==$p['asset']
            || ($dest['payout_asset']??null)!==$p['asset'] || ($dest['acc']??null)!==$original['fields']['address'] || (string)($dest['tag']??'')!=='')throw new CallbackValidationException('paw_payout_intent_mismatch');
        return $original;
    }

    private static function client(?callable $transport,string $method,array $original,mixed $argument=null): array
    {
        if($transport)return $transport($method,$original['key'],$argument);
        $client=new PawInvoiceClient($original['key']);
        return match($method){'assets'=>$client->assets(),'create'=>$client->payout($original['fields'],$original['contract']['uniq_id']),'status'=>$client->payoutStatus($argument)};
    }

    private static function assertUnattempted(Connection $db,int $id): void
    {
        $o=self::operation($db,$id);
        $s=$db->link->prepare("SELECT ikID FROM IdempotencyKeys WHERE ikOperationID=? AND ikNamespace LIKE 'paw:payout:%' LIMIT 1 FOR UPDATE");
        $s->execute([$id]);
        if(array_key_exists('paw_payout',strToArray($o['oParams'])) || $s->fetchColumn()!==false)throw new CallbackValidationException('paw_payout_proof_missing');
    }

    public static function dispatch(Connection $db,int $id,?callable $transport=null): array
    {
        if($db->link->inTransaction())throw new \LogicException('Payout I/O requires a committed intent');
        if(self::record($db,'payout:attempt',$id)!==null)return self::reconcile($db,$id,$transport);
        if(WithdrawalDispatcher::wasAttempted($db,$id))throw new CallbackValidationException('paw_payout_proof_missing');
        $bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&15)|64);$bytes[8]=chr((ord($bytes[8])&63)|128);$hex=bin2hex($bytes);
        $nonce=['uniq_id'=>substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20),
            'ref'=>'hs-'.bin2hex(random_bytes(16)),'issued_at'=>time()];
        $snapshot=self::prepare($db,$id,$nonce);
        PawPayoutTarget::catalog(self::client($transport,'assets',$snapshot),$snapshot['contract']['asset']);
        $attempt=(new IdempotencyStore($db))->execute('payout:attempt',(string)$id,0,IdempotencyRequest::fingerprint(['operation_id'=>$id]),static function()use($db,$id,$nonce,$snapshot):IdempotencyResult{
            $current=self::prepare($db,$id,$nonce,true);
            self::assertUnattempted($db,$id);
            if($current!==$snapshot)throw new FinancialOperationException('paw_payout_configuration_changed');
            $cipher=SecretCipher::encrypt(json_encode($current,JSON_THROW_ON_ERROR),'paw-payout:'.$id);
            if($cipher===null)throw new FinancialOperationException('payout_configuration_invalid');
            $o=self::operation($db,$id);$params=strToArray($o['oParams']);$params['paw_payout']=$cipher;
            $db->update('Opers',['oParams'=>arrayToStr($params)],'','oID=?d',[$id]);
            return new IdempotencyResult(['send'=>true,'paw_contract'=>$current['contract'],'capsule_hash'=>hash('sha256',$cipher)],202,$id);
        });
        if($attempt->replayed)return self::reconcile($db,$id,$transport);
        $original=self::original($db,$id);
        $response=$transport?self::client($transport,'create',$original,['fields'=>$original['fields'],'uniq_id'=>$original['contract']['uniq_id']])
            :(new PaymentManager($db))->processWithdrawal('PAW',['paw_capsule'=>$original]);
        $order=$response['order_id']??null;
        if(!is_string($order) || !preg_match('/^[a-f0-9]{24}$/D',$order))throw new RuntimeException('paw_payout_reconciliation_required');
        (new IdempotencyStore($db))->execute('paw:payout:accepted',(string)$id,0,IdempotencyRequest::fingerprint(['id'=>$order]),static function()use($db,$id,$order):IdempotencyResult{
            self::operation($db,$id,true);$original=self::original($db,$id);
            (new IdempotencyStore($db))->executeInTransaction('paw:payout:order','live:'.$order,0,IdempotencyRequest::fingerprint(['operation'=>$id,'contract'=>$original['contract']]),static fn()=>new IdempotencyResult(['bound'=>true],200,$id));
            return new IdempotencyResult(['order_id'=>$order],200,$id);
        });
        return ['pending'=>true];
    }

    /** No list/ref adoption: the provider does not echo the original fiat request. */
    public static function reconcile(Connection $db,int $id,?callable $transport=null): array
    {
        if($db->link->inTransaction())throw new \LogicException('Payout status requires no transaction');
        $original=self::original($db,$id);$accepted=self::record($db,'paw:payout:accepted',$id);$o=self::operation($db,$id);
        if((int)$o['oState']===3){self::assertCompletion($db,$id);return ['completed'=>true];}
        if((int)$o['oState']!==2 || !$accepted)throw new CallbackValidationException('paw_payout_reconciliation_required');
        self::bound($db,$id,$original,$accepted);
        $check=(new IdempotencyStore($db))->execute('paw:payout:check',$id.':'.intdiv(time(),60),0,IdempotencyRequest::fingerprint(['operation'=>$id]),static fn()=>new IdempotencyResult(['check'=>true],200,$id));
        if($check->replayed)return ['pending'=>true];
        $status=self::client($transport,'status',$original,$accepted['order_id']);
        $receipt=self::validate($original,$accepted['order_id'],$status);
        if($receipt===null)return ['pending'=>true];
        return TransactionScope::run($db->link,static function()use($db,$id,$original,$accepted,$receipt):array{
            $o=self::operation($db,$id,true);$current=self::original($db,$id);
            if($original!==$current)throw new CallbackValidationException('paw_payout_proof_invalid');
            self::bound($db,$id,$current,$accepted);
            $alias='po'.substr(hash('sha256','live:'.$accepted['order_id']),0,37);
            $data=$receipt+['order_id'=>$accepted['order_id'],'batch'=>$alias,'contract_hash'=>IdempotencyRequest::fingerprint($current['contract'])];
            $saved=(new IdempotencyStore($db))->executeInTransaction('paw:payout:receipt',(string)$id,0,IdempotencyRequest::fingerprint($data),static fn()=>new IdempotencyResult($data,200,$id));
            self::assertCompletion($db,$id);
            if((int)$o['oState']===3)return ['completed'=>true];
            if((int)$o['oState']!==2)throw new CallbackValidationException('paw_payout_reconciliation_required');
            CallbackContract::refreshCurrencyContext($o,$current['contract']['scale']);
            $dest=strToArray($o['oParams2']);unset($dest['sum']);
            $dest['txid']=$saved->data['txid'];$dest['crypto_amount']=$saved->data['crypto_amount'];
            $dest['date']=$saved->data['paid_at'];$dest['batch']=$alias;
            FinancialOperationException::check(opOperComplete(-1,$id,$dest));
            return ['completed'=>true];
        });
    }

    public static function validate(array $original,string $order,array $s): ?array
    {
        $p=$original['contract'];$f=$original['fields'];$address=$s['address']??null;
        $same=is_string($address) && ($p['chain']==='eth'?strtolower($address)===strtolower($f['address']):$address===$f['address']);
        if(($s['order_id']??null)!==$order || ($s['ref']??null)!==$p['ref'] || ($s['currency']??null)!==$p['asset']
            || !$same || ($s['fee_bearer']??null)!=='merchant')throw new CallbackValidationException('paw_payout_status_mismatch');
        if(!in_array($s['status']??null,['first_payout_review','pending','processing','success','failed','cancelled'],true))throw new CallbackValidationException('paw_payout_status_mismatch');
        if($s['status']!=='success')return null;
        if(($s['voided']??null)!==false || ($s['voided_at']??null)!==null || ($s['voided_reason']??null)!==null
            || !in_array($s['tx_hashes']??null,[null,[]],true))throw new CallbackValidationException('paw_payout_status_mismatch');
        $values=[];
        foreach(['amount','fiat_amount_usd','debit_usd','fee','fee_usd','energy_fee_usd'] as $field){
            $v=$s[$field]??null;
            if(!is_string($v) || !preg_match('/^(?:0|[1-9][0-9]{0,23})(?:\.[0-9]{1,18})?$/D',$v)
                || (in_array($field,['amount','fiat_amount_usd','debit_usd'],true) && Decimal::of($v)->compareTo(Decimal::of(0))<=0))throw new CallbackValidationException('paw_payout_status_mismatch');
            $values[$field]=$v;
        }
        foreach(['created_at','processed_at'] as $field)if(!is_string($s[$field]??null) || !preg_match('/^[1-9][0-9]{0,9}$/D',$s[$field]) || (int)$s[$field]>time()+300)throw new CallbackValidationException('paw_payout_status_mismatch');
        if((int)$s['created_at']<$p['issued_at']-300 || (int)$s['processed_at']<(int)$s['created_at'])throw new CallbackValidationException('paw_payout_status_mismatch');
        $tx=$s['txid']??null;
        if(!is_string($tx) || !preg_match('/^(?:0x)?[a-fA-F0-9]{64}$/D',$tx))throw new CallbackValidationException('paw_payout_status_mismatch');
        return ['paid_at'=>(int)$s['processed_at'],'txid'=>strtolower(preg_replace('/^0x/','',$tx)),
            'crypto_amount'=>$values['amount'],'provider_values'=>$values];
    }

    private static function bound(Connection $db,int $id,array $original,array $accepted): void
    {
        $order=$accepted['order_id']??null;
        if(!is_string($order) || !preg_match('/^[a-f0-9]{24}$/D',$order))throw new CallbackValidationException('paw_payout_proof_missing');
        $s=$db->link->prepare("SELECT ikOwnerID,ikOperationID,ikFingerprint FROM IdempotencyKeys WHERE ikNamespace='paw:payout:order' AND ikKeyHash=? AND ikState='completed'");
        $s->execute([hash('sha256',"0\0live:".$order)]);$row=$s->fetch();
        if(!$row || (int)$row['ikOwnerID']!==0 || (int)$row['ikOperationID']!==$id || $row['ikFingerprint']!==IdempotencyRequest::fingerprint(['operation'=>$id,'contract'=>$original['contract']]))throw new CallbackValidationException('paw_payout_proof_missing');
    }

    public static function assertCompletion(Connection $db,int $id): void
    {
        $original=self::original($db,$id);$accepted=self::record($db,'paw:payout:accepted',$id);$receipt=self::record($db,'paw:payout:receipt',$id);$o=self::operation($db,$id);
        if(!$accepted || !$receipt)throw new CallbackValidationException('paw_payout_proof_missing');
        self::bound($db,$id,$original,$accepted);
        $seal=$db->link->prepare("SELECT ikFingerprint FROM IdempotencyKeys WHERE ikNamespace='paw:payout:receipt' AND ikKeyHash=? AND ikState='completed'");
        $seal->execute([hash('sha256',"0\0".$id)]);
        if($seal->fetchColumn()!==IdempotencyRequest::fingerprint($receipt))throw new CallbackValidationException('paw_payout_proof_invalid');
        $alias='po'.substr(hash('sha256','live:'.$accepted['order_id']),0,37);
        $dest=strToArray($o['oParams2']);
        if((int)$o['oState']===3 && (($dest['txid']??null)!==($receipt['txid']??null) || ($dest['crypto_amount']??null)!==($receipt['crypto_amount']??null)))throw new CallbackValidationException('paw_payout_proof_invalid');
        if(($receipt['order_id']??null)!==$accepted['order_id'] || ($receipt['contract_hash']??null)!==IdempotencyRequest::fingerprint($original['contract'])
            || ($receipt['batch']??null)!==$alias || !is_int($receipt['paid_at']??null) || !is_string($receipt['txid']??null)
            || !preg_match('/^[a-f0-9]{64}$/D',$receipt['txid']) || ((int)$o['oState']===3 && ($o['oBatch']!==$alias || (string)$o['oCTS']!==(string)timeToStamp($receipt['paid_at']))))throw new CallbackValidationException('paw_payout_proof_invalid');
    }

    public static function saveReceipt(Connection $db,int $id,array $receipt): void
    {
        TransactionScope::run($db->link,static function()use($db,$id,$receipt):void{
            $o=self::operation($db,$id,true);
            if((int)$o['oState']>2 || WithdrawalDispatcher::wasAttempted($db,$id))throw new FinancialOperationException('paw_payout_reconciliation_required');
            $db->update('Opers',['oBatch'=>(int)$o['oState']===2?'?'.$receipt['batch']:'','oParams2'=>arrayToStr($receipt)],'','oID=?d',[$id]);
        });
    }

    /** Fair bounded polling; acknowledged orders survive jobs, restarts and key changes. */
    public static function poll(Connection $db,int $limit=3): void
    {
        if($db->link->inTransaction())throw new \LogicException('Payout polling requires no transaction');
        $limit=max(1,min(5,$limit));
        $rows=$db->fetchRows($db->query("SELECT oID,
            (SELECT COALESCE(MAX(ikCreatedAt),0) FROM IdempotencyKeys WHERE ikOperationID=oID AND ikNamespace IN ('paw:payout:check','paw:payout:poll')) AS lastCheck
            FROM Opers JOIN Currs ON cID=ocID
            WHERE cCID='PAW' AND oOper='CASHOUT' AND oState=2
            AND EXISTS (SELECT 1 FROM IdempotencyKeys WHERE ikOperationID=oID AND ikNamespace='paw:payout:accepted' AND ikState='completed')
            HAVING lastCheck<?d ORDER BY lastCheck,oID LIMIT ?d",[time()-60,$limit]));
        foreach($rows as $row)try {
            $id=(int)$row['oID'];
            // Scheduling metadata is committed before reading financial proofs.
            // A corrupt older order must not monopolize every bounded batch.
            $poll=(new IdempotencyStore($db))->execute('paw:payout:poll',$id.':'.intdiv(time(),60),0,
                IdempotencyRequest::fingerprint(['operation'=>$id]),static fn()=>new IdempotencyResult(['poll'=>true],200,$id));
            if(!$poll->replayed)self::reconcile($db,$id);
        }
        catch(\Throwable) {/* The reservation remains for explicit reconciliation. */}
    }
}
