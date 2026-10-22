<?php

declare(strict_types=1);

namespace HScript\Navigation;

use HScript\Database\Connection;
use RuntimeException;

final class MenuPinsSchema
{
    public static function ensure(Connection $db): void
    {
        $engine = $db->fetch1($db->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='AddInfo'"));
        if (strtolower((string)$engine) !== 'innodb') throw new RuntimeException('Menu preferences require InnoDB AddInfo');
        $column = $db->fetch1Row($db->query("SHOW FULL COLUMNS FROM AddInfo WHERE Field='aMenuPins'"));
        if (!$column) {
            $db->query('ALTER TABLE AddInfo ADD COLUMN aMenuPins text NULL');
            return;
        }
        if (strtolower($column['Type']) !== 'text' || $column['Null'] !== 'YES'
            || !in_array($column['Default'], [null, 'NULL'], true) || $column['Extra'] !== '')
            throw new RuntimeException('Existing menu preferences column is incompatible');
    }
}
