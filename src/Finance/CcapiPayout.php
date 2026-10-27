<?php

declare(strict_types=1);
namespace HScript\Finance;

use HScript\Database\Connection;
use HScript\Database\TransactionScope;
use HScript\Payment\{CcapiV3Client,PaymentManager};
use HScript\Payment\Gateways\CryptoCurrencyApi;
use HScript\Payment\Provider\ProviderRepository;
use HScript\Payment\Routing\{CcapiWallets,PaymentRoutes,TokenCatalog};
use HScript\Security\SecretCipher;
use RuntimeException;

/** One committed send attempt; queue acceptance never releases the reservation. */
final class CcapiPayout
{
    public static function route(array $currency): ?array
    {
        if(isset(CryptoCurrencyApi::NATIVE[$currency['cCID']])) {
            $asset=CryptoCurrencyApi::NATIVE[$currency['cCID']][1];
            if(($currency['cCurrID']??null)!==$asset || ($currency['cAssetKey']??null)!=='asset:'.$asset)throw new FinancialOperationException('ccapi_payout_asset_mismatch');
            return null;
        }
        $routes=PaymentRoutes::catalog($currency['cCID'],$currency);
        if(count($routes)!==1)throw new FinancialOperationException('ccapi_payout_route_ambiguous');
        $route=array_values($routes)[0];
        $key=$currency['cCID']==='CCT'?TokenCatalog::assetKey($route):'asset:'.$route['asset'];
        if($route['provider']!=='CCAPI' || $route['gateway']!==$currency['cCID'] || ($currency['cCurrID']??null)!==$route['asset'] || ($currency['cAssetKey']??null)!==$key)throw new FinancialOperationException('ccapi_payout_asset_mismatch');
        return $route;
    }

    public static function source(Connection $db,array $currency,?array $route): string
    {
        $chain=$route['network']??CryptoCurrencyApi::NATIVE[$currency['cCID']][0];
        $source=CcapiWallets::read($db,(int)$currency['cProviderID'],$chain)['pnPayoutFrom'];
        // The primary-switch method is a WRITE, not a read-side resolver.
        // An explicit primary or additional address is required before sending.
        if($source==='' || !CcapiWallets::validAddress($chain,$source))throw new FinancialOperationException('ccapi_payout_source_required');
        return $source;
    }

    private static function operation(Connection $db,int $id,bool $lock=false): array
    {
        $o=$db->fetch1Row($db->query('SELECT Opers.*,Currs.* FROM Opers JOIN Currs ON cID=ocID WHERE oID=?d'.($lock?' FOR UPDATE':''),[$id]));
        if(!$o || $o['oOper']!=='CASHOUT' || !CryptoCurrencyApi::supports($o['cCID']))throw new FinancialOperationException('oper_not_found');
        return $o;
    }

    /** Receipt edits and the first worker attempt serialize on the operation. */
    public static function saveReceipt(Connection $db,int $id,array $receipt): void
    {
        TransactionScope::run($db->link,static function()use($db,$id,$receipt):void{
            $o=self::operation($db,$id,true);
            if((int)$o['oState']>2 || WithdrawalDispatcher::wasAttempted($db,$id))throw new FinancialOperationException('ccapi_payout_reconciliation_required');
            $db->update('Opers',['oBatch'=>(int)$o['oState']===2?'?'.$receipt['batch']:'','oParams2'=>arrayToStr($receipt)],'','oID=?d',[$id]);
        });
    }

