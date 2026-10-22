<?php
use HScript\Database\Connection;
return ['id'=>'202610170900_provider_checkout','from'=>'1.0.10','to'=>'1.0.11','classification'=>'backup-required',
    'up'=>static function(Connection $database):void{HScript\Payment\CheckoutProgressSchema::ensure($database);HScript\Payment\Provider\ProviderCatalogSchema::purgeRetired($database);}];
