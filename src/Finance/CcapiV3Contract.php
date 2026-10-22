<?php

declare(strict_types=1);
namespace HScript\Finance;

use HScript\Database\Connection;
use HScript\Database\TransactionScope;
use HScript\Payment\CcapiV3Client;
use HScript\Payment\Gateways\CryptoCurrencyApi;
use JsonException;
use HScript\Payment\Routing\PaymentRoutes;

/** Native v3 input is bound to the original give receiver and single label. */
final class CcapiV3Contract
{
    public const PROFILE='native-v3-v1';
    public const TOKEN_PROFILE='token-v3-v1';
    private const METADATA=['ccapi_profile','ccapi_address','ccapi_chain','ccapi_asset','ccapi_label','ccapi_scale','ccapi_gross','ccapi_key_hash','ccapi_request_id'];

    /** Legacy oBatch is varchar(40), including its pending '?' prefix.
     * The full txid#pos remains the permanent registry identity; this alias
     * is only legacy storage. A collision must refuse a second money effect.
     */
    public static function batchAlias(string $batch): string
    {
        if (!preg_match('/^([A-Za-z0-9_+\/=\-]{32,128})#(?:0|[1-9][0-9]{0,9})$/D',$batch))
            throw new CallbackValidationException('callback_ccapi_proof_mismatch');
        return 'c3'.substr(hash('sha256',$batch),0,37);
    }

    public static function invoiceHash(array $metadata): string
    {
        $fields=[];
        foreach (array_merge(self::METADATA,($metadata['ccapi_profile']??null)===self::TOKEN_PROFILE?['ccapi_route_hash','ccapi_native_asset','ccapi_token_contract']:[]) as $field) {
            if (!is_string($metadata[$field] ?? null)) throw new CallbackValidationException('callback_invoice_unavailable');
            $fields[$field]=$metadata[$field];
        }
        if(array_key_exists('ccapi_forward_to',$metadata)){
            if(!is_string($metadata['ccapi_forward_to']) || ($metadata['ccapi_forward_to']!=='' && !\HScript\Payment\Routing\CcapiWallets::validAddress($metadata['ccapi_chain'],$metadata['ccapi_forward_to'])))throw new CallbackValidationException('payment_wallet_proof_invalid');
            $fields['ccapi_forward_to']=$metadata['ccapi_forward_to'];
        }
        return hash('sha256',json_encode($fields,JSON_THROW_ON_ERROR));
    }

    public static function invoice(array $operation,?array $form,array $sci): array
    {
        if ($form===null || !in_array($form['ccapi_profile'] ?? null,[self::PROFILE,self::TOKEN_PROFILE],true)) throw new CallbackValidationException('callback_invoice_unavailable');
        $hash=self::invoiceHash($form);
        if (!is_string($form['ccapi_invoice_hash'] ?? null) || !hash_equals($hash,$form['ccapi_invoice_hash'])
            || !preg_match('/^[a-f0-9]{64}$/D',$form['ccapi_key_hash']) || !CcapiV3Client::address($form['ccapi_address'])
            || !preg_match('/^[0-8]$/D',$form['ccapi_scale']) || !preg_match('/^[1-9][0-9]{0,9}$/D',$form['ccapi_label'])
            || $form['ccapi_label']!==(string)$operation['oID']
            || !preg_match('/^hscript:[a-f0-9]{64}:'.preg_quote($form['ccapi_label'],'/').'$/D',$form['ccapi_request_id'])) throw new CallbackValidationException('callback_invoice_identity_mismatch');
        $token=isset(PaymentRoutes::GATEWAYS[$operation['cCID']]);
        if($token) {
            $route=PaymentRoutes::assertSnapshot($operation['cCID'],$form['payment_route']??null);
            if($form['ccapi_profile']!==self::TOKEN_PROFILE || $form['ccapi_route_hash']!==PaymentRoutes::hash($route) || $form['ccapi_native_asset']!==$route['wire'] || $form['ccapi_token_contract']!==$route['contract'] || ($form['payment_network']??null)!==$route['network'])throw new CallbackValidationException('payment_route_proof_invalid');
            [$chain,$asset]=[$route['network'],$route['asset']];
        } else {if($form['ccapi_profile']!==self::PROFILE)throw new CallbackValidationException('payment_route_proof_invalid');[$chain,$asset]=CryptoCurrencyApi::NATIVE[$operation['cCID']];}
        if ($form['ccapi_chain']!==$chain || $form['ccapi_asset']!==$asset || $operation['cCurrID']!==$asset)
            throw new CallbackValidationException('callback_currency_mismatch');
        if (!CcapiV3Client::configured($sci) || !hash_equals($form['ccapi_key_hash'],hash('sha256',$sci['v3_key'])))
            throw new CallbackValidationException('callback_merchant_configuration_changed');
        if (CallbackAmount::atScale($form['ccapi_gross'],(int)$form['ccapi_scale'])!==$form['ccapi_gross'])
            throw new CallbackValidationException('callback_invoice_amount_mismatch');
        if (($form['formkind'] ?? null)!=='native_address' || ($form['payment_address'] ?? null)!==$form['ccapi_address']
            || ($form['payment_asset'] ?? null)!==$form['ccapi_asset'] || ($form['payment_amount'] ?? null)!==$form['ccapi_gross'])
            throw new CallbackValidationException('callback_invoice_identity_mismatch');
        return $form;
    }

