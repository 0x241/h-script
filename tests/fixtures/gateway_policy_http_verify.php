<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('H_SCRIPT_FINANCIAL_TEST') !== '1' || getenv('DB_NAME') !== 'fixture') exit(2);
require dirname(__DIR__,2).'/vendor/autoload.php';
$db=new HScript\Database\Connection();
if (!$db->open(getenv('DB_HOST'),'fixture',getenv('DB_USER'),getenv('DB_PASSWORD'))) exit(1);
$baseline=json_decode(file_get_contents('/tmp/gateway-policy-baseline.json'),true,32,JSON_THROW_ON_ERROR);
$checks=0;
foreach (['Currs','Wallets','Opers','Hist','Deps','Jobs'] as $table) {
    if ($db->fetchRows($db->query('SELECT * FROM '.$table))!==$baseline[$table]) throw new RuntimeException('Unsupported HTTP request changed '.$table);
    $checks++;
}
if ($db->fetchRows($db->query('SELECT uID,uBal FROM Users ORDER BY uID'))!==$baseline['Users'])
    throw new RuntimeException('Unsupported HTTP request changed user balances');
$checks++;
$keys=$db->fetchRows($db->query("SELECT * FROM IdempotencyKeys WHERE ikNamespace LIKE 'gateway:%' OR ikNamespace='invoice:attempt'"));
if ($keys!==$baseline['gateway_keys']) throw new RuntimeException('Unsupported HTTP request persisted callback/invoice authority');
echo 'Callback policy HTTP financial/configuration projection preserved: '.($checks+1)." checks.\n";
