<?php
use HScript\Database\Connection;
return ['id'=>'202610241600_paykassa_assets','from'=>'1.0.13','to'=>'1.0.14','classification'=>'backup-required',
    'up'=>static function(Connection $database):void {HScript\Payment\Provider\PaykassaAssetSchema::ensure($database);}];
