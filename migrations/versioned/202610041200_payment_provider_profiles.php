<?php

use HScript\Database\Connection;
use HScript\Payment\Provider\PaymentProviderSchema;

return [
    'id'=>'202610041200_payment_provider_profiles',
    'from'=>'1.0.4',
    'to'=>'1.0.5',
    'classification'=>'backup-required',
    'up'=>static function(Connection $database): void { PaymentProviderSchema::ensure($database); },
];
