<?php
use HScript\Database\Connection;
return ['id'=>'202610251300_coinpayments_new_catalog','from'=>'1.0.14','to'=>'1.0.15','classification'=>'backup-required',
    'up'=>static function(Connection $database):void{HScript\Payment\Provider\CoinPaymentsNewSchema::ensure($database);}];