    private static function prepare(Connection $db,int $id,bool $lock=false): array
    {
        $o=self::operation($db,$id,$lock);
        if((int)$o['oState']!==2 || (int)$o['cDisabled'] || !(int)$o['cProviderSelected'] || !(int)$o['cProviderID'] || !in_array((int)$o['cCASHOUTMode'],[1,2],true))throw new FinancialOperationException('oper_disabled');
        // Settings lock profile -> currencies. The worker locks its currency
        // and reads retained credentials without inverting that lock order.
        $repository=new ProviderRepository($db);$profile=$repository->find((int)$o['cProviderID']);
        if(!$profile || $profile['ppKind']!=='CCAPI' || $profile['ppState']!=='enabled')throw new FinancialOperationException('oper_disabled');
        $credentials=$repository->credentials($profile,(int)$o['cProviderKeyVersion']);
        $config=['protocol'=>'3','v3_key'=>$credentials['v3_key']];
        $route=self::route($o);$chain=$route['network']??CryptoCurrencyApi::NATIVE[$o['cCID']][0];
        $destination=strToArray($o['oParams2']);$to=$destination['acc']??null;
        if(!is_string($to) || !CcapiWallets::validAddress($chain,$to) || (string)($destination['tag']??'')!=='')throw new FinancialOperationException('ccapi_payout_destination_invalid');
        $source=self::source($db,$o,$route);$scale=CallbackContract::scale($o);
        $gross=CallbackAmount::atScale($o['oSum'],$scale);$commission=RequestDecimal::normalize($o['oComis'],true);
        $amount=CallbackAmount::atScale((string)Decimal::of($o['oSum'])->minus(Decimal::of($o['oComis'])),$scale);
        $callback=fullURL(ltrim(moduleToLink('balance/status'),'/')).'?'.http_build_query(['its'=>'CCAPI_OUT','operation'=>$id,'provider'=>$profile['ppPublicID'],'version'=>(int)$o['cProviderKeyVersion']],'','&',PHP_QUERY_RFC3986);
        $uniq='hscript-out:'.hash('sha256',$callback."\0".$profile['ppPublicID']."\0".$chain."\0".$id);
        $arguments=['to'=>$to,'amount'=>$amount,'label'=>(string)$id,'uniqID'=>$uniq,'statusURL'=>$callback,'from'=>$source];
        if($route!==null)$arguments['token']=$route['asset'];
        // Pure validation must precede the durable attempt; no network call.
        CcapiV3Client::options($chain,'send',$config,$arguments);
        $contract=['version'=>1,'operation'=>$id,'owner'=>(int)$o['ouID'],'currency'=>(int)$o['ocID'],'gateway'=>$o['cCID'],'asset'=>$o['cCurrID'],
            'asset_key'=>$o['cAssetKey'],'scale'=>$scale,'gross'=>$gross,'commission'=>$commission,'amount'=>$amount,
            'created'=>$o['oCTS'],'provider'=>(int)$profile['ppID'],'public'=>$profile['ppPublicID'],'key_version'=>(int)$o['cProviderKeyVersion'],
            'key_hash'=>hash('sha256',$config['v3_key']),'chain'=>$chain,'route'=>$route,'source_hash'=>hash('sha256',$source),'destination_hash'=>hash('sha256',$to),'uniq_id'=>$uniq];
        return ['contract'=>$contract,'config'=>$config,'arguments'=>$arguments];
    }

    /** Closed gateway input built only from the original committed capsule. */
    public static function validRequest(string $gateway,array $request): bool
    {
        $proof=$request['ccapi_contract']??null;$config=$request['from']??null;$args=$request['ccapi_arguments']??null;
        return is_array($proof) && is_array($config) && is_array($args) && ($proof['version']??null)===1 && ($proof['gateway']??null)===$gateway
            && ($request['ccapi_chain']??null)===($proof['chain']??null) && CcapiV3Client::configured($config)
            && ($proof['key_hash']??null)===hash('sha256',$config['v3_key']) && ($args['label']??null)===(string)($proof['operation']??'')
            && ($args['uniqID']??null)===($proof['uniq_id']??null) && ($args['amount']??null)===($proof['amount']??null)
            && is_string($args['to']??null) && ($proof['destination_hash']??null)===hash('sha256',$args['to'])
            && is_string($args['from']??null) && ($proof['source_hash']??null)===hash('sha256',$args['from'])
            && ($args['token']??null)===($proof['route']['asset']??null);
    }

    private static function record(Connection $db,string $namespace,int $id): ?array
    {
        $s=$db->link->prepare("SELECT ikOwnerID,ikOperationID,ikResponse FROM IdempotencyKeys WHERE ikNamespace=? AND ikKeyHash=? AND ikState='completed'");
        $s->execute([$namespace,hash('sha256',"0\0".$id)]);$row=$s->fetch();
        if(!$row)return null;
        if((int)$row['ikOwnerID']!==0 || (int)$row['ikOperationID']!==$id)throw new CallbackValidationException('ccapi_payout_proof_invalid');
        return json_decode($row['ikResponse'],true,32,JSON_THROW_ON_ERROR)['data'];
    }

