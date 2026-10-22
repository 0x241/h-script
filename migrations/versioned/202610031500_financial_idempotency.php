<?php

use HScript\Database\Connection;
use HScript\Finance\IdempotencySchema;

return [
    'id' => '202610031500_financial_idempotency',
    'from' => '1.0.3',
    'to' => '1.0.4',
    'classification' => 'backup-required',
    'up' => static function (Connection $database): void { IdempotencySchema::ensure($database); },
];
