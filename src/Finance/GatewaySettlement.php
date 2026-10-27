<?php

namespace HScript\Finance;

use HScript\Database\Connection;
use HScript\Database\TransactionScope;
use InvalidArgumentException;

/** Verified provider transaction identity remains bound across pending/success. */
final class GatewaySettlement
{
    /** Unsigned WebMoney prerequest validates an order but creates no effect. */
    public static function preflight(Connection $db, string $gateway, int $currency, array $request): void
    {
        $id = filter_var($request['tag'] ?? null, FILTER_VALIDATE_INT);
        if ($gateway !== 'WM' || ($request['outcome'] ?? '') !== 'preflight' || !empty($request['correct'])
            || !empty($request['authenticated']) || !$id || $id < 1)
            throw new CallbackValidationException('callback_prerequest_invalid');
        TransactionScope::run($db->link, static function () use ($db, $gateway, $currency, $request, $id): void {
            $o = self::operation($db, $id);
            if (!$o || ArchivedReplay::inactive($o) || $o['oOper'] !== 'CASHIN' || (int)$o['ocID'] !== $currency || !in_array((int)$o['oState'], [0,1,2], true))
                throw new CallbackValidationException('callback_order_unavailable');
            CallbackContract::validate($db, $gateway, $o, $request, time());
        });
    }

