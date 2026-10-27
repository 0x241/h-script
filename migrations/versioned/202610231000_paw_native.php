<?php
use HScript\Database\Connection;
return ['id'=>'202610231000_paw_native','from'=>'1.0.11','to'=>'1.0.12','classification'=>'backup-required',
    'up'=>static function(Connection $database):void {HScript\Payment\Provider\PawNativeSchema::ensure($database);}];