    public static function validate(Connection $db,array $operation,array $verified,?array $form,array $sci,int $scale,string $expected,int $now,bool $first): array
    {
        $form=self::invoice($operation,$form,$sci);
        if ((int)$form['ccapi_scale']!==$scale) throw new CallbackValidationException('callback_precision_configuration_changed');
        if ($form['ccapi_gross']!==$expected) throw new CallbackValidationException('callback_invoice_amount_mismatch');
        if (isset($form['payment_route']) ? (PaymentRoutes::address($form['payment_route'],$form['ccapi_address'])===null || PaymentRoutes::address($form['payment_route'],$form['ccapi_address'])!==PaymentRoutes::address($form['payment_route'],(string)($verified['accto']??''))) : !CcapiV3Client::sameAddress($form['ccapi_chain'],$form['ccapi_address'],(string)($verified['accto'] ?? ''))) throw new CallbackValidationException('callback_destination_mismatch');
        if (($verified['ccapi_chain'] ?? null)!==$form['ccapi_chain']) throw new CallbackValidationException('callback_currency_mismatch');
        if (($verified['ccapi_profile'] ?? null)!==$form['ccapi_profile'] || ($verified['ccapi_key_hash'] ?? null)!==$form['ccapi_key_hash'])
            throw new CallbackValidationException('callback_ccapi_proof_mismatch');
        if(isset($form['ccapi_route_hash']) && ($verified['ccapi_route_hash']??null)!==$form['ccapi_route_hash'])throw new CallbackValidationException('payment_route_mismatch');
        self::verified($verified);
        self::originalEvent($db,$operation,$verified);
        $created=stampToTime($operation['oCTS']); $date=$verified['signed_at'];
        if (!$created || ($first && ($date>$now+CallbackContract::CLOCK_SKEW || $date<$created-CallbackContract::CLOCK_SKEW)))
            throw new CallbackValidationException('callback_time_outside_window');
        // pos identifies a separate event, not permission to reuse one invoice.
        // Reject it before committing an orphan identity for a completed order.
        if (in_array((int)$operation['oState'],[2,3],true) && (string)$operation['oBatch']!==''
            && ltrim((string)$operation['oBatch'],'?')!==self::batchAlias($verified['batch']))
            throw new CallbackValidationException('callback_invoice_identity_mismatch');
        // Bind the exact authenticated callback representation after checking
        // canonical equality with the immutable give receiver above.
        return ['destination'=>$verified['accto'],'signed_at'=>$date,'ccapi_profile'=>$form['ccapi_profile'],'ccapi_route_hash'=>$form['ccapi_route_hash']??null,
            'ccapi_invoice_hash'=>$form['ccapi_invoice_hash'],'ccapi_key_hash'=>$form['ccapi_key_hash'],
            'ccapi_batch_hash'=>hash('sha256',$verified['batch']),
            'ccapi_chain'=>$form['ccapi_chain'],'ccapi_event_hash'=>$verified['ccapi_event_hash'],
            'ccapi_position'=>$verified['ccapi_position'],'ccapi_network_fee'=>$verified['ccapi_network_fee']];
    }