    public static function settle(Connection $db, string $gateway, int $currency, array $verified, ?int $now = null): IdempotencyResult
    {
        if (!\HScript\Payment\CallbackCapabilities::allows($gateway,!empty($verified['outbound']) ? 'out' : 'in'))
            throw new CallbackValidationException('automatic_callback_unsupported');
        if (($verified['correct'] ?? null)!==true || ($verified['authenticated'] ?? null)!==true
            || !in_array($verified['outcome'] ?? null,['verified','pending'],true)
            || (array_key_exists('wait',$verified) && !is_bool($verified['wait']))
            || (($verified['outcome']==='pending')!==($verified['wait'] ?? false)))
            throw new CallbackValidationException('callback_authentication_required');
        if($gateway==='PAW')return PawSettlement::settle($db,$currency,$verified);
        $batch = $verified['batch'] ?? null;
        if (in_array($gateway,['CP','CPE','CPL','CPR','YM','YMC'],true) && is_string($batch) && strlen($batch)>39)
            throw new CallbackValidationException('callback_transaction_id_unsupported');
        $operationId = filter_var($verified['tag'] ?? null, FILTER_VALIDATE_INT);
        if (empty($verified['correct']) || !is_string($batch) || $batch === '' || strlen($batch) > 512
            || preg_match('/[\x00-\x1f\x7f]/', $batch) || !$operationId || $operationId < 1
            || !preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $gateway) || $currency < 1)
            throw new InvalidArgumentException('Invalid verified transaction identity');
        $namespace = 'gateway:' . strtolower($gateway) . ':currency:' . $currency;
        $outbound = !empty($verified['outbound']);
        $storedBatch = !$outbound && \HScript\Payment\Gateways\CryptoCurrencyApi::supports($gateway)
            ? CcapiV3Contract::batchAlias($batch) : ($gateway==='ST' ? StripeContract::batchAlias($batch) : $batch);
        $key = hash('sha256', $batch);
        $amount = ($verified['sum'] ?? '?') === '?' ? '?' : RequestDecimal::normalize($verified['sum']);
        $fingerprint = IdempotencyRequest::fingerprint([
            'operation_id' => $operationId, 'currency_id' => $currency, 'amount' => $amount,
            'direction' => $outbound ? 'out' : 'in',
            'sender_hash' => hash('sha256', (string)($verified['accfrom'] ?? '')),
        ]);
        $store = new IdempotencyStore($db);
        $now ??= time();
        $archived=ArchivedReplay::gateway($db,$gateway,$currency,$verified,$fingerprint,$namespace,$key,$storedBatch,
            static function(IdempotencyResult $identity,array $o)use($db,$gateway,$operationId,$currency,$outbound,$verified,$now):void{
                self::assertIdentity($db,$gateway,$operationId,$currency,$outbound,$verified,$identity);
                self::validateStage($db,$gateway,$currency,$o,$verified,$identity,$now);
            });
        if($archived!==null)return $archived;
        // A provider may first acknowledge pending and later confirm settlement.
        // Both stages share a permanent identity binding; neither can redirect it.
        $identity = $store->execute($namespace . ':identity', $key, 0, $fingerprint, static function () use ($db, $gateway, $operationId, $currency, $outbound, $verified, $now): IdempotencyResult {
            $o = self::operation($db, $operationId);
            if (!$o || $o['oOper'] !== ($outbound ? 'CASHOUT' : 'CASHIN') || (int)$o['ocID'] !== $currency) throw new FinancialOperationException('oper_not_found');
            if(ArchivedReplay::inactive($o))throw new CallbackValidationException('archived_callback_reconciliation_required');
            $proof = CallbackContract::validate($db, $gateway, $o, $verified, $now);
            return new IdempotencyResult(['bound' => true, 'contract'=>$proof], 200, $operationId);
        });
        self::assertIdentity($db,$gateway,$operationId,$currency,$outbound,$verified,$identity);
        $pending = !empty($verified['wait']);
        return $store->execute($namespace . ($pending ? ':pending' : ':settled'), $key, 0, $fingerprint, static function () use ($db, $gateway, $operationId, $currency, $verified, $pending, $storedBatch, $identity, $now): IdempotencyResult {
            $o = self::operation($db, $operationId);
            if (!$o) throw new FinancialOperationException('oper_not_found');
            if(ArchivedReplay::inactive($o))throw new CallbackValidationException('archived_callback_reconciliation_required');
            self::validateStage($db, $gateway, $currency, $o, $verified, $identity, $now);
            if ((int)$o['oState'] === 3) {
                if (!hash_equals((string)$o['oBatch'], $storedBatch)) throw new IdempotencyConflict('gateway_operation_already_settled');
                return new IdempotencyResult(['settled' => true], 200, $operationId);
            }
            $params = $verified;
            $params['batch'] = $storedBatch;
            unset($params['profile_key_hash'],$params['profile_timezone']);
            unset($params['response'], $params['correct'], $params['handled'], $params['description_hash']);
            unset($params['merchant_id'], $params['provider_invoice_hash'], $params['volet_profile'], $params['volet_proof_hash'], $params['volet_fee_policy_hash']);
            foreach (array_keys($params) as $field) if (str_starts_with($field,'ccapi_') || str_starts_with($field,'stripe_')) unset($params[$field]);
            $params['cid'] = $currency;
            // Validated provider gross is evidence, not a replacement receipt.
            // This keeps the originally agreed oComis instead of recalculating
            // an existing invoice using the current min/max/commission policy.
            unset($params['sum']);
            $params['date'] = $identity->data['contract']['signed_at'] ?? time();
            if (!$params['date']) $params['date'] = time();
            $params['acc'] = $params['accfrom'] ?? '';
            FinancialOperationException::check(opOperConfirm(-1, $operationId, $params));
            if (!$pending) FinancialOperationException::check(opOperComplete(-1, $operationId));
            return new IdempotencyResult(['settled' => !$pending], 200, $operationId);
        });
    }

    private static function assertIdentity(Connection $db,string $gateway,int $operationId,int $currency,bool $outbound,array $verified,IdempotencyResult $identity): void
    {
        // Older completed stages replay without executing validateStage. Their
        // identity lacks the newly required description proof; do not infer it
        // from an editable receipt or acknowledge an unvalidated WM replay.
        if (VoletContract::supports($gateway) && !$outbound && (($identity->data['contract']['volet_profile'] ?? null)!==VoletContract::profile($gateway)
            || !is_string($identity->data['contract']['volet_proof_hash'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D',$identity->data['contract']['volet_proof_hash'])
            || !is_string($identity->data['contract']['volet_fee_policy_hash'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D',$identity->data['contract']['volet_fee_policy_hash'])))
            throw new CallbackValidationException('callback_volet_binding_missing');
        if ($gateway === 'WM' && !$outbound && !is_string($identity->data['contract']['description_hash'] ?? null))
            throw new CallbackValidationException('callback_description_binding_missing');
        if (!$outbound && isset(PaykassaContract::SYSTEMS[$gateway])
            && (!is_string($identity->data['contract']['merchant_hash'] ?? null)
                || !is_string($identity->data['contract']['provider_invoice_hash'] ?? null)
                || ($identity->data['contract']['destination_tag_bound'] ?? null) !== true))
            throw new CallbackValidationException('callback_invoice_binding_missing');
        if (!$outbound && \HScript\Payment\Gateways\CryptoCurrencyApi::supports($gateway))
            CcapiV3Contract::assertReplay($db,$operationId,$identity->data['contract'] ?? [],$verified);
        if (!$outbound) {
            InboundIntent::assertProof($identity->data['contract']['inbound_intent'] ?? null);
            InboundIntent::assertRequest($identity->data['contract']['inbound_intent'],$operationId,$currency,$gateway,$verified);
            if ($identity->operationId!==$operationId || ($identity->data['contract']['amount'] ?? null)!==$identity->data['contract']['inbound_intent']['gross']
                || ($identity->data['contract']['asset'] ?? null)!==$identity->data['contract']['inbound_intent']['asset']
                || ($identity->data['contract']['scale'] ?? null)!==$identity->data['contract']['inbound_intent']['scale'])
                throw new CallbackValidationException('callback_inbound_binding_missing');
        }
        if (!$outbound && $gateway==='ST') StripeContract::assertReplay($db,$operationId,$identity->data['contract']??[],$verified);
        if (!$outbound && InboundProfile::managed($gateway))
            InboundProfile::assertReplay($db,$operationId,$identity->data['contract']['inbound_profile'] ?? null,$verified);
        if (isset($identity->data['contract'])) CallbackContract::assertBinding($identity->data['contract'], $verified);
    }

    private static function operation(Connection $db, int $id): array|false
    {
        return $db->fetch1Row($db->query('SELECT Opers.*,Currs.*,oSum-oComis AS callbackNetAmount FROM Opers JOIN Currs ON cID=ocID WHERE oID=?d FOR UPDATE', [$id]));
    }

    private static function validateStage(Connection $db, string $gateway, int $currency, array|false $o, array $verified, IdempotencyResult $identity, int $now): void
    {
        if (!$o || $o['oOper'] !== (!empty($verified['outbound']) ? 'CASHOUT' : 'CASHIN') || (int)$o['ocID'] !== $currency)
            throw new FinancialOperationException('oper_not_found');
        if ($o['cCID'] !== $gateway || ($verified['curr'] ?? null) !== $o['cCurrID'])
            throw new CallbackValidationException('callback_currency_mismatch');
        // Re-read money under the settlement lock; an admin edit between stages
        // cannot make the original verified amount credit a different amount.
        $expected = !empty($verified['outbound']) ? $o['callbackNetAmount'] : $o['oSum'];
        $scale = $identity->data['contract']['scale'] ?? CallbackContract::scale($o);
        if (CallbackAmount::atScale($expected, $scale) !== CallbackAmount::atScale($verified['sum'] ?? null, $scale))
            throw new CallbackValidationException('callback_amount_mismatch');
        // Every new financial stage repeats the accepted contract under lock;
        // a cached response never supplies missing immutable inbound authority.
        $currentProof = CallbackContract::validate($db, $gateway, $o, $verified, $now, !isset($identity->data['contract']));
        if (isset($identity->data['contract']) && $currentProof['scale'] !== $identity->data['contract']['scale'])
            throw new CallbackValidationException('callback_precision_configuration_changed');
        if (empty($verified['outbound'])) InboundIntent::assertOperation($identity->data['contract']['inbound_intent'],$o);
        // Legacy arithmetic reads request-global currency data. Use the same
        // locked row that was just validated, so a stale auth snapshot cannot
        // round a valid callback using an obsolete scale or commission.
        CallbackContract::refreshCurrencyContext($o, $currentProof['scale']);
    }
}