    public static function original(Connection $db,int $id): array
    {
        $o=self::operation($db,$id);$attempt=self::record($db,'payout:attempt',$id);
        $cipher=strToArray($o['oParams'])['ccapi_payout']??null;
        if(!is_array($attempt['ccapi_contract']??null) || !is_string($cipher) || ($attempt['capsule_hash']??null)!==hash('sha256',$cipher))throw new CallbackValidationException('ccapi_payout_proof_missing');
        $plain=SecretCipher::decrypt($cipher,'ccapi-payout:'.$id);
        if($plain===null)throw new CallbackValidationException('ccapi_payout_proof_invalid');
        $capsule=json_decode($plain,true,32,JSON_THROW_ON_ERROR);$p=$capsule['contract']??null;
        if($p!==$attempt['ccapi_contract'] || !self::validRequest($o['cCID'],['ccapi_contract'=>$p,'from'=>$capsule['config'],'ccapi_arguments'=>$capsule['arguments'],'ccapi_chain'=>$p['chain']]))throw new CallbackValidationException('ccapi_payout_proof_invalid');
        $dest=strToArray($o['oParams2']);
        if($p['operation']!==$id || $p['owner']!==(int)$o['ouID'] || $p['currency']!==(int)$o['ocID'] || $p['provider']!==(int)$o['cProviderID']
            || $p['asset']!==$o['cCurrID'] || $p['asset_key']!==$o['cAssetKey'] || $p['scale']!==CallbackContract::scale($o) || ((int)$o['oState']!==3 && $p['created']!==$o['oCTS'])
            || $p['gross']!==CallbackAmount::atScale($o['oSum'],$p['scale']) || $p['commission']!==RequestDecimal::normalize($o['oComis'],true)
            || !is_string($dest['acc']??null) || $p['destination_hash']!==hash('sha256',$dest['acc']) || (string)($dest['tag']??'')!=='')throw new CallbackValidationException('ccapi_payout_intent_mismatch');
        return $capsule;
    }

    private static function client(?callable $transport,string $chain,string $method,array $config,array $arguments): array
    {
        return $transport ? $transport($chain,$method,$config,$arguments) : CcapiV3Client::request($chain,$method,$config,$arguments);
    }

    /** A partial restore must never turn a previously issued intent into a send. */
    private static function assertUnattempted(Connection $db,int $id): void
    {
        $o=self::operation($db,$id);
        $s=$db->link->prepare("SELECT ikID FROM IdempotencyKeys WHERE ikOperationID=? AND ikNamespace LIKE 'ccapi:payout:%' LIMIT 1");
        $s->execute([$id]);
        if(array_key_exists('ccapi_payout',strToArray($o['oParams'])) || $s->fetchColumn()!==false)throw new CallbackValidationException('ccapi_payout_proof_missing');
    }

    public static function dispatch(Connection $db,int $id,?callable $transport=null): array
    {
        if($db->link->inTransaction())throw new \LogicException('Payout I/O requires committed intent');
        if(self::record($db,'payout:attempt',$id)!==null)return self::reconcile($db,$id,$transport);
        if(WithdrawalDispatcher::wasAttempted($db,$id))throw new CallbackValidationException('ccapi_payout_proof_missing');
        self::assertUnattempted($db,$id);
        $snapshot=self::prepare($db,$id);$p=$snapshot['contract'];
        if($p['route']!==null){
            $tokens=self::client($transport,$p['chain'],'tokens',$snapshot['config'],[])['tokens']??[];$match=false;
            foreach($tokens as $token)if($token['asset']===$p['route']['asset'] && $token['contract']===$p['route']['contract'])$match=true;
            if(!$match)throw new FinancialOperationException('ccapi_payout_token_changed');
        }
        $store=new IdempotencyStore($db);$fingerprint=IdempotencyRequest::fingerprint(['operation_id'=>$id]);
        $attempt=$store->execute('payout:attempt',(string)$id,0,$fingerprint,static function()use($db,$id,$snapshot):IdempotencyResult{
            $current=self::prepare($db,$id,true);
            self::assertUnattempted($db,$id);
            if($snapshot!==$current)throw new FinancialOperationException('ccapi_payout_configuration_changed');
            $cipher=SecretCipher::encrypt(json_encode($current,JSON_THROW_ON_ERROR),'ccapi-payout:'.$id);
            if($cipher===null)throw new FinancialOperationException('payout_configuration_invalid');
            $o=self::operation($db,$id);$params=strToArray($o['oParams']);$params['ccapi_payout']=$cipher;
            $db->update('Opers',['oParams'=>arrayToStr($params)],'','oID=?d',[$id]);
            return new IdempotencyResult(['send'=>true,'ccapi_contract'=>$current['contract'],'capsule_hash'=>hash('sha256',$cipher)],202,$id);
        });
        if($attempt->replayed)return self::reconcile($db,$id,$transport);
        // Re-read the committed proof before I/O, never reconstruct it from settings.
        $original=self::original($db,$id);$p=$original['contract'];
        if($transport)$response=self::client($transport,$p['chain'],'send',$original['config'],$original['arguments']);
        else $response=(new PaymentManager($db))->processWithdrawal($p['gateway'],['from'=>$original['config'],'ccapi_chain'=>$p['chain'],'ccapi_arguments'=>$original['arguments'],'ccapi_contract'=>$p]);
        if(!is_string($response['queue_id']??null) || !preg_match('/^[1-9][0-9]{0,19}$/D',$response['queue_id']))throw new RuntimeException('ccapi_payout_reconciliation_required');
        self::accept($db,$id,$response['queue_id']);
        return ['pending'=>true];
    }

