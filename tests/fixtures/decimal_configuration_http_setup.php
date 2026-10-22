<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('H_SCRIPT_FINANCIAL_TEST') !== '1' || getenv('DB_NAME') !== 'fixture') exit(2);
require dirname(__DIR__,2).'/vendor/autoload.php';
$db=new HScript\Database\Connection();
if (!$db->open(getenv('DB_HOST'),'fixture',getenv('DB_USER'),getenv('DB_PASSWORD'))) exit(1);
// Do not enqueue a real AdminIn delivery in this synthetic, worker-free fixture.
$db->replace('Cfg',['Module'=>'Sys','Prop'=>'AdminMail','Val'=>'']);
$db->link->exec('DELETE FROM Calend');
echo "Synthetic configuration form fixtures ready.\n";
