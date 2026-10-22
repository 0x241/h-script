<?php

namespace HScript\Finance;

use HScript\Database\Connection;
use HScript\Database\TransactionScope;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use PDOException;
use Throwable;

/** One transaction owns the key, local effects and safe response snapshot. */
final class IdempotencyStore
{
    public function __construct(private Connection $database) {}

    /**
     * Work must use this same PDO connection, never commit, and never send
     * external payments/messages. Dispatch through a transactional outbox.
     * Expiry is a retention floor, never permission to reuse a completed key.
     */
    public function execute(string $namespace, string $key, int $owner, string $fingerprint, callable $work, int $retentionSeconds = 0): IdempotencyResult
    {
        if ($this->database->link->inTransaction()) throw new LogicException('IdempotencyStore must own the transaction');
        for ($attempt = 0; ; $attempt++) {
            try { return $this->perform($namespace, $key, $owner, $fingerprint, $work, $retentionSeconds); }
            catch (PDOException $exception) {
                if ($attempt >= 2 || ($exception->getCode() !== '40001' && !in_array((int)($exception->errorInfo[1] ?? 0), [1205, 1213], true))) throw $exception;
                usleep(random_int(10000, 50000));
            }
        }
    }

    /** Explicit participation for an outbox marker inside a financial transaction. */
    public function executeInTransaction(string $namespace, string $key, int $owner, string $fingerprint, callable $work): IdempotencyResult
    {
        if (!$this->database->link->inTransaction()) throw new LogicException('An outer transaction is required');
        return $this->perform($namespace, $key, $owner, $fingerprint, $work, 0);
    }

    private function perform(string $namespace, string $key, int $owner, string $fingerprint, callable $work, int $retentionSeconds): IdempotencyResult
    {
        IdempotencyRequest::validate($namespace, $key, $owner);
        if (!preg_match('/^[a-f0-9]{64}$/D', $fingerprint) || $retentionSeconds < 0 || $retentionSeconds > 315360000)
            throw new InvalidArgumentException('Invalid idempotency fingerprint or retention');
        $pdo = $this->database->link;
        $keyHash = hash('sha256', $owner . "\0" . $key);
        $now = time();
        $claimToken = bin2hex(random_bytes(16));
        $scope = new TransactionScope($pdo);
        try {
            // The no-op update acquires an exclusive duplicate-key lock. Catching
            // INSERT's duplicate error then upgrading its shared lock can deadlock.
            $insert = $pdo->prepare("INSERT INTO IdempotencyKeys (ikNamespace,ikKeyHash,ikFingerprint,ikClaimToken,ikOwnerID,ikState,ikCreatedAt,ikExpiresAt) VALUES (?,?,?,?,?,'pending',?,?) ON DUPLICATE KEY UPDATE ikID=ikID");
            $insert->execute([$namespace, $keyHash, $fingerprint, $claimToken, $owner, $now, $retentionSeconds ? $now + $retentionSeconds : 0]);
            $select = $pdo->prepare('SELECT * FROM IdempotencyKeys WHERE ikNamespace=? AND ikKeyHash=? FOR UPDATE');
            $select->execute([$namespace, $keyHash]);
            $row = $select->fetch();
            if (!$row || (int)$row['ikOwnerID'] !== $owner || !hash_equals($row['ikFingerprint'], $fingerprint))
                throw new IdempotencyConflict('idempotency_input_conflict');
            // A random claim token is independent of PDO FOUND_ROWS configuration.
            if (!hash_equals($row['ikClaimToken'], $claimToken)) {
                if ($row['ikState'] !== 'completed' || !is_string($row['ikResponse'])) throw new IdempotencyConflict('idempotency_incomplete');
                $snapshot = json_decode($row['ikResponse'], true, 512, JSON_THROW_ON_ERROR);
                $result = new IdempotencyResult($snapshot['data'], $snapshot['status'], $row['ikOperationID'] === null ? null : (int)$row['ikOperationID'], true);
                $scope->commit();
                return $result;
            }
            $result = $work();
            if (!$result instanceof IdempotencyResult) throw new LogicException('Work must return IdempotencyResult');
            if (!$pdo->inTransaction()) throw new LogicException('Work ended the idempotency transaction');
            $snapshot = json_encode(['data' => $result->data, 'status' => $result->status], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            if (strlen($snapshot) > 65536) throw new RuntimeException('Idempotency response exceeds the storage bound');
            $update = $pdo->prepare("UPDATE IdempotencyKeys SET ikOperationID=?,ikState='completed',ikResponse=? WHERE ikID=? AND ikState='pending'");
            $update->execute([$result->operationId, $snapshot, $row['ikID']]);
            if ($update->rowCount() !== 1) throw new RuntimeException('Idempotency claim was lost');
            $scope->commit();
            return $result;
        } catch (Throwable $exception) {
            $scope->rollback($exception);
            throw $exception;
        }
    }
}
