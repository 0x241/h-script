<?php

namespace HScript\Finance;

use InvalidArgumentException;

/** Route-owned, safe response data; excludes credentials and raw gateway data. */
final readonly class IdempotencyResult
{
    public function __construct(
        public array $data,
        public int $status = 200,
        public ?int $operationId = null,
        public bool $replayed = false,
    ) {
        if ($status < 200 || $status > 599 || ($operationId !== null && ($operationId < 1 || $operationId > 2147483647)))
            throw new InvalidArgumentException('Invalid idempotency result');
    }
}
