<?php

namespace HScript\Finance;

use HScript\Database\Connection;
use HScript\Payment\PaymentManager;
use HScript\Queue\JobQueue;
use HScript\Security\SecretCipher;
use LogicException;
use RuntimeException;

/** Commit an intent before contacting a provider that may lack deduplication. */
final class WithdrawalDispatcher
{
    private static function fingerprint(int $operationId): string
    {
        return IdempotencyRequest::fingerprint(['operation_id' => $operationId]);
    }

    public static function enqueue(Connection $db, int $operationId, array $apiOverride = []): void
    {
        if ($apiOverride) {
            if (self::wasAttempted($db, $operationId)) throw new FinancialOperationException('payout_reconciliation_required');
            $json = json_encode($apiOverride, JSON_THROW_ON_ERROR);
            if (strlen($json) > 8192) throw new FinancialOperationException('payout_configuration_invalid');
            $protected = SecretCipher::encrypt($json, 'payout-api:' . $operationId);
            if ($protected === null) throw new FinancialOperationException('payout_configuration_invalid');
            $params = strToArray($db->fetch1($db->select('Opers', 'oParams', 'oID=?d', [$operationId])));
            $params['payout_api'] = $protected;
            $db->update('Opers', ['oParams'=>arrayToStr($params)], '', 'oID=?d', [$operationId]);
        }
        (new IdempotencyStore($db))->executeInTransaction('payout:queue', (string)$operationId, 0, self::fingerprint($operationId), static function () use ($db, $operationId): IdempotencyResult {
            if ((new JobQueue($db))->dispatch('financial.withdraw', ['operation_id' => $operationId], 3) < 1)
                throw new FinancialOperationException('payout_queue_unavailable');
            return new IdempotencyResult(['queued' => true], 202, $operationId);
        });
    }

    public static function wasAttempted(Connection $db, int $operationId): bool
    {
        $statement = $db->link->prepare('SELECT ikID FROM IdempotencyKeys WHERE ikNamespace=? AND ikKeyHash=? FOR UPDATE');
        $statement->execute(['payout:attempt', hash('sha256', "0\0" . $operationId)]);
        return (bool)$statement->fetchColumn();
    }

    public static function callbackProof(Connection $db, int $operationId): array
    {
        $statement = $db->link->prepare("SELECT ikResponse FROM IdempotencyKeys WHERE ikNamespace='payout:attempt' AND ikKeyHash=? AND ikState='completed' FOR UPDATE");
        $statement->execute([hash('sha256', "0\0" . $operationId)]);
        $response = $statement->fetchColumn();
        $data = is_string($response) ? json_decode($response, true, 32, JSON_THROW_ON_ERROR)['data'] : [];
        if (empty($data['send']) || !is_array($data['contract'] ?? null))
            throw new CallbackValidationException('callback_payout_proof_missing');
        return $data['contract'];
    }

