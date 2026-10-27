<?php

namespace HScript\Finance;

use HScript\Database\Connection;
use HScript\Database\TransactionScope;
use HScript\Security\SecretCipher;
use RuntimeException;

/** A checkout retry returns the saved form and cannot create a second invoice. */
final class DepositCheckout
{
    public static function prepare(Connection $db, int $owner, int $operationId, callable $create, mixed $routeCode=null): array
    {
        global $_cfg;
        $encryptionKey = hash('sha256', 'checkout:' . $operationId . ':' . $owner . ':' . ($_cfg['Const_Salt'] ?? ''));
        if (SecretCipher::encrypt('{}', $encryptionKey) === null)
            throw new FinancialOperationException('invoice_configuration_missing');
        $request=['operation_id'=>$operationId];
        if($routeCode!==null && $routeCode!=='') { if(!is_string($routeCode))throw new CallbackValidationException('payment_route_invalid');$request['payment_route']=$routeCode; }
        $fingerprint = IdempotencyRequest::fingerprint($request);
        $store = new IdempotencyStore($db);
        $prepared=[];
        $intent = $store->execute('invoice:attempt', (string)$operationId, $owner, $fingerprint, static function () use ($db, $owner, $operationId, &$prepared, $routeCode): IdempotencyResult {
            $o = $db->fetch1Row($db->query('SELECT Opers.*,Currs.* FROM Opers JOIN Currs ON cID=ocID WHERE oID=?d FOR UPDATE', [$operationId]));
            if (!$o || ArchivedReplay::inactive($o) || (int)$o['ouID'] !== $owner || $o['oOper'] !== 'CASHIN' || (int)$o['oState'] > 2)
                throw new FinancialOperationException('oper_state_wrong');
            if (\HScript\Payment\CallbackCapabilities::disabledAutomation($o['cCID']))
                throw new CallbackValidationException('automatic_payment_unsupported');
            // Existing encrypted checkouts have no agreed fee proof to backfill.
            if (isset(strToArray($o['oParams'])['checkout'])) throw new CallbackValidationException('callback_invoice_intent_missing');
            if (!empty($o['cProviderID']) && ((int)$o['cDisabled']!==0 || (int)$o['cProviderSelected']!==1)) throw new CallbackValidationException('callback_configuration_missing');
            $route=\HScript\Payment\Routing\PaymentRoutes::select($o,$routeCode);
            $wallets=\HScript\Payment\Routing\CcapiWallets::capture($db,$o,$route);
            $paw=null;
            if($o['cCID']==='PAW'){opDecodeCurrParams($o,$pay,$sci,$api);$paw=PawContract::capture($o,$sci);$o['_paw_config']=$sci;}
            $prepared=$o; $prepared['_payment_route']=$route;$prepared['_ccapi_wallets']=$wallets;
            return new IdempotencyResult(['started' => true,'contract'=>InboundIntent::capture($o),'profile'=>InboundProfile::capture($o)]+($paw!==null?['paw_profile'=>$paw]:[])+($route!==null?['payment_route'=>$route]:[])+($wallets!==null?['ccapi_wallets'=>$wallets]:[]), 202, $operationId);
        });
        InboundIntent::assertProof($intent->data['contract'] ?? null,'callback_invoice_intent_missing');
        if (!$intent->replayed) {
            // The creation callback runs after committing intent, without locks.
            $form = $create($prepared);
            if(isset($intent->data['payment_route'])) {
                $route=\HScript\Payment\Routing\PaymentRoutes::assertSnapshot($prepared['cCID'],$intent->data['payment_route']);
                if(($form['payment_route']??null)!==$route)throw new CallbackValidationException('payment_route_proof_invalid');
            }
            if (!is_array($form) || empty($form['url'])) throw new RuntimeException('invoice_reconciliation_required');
            $store->execute('invoice:result', (string)$operationId, $owner, $fingerprint, static function () use ($db, $operationId, $owner, $form, $encryptionKey, $intent): IdempotencyResult {
                $o = $db->fetch1Row($db->query('SELECT Opers.*,Currs.* FROM Opers JOIN Currs ON cID=ocID WHERE oID=?d AND ouID=?d FOR UPDATE', [$operationId, $owner]));
                if (!$o || (int)$o['oState'] > 2) throw new FinancialOperationException('oper_state_wrong');
                if (InboundProfile::managed($o['cCID'])) {
                    InboundProfile::assertOperation($intent->data['profile'] ?? [],$o);
                    $form['inbound_profile']=$intent->data['profile'];
                    InboundProfile::assertForm($intent->data['profile'],$form);
                }
                if (VoletContract::supports($o['cCID'])) VoletContract::feeBinding($o,$form);
                if (\HScript\Payment\Gateways\CryptoCurrencyApi::supports($o['cCID'])) {
                    opDecodeCurrParams($o,$pay,$sci,$api);
                    \HScript\Payment\Routing\CcapiWallets::assertInvoice($db,$o,$form);
                    CcapiV3Contract::invoice($o,$form,$sci);
                    if ($form['ccapi_scale']!==(string)CallbackContract::scale($o) || $form['ccapi_gross']!==CallbackAmount::atScale($o['oSum'],CallbackContract::scale($o)))
                        throw new CallbackValidationException('callback_invoice_amount_mismatch');
                }
                if ($o['cCID']==='ST') {
                    opDecodeCurrParams($o,$pay,$sci,$api);
                    if (StripeContract::invoice($o,$form)!==StripeContract::capture($o,$sci)) throw new CallbackValidationException('stripe_profile_mismatch');
                }
                if($o['cCID']==='PAW'){
                    $paw=PawContract::form($o,$form);unset($paw['order_id'],$paw['created_at'],$paw['expires_at']);
                    if($paw!==($intent->data['paw_profile']??null))throw new CallbackValidationException('paw_profile_mismatch');
                }
                InboundIntent::assertOperation($intent->data['contract'],$o);
                $form['inbound_intent']=$intent->data['contract'];
                $params = strToArray($o['oParams']);
                if (isset($form['psysorder'])) $params['psysorder'] = $form['psysorder'];
                $encoded = json_encode($form, JSON_THROW_ON_ERROR);
                if (strlen($encoded) > 24576) throw new RuntimeException('Invoice form exceeds storage bound');
                $encrypted = SecretCipher::encrypt($encoded, $encryptionKey);
                if ($encrypted === null) throw new RuntimeException('Protected invoice storage is unavailable');
                $params['checkout'] = $encrypted;
                $db->update('Opers', ['oParams'=>arrayToStr($params)], '', 'oID=?d', [$operationId]);
                return new IdempotencyResult(['available'=>true], 200, $operationId);
            });
        }
        $form=TransactionScope::run($db->link,static function () use ($db,$operationId,$owner,$intent): array {
            $operation=$db->fetch1Row($db->query('SELECT Opers.*,Currs.* FROM Opers JOIN Currs ON cID=ocID WHERE oID=?d AND ouID=?d FOR UPDATE',[$operationId,$owner]));
            if (!$operation) throw new FinancialOperationException('oper_not_found');
            $form=self::storedForm($operation);
            if(isset($intent->data['payment_route']) && ($form['payment_route']??null)!==$intent->data['payment_route'])throw new CallbackValidationException('payment_route_proof_invalid');
            if (!$form || empty($form['url'])) throw new RuntimeException('invoice_reconciliation_required');
            if (\HScript\Payment\CallbackCapabilities::disabledAutomation($operation['cCID']))
                throw new CallbackValidationException('automatic_payment_unsupported');
            if (InboundProfile::managed($operation['cCID'])) {
                InboundProfile::assertOperation($intent->data['profile'] ?? [],$operation);
                InboundProfile::assertForm($intent->data['profile'],$form);
            }
            if (VoletContract::supports($operation['cCID'])) VoletContract::feeBinding($operation,$form);
            if (\HScript\Payment\Gateways\CryptoCurrencyApi::supports($operation['cCID'])) {
                opDecodeCurrParams($operation,$pay,$sci,$api);
                \HScript\Payment\Routing\CcapiWallets::assertInvoice($db,$operation,$form);
                CcapiV3Contract::invoice($operation,$form,$sci);
            }
            if ($operation['cCID']==='ST') StripeContract::invoice($operation,$form);
            if ($operation['cCID']==='PAW') PawContract::attempt($db,$operation,$form);
            InboundIntent::assertOperation($intent->data['contract'],$operation);
            InboundIntent::assertForm($intent->data['contract'],$form);
            return $form;
        });
        if(isset($form['ccapi_token_contract']))$form['payment_contract']=$form['ccapi_token_contract'];
        if(($form['formkind']??'')==='paw_sandbox')$form['payment_reference']=$form['paw_order'];
        unset($form['psysorder'],$form['payment_route']);
        foreach (array_keys($form) as $field)
            if (str_starts_with($field,'paykassa_') || str_starts_with($field,'volet_') || str_starts_with($field,'inbound_') || str_starts_with($field,'ccapi_') || str_starts_with($field,'stripe_') || str_starts_with($field,'paw_')) unset($form[$field]);
        return $form;
    }

