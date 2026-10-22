<?php

namespace HScript\Database;

use PDO;
use PDOException;
use Throwable;

/** A local unit of work cannot commit or roll back its caller's transaction. */
final class TransactionScope
{
    private ?string $savepoint;
    private bool $finished = false;

    public function __construct(private PDO $pdo)
    {
        $this->savepoint = $pdo->inTransaction() ? 'hs_' . bin2hex(random_bytes(8)) : null;
        if ($this->savepoint === null) $pdo->beginTransaction();
        else $pdo->exec('SAVEPOINT ' . $this->savepoint);
    }

    public function commit(): void
    {
        if ($this->finished) return;
        if ($this->savepoint === null) $this->pdo->commit();
        else $this->pdo->exec('RELEASE SAVEPOINT ' . $this->savepoint);
        $this->finished = true;
    }

    public function rollback(?Throwable $cause = null): void
    {
        if ($this->finished) return;
        // InnoDB deadlocks can roll back the whole transaction itself.
        if ($this->pdo->inTransaction()) {
            if ($this->savepoint === null) $this->pdo->rollBack();
            else {
                try {
                    $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $this->savepoint);
                    $this->pdo->exec('RELEASE SAVEPOINT ' . $this->savepoint);
                } catch (PDOException $cleanup) {
                    // Some drivers still report an active transaction after
                    // InnoDB aborts it. Preserve the deadlock/timeout so the
                    // outer owner can reset PDO and retry the entire unit.
                    if (!$cause instanceof PDOException
                        || ($cause->getCode() !== '40001' && !in_array((int)($cause->errorInfo[1] ?? 0), [1205, 1213], true))
                        || (int)($cleanup->errorInfo[1] ?? 0) !== 1305) throw $cleanup;
                }
            }
        }
        $this->finished = true;
    }

    /** Financial functions return an error code string instead of throwing. */
    public static function run(PDO $pdo, callable $work): mixed
    {
        $scope = new self($pdo);
        try {
            $result = $work();
            if (is_string($result)) $scope->rollback();
            else $scope->commit();
            return $result;
        } catch (Throwable $exception) {
            $scope->rollback($exception);
            throw $exception;
        }
    }
}
