<?php
use HScript\Database\Connection;
return ['id'=>'202610241200_paw_payouts','from'=>'1.0.12','to'=>'1.0.13','classification'=>'backup-required',
    'up'=>static function(Connection $database):void {HScript\Payment\Provider\PawPayoutSchema::ensure($database);}];