    private static function accept(Connection $db,int $id,string $queue): void
    {
        (new IdempotencyStore($db))->execute('ccapi:payout:accepted',(string)$id,0,IdempotencyRequest::fingerprint(['id'=>$queue]),static function()use($db,$id,$queue):IdempotencyResult{
            self::operation($db,$id,true);$original=self::original($db,$id);$p=$original['contract'];
            // Queue ids are scoped to the original provider and chain, not a CID.
            (new IdempotencyStore($db))->executeInTransaction('ccapi:payout:order',$p['public'].':'.$p['chain'].':'.$queue,0,IdempotencyRequest::fingerprint(['operation'=>$id]),static fn()=>new IdempotencyResult(['bound'=>true],200,$id));
            return new IdempotencyResult(['queue_id'=>$queue],200,$id);
        });
    }

    /** Diagnostic recovery is read-only at the provider; it never calls send. */
    public static function reconcile(Connection $db,int $id,?callable $transport=null): array
    {
        if($db->link->inTransaction())throw new \LogicException('Status I/O requires no transaction');
        $original=self::original($db,$id);$p=$original['contract'];$o=self::operation($db,$id);
        if((int)$o['oState']===3){self::assertCompletion($db,$id);return ['completed'=>true];}
        if((int)$o['oState']!==2)throw new CallbackValidationException('ccapi_payout_state_invalid');
        $check=(new IdempotencyStore($db))->execute('ccapi:payout:check',$id.':'.intdiv(time(),60),0,IdempotencyRequest::fingerprint(['operation'=>$id]),static fn()=>new IdempotencyResult(['check'=>true],200,$id));
        if($check->replayed)return ['pending'=>true];
        $s=self::client($transport,$p['chain'],'status',$original['config'],['uniqID'=>$p['uniq_id']]);
        if(!is_string($s['id']??null) || !preg_match('/^[1-9][0-9]{0,19}$/D',$s['id']) || ($s['label']??null)!==(string)$id
            || !self::sameAddress($p['chain'],$s['to']??null,$original['arguments']['to']) || !self::sameAddress($p['chain'],$s['from']??null,$original['arguments']['from'])
            || ($s['token']??'')!==($p['route']['asset']??'') || CallbackAmount::atScale($s['amount']??null,$p['scale'])!==$p['amount'])throw new CallbackValidationException('ccapi_payout_status_mismatch');
        self::accept($db,$id,$s['id']);
        $early=self::record($db,'ccapi:payout:early:'.$s['id'],$id);
        if($early!==null){
            $raw=SecretCipher::decrypt($early['event']??'','ccapi-payout-event:'.$id.':'.$s['id']);
            if($raw===null)throw new CallbackValidationException('ccapi_payout_proof_invalid');
            return self::receive($db,$id,$raw,$p['public'],$p['key_version']);
        }
        // Status has neither tokenContract nor destination-tag proof. Token
        // finality always requires the signed out notification with its contract.
        if($p['route']!==null || !in_array($s['state']??null,['6','7'],true))return ['pending'=>true];
        if(!CcapiV3Client::transaction($s['txid']??null))throw new CallbackValidationException('ccapi_payout_status_mismatch');
        return self::settle($db,$id,['ccapi_payout_id'=>$s['id'],'ccapi_chain'=>$p['chain'],'curr'=>$p['asset'],'sum'=>$s['amount'],
            'accto'=>$s['to'],'accfrom'=>$s['from'],'batch'=>$s['txid'].'#0','ccapi_key_hash'=>$p['key_hash'],'outbound'=>true,'wait'=>false]);
    }

