<?php

use HScript\Database\Connection;
use HScript\Payment\Provider\ProviderConnectionSchema;
use HScript\Payment\Provider\ProviderCatalogSchema;

return [
    'id'=>'202610150900_shared_provider_connections',
    'from'=>'1.0.5','to'=>'1.0.6','classification'=>'backup-required',
    'up'=>static function(Connection $database): void {
        ProviderConnectionSchema::ensure($database);
        ProviderCatalogSchema::ensure($database);
    },
];
