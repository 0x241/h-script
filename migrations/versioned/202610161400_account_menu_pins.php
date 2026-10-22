<?php

use HScript\Database\Connection;
use HScript\Navigation\MenuPinsSchema;

return [
    'id' => '202610161400_account_menu_pins',
    'from' => '1.0.7', 'to' => '1.0.8', 'classification' => 'backup-required',
    'up' => static function (Connection $database): void { MenuPinsSchema::ensure($database); },
];