    private static function sameAddress(string $chain,mixed $a,string $b): bool
    {
        if(!is_string($a))return false;
        if(in_array($chain,['ethereum','polygon','bsc'],true))return strtolower($a)===strtolower($b);
        return CcapiV3Client::sameAddress($chain,$a,$b);
    }

    private static function transactionIdentity(string $chain,string $id): string
    {
        // Base58/base64 transaction identifiers are case-sensitive.
        if(!in_array($chain,['ton','solana'],true) && preg_match('/^(?:0x)?([a-fA-F0-9]{64})$/D',$id,$m))return strtolower($m[1]);
        return $id;
    }

    public static function receive(Connection $db,int $id,string $raw,string $public,int $version): array
    {
        $original=self::original($db,$id);$p=$original['contract'];
        if($public!==$p['public'] || $version!==$p['key_version'])throw new CallbackValidationException('ccapi_payout_profile_mismatch');
        $verified=(new PaymentManager($db))->handleCallback($p['gateway'],['_raw_body'=>$raw],$original['config']+['_ccapi_payout_context'=>true]);
        if(($verified['correct']??false)!==true || ($verified['authenticated']??false)!==true || ($verified['outbound']??false)!==true || ($verified['tag']??null)!==(string)$id)throw new CallbackValidationException('ccapi_payout_authentication_required');
        if(self::record($db,'ccapi:payout:accepted',$id)===null){
            self::validate($original,$verified);
            if(empty($verified['wait'])) {
                $queue=$verified['ccapi_payout_id'];
                $cipher=SecretCipher::encrypt($raw,'ccapi-payout-event:'.$id.':'.$queue);
                if($cipher===null)throw new CallbackValidationException('ccapi_payout_proof_invalid');
                // Preserve early terminal evidence without claiming a provider
                // order or moving funds. Recovery must first bind status(uniqID).
                (new IdempotencyStore($db))->execute('ccapi:payout:early:'.$queue,(string)$id,0,
                    IdempotencyRequest::fingerprint(['event'=>$verified['ccapi_event_hash']]),static function()use($db,$id,$cipher):IdempotencyResult{
                        $o=self::operation($db,$id,true);self::original($db,$id);
                        $s=$db->link->prepare("SELECT COUNT(*) FROM IdempotencyKeys WHERE ikOperationID=? AND ikNamespace LIKE 'ccapi:payout:early:%'");$s->execute([$id]);
                        if((int)$s->fetchColumn()>=8)throw new CallbackValidationException('ccapi_payout_reconciliation_required');
                        return new IdempotencyResult(['event'=>$cipher],200,$id);
                    });
            }
            throw new CallbackValidationException('ccapi_payout_ack_pending');
        }
        return self::settle($db,$id,$verified);
    }

    private static function validate(array $original,array $v): void
    {
        $p=$original['contract'];
        if(($v['ccapi_chain']??null)!==$p['chain'] || ($v['curr']??null)!==$p['asset'] || ($v['ccapi_key_hash']??null)!==$p['key_hash']
            || CallbackAmount::atScale($v['sum']??null,$p['scale'])!==$p['amount']
            || !self::sameAddress($p['chain'],$v['accto']??null,$original['arguments']['to']) || !self::sameAddress($p['chain'],$v['accfrom']??null,$original['arguments']['from'])
            || ($p['route']!==null && ($v['ccapi_route_hash']??null)!==PaymentRoutes::hash($p['route'])))throw new CallbackValidationException('ccapi_payout_mismatch');
        $created=stampToTime($p['created']);$signed=$v['signed_at']??null;
        if($signed!==null && (!is_int($signed) || $signed<$created-300 || $signed>time()+300))throw new CallbackValidationException('callback_time_outside_window');
    }