    /** The first full signed event, never its shortened SQL alias, is authority. */
    private static function originalEvent(Connection $db,array $operation,array $verified): void
    {
        // A namespace scan can lock other orders' pending callback claims
        // while this transaction owns the shared currency row. Pin the narrow
        // operation index to prevent that lock inversion on MariaDB.
        $statement=$db->link->prepare("SELECT ikResponse FROM IdempotencyKeys FORCE INDEX (ix_idempotency_operation) WHERE ikNamespace=? AND ikOperationID=? AND ikState='completed' FOR UPDATE");
        $statement->execute(['gateway:'.strtolower($operation['cCID']).':currency:'.$operation['ocID'].':identity',(int)$operation['oID']]);
        $accepted=false;
        while ($row=$statement->fetch()) {
            if (!is_string($row['ikResponse'])) throw new CallbackValidationException('callback_ccapi_binding_missing');
            try { $response=json_decode($row['ikResponse'],true,32,JSON_THROW_ON_ERROR); }
            catch (JsonException) { throw new CallbackValidationException('callback_ccapi_binding_missing'); }
            $proof=$response['data']['contract'] ?? [];
            if (!is_array($proof) || ($proof['ccapi_profile'] ?? null)!==($verified['ccapi_profile']??null) || !is_string($proof['ccapi_event_hash'] ?? null)
                || !preg_match('/^[a-f0-9]{64}$/D',$proof['ccapi_event_hash']) || !is_string($proof['ccapi_position'] ?? null)
                || !is_string($proof['ccapi_batch_hash'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D',$proof['ccapi_batch_hash']))
                throw new CallbackValidationException('callback_ccapi_binding_missing');
            if (!hash_equals($proof['ccapi_event_hash'],$verified['ccapi_event_hash']) || $proof['ccapi_position']!==$verified['ccapi_position']
                || !hash_equals($proof['ccapi_batch_hash'],hash('sha256',$verified['batch'])))
                throw new CallbackValidationException('callback_invoice_identity_mismatch');
            $accepted=true;
        }
        if (!$accepted && in_array((int)$operation['oState'],[2,3],true))
            throw new CallbackValidationException('callback_ccapi_binding_missing');
    }

    private static function verified(array $verified): void
    {
        foreach (['ccapi_key_hash','ccapi_event_hash'] as $field)
            if (!is_string($verified[$field] ?? null) || !preg_match('/^[a-f0-9]{64}$/D',$verified[$field])) throw new CallbackValidationException('callback_ccapi_proof_mismatch');
        if (!is_int($verified['signed_at'] ?? null) || $verified['signed_at']<1 || !is_string($verified['ccapi_position'] ?? null)
            || !preg_match('/^(?:0|[1-9][0-9]{0,9})$/D',$verified['ccapi_position']) || !is_string($verified['ccapi_network_fee'] ?? null)
            || CcapiV3Client::networkFee($verified['ccapi_network_fee'])!==$verified['ccapi_network_fee']
            || !is_string($verified['batch'] ?? null) || !preg_match('/^[A-Za-z0-9_+\/=\-]{32,128}#'.preg_quote($verified['ccapi_position'],'/').'$/D',$verified['batch']))
            throw new CallbackValidationException('callback_ccapi_proof_mismatch');
    }

    public static function assertBinding(array $proof,array $verified): void
    {
        self::verified($verified);
        if (!in_array($proof['ccapi_profile'] ?? null,[self::PROFILE,self::TOKEN_PROFILE],true) || !is_string($proof['ccapi_invoice_hash'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D',$proof['ccapi_invoice_hash']) || !is_string($proof['ccapi_batch_hash'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D',$proof['ccapi_batch_hash'])) throw new CallbackValidationException('callback_ccapi_binding_missing');
        if(($proof['ccapi_profile']??null)!==($verified['ccapi_profile']??null) || ($proof['ccapi_route_hash']??null)!==($verified['ccapi_route_hash']??null))throw new CallbackValidationException('payment_route_mismatch');
        if (!hash_equals($proof['ccapi_batch_hash'],hash('sha256',$verified['batch'])))
            throw new CallbackValidationException('callback_ccapi_proof_mismatch');
        foreach (['ccapi_key_hash','ccapi_event_hash','ccapi_chain','ccapi_position','ccapi_network_fee'] as $field)
            if (!is_string($proof[$field] ?? null) || !hash_equals($proof[$field],(string)($verified[$field] ?? '')))
                throw new CallbackValidationException('callback_ccapi_proof_mismatch');
    }

    /** Even cached replies need the same encrypted invoice/current v3 key. */
    public static function assertReplay(Connection $db,int $operationId,array $proof,array $verified): void
    {
        self::assertBinding($proof,$verified);
        TransactionScope::run($db->link,static function () use ($db,$operationId,$proof): void {
            $operation=$db->fetch1Row($db->query('SELECT Opers.*,Currs.* FROM Opers JOIN Currs ON cID=ocID WHERE oID=?d FOR UPDATE',[$operationId]));
            if (!$operation || !CryptoCurrencyApi::supports($operation['cCID'])) throw new CallbackValidationException('callback_ccapi_binding_missing');
            opDecodeCurrParams($operation,$pay,$sci,$api);
            \HScript\Payment\Routing\CcapiWallets::assertInvoice($db,$operation,DepositCheckout::storedForm($operation)??[]);
            $form=self::invoice($operation,DepositCheckout::storedForm($operation),$sci);
            if (!hash_equals($proof['ccapi_invoice_hash'],$form['ccapi_invoice_hash'])) throw new CallbackValidationException('callback_ccapi_proof_mismatch');
            $attempt=InboundIntent::attemptProof($db,$operation);
            if ($attempt===null) throw new CallbackValidationException('callback_invoice_intent_missing');
            InboundIntent::assertForm($attempt,$form);
            if ($attempt!==($proof['inbound_intent'] ?? null)) throw new CallbackValidationException('callback_ccapi_proof_mismatch');
        });
    }
}
