<?php
use HScript\Database\Connection;
use HScript\Payment\Routing\TokenCatalogSchema;
return ['id'=>'202610162100_token_catalog','from'=>'1.0.9','to'=>'1.0.10','classification'=>'backup-required',
    'up'=>static function(Connection $database):void{TokenCatalogSchema::ensure($database);}];