    private static function settle(Connection $db,int $id,array $v): array
    {
        return TransactionScope::run($db->link,static function()use($db,$id,$v):array{
            $o=self::operation($db,$id,true);$original=self::original($db,$id);$p=$original['contract'];
            $accepted=self::record($db,'ccapi:payout:accepted',$id);
            if($accepted===null)throw new CallbackValidationException('ccapi_payout_ack_pending');
            if(($v['ccapi_payout_id']??null)!==$accepted['queue_id'])throw new CallbackValidationException('ccapi_payout_mismatch');
            self::validate($original,$v);$signed=$v['signed_at']??null;
            $batch=$v['batch']??null;
            if(!is_string($batch) || strlen($batch)>200 || !preg_match('/^(.+)#([0-9]{1,10})$/D',$batch,$parts) || !CcapiV3Client::transaction($parts[1]))throw new CallbackValidationException('ccapi_payout_mismatch');
            $transaction=self::transactionIdentity($p['chain'],$parts[1]);
            $fingerprint=IdempotencyRequest::fingerprint(['operation'=>$id,'queue'=>$accepted['queue_id'],'txid'=>$transaction]);
            (new IdempotencyStore($db))->executeInTransaction('ccapi:payout:transaction',(string)$id,0,$fingerprint,static fn()=>new IdempotencyResult(['batch'=>$batch],200,$id));
            if(!empty($v['wait']))return ['pending'=>true];
            $alias='co'.substr(hash('sha256',$p['public'].':'.$p['chain'].':'.$accepted['queue_id']),0,37);
            $receipt=(new IdempotencyStore($db))->executeInTransaction('ccapi:payout:receipt',(string)$id,0,$fingerprint,static fn()=>new IdempotencyResult(['queue_id'=>$accepted['queue_id'],'batch'=>$alias,'paid_at'=>$signed??time(),'transaction_hash'=>hash('sha256',$transaction),'contract_hash'=>IdempotencyRequest::fingerprint($p)],200,$id));
            self::assertCompletion($db,$id);
            if((int)$o['oState']===3){if($o['oBatch']!==$receipt->data['batch'])throw new CallbackValidationException('ccapi_payout_proof_invalid');return ['completed'=>true];}
            if((int)$o['oState']!==2)throw new CallbackValidationException('ccapi_payout_state_invalid');
            CallbackContract::refreshCurrencyContext($o,$p['scale']);
            $dest=strToArray($o['oParams2']);unset($dest['sum']);$dest['date']=$receipt->data['paid_at'];$dest['batch']=$receipt->data['batch'];$dest['txid']=$parts[1];
            FinancialOperationException::check(opOperComplete(-1,$id,$dest));
            return ['completed'=>true];
        });
    }

    /** Admin receipt fields or a cached state3 cannot substitute provider proof. */
    public static function assertCompletion(Connection $db,int $id): void
    {
        $original=self::original($db,$id);$receipt=self::record($db,'ccapi:payout:receipt',$id);$accepted=self::record($db,'ccapi:payout:accepted',$id);
        if(!$receipt || !$accepted || ($receipt['queue_id']??null)!==($accepted['queue_id']??null) || ($receipt['contract_hash']??null)!==IdempotencyRequest::fingerprint($original['contract']))throw new CallbackValidationException('ccapi_payout_reconciliation_required');
        $o=self::operation($db,$id);
        $alias='co'.substr(hash('sha256',$original['contract']['public'].':'.$original['contract']['chain'].':'.$accepted['queue_id']),0,37);
        if(($receipt['batch']??null)!==$alias || !is_int($receipt['paid_at']??null) || ((int)$o['oState']===3 && ((string)$o['oCTS']!==(string)timeToStamp($receipt['paid_at']) || $o['oBatch']!==$alias)))throw new CallbackValidationException('ccapi_payout_proof_invalid');
        $p=$original['contract'];$key=$p['public'].':'.$p['chain'].':'.$accepted['queue_id'];
        $s=$db->link->prepare("SELECT ikOwnerID,ikOperationID,ikFingerprint FROM IdempotencyKeys WHERE ikNamespace='ccapi:payout:order' AND ikKeyHash=? AND ikState='completed'");
        $s->execute([hash('sha256',"0\0".$key)]);$bound=$s->fetch();
        $transaction=self::record($db,'ccapi:payout:transaction',$id);
        if(!$bound || (int)$bound['ikOwnerID']!==0 || (int)$bound['ikOperationID']!==$id || $bound['ikFingerprint']!==IdempotencyRequest::fingerprint(['operation'=>$id])
            || !is_string($transaction['batch']??null) || !preg_match('/^(.+)#[0-9]+$/D',$transaction['batch'],$parts)
            || ($receipt['transaction_hash']??null)!==hash('sha256',self::transactionIdentity($p['chain'],$parts[1])))throw new CallbackValidationException('ccapi_payout_proof_missing');
    }
}
