<?php

namespace HScript\Finance;

use HScript\Database\Connection;
use InvalidArgumentException;
use JsonException;

/** Agreed inbound gross and local commission, without recalculating policy. */
final class InboundIntent
{
    private const FIELDS = ['version','operation_id','owner_id','currency_id','gateway','asset','scale','gross','commission'];

    public static function capture(array $operation): array
    {
        try { $commission=RequestDecimal::normalize($operation['oComis'],true); }
        catch (InvalidArgumentException) { throw new CallbackValidationException('callback_inbound_intent_mismatch'); }
        $proof = [
            'version'=>1, 'operation_id'=>(int)$operation['oID'], 'owner_id'=>(int)$operation['ouID'],
            'currency_id'=>(int)$operation['ocID'], 'gateway'=>(string)$operation['cCID'],
            'asset'=>(string)$operation['cCurrID'], 'scale'=>CallbackContract::scale($operation),
            'gross'=>CallbackAmount::atScale($operation['oSum'],CallbackContract::scale($operation)),
            'commission'=>$commission,
        ];
        self::assertProof($proof,'callback_inbound_intent_mismatch');
        return $proof;
    }

    public static function assertProof(mixed $proof, string $reason = 'callback_inbound_binding_missing'): void
    {
        try {
            if (!is_array($proof) || array_diff(self::FIELDS,array_keys($proof)) || array_diff(array_keys($proof),self::FIELDS)
                || $proof['version']!==1 || !is_int($proof['operation_id']) || $proof['operation_id']<1
                || !is_int($proof['owner_id']) || $proof['owner_id']<1 || !is_int($proof['currency_id']) || $proof['currency_id']<1
                || !is_string($proof['gateway']) || !preg_match('/^[A-Za-z0-9_-]{1,32}$/D',$proof['gateway'])
                || !is_string($proof['asset']) || $proof['asset']==='' || strlen($proof['asset'])>32 || preg_match('/[\x00-\x1f\x7f]/',$proof['asset'])
                || !is_int($proof['scale']) || !is_string($proof['gross']) || !is_string($proof['commission'])
                || CallbackAmount::atScale($proof['gross'],$proof['scale'])!==$proof['gross']
                || RequestDecimal::normalize($proof['commission'],true)!==$proof['commission'])
                throw new InvalidArgumentException('invalid_inbound_intent');
            if ($proof['commission']!=='0') {
                $fee=CallbackAmount::atScale($proof['commission'],$proof['scale']);
                [$grossWhole,$grossFraction]=array_pad(explode('.',$proof['gross'],2),2,'');
                [$feeWhole,$feeFraction]=array_pad(explode('.',$fee,2),2,'');
                if (strlen($feeWhole)>strlen($grossWhole) || (strlen($feeWhole)===strlen($grossWhole)
                    && (strcmp($feeWhole,$grossWhole)>0 || ($feeWhole===$grossWhole && strcmp($feeFraction,$grossFraction)>0))))
                    throw new InvalidArgumentException('invalid_inbound_intent');
            }
        } catch (InvalidArgumentException) {
            throw new CallbackValidationException($reason);
        }
    }

    /** A cached reply needs the original proof, never a newly inferred fee. */
    public static function assertRequest(array $proof, int $operationId, int $currencyId, string $gateway, array $verified): void
    {
        self::assertProof($proof);
        if ($proof['operation_id']!==$operationId || $proof['currency_id']!==$currencyId || $proof['gateway']!==$gateway)
            throw new CallbackValidationException('callback_inbound_binding_missing');
        if ($proof['asset']!==($verified['curr'] ?? null)) throw new CallbackValidationException('callback_currency_mismatch');
        if ($proof['gross']!==CallbackAmount::atScale($verified['sum'] ?? null,$proof['scale']))
            throw new CallbackValidationException('callback_amount_mismatch');
    }

    public static function assertOperation(array $proof, array $operation): void
    {
        self::assertProof($proof);
        $current=self::capture($operation);
        if ($current['scale']!==$proof['scale']) throw new CallbackValidationException('callback_precision_configuration_changed');
        if ($current!==$proof) throw new CallbackValidationException('callback_inbound_intent_mismatch');
    }

    /** Called under the operation lock; the attempt was committed before I/O. */
    public static function attemptProof(Connection $db, array $operation): ?array
    {
        $statement=$db->link->prepare("SELECT ikOwnerID,ikOperationID,ikState,ikResponse FROM IdempotencyKeys WHERE ikNamespace='invoice:attempt' AND ikKeyHash=? FOR UPDATE");
        $statement->execute([hash('sha256',(int)$operation['ouID']."\0".(int)$operation['oID'])]);
        $row=$statement->fetch();
        if (!$row) return null;
        try { $data=is_string($row['ikResponse']) ? json_decode($row['ikResponse'],true,32,JSON_THROW_ON_ERROR) : null; }
        catch (JsonException) { throw new CallbackValidationException('callback_invoice_intent_missing'); }
        if ((int)$row['ikOwnerID']!==(int)$operation['ouID'] || (int)$row['ikOperationID']!==(int)$operation['oID']
            || $row['ikState']!=='completed' || ($data['data']['started'] ?? null)!==true)
            throw new CallbackValidationException('callback_invoice_intent_missing');
        $proof=$data['data']['contract'] ?? null;
        self::assertProof($proof,'callback_invoice_intent_missing');
        return $proof;
    }

    public static function assertForm(array $proof, array $form): void
    {
        self::assertProof($form['inbound_intent'] ?? null,'callback_invoice_intent_missing');
        if ($form['inbound_intent']!==$proof) throw new CallbackValidationException('callback_invoice_intent_missing');
    }

    public static function callbackProof(Connection $db, array $operation): array
    {
        $proof=self::attemptProof($db,$operation);
        $form=DepositCheckout::storedForm($operation);
        if($form!==null){\HScript\Payment\Routing\PaymentRoutes::assertInvoice($db,$operation,$form);\HScript\Payment\Routing\CcapiWallets::assertInvoice($db,$operation,$form);}
        if ($proof!==null) {
            if ($form===null) throw new CallbackValidationException('callback_invoice_intent_missing');
            self::assertForm($proof,$form);
            self::assertOperation($proof,$operation);
            return $proof;
        }
        // A historical encrypted invoice cannot be upgraded from mutable money.
        if ($form!==null) throw new CallbackValidationException('callback_invoice_intent_missing');
        // Configured no-checkout profiles agree money at first signed identity.
        // There is no preceding invoice proof for edits before that acceptance.
        return self::capture($operation);
    }
}
