<?php
use HScript\Database\Connection;
use HScript\Payment\Routing\PaymentRouteSchema;
return ['id'=>'202610161800_payment_routes','from'=>'1.0.8','to'=>'1.0.9','classification'=>'backup-required',
    'up'=>static function(Connection $database):void{PaymentRouteSchema::ensure($database);}];