    /** Internal stored checkout, without provider I/O or a new invoice attempt. */
    public static function nativeStored(Connection $db,int $owner,int $operationId): ?array
    {
        return TransactionScope::run($db->link,static function () use ($db,$owner,$operationId): ?array {
            $operation=$db->fetch1Row($db->query('SELECT Opers.*,Currs.* FROM Opers JOIN Currs ON cID=ocID WHERE oID=?d AND ouID=?d FOR UPDATE',[$operationId,$owner]));
            if (!$operation || $operation['oOper']!=='CASHIN' || (int)$operation['oState']>2
                || !\HScript\Payment\Gateways\CryptoCurrencyApi::supports($operation['cCID'])) throw new FinancialOperationException('oper_state_wrong');
            $form=self::storedForm($operation);
            if ($form===null) return null;
            \HScript\Payment\Routing\PaymentRoutes::assertInvoice($db,$operation,$form);
            opDecodeCurrParams($operation,$pay,$sci,$api);
            \HScript\Payment\Routing\CcapiWallets::assertInvoice($db,$operation,$form);
            CcapiV3Contract::invoice($operation,$form,$sci);
            $intent=InboundIntent::attemptProof($db,$operation);
            if ($intent===null) throw new CallbackValidationException('callback_invoice_intent_missing');
            InboundIntent::assertOperation($intent,$operation);
            InboundIntent::assertForm($intent,$form);
            if(isset($form['ccapi_token_contract']))$form['payment_contract']=$form['ccapi_token_contract'];
            return array_intersect_key($form,array_flip(['formkind','payment_address','payment_asset','payment_amount','payment_network','payment_contract','url']));
        });
    }

    /** Internal stored checkout, without provider I/O or a new invoice attempt. */
    public static function storedForm(array $operation): ?array
    {
        global $_cfg;
        $params = strToArray($operation['oParams']);
        if (!isset($params['checkout'])) return null;
        $key = hash('sha256', 'checkout:' . $operation['oID'] . ':' . $operation['ouID'] . ':' . ($_cfg['Const_Salt'] ?? ''));
        $decoded = SecretCipher::decrypt($params['checkout'], $key);
        if ($decoded === null) throw new CallbackValidationException('callback_checkout_unavailable');
        $form = json_decode($decoded, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($form) || empty($form['url'])) throw new CallbackValidationException('callback_checkout_unavailable');
        return $form;
    }
}