    /** A crash or unknown provider response requires reconciliation, never resend. */
    public static function dispatch(Connection $db, int $operationId, ?callable $send = null): array
    {
        if ($db->link->inTransaction()) throw new LogicException('Payout network I/O requires a committed transaction');
        $store = new IdempotencyStore($db);
        $prepared = [];
        $intent = $store->execute('payout:attempt', (string)$operationId, 0, self::fingerprint($operationId), static function () use ($db, $operationId, $send, &$prepared): IdempotencyResult {
            $o = $db->fetch1Row($db->query('SELECT *,oSum-oComis AS callbackNetAmount FROM Opers WHERE oID=?d FOR UPDATE', [$operationId]));
            if (!$o || $o['oOper'] !== 'CASHOUT' || (int)$o['oState'] !== 2)
                return new IdempotencyResult(['send' => false], 200, $o ? $operationId : null);
            $currency = $db->fetch1Row($db->select('Currs', '*', 'cID=?d', [(int)$o['ocID']]));
            if (\HScript\Payment\CallbackCapabilities::disabledAutomation($currency['cCID']))
                throw new FinancialOperationException('automatic_payment_unsupported');
            opDecodeCurrParams($currency, $pay, $sci, $api);
            $params = strToArray($o['oParams']);
            if (isset($params['payout_api'])) {
                $decoded = SecretCipher::decrypt($params['payout_api'], 'payout-api:' . $operationId);
                if ($decoded === null) throw new FinancialOperationException('payout_configuration_invalid');
                $api = json_decode($decoded, true, 32, JSON_THROW_ON_ERROR);
            }
            $manager = new PaymentManager($db);
            $definition = $manager->definitions($currency['cCID']);
            // Configuration errors happen before committing a network attempt.
            if (!$send && (empty($definition[3]) || empty($api['apipass']))) throw new FinancialOperationException('payout_configuration_missing');
            $destination = strToArray($o['oParams2']);
            // Automated payout money comes from the reservation, never an
            // editable legacy receipt field that opOperComplete would apply.
            unset($destination['sum']);
            $scale = CallbackContract::scale($currency);
            $amount = CallbackAmount::atScale($o['callbackNetAmount'], $scale);
            $contract = ['amount'=>$amount,'asset'=>$currency['cCurrID'],'gateway'=>$currency['cCID'],'scale'=>$scale,
                'gross'=>CallbackAmount::atScale($o['oSum'],$scale),'commission'=>RequestDecimal::normalize($o['oComis'],true),
                'destination_hash'=>hash('sha256', (string)($destination['acc'] ?? '')),
                'destination_tag_hash'=>hash('sha256', (string)($destination['tag'] ?? ''))];
            $prepared = ['gateway' => $currency['cCID'], 'destination' => $destination, 'payload' => [
                'from' => $api, 'to' => $destination, 'sum' => $amount,
                'memo' => $destination['memo'] ?? '', 'tag' => $operationId,
                'url_callback' => fullURL(moduleToLink('balance/status')),
            ]];
            return new IdempotencyResult(['send' => true,'contract'=>$contract], 202, $operationId);
        });
        if (empty($intent->data['send'])) return ['skipped' => true];
        if ($intent->replayed) {
            $state = $db->fetch1($db->select('Opers', 'oState', 'oID=?d', [$operationId]));
            if ((int)$state === 3) return ['completed' => true];
            throw new RuntimeException('payout_reconciliation_required');
        }
        $destination = $prepared['destination'];
        $payload = $prepared['payload'];
        $manager = new PaymentManager($db);
        $response = $send ? $send($payload) : $manager->processWithdrawal($prepared['gateway'], $payload);
        if (empty($response['batch'])) throw new RuntimeException('payout_reconciliation_required');
        // Provider data and destinations never enter the response snapshot or job.
        $destination['date'] = time();
        $destination['batch'] = (string)$response['batch'];
        $result = $store->execute('payout:result', (string)$operationId, 0, self::fingerprint($operationId), static function () use ($db, $operationId, $destination, $intent): IdempotencyResult {
            $o = $db->fetch1Row($db->query('SELECT Opers.*,Currs.*,oSum-oComis AS callbackNetAmount FROM Opers JOIN Currs ON cID=ocID WHERE oID=?d FOR UPDATE', [$operationId]));
            if (!$o || $o['oOper'] !== 'CASHOUT') throw new FinancialOperationException('oper_not_found');
            $verified = ['outbound'=>true,'sum'=>$intent->data['contract']['amount'],'curr'=>$intent->data['contract']['asset'],
                'accto'=>(string)($destination['acc'] ?? ''),'destination_tag'=>(string)($destination['tag'] ?? '')];
            $proof = CallbackContract::validate($db, $intent->data['contract']['gateway'], $o, $verified, time());
            CallbackContract::refreshCurrencyContext($o, $proof['scale']);
            FinancialOperationException::check(opOperComplete(-1, $operationId, $destination));
            return new IdempotencyResult(['completed' => true], 200, $operationId);
        });
        return $result->data;
    }
}
