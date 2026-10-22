<?php

namespace HScript\Finance;

use HScript\Database\Connection;
use PDO;
use RuntimeException;

/** Explicit migration only; request handlers must never create schema. */
final class IdempotencySchema
{
    public static function ensure(Connection $database): void
    {
        $pdo = $database->link;
        $definition = require dirname(__DIR__, 2) . '/resources/idempotency-schema.php';
        $pdo->exec('CREATE TABLE IF NOT EXISTS IdempotencyKeys (' . $definition . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $engine = $pdo->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='IdempotencyKeys'")->fetchColumn();
        if ($engine !== 'InnoDB') throw new RuntimeException('Incompatible idempotency storage engine');
        $columns = $pdo->query('SHOW FULL COLUMNS FROM IdempotencyKeys')->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
        $expected = [
            'ikID' => ['bigint unsigned', 'NO'], 'ikNamespace' => ['varchar(96)', 'NO'],
            'ikKeyHash' => ['char(64)', 'NO'], 'ikFingerprint' => ['char(64)', 'NO'],
            'ikClaimToken' => ['char(32)', 'NO'],
            'ikOwnerID' => ['int unsigned', 'NO'], 'ikOperationID' => ['int unsigned', 'YES'],
            'ikState' => ['varchar(16)', 'NO'], 'ikResponse' => ['longtext', 'YES'],
            'ikCreatedAt' => ['bigint unsigned', 'NO'], 'ikExpiresAt' => ['bigint unsigned', 'NO'],
        ];
        if (count($columns) !== count($expected)) throw new RuntimeException('Incompatible idempotency columns');
        foreach ($expected as $name => [$type, $nullable]) {
            $column = $columns[$name] ?? [];
            $actual = preg_replace('/^(bigint|int)\([0-9]+\)/', '$1', strtolower($column['Type'] ?? ''));
            if ($actual !== $type || ($column['Null'] ?? '') !== $nullable)
                throw new RuntimeException('Incompatible idempotency column: ' . $name);
            if (in_array($name, ['ikNamespace', 'ikKeyHash', 'ikFingerprint', 'ikClaimToken', 'ikState'], true) && ($column['Collation'] ?? '') !== 'ascii_bin')
                throw new RuntimeException('Idempotency identifiers must use binary ASCII collation');
        }
        if (($columns['ikID']['Extra'] ?? '') !== 'auto_increment') throw new RuntimeException('Incompatible idempotency identity');
        $indexes = $pdo->query('SHOW INDEX FROM IdempotencyKeys')->fetchAll(PDO::FETCH_ASSOC);
        $unique = array_values(array_filter($indexes, fn(array $row): bool => $row['Key_name'] === 'uq_idempotency_namespace_key'));
        usort($unique, fn(array $a, array $b): int => $a['Seq_in_index'] <=> $b['Seq_in_index']);
        if (array_column($unique, 'Column_name') !== ['ikNamespace', 'ikKeyHash'] || array_sum(array_column($unique, 'Non_unique')) !== 0)
            throw new RuntimeException('Missing exact unique idempotency namespace/key constraint');
        foreach ($unique as $index) if ($index['Sub_part'] !== null) throw new RuntimeException('Idempotency uniqueness cannot use prefix indexes');
        $constraints = $pdo->query("SELECT cc.CONSTRAINT_NAME, cc.CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS cc JOIN information_schema.TABLE_CONSTRAINTS tc ON tc.CONSTRAINT_SCHEMA=cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME=cc.CONSTRAINT_NAME WHERE tc.CONSTRAINT_SCHEMA=DATABASE() AND tc.TABLE_NAME='IdempotencyKeys'")->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ([
            'chk_idempotency_state' => "ikstatein'pending','completed'",
            'chk_idempotency_response' => 'ikresponseisnullorjson_validikresponse',
        ] as $name => $clause) {
            // MySQL emits charset introducers on literals; MariaDB omits them.
            $actual = str_replace("\\'", "'", strtolower($constraints[$name] ?? ''));
            $actual = preg_replace("/_[a-z][a-z0-9]*(?=')/", '', $actual);
            if (preg_replace('/[`()\s]/', '', $actual) !== $clause)
                throw new RuntimeException('Incompatible idempotency constraint: ' . $name);
        }
    }
}
