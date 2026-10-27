<?php
use HScript\Database\Connection;
return ['id'=>'202610261200_currency_archive','from'=>'1.0.15','to'=>'1.0.16','classification'=>'backup-required',
    'up'=>static function(Connection $database):void{HScript\Payment\Provider\CurrencyArchiveSchema::ensure($database);}];
