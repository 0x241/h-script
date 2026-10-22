<?php

use HScript\Database\Connection;
use HScript\Payment\Provider\ProviderNetworkSchema;

return [
    'id'=>'202610151900_provider_networks',
    'from'=>'1.0.6','to'=>'1.0.7','classification'=>'backup-required',
    'up'=>static function(Connection $database): void { ProviderNetworkSchema::ensure($database); },
];
